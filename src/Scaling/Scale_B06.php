<?php

declare(strict_types=1);

namespace PhpLlamaBench\Scaling;

use SplFixedArray;
use stdClass;

/**
 * Scaling B06: array-of-stdClass (row layout) vs parallel SplFixedArray
 * columns (column layout), single-column scan plus full-row scan.
 *
 * The `ns_per_record` metric — scan_time / N — is the chart's headline so
 * the ladder steps at L1/L2/L3 cache boundaries become visible.
 */
final class Scale_B06 implements ScaleBenchmark
{
    private int $n = 0;

    public function name(): string
    {
        return 'B06';
    }

    public function setup(int $n): void
    {
        $this->n = $n;
    }

    public function run(string $path): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        if ($path === 'naive') {
            /** @var SplFixedArray<stdClass> $rows */
            $rows = new SplFixedArray($this->n);
            for ($i = 0; $i < $this->n; $i++) {
                $o = new stdClass();
                $o->f1 = $i;
                $o->f2 = $i + 1;
                $o->f3 = $i + 2;
                $o->f4 = $i + 3;
                $o->f5 = $i + 4;
                $rows[$i] = $o;
            }
            $peak = memory_get_peak_usage(true) - $baseline;

            $sum = 0;
            $t0  = hrtime(true);
            foreach ($rows as $r) {
                /** @phpstan-ignore-next-line */
                $sum += $r->f3;
            }
            $scanNs = hrtime(true) - $t0;

            $sum2 = 0;
            $t0   = hrtime(true);
            foreach ($rows as $r) {
                /** @phpstan-ignore-next-line */
                $sum2 += $r->f1 + $r->f2 + $r->f3 + $r->f4 + $r->f5;
            }
            $fullRowNs = hrtime(true) - $t0;

            return [
                'n'                    => $this->n,
                'path'                 => 'naive',
                'peak_memory_bytes'    => $peak,
                'scan_time_ns'         => $scanNs,
                'full_row_scan_ns'     => $fullRowNs,
                'ns_per_record'        => $scanNs / max(1, $this->n),
                'sum_sample'           => $sum % PHP_INT_MAX,
                'full_sum_sample'      => $sum2 % PHP_INT_MAX,
            ];
        }

        $f1 = new SplFixedArray($this->n);
        $f2 = new SplFixedArray($this->n);
        $f3 = new SplFixedArray($this->n);
        $f4 = new SplFixedArray($this->n);
        $f5 = new SplFixedArray($this->n);
        for ($i = 0; $i < $this->n; $i++) {
            $f1[$i] = $i;
            $f2[$i] = $i + 1;
            $f3[$i] = $i + 2;
            $f4[$i] = $i + 3;
            $f5[$i] = $i + 4;
        }
        $peak = memory_get_peak_usage(true) - $baseline;

        $sum = 0;
        $t0  = hrtime(true);
        foreach ($f3 as $v) {
            $sum += $v;
        }
        $scanNs = hrtime(true) - $t0;

        $sum2 = 0;
        $t0   = hrtime(true);
        for ($i = 0; $i < $this->n; $i++) {
            $sum2 += $f1[$i] + $f2[$i] + $f3[$i] + $f4[$i] + $f5[$i];
        }
        $fullRowNs = hrtime(true) - $t0;

        return [
            'n'                    => $this->n,
            'path'                 => 'optimized',
            'peak_memory_bytes'    => $peak,
            'scan_time_ns'         => $scanNs,
            'full_row_scan_ns'     => $fullRowNs,
            'ns_per_record'        => $scanNs / max(1, $this->n),
            'sum_sample'           => $sum % PHP_INT_MAX,
            'full_sum_sample'      => $sum2 % PHP_INT_MAX,
        ];
    }

    public function teardown(): void
    {
        gc_collect_cycles();
    }

    public function scales(): array
    {
        return [
            ['100K', 100_000],
            ['500K', 500_000],
            ['1M',   1_000_000],
            ['5M',   5_000_000],
            ['25M',  25_000_000],
            ['50M',  50_000_000],
            ['100M', 100_000_000],
        ];
    }

    public function smokeScales(): array
    {
        return [
            ['100K', 100_000],
            ['1M',   1_000_000],
        ];
    }

    public function headlineMetric(): string
    {
        return 'ns_per_record';
    }
}
