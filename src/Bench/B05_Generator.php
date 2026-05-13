<?php

declare(strict_types=1);

namespace PhpLlamaBench\Bench;

use Generator;
use PhpLlamaBench\Contract\Benchmark;
use PhpLlamaBench\Contract\HasExtraReport;
use PhpLlamaBench\Stats;

/**
 * B05: Generator vs full materialisation.
 *
 * Hypothesis: generators drop peak memory by orders of magnitude with negligible
 * throughput penalty when the stream is consumed once.
 *
 * llama.cpp parallel: token streaming. The model emits one token at a time
 * rather than building a full N-token buffer first.
 */
final class B05_Generator implements Benchmark, HasExtraReport
{
    private const N = 5_000_000;

    private int $materializeMemoryBytes  = 0;
    private int $generatorMemoryBytes    = 0;
    private int $materializeBuildNs      = 0;
    private int $generatorFirstResultNs  = 0;
    private int $materializeFirstResultNs = 0;

    public function name(): string
    {
        return 'B05_Generator';
    }

    public function description(): string
    {
        return 'Materialised array vs generator on a 5M-record stream';
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
        // Measure peak memory for full materialisation once.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $t0 = hrtime(true);
        $records = $this->materializeAll();
        $this->materializeBuildNs    = hrtime(true) - $t0;
        $this->materializeMemoryBytes = memory_get_peak_usage(true) - $baseline;

        // Time-to-first-result: scan up to first 'X'-tagged record.
        $t0 = hrtime(true);
        foreach ($records as $r) {
            if ($r['tag'] === 'X') {
                break;
            }
        }
        $this->materializeFirstResultNs = hrtime(true) - $t0;
        unset($records);

        // Peak memory + time-to-first-result for generator path.
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $t0  = hrtime(true);
        $gen = $this->streamRecords();
        foreach ($gen as $r) {
            if ($r['tag'] === 'X') {
                break;
            }
        }
        $this->generatorFirstResultNs = hrtime(true) - $t0;
        $this->generatorMemoryBytes   = memory_get_peak_usage(true) - $baseline;
        unset($gen);
    }

    /**
     * @return list<array{id: int, value: int, tag: string}>
     */
    private function materializeAll(): array
    {
        $out = [];
        for ($i = 0; $i < self::N; $i++) {
            $out[] = [
                'id'    => $i,
                'value' => ($i * 2654435761) & 0x7FFFFFFF,
                'tag'   => ($i % 1000 === 17) ? 'X' : 'Y',
            ];
        }
        return $out;
    }

    /**
     * @return Generator<int, array{id: int, value: int, tag: string}>
     */
    private function streamRecords(): Generator
    {
        for ($i = 0; $i < self::N; $i++) {
            yield [
                'id'    => $i,
                'value' => ($i * 2654435761) & 0x7FFFFFFF,
                'tag'   => ($i % 1000 === 17) ? 'X' : 'Y',
            ];
        }
    }

    /**
     * Naive path: build full array, then iterate.
     *
     * @return array{sum: int, x_count: int}
     */
    public function naive(): array
    {
        $sum = 0;
        $cnt = 0;
        $records = $this->materializeAll();
        foreach ($records as $r) {
            $sum += $r['value'];
            if ($r['tag'] === 'X') {
                $cnt++;
            }
        }
        return ['sum' => $sum, 'x_count' => $cnt];
    }

    /**
     * Optimized path: generator, one record in scope at a time.
     *
     * @return array{sum: int, x_count: int}
     */
    public function optimized(): array
    {
        $sum = 0;
        $cnt = 0;
        foreach ($this->streamRecords() as $r) {
            $sum += $r['value'];
            if ($r['tag'] === 'X') {
                $cnt++;
            }
        }
        return ['sum' => $sum, 'x_count' => $cnt];
    }

    public function teardown(): void
    {
        gc_collect_cycles();
    }

    public function hypothesis(): string
    {
        return 'Generator brings peak memory close to O(1) with a small throughput penalty.';
    }

    public function extraReport(): array
    {
        return [
            [
                'metric'    => 'Peak memory (full pipeline)',
                'naive'     => Stats::formatBytes($this->materializeMemoryBytes),
                'optimized' => Stats::formatBytes($this->generatorMemoryBytes),
                'ratio'     => $this->generatorMemoryBytes <= 0
                    ? 'generator below allocator granularity'
                    : Stats::formatRatio(
                        (float) $this->materializeMemoryBytes,
                        (float) $this->generatorMemoryBytes,
                    ),
            ],
            [
                'metric'    => 'Time-to-first-result',
                'naive'     => Stats::formatNs($this->materializeFirstResultNs),
                'optimized' => Stats::formatNs($this->generatorFirstResultNs),
                'ratio'     => Stats::formatRatio((float) $this->materializeFirstResultNs, (float) $this->generatorFirstResultNs),
            ],
            [
                'metric'    => 'Setup-time materialisation cost',
                'naive'     => Stats::formatNs($this->materializeBuildNs),
                'optimized' => 'n/a (lazy)',
                'ratio'     => '—',
            ],
        ];
    }

    public function verdict(): string
    {
        return 'Generators are the default for any single-pass stream you do not need '
            . 'to revisit. The memory win is enormous; throughput is usually within '
            . '5-15% of a materialised array. Materialise only when you need random '
            . 'access, multiple passes, or count() before processing.';
    }
}
