<?php

declare(strict_types=1);

namespace PhpLlamaBench\Scaling;

use FFI;
use RuntimeException;

/**
 * Scaling B01: FFI mmap vs JSON-loaded PHP array.
 *
 * Per-tier fixtures live under `data/scale/lookup_<N>.bin` (always) and
 * `data/scale/lookup_<N>.json` (only for N ≤ 100M — at 250M and above the
 * JSON sibling becomes unreasonable on disk and IO).
 *
 * The setup() routine *re-generates* the fixture if absent. The orchestrator
 * cleans up between tiers (except the smallest 1M for sanity).
 */
final class Scale_B01 implements ScaleBenchmark
{
    /** Tiers above this threshold skip the JSON sibling and the naive path. */
    private const MAX_JSON_TIER = 100_000_000;

    /** Lookups per measured tier; we drop to 10K for the largest tiers. */
    private const LOOKUPS_NORMAL  = 100_000;
    private const LOOKUPS_LARGE   = 10_000;
    private const LARGE_THRESHOLD = 100_000_000;

    private int $n = 0;
    private string $binPath  = '';
    private string $jsonPath = '';
    private bool $jsonAvailable = false;
    private int $lookupCount = self::LOOKUPS_NORMAL;
    /** @var list<int> */
    private array $randomIds = [];

    private FFI $ffi;

    public function name(): string
    {
        return 'B01';
    }

    public function setup(int $n): void
    {
        $this->n            = $n;
        $this->lookupCount  = $n > self::LARGE_THRESHOLD ? self::LOOKUPS_LARGE : self::LOOKUPS_NORMAL;
        $base               = __DIR__ . '/../../data/scale';
        if (!is_dir($base)) {
            mkdir($base, 0o755, true);
        }
        $this->binPath       = $base . '/lookup_' . $n . '.bin';
        $this->jsonPath      = $base . '/lookup_' . $n . '.json';
        $this->jsonAvailable = $n <= self::MAX_JSON_TIER;

        $this->ffi = $this->openLibc();

        $this->ensureBinFixture();
        if ($this->jsonAvailable) {
            $this->ensureJsonFixture();
        }

        // Precompute random ids for the lookup loops (excluded from timing).
        mt_srand(0xB01 ^ $n);
        $ids = [];
        for ($i = 0; $i < $this->lookupCount; $i++) {
            $ids[] = mt_rand(0, $n - 1);
        }
        $this->randomIds = $ids;
    }

    private function openLibc(): FFI
    {
        $libc = match (PHP_OS_FAMILY) {
            'Darwin' => 'libc.dylib',
            'Linux'  => 'libc.so.6',
            default  => throw new RuntimeException('Unsupported OS for FFI mmap'),
        };
        return FFI::cdef(<<<'CDEF'
            void *mmap(void *addr, size_t length, int prot, int flags, int fd, long offset);
            int munmap(void *addr, size_t length);
            int open(const char *pathname, int flags);
            int close(int fd);
            CDEF, $libc);
    }

    private function ensureBinFixture(): void
    {
        $expected = $this->n * 8;
        if (is_file($this->binPath) && filesize($this->binPath) === $expected) {
            return;
        }
        fwrite(STDERR, sprintf("  (generating %s, %.2f GB)...\n", basename($this->binPath), $expected / (1024 ** 3)));
        mt_srand(0xC0FFEE ^ $this->n);
        $fh = fopen($this->binPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('cannot open ' . $this->binPath);
        }
        $chunk = 100_000;
        $batch = '';
        for ($i = 0; $i < $this->n; $i++) {
            $v = mt_rand(0, 0x7FFFFFFF);
            $batch .= pack('VV', $i, $v);
            if ((($i + 1) % $chunk) === 0) {
                fwrite($fh, $batch);
                $batch = '';
            }
        }
        if ($batch !== '') {
            fwrite($fh, $batch);
        }
        fclose($fh);
    }

    private function ensureJsonFixture(): void
    {
        if (is_file($this->jsonPath) && filesize($this->jsonPath) > 2) {
            return;
        }
        fwrite(STDERR, "  (generating " . basename($this->jsonPath) . ")...\n");
        mt_srand(0xC0FFEE ^ $this->n);
        $fh = fopen($this->jsonPath, 'wb');
        if ($fh === false) {
            throw new RuntimeException('cannot open ' . $this->jsonPath);
        }
        fwrite($fh, '[');
        $chunk = 100_000;
        $batch = '';
        for ($i = 0; $i < $this->n; $i++) {
            $v = mt_rand(0, 0x7FFFFFFF);
            $batch .= $i === 0 ? (string) $v : (',' . $v);
            if ((($i + 1) % $chunk) === 0) {
                fwrite($fh, $batch);
                $batch = '';
            }
        }
        if ($batch !== '') {
            fwrite($fh, $batch);
        }
        fwrite($fh, ']');
        fclose($fh);
    }

    public function run(string $path): array
    {
        if ($path === 'naive') {
            if (!$this->jsonAvailable) {
                // Fixture intentionally skipped — naive path can't run.
                return [
                    'n'                       => $this->n,
                    'path'                    => 'naive',
                    'status_reason'           => 'SKIPPED_FIXTURE_TOO_LARGE',
                    'load_time_ns'            => 0,
                    'php_heap_after_load_bytes' => 0,
                    'lookup_p50_ns'           => 0,
                    'lookup_p99_ns'           => 0,
                    'lookup_count'            => 0,
                ];
            }
            return $this->runNaive();
        }
        return $this->runOptimized();
    }

    /**
     * @return array<string, mixed>
     */
    private function runNaive(): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $t0 = hrtime(true);
        $data = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($data) || !isset($data[0])) {
            throw new RuntimeException('json decode failed for ' . $this->jsonPath);
        }
        $first = $data[0]; // sanity lookup
        $loadNs = hrtime(true) - $t0;
        $heap   = memory_get_usage(true) - $baseline;
        if ($first < 0) {
            throw new RuntimeException('naive first lookup negative');
        }

        /** @var list<int> $samples */
        $samples = [];
        foreach ($this->randomIds as $id) {
            $t = hrtime(true);
            $v = $data[$id];
            $samples[] = hrtime(true) - $t;
            if ($v < 0) {
                throw new RuntimeException('naive lookup negative');
            }
        }
        sort($samples);

        return [
            'n'                       => $this->n,
            'path'                    => 'naive',
            'load_time_ns'            => $loadNs,
            'php_heap_after_load_bytes' => $heap,
            'lookup_p50_ns'           => $samples[(int) floor(count($samples) * 0.5)],
            'lookup_p99_ns'           => $samples[(int) floor(count($samples) * 0.99)],
            'lookup_count'            => count($samples),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runOptimized(): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $size = (int) filesize($this->binPath);

        $t0 = hrtime(true);
        $fd = $this->ffi->open($this->binPath, 0);
        if ($fd < 0) {
            throw new RuntimeException('open failed for ' . $this->binPath);
        }
        // PROT_READ=1, MAP_PRIVATE=2
        $ptr = $this->ffi->mmap(null, $size, 1, 2, $fd, 0);
        $this->ffi->close($fd);
        $u32 = $this->ffi->cast('uint32_t*', $ptr);
        $first = $u32[1];
        $loadNs = hrtime(true) - $t0;
        $heap   = memory_get_usage(true) - $baseline;
        if ($first < 0) {
            throw new RuntimeException('mmap first lookup negative');
        }

        /** @var list<int> $samples */
        $samples = [];
        foreach ($this->randomIds as $id) {
            $t = hrtime(true);
            $v = $u32[$id * 2 + 1];
            $samples[] = hrtime(true) - $t;
            if ($v < 0) {
                throw new RuntimeException('mmap lookup negative');
            }
        }
        sort($samples);

        $this->ffi->munmap($ptr, $size);

        return [
            'n'                       => $this->n,
            'path'                    => 'optimized',
            'load_time_ns'            => $loadNs,
            'php_heap_after_load_bytes' => $heap,
            'lookup_p50_ns'           => $samples[(int) floor(count($samples) * 0.5)],
            'lookup_p99_ns'           => $samples[(int) floor(count($samples) * 0.99)],
            'lookup_count'            => count($samples),
        ];
    }

    public function teardown(): void
    {
        $this->randomIds = [];
        gc_collect_cycles();
    }

    public function scales(): array
    {
        return [
            ['1M',   1_000_000],
            ['10M',  10_000_000],
            ['50M',  50_000_000],
            ['100M', 100_000_000],
            ['250M', 250_000_000],
            ['500M', 500_000_000],
            ['1B',   1_000_000_000],
        ];
    }

    public function smokeScales(): array
    {
        return [
            ['1M',  1_000_000],
            ['10M', 10_000_000],
        ];
    }

    public function headlineMetric(): string
    {
        return 'load_time_ns';
    }
}
