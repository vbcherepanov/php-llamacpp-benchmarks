<?php

declare(strict_types=1);

namespace PhpLlamaBench\Scaling;

use Generator;

/**
 * Scaling B05: full-materialised array vs generator on a synthetic stream.
 *
 * Workload: sum of `value` and count where `tag === 'X'` across N records.
 *
 * Naive materialises N record arrays, then iterates.
 * Optimized yields records one-at-a-time.
 */
final class Scale_B05 implements ScaleBenchmark
{
    private int $n = 0;

    public function name(): string
    {
        return 'B05';
    }

    public function setup(int $n): void
    {
        $this->n = $n;
    }

    /**
     * @return Generator<int, array{id:int,value:int,tag:string}>
     */
    private function stream(): Generator
    {
        for ($i = 0; $i < $this->n; $i++) {
            yield [
                'id'    => $i,
                'value' => ($i * 2654435761) & 0x7FFFFFFF,
                'tag'   => ($i % 1000 === 17) ? 'X' : 'Y',
            ];
        }
    }

    /**
     * @return list<array{id:int,value:int,tag:string}>
     */
    private function materialiseAll(): array
    {
        $out = [];
        for ($i = 0; $i < $this->n; $i++) {
            $out[] = [
                'id'    => $i,
                'value' => ($i * 2654435761) & 0x7FFFFFFF,
                'tag'   => ($i % 1000 === 17) ? 'X' : 'Y',
            ];
        }
        return $out;
    }

    public function run(string $path): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $sum = 0;
        $cnt = 0;
        $t0  = hrtime(true);

        if ($path === 'naive') {
            $records = $this->materialiseAll();
            foreach ($records as $r) {
                $sum += $r['value'];
                if ($r['tag'] === 'X') {
                    $cnt++;
                }
            }
        } else {
            foreach ($this->stream() as $r) {
                $sum += $r['value'];
                if ($r['tag'] === 'X') {
                    $cnt++;
                }
            }
        }

        $wallNs = hrtime(true) - $t0;
        $peak   = memory_get_peak_usage(true) - $baseline;

        return [
            'n'                 => $this->n,
            'path'              => $path,
            'wall_ns'           => $wallNs,
            'peak_memory_bytes' => $peak,
            'sum_sample'        => $sum % PHP_INT_MAX,
            'x_count'           => $cnt,
        ];
    }

    public function teardown(): void
    {
        gc_collect_cycles();
    }

    public function scales(): array
    {
        return [
            ['1M',   1_000_000],
            ['5M',   5_000_000],
            ['25M',  25_000_000],
            ['100M', 100_000_000],
            ['500M', 500_000_000],
        ];
    }

    public function smokeScales(): array
    {
        return [
            ['1M', 1_000_000],
            ['5M', 5_000_000],
        ];
    }

    public function headlineMetric(): string
    {
        return 'peak_memory_bytes';
    }
}
