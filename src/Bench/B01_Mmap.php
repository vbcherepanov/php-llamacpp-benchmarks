<?php

declare(strict_types=1);

namespace PhpLlamaBench\Bench;

use FFI;
use PhpLlamaBench\Contract\Benchmark;
use PhpLlamaBench\Contract\HasExtraReport;
use PhpLlamaBench\Stats;
use RuntimeException;

/**
 * B01: memory-mapped lookup table vs JSON-loaded PHP array.
 *
 * Hypothesis: for large read-only tables, mmap'd binary blobs win on cold-start
 * time, peak memory, and (often) per-lookup latency.
 *
 * llama.cpp parallel: gguf model weights are mmap'd from disk into a typed
 * view (see src/llama-mmap.cpp). The process pays no JSON-parse cost, no zval
 * inflation, and benefits from kernel page-cache sharing across processes.
 */
final class B01_Mmap implements Benchmark, HasExtraReport
{
    private const N       = 10_000_000;
    private const LOOKUPS = 1_000_000;

    private string $binPath;
    private string $jsonPath;

    private FFI $ffi;
    /** @var FFI\CData|null void* */
    private $mmapPtr = null;
    /** @var FFI\CData|null uint32_t* */
    private $mmapU32 = null;
    private int $mmapSize = 0;

    /** @var array<int, int> id => value */
    private array $jsonData = [];

    /** @var list<int> */
    private array $randomIds = [];

    private int $loadJsonNs        = 0;
    private int $loadMmapNs        = 0;
    private int $memoryJsonBytes   = 0;
    private int $memoryMmapBytes   = 0;
    private int $crossProcessJsonNs    = 0;
    private int $crossProcessMmapNs    = 0;
    private int $crossProcessJsonRss   = 0;
    private int $crossProcessMmapRss   = 0;

    public function name(): string
    {
        return 'B01_Mmap';
    }

    public function description(): string
    {
        return '10M-entry read-only lookup: JSON-loaded PHP array vs FFI mmap';
    }

    public function iterations(): int
    {
        return 5;
    }

    public function warmupIterations(): int
    {
        return 1;
    }

    public function setup(): void
    {
        $this->binPath  = realpath(__DIR__ . '/../../data/lookup.bin') ?: throw new RuntimeException(
            'data/lookup.bin missing — run `make fixtures` first'
        );
        $this->jsonPath = realpath(__DIR__ . '/../../data/lookup.json') ?: throw new RuntimeException(
            'data/lookup.json missing — run `make fixtures` first'
        );

        $this->mmapSize = (int) filesize($this->binPath);

        $this->ffi = $this->openLibc();

        // Precompute lookup indices once so the timed loops only do the lookup.
        mt_srand(0x1337);
        $ids = [];
        for ($i = 0; $i < self::LOOKUPS; $i++) {
            $ids[] = mt_rand(0, self::N - 1);
        }
        $this->randomIds = $ids;

        $this->measureWarmJsonLoad();
        $this->measureWarmMmapLoad();
        $this->measureCrossProcess();
    }

    private function openLibc(): FFI
    {
        $libc = match (PHP_OS_FAMILY) {
            'Darwin' => 'libc.dylib',
            'Linux'  => 'libc.so.6',
            default  => throw new RuntimeException('Unsupported OS for FFI mmap: ' . PHP_OS_FAMILY),
        };

        return FFI::cdef(<<<'CDEF'
            void *mmap(void *addr, size_t length, int prot, int flags, int fd, long offset);
            int munmap(void *addr, size_t length);
            int open(const char *pathname, int flags);
            int close(int fd);
            CDEF, $libc);
    }

    /**
     * Warm load: prime OS page cache + PHP file_get_contents path on a
     * throw-away call, then time the second call. Per spec we report warm.
     */
    private function measureWarmJsonLoad(): void
    {
        // Prime.
        $prime = json_decode((string) file_get_contents($this->jsonPath), true);
        unset($prime);
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baselineMem = memory_get_usage(true);

        $t0 = hrtime(true);
        $data = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($data) || !isset($data[0])) {
            throw new RuntimeException('json load failed');
        }
        $first = $data[0]; // a real lookup so the load is end-to-end usable
        $t1 = hrtime(true);

        $this->loadJsonNs        = $t1 - $t0;
        $this->memoryJsonBytes   = memory_get_peak_usage(true) - $baselineMem;
        if ($first < 0) {
            throw new RuntimeException('json first lookup vanished');
        }

        /** @var array<int, int> $data */
        $this->jsonData = $data;
    }

    private function measureWarmMmapLoad(): void
    {
        // Prime: a quick scan over a few pages so the kernel page cache is hot.
        $fdPrime = $this->ffi->open($this->binPath, 0);
        if ($fdPrime < 0) {
            throw new RuntimeException('open(lookup.bin) failed');
        }
        $ptrPrime = $this->ffi->mmap(null, $this->mmapSize, 1, 2, $fdPrime, 0);
        $this->ffi->close($fdPrime);
        $u32Prime = $this->ffi->cast('uint32_t*', $ptrPrime);
        if ($u32Prime === null) {
            throw new RuntimeException('mmap prime cast returned null');
        }
        $accum = 0;
        for ($i = 0; $i < $this->mmapSize; $i += 65_536) {
            $accum ^= $u32Prime[($i >> 2)];
        }
        // referenced via stderr in case the JIT eliminates the loop entirely
        if ($accum === 0xDEADBEEF) {
            fwrite(STDERR, "(prime checksum sentinel hit)\n");
        }
        $this->ffi->munmap($ptrPrime, $this->mmapSize);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $baselineMem = memory_get_usage(true);

        $t0 = hrtime(true);
        $fd = $this->ffi->open($this->binPath, 0);
        if ($fd < 0) {
            throw new RuntimeException('open(lookup.bin) failed');
        }
        // PROT_READ=1, MAP_PRIVATE=2 — same constants on Linux and macOS.
        $ptr = $this->ffi->mmap(null, $this->mmapSize, 1, 2, $fd, 0);
        $this->ffi->close($fd);
        $u32 = $this->ffi->cast('uint32_t*', $ptr);
        if ($u32 === null) {
            throw new RuntimeException('mmap cast returned null');
        }
        $first = $u32[1]; // value for id=0 (record layout: id, value)
        $t1 = hrtime(true);

        $this->loadMmapNs        = $t1 - $t0;
        $this->memoryMmapBytes   = memory_get_peak_usage(true) - $baselineMem;
        if ($first < 0) {
            throw new RuntimeException('mmap first lookup vanished');
        }

        $this->mmapPtr = $ptr;
        $this->mmapU32 = $u32;
    }

    private function measureCrossProcess(): void
    {
        // Run two child PHP scripts that load the table from scratch and report
        // their self-measured load+first-lookup time. The kernel page cache is
        // already hot from this parent process, so mmap should be near-instant.
        $phpBin = PHP_BINARY;
        $script = __DIR__ . '/../../bin/_b01_child_loader.php';

        [$this->crossProcessJsonNs, $this->crossProcessJsonRss] = $this->runChild($phpBin, $script, 'json', $this->jsonPath);
        [$this->crossProcessMmapNs, $this->crossProcessMmapRss] = $this->runChild($phpBin, $script, 'mmap', $this->binPath);
    }

    /**
     * @return array{0:int,1:int} [ns, rss_bytes]
     */
    private function runChild(string $phpBin, string $script, string $mode, string $path): array
    {
        $cmd = [$phpBin, $script, $mode, $path];
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            throw new RuntimeException('failed to spawn child for cross-process measurement');
        }
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        if ($exit !== 0) {
            throw new RuntimeException("child loader ($mode) exit $exit: $stderr");
        }
        $parts = preg_split('/\s+/', trim($stdout));
        if ($parts === false || count($parts) < 2) {
            throw new RuntimeException("child loader ($mode) bad output: $stdout");
        }
        $ns  = (int) $parts[0];
        $rss = (int) $parts[1];
        if ($ns <= 0) {
            throw new RuntimeException("child loader ($mode) returned invalid ns: $stdout");
        }
        return [$ns, $rss];
    }

    public function naive(): int
    {
        // 1M random lookups against the PHP array.
        $sum  = 0;
        $data = $this->jsonData;
        foreach ($this->randomIds as $id) {
            $sum += $data[$id];
        }
        return $sum;
    }

    public function optimized(): int
    {
        // 1M random lookups via mmap'd uint32_t* (id, value) pairs.
        $sum = 0;
        $u32 = $this->mmapU32;
        if ($u32 === null) {
            throw new RuntimeException('mmap pointer not initialised');
        }
        foreach ($this->randomIds as $id) {
            $sum += $u32[$id * 2 + 1];
        }
        return $sum;
    }

    public function teardown(): void
    {
        if ($this->mmapPtr !== null && $this->mmapSize > 0) {
            $this->ffi->munmap($this->mmapPtr, $this->mmapSize);
        }
        $this->mmapPtr  = null;
        $this->mmapU32  = null;
        $this->jsonData = [];
        $this->randomIds = [];
        gc_collect_cycles();
    }

    public function hypothesis(): string
    {
        return 'mmap is far faster on cold start, uses orders of magnitude less '
            . 'PHP heap, and per-lookup latency is competitive with hashmap '
            . 'lookups. The cross-process win is the headline.';
    }

    public function extraReport(): array
    {
        return [
            [
                'metric'    => 'Load time (warm, single process)',
                'naive'     => Stats::formatNs($this->loadJsonNs),
                'optimized' => Stats::formatNs($this->loadMmapNs),
                'ratio'     => Stats::formatRatio((float) $this->loadJsonNs, (float) $this->loadMmapNs),
            ],
            [
                'metric'    => 'PHP heap added by load',
                'naive'     => Stats::formatBytes($this->memoryJsonBytes),
                'optimized' => Stats::formatBytes($this->memoryMmapBytes),
                'ratio'     => $this->memoryMmapBytes <= 0
                    ? 'mmap below allocator granularity'
                    : Stats::formatRatio((float) $this->memoryJsonBytes, (float) $this->memoryMmapBytes),
            ],
            [
                'metric'    => 'Cross-process RSS after load',
                'naive'     => Stats::formatBytes($this->crossProcessJsonRss),
                'optimized' => Stats::formatBytes($this->crossProcessMmapRss),
                'ratio'     => Stats::formatRatio(
                    (float) $this->crossProcessJsonRss,
                    (float) max(1, $this->crossProcessMmapRss),
                ),
            ],
            [
                'metric'    => 'Cross-process cold start (child)',
                'naive'     => Stats::formatNs($this->crossProcessJsonNs),
                'optimized' => Stats::formatNs($this->crossProcessMmapNs),
                'ratio'     => Stats::formatRatio((float) $this->crossProcessJsonNs, (float) $this->crossProcessMmapNs),
            ],
        ];
    }

    public function verdict(): string
    {
        return 'mmap is a process-level win, not a per-lookup win. Inside a '
            . 'single PHP process the JIT optimises `$arr[$id]` so well that '
            . 'individual FFI lookups are *slower* (~2× per access). What mmap '
            . 'actually buys you is startup time (no JSON parse, no zval '
            . 'inflation), flat PHP heap, and free sharing of the table across '
            . 'worker processes via the kernel page cache. Use it when a fleet '
            . 'of PHP-FPM workers needs to share a fat read-only table; skip '
            . 'it for tight read loops inside one process.';
    }
}
