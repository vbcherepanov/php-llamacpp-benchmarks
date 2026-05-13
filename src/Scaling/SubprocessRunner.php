<?php

declare(strict_types=1);

namespace PhpLlamaBench\Scaling;

use RuntimeException;

/**
 * Spawns a child PHP process per (benchmark, scale, path). The child runs
 * inside its own address space, so PHP fatal errors, kernel OOM-killer kills,
 * and segfaults can't bring the orchestrator down.
 */
final class SubprocessRunner
{
    public function __construct(
        private readonly string $phpBinary       = PHP_BINARY,
        private readonly string $workerScript    = __DIR__ . '/../../bin/scale-worker.php',
        private readonly int    $timeoutSeconds  = 1200,
        private readonly string $memoryLimit     = '60G',
        // Maximum stderr/stdout we keep in memory; child may write a lot.
        private readonly int    $maxCapturedBytes = 1_048_576,
    ) {}

    /**
     * @return array{
     *     status: 'ok'|'OOM'|'TIMEOUT'|'CRASH',
     *     metrics: array<string, mixed>|null,
     *     stderr: string,
     *     exit_code: int,
     *     elapsed_ns: int
     * }
     */
    public function run(string $shortClassName, string $scaleLabel, int $n, string $path): array
    {
        $cmd = [
            $this->phpBinary,
            '-d', 'memory_limit=' . $this->memoryLimit,
            '-d', 'opcache.enable_cli=1',
            '-d', 'opcache.jit=tracing',
            $this->workerScript,
            $shortClassName,
            (string) $n,
            $path,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $start = hrtime(true);
        $proc  = proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($proc)) {
            throw new RuntimeException('proc_open failed for ' . $shortClassName);
        }
        fclose($pipes[0]); // child reads no stdin

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout      = '';
        $stderr      = '';
        $deadline    = microtime(true) + $this->timeoutSeconds;
        $killed      = false;
        $sentSigterm = false;
        $finalStatus = null; // proc_get_status snapshot captured on exit

        while (true) {
            $status = proc_get_status($proc);
            if (!$status['running']) {
                // proc_get_status returns the real exit code only on the first
                // call after the process is no longer running; cache it here.
                $finalStatus = $status;
                // Drain remaining pipe contents.
                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }

            $now = microtime(true);
            if ($now >= $deadline) {
                if (!$sentSigterm) {
                    @proc_terminate($proc, 15); // SIGTERM
                    $sentSigterm = true;
                    $deadline    = $now + 5.0; // grace
                } else {
                    @proc_terminate($proc, 9); // SIGKILL
                    $killed = true;
                }
            }

            $read   = [$pipes[1], $pipes[2]];
            $write  = null;
            $except = null;
            // 200ms tick — quick enough to react to exit, slow enough to be cheap.
            @stream_select($read, $write, $except, 0, 200_000);

            foreach ($read as $stream) {
                $chunk = fread($stream, 65_536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $pipes[1]) {
                    if (strlen($stdout) < $this->maxCapturedBytes) {
                        $stdout .= $chunk;
                    }
                } else {
                    if (strlen($stderr) < $this->maxCapturedBytes) {
                        $stderr .= $chunk;
                    }
                }
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        $closeCode = proc_close($proc);
        $elapsed   = hrtime(true) - $start;

        // Prefer the cached snapshot from the moment the process stopped;
        // proc_close returns -1 if the kernel cleaned up before us, and for
        // signal terminations (e.g. SIGKILL from the OOM-killer) the signal
        // is only visible via proc_get_status.
        $exitCode = $closeCode;
        $termSig  = 0;
        $signaled = false;
        if (is_array($finalStatus)) {
            $signaled = $finalStatus['signaled'];
            $termSig  = $finalStatus['termsig'];
            $reported = $finalStatus['exitcode'];
            if ($reported >= 0) {
                $exitCode = $reported;
            } elseif ($signaled && $termSig > 0) {
                $exitCode = 128 + $termSig;
            }
        }

        $status = $this->classify($exitCode, $stdout, $stderr, $killed, $sentSigterm, $signaled, $termSig);

        $metrics = null;
        if ($status === 'ok') {
            $envelope = $this->parseMetrics($stdout);
            if ($envelope === null) {
                // Child claimed success but emitted no parsable JSON — treat as crash.
                $status = 'CRASH';
                $stderr = "no JSON on stdout\n--- stdout ---\n" . $stdout . "\n--- stderr ---\n" . $stderr;
            } else {
                $inner = $envelope['metrics'] ?? null;
                if (is_array($inner)) {
                    /** @var array<string, mixed> $inner */
                    $metrics = $inner;
                } else {
                    $status = 'CRASH';
                    $stderr = "envelope.metrics missing\n--- stdout ---\n" . $stdout;
                }
            }
        }

        return [
            'status'     => $status,
            'metrics'    => $metrics,
            'stderr'     => $this->compactStderr($stderr),
            'exit_code'  => $exitCode,
            'elapsed_ns' => $elapsed,
        ];
    }

    /**
     * @return 'ok'|'OOM'|'TIMEOUT'|'CRASH'
     */
    private function classify(
        int $exitCode,
        string $stdout,
        string $stderr,
        bool $killed,
        bool $sentSigterm,
        bool $signaled,
        int $termSig,
    ): string {
        if ($killed || $sentSigterm) {
            // We sent the signal because the deadline elapsed.
            return 'TIMEOUT';
        }
        // SIGKILL (9) without us sending it == kernel OOM-killer on Linux / Docker.
        if ($signaled && $termSig === 9) {
            return 'OOM';
        }
        if ($exitCode === 137) {
            return 'OOM';
        }
        if ($exitCode === 0) {
            return 'ok';
        }
        if (str_contains($stderr, 'Allowed memory size of')
            || str_contains($stderr, 'out of memory')
            || str_contains($stderr, 'Out of memory')) {
            return 'OOM';
        }
        return 'CRASH';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseMetrics(string $stdout): ?array
    {
        // The child prints exactly one JSON object on its last non-empty line.
        $lines = preg_split('/\r?\n/', trim($stdout));
        if ($lines === false) {
            return null;
        }
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if ($line === '') {
                continue;
            }
            if ($line[0] !== '{') {
                continue;
            }
            try {
                $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    /** @var array<string, mixed> $decoded */
                    return $decoded;
                }
            } catch (\JsonException) {
                continue;
            }
        }
        return null;
    }

    private function compactStderr(string $stderr): string
    {
        $s = trim($stderr);
        if (strlen($s) <= 600) {
            return $s;
        }
        return substr($s, 0, 300) . "\n…\n" . substr($s, -300);
    }
}
