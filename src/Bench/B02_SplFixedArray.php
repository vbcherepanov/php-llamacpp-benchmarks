<?php

declare(strict_types=1);

namespace PhpLlamaBench\Bench;

use PhpLlamaBench\Contract\Benchmark;
use PhpLlamaBench\Contract\HasExtraReport;
use PhpLlamaBench\Stats;
use SplFixedArray;

/**
 * B02: SplFixedArray vs PHP array (HashMap).
 *
 * Hypothesis: for dense numeric data of known size, SplFixedArray uses
 * ~3-5× less memory than PHP's array and iterates faster (no hash overhead).
 *
 * llama.cpp parallel: dense ggml tensors are flat contiguous buffers, not
 * key→value maps. PHP's SplFixedArray is the nearest equivalent.
 */
final class B02_SplFixedArray implements Benchmark, HasExtraReport
{
    private const N = 10_000_000;
    private const RANDOM_OPS = 1_000_000;

    /** @var array<int, int> */
    private array $arrayHash;
    private SplFixedArray $arrayFixed;
    /** @var list<int> */
    private array $randomIndices;

    private int $arrayHashMemory  = 0;
    private int $arrayFixedMemory = 0;
    private int $arrayHashPopulateNs  = 0;
    private int $arrayFixedPopulateNs = 0;
    private int $arrayHashReadsNs  = 0;
    private int $arrayFixedReadsNs = 0;
    private int $arrayHashWritesNs  = 0;
    private int $arrayFixedWritesNs = 0;
    private int $antiDce            = 0; // keeps the JIT from eliding sum loops

    public function name(): string
    {
        return 'B02_SplFixedArray';
    }

    public function description(): string
    {
        return 'SplFixedArray vs HashMap array on 10M sequential integers';
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
        mt_srand(0xA11CE);
        $idx = [];
        for ($i = 0; $i < self::RANDOM_OPS; $i++) {
            $idx[] = mt_rand(0, self::N - 1);
        }
        $this->randomIndices = $idx;

        // Populate HashMap-style array, capture memory + populate time.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $t0  = hrtime(true);
        $arr = [];
        for ($i = 0; $i < self::N; $i++) {
            $arr[$i] = $i;
        }
        $this->arrayHashPopulateNs = hrtime(true) - $t0;
        $this->arrayHashMemory     = memory_get_peak_usage(true) - $baseline;
        $this->arrayHash           = $arr;

        // Random reads on hash array.
        $sum = 0;
        $t0  = hrtime(true);
        foreach ($this->randomIndices as $i) {
            $sum += $arr[$i];
        }
        $this->arrayHashReadsNs = hrtime(true) - $t0;
        $this->antiDce         += $sum;

        // Random writes on hash array.
        $t0 = hrtime(true);
        foreach ($this->randomIndices as $i) {
            $arr[$i] = $i + 1;
        }
        $this->arrayHashWritesNs = hrtime(true) - $t0;
        $this->arrayHash = $arr;

        // Populate SplFixedArray.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $t0   = hrtime(true);
        $sfa  = new SplFixedArray(self::N);
        for ($i = 0; $i < self::N; $i++) {
            $sfa[$i] = $i;
        }
        $this->arrayFixedPopulateNs = hrtime(true) - $t0;
        $this->arrayFixedMemory     = memory_get_peak_usage(true) - $baseline;
        $this->arrayFixed           = $sfa;

        // Random reads on SplFixedArray.
        $sum = 0;
        $t0  = hrtime(true);
        foreach ($this->randomIndices as $i) {
            $sum += $sfa[$i];
        }
        $this->arrayFixedReadsNs = hrtime(true) - $t0;
        $this->antiDce          += $sum;

        // Random writes on SplFixedArray.
        $t0 = hrtime(true);
        foreach ($this->randomIndices as $i) {
            $sfa[$i] = $i + 1;
        }
        $this->arrayFixedWritesNs = hrtime(true) - $t0;
        $this->arrayFixed = $sfa;
    }

    public function naive(): int
    {
        $sum = 0;
        foreach ($this->arrayHash as $v) {
            $sum += $v;
        }
        return $sum;
    }

    public function optimized(): int
    {
        $sum = 0;
        foreach ($this->arrayFixed as $v) {
            $sum += $v;
        }
        return $sum;
    }

    public function teardown(): void
    {
        unset($this->arrayHash, $this->arrayFixed, $this->randomIndices);
        gc_collect_cycles();
    }

    public function hypothesis(): string
    {
        return 'SplFixedArray uses several × less memory than a HashMap array for '
            . 'dense numeric data, and iterates faster because there is no hash '
            . 'overhead. On PHP 8.4 with JIT only the memory half holds.';
    }

    public function extraReport(): array
    {
        return [
            [
                'metric'    => 'Memory (10M ints)',
                'naive'     => Stats::formatBytes($this->arrayHashMemory),
                'optimized' => Stats::formatBytes($this->arrayFixedMemory),
                'ratio'     => Stats::formatRatio((float) $this->arrayHashMemory, (float) $this->arrayFixedMemory),
                'raw'       => [
                    'naive_bytes' => $this->arrayHashMemory,
                    'opt_bytes'   => $this->arrayFixedMemory,
                ],
            ],
            [
                'metric'    => 'Population time (10M inserts)',
                'naive'     => Stats::formatNs($this->arrayHashPopulateNs),
                'optimized' => Stats::formatNs($this->arrayFixedPopulateNs),
                'ratio'     => Stats::formatRatio((float) $this->arrayHashPopulateNs, (float) $this->arrayFixedPopulateNs),
            ],
            [
                'metric'    => '1M random reads',
                'naive'     => Stats::formatNs($this->arrayHashReadsNs),
                'optimized' => Stats::formatNs($this->arrayFixedReadsNs),
                'ratio'     => Stats::formatRatio((float) $this->arrayHashReadsNs, (float) $this->arrayFixedReadsNs),
            ],
            [
                'metric'    => '1M random writes',
                'naive'     => Stats::formatNs($this->arrayHashWritesNs),
                'optimized' => Stats::formatNs($this->arrayFixedWritesNs),
                'ratio'     => Stats::formatRatio((float) $this->arrayHashWritesNs, (float) $this->arrayFixedWritesNs),
            ],
        ];
    }

    public function verdict(): string
    {
        return 'On PHP 8.4 with JIT the win is memory-only. Packed integer-keyed '
            . 'arrays are aggressively optimised — both populate, iterate, and '
            . 'index faster than SplFixedArray. SplFixedArray still pays off '
            . 'when memory is the constraint (workers, very large tables) but '
            . 'do not reach for it expecting a speed boost on modern PHP.';
    }
}
