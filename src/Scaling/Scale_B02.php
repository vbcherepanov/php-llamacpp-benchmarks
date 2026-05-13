<?php

declare(strict_types=1);

namespace PhpLlamaBench\Scaling;

use SplFixedArray;

/**
 * Scaling B02: SplFixedArray vs PHP array on sequential ints.
 *
 * Pure in-memory — no fixture files. Each scale tier reports populate,
 * iterate, random read times and peak memory after population.
 */
final class Scale_B02 implements ScaleBenchmark
{
    private const RANDOM_OPS = 100_000;

    private int $n = 0;
    /** @var list<int> */
    private array $randomIdx = [];

    public function name(): string
    {
        return 'B02';
    }

    public function setup(int $n): void
    {
        $this->n = $n;
        mt_srand(0xB02);
        $idx = [];
        for ($i = 0; $i < self::RANDOM_OPS; $i++) {
            $idx[] = mt_rand(0, $n - 1);
        }
        $this->randomIdx = $idx;
    }

    public function run(string $path): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        if ($path === 'naive') {
            $t0   = hrtime(true);
            $arr  = [];
            for ($i = 0; $i < $this->n; $i++) {
                $arr[$i] = $i;
            }
            $populateNs = hrtime(true) - $t0;
            $peakBytes  = memory_get_peak_usage(true) - $baseline;

            $sum = 0;
            $t0  = hrtime(true);
            foreach ($arr as $v) {
                $sum += $v;
            }
            $iterateNs = hrtime(true) - $t0;

            $t0 = hrtime(true);
            foreach ($this->randomIdx as $idx) {
                $sum += $arr[$idx];
            }
            $readNs = hrtime(true) - $t0;

            return [
                'n'                   => $this->n,
                'path'                => 'naive',
                'populate_ns'         => $populateNs,
                'peak_memory_bytes'   => $peakBytes,
                'iterate_ns'          => $iterateNs,
                'random_read_ns'      => $readNs,
                'iterate_sum_sample'  => $sum % PHP_INT_MAX,
            ];
        }

        // optimized — SplFixedArray
        $t0  = hrtime(true);
        $sfa = new SplFixedArray($this->n);
        for ($i = 0; $i < $this->n; $i++) {
            $sfa[$i] = $i;
        }
        $populateNs = hrtime(true) - $t0;
        $peakBytes  = memory_get_peak_usage(true) - $baseline;

        $sum = 0;
        $t0  = hrtime(true);
        foreach ($sfa as $v) {
            $sum += $v;
        }
        $iterateNs = hrtime(true) - $t0;

        $t0 = hrtime(true);
        foreach ($this->randomIdx as $idx) {
            $sum += $sfa[$idx];
        }
        $readNs = hrtime(true) - $t0;

        return [
            'n'                   => $this->n,
            'path'                => 'optimized',
            'populate_ns'         => $populateNs,
            'peak_memory_bytes'   => $peakBytes,
            'iterate_ns'          => $iterateNs,
            'random_read_ns'      => $readNs,
            'iterate_sum_sample'  => $sum % PHP_INT_MAX,
        ];
    }

    public function teardown(): void
    {
        $this->randomIdx = [];
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
        return 'peak_memory_bytes';
    }
}
