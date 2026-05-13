<?php

declare(strict_types=1);

namespace PhpLlamaBench\Bench;

use PhpLlamaBench\Contract\Benchmark;
use PhpLlamaBench\Contract\HasExtraReport;
use PhpLlamaBench\Stats;
use SplFixedArray;
use stdClass;

/**
 * B06: column-oriented vs row-oriented storage.
 *
 * Hypothesis: for analytical scans of a single field, splitting the table into
 * parallel per-field arrays beats an array-of-structs (row-oriented) thanks to
 * better cache behaviour.
 *
 * llama.cpp parallel: ggml stores tensor data as a single contiguous buffer
 * per tensor — the column. Each matmul streams a column, not a row-of-structs.
 */
final class B06_ColumnOriented implements Benchmark, HasExtraReport
{
    private const N = 5_000_000;

    /** @var SplFixedArray<stdClass> */
    private SplFixedArray $rowOriented;
    private SplFixedArray $colF1;
    private SplFixedArray $colF2;
    private SplFixedArray $colF3;
    private SplFixedArray $colF4;
    private SplFixedArray $colF5;

    private int $rowMemoryBytes = 0;
    private int $colMemoryBytes = 0;
    private int $rowFullScanNs = 0;
    private int $colFullScanNs = 0;

    public function name(): string
    {
        return 'B06_ColumnOriented';
    }

    public function description(): string
    {
        return 'Array-of-stdClass vs parallel SplFixedArray columns, single-column scan';
    }

    public function iterations(): int
    {
        return 7;
    }

    public function warmupIterations(): int
    {
        return 2;
    }

    public function setup(): void
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        // Row-oriented: SplFixedArray of stdClass instances. We use SFA for the
        // outer container so the *only* difference vs the column path is the
        // record layout, not the container type.
        /** @var SplFixedArray<stdClass> $rows */
        $rows = new SplFixedArray(self::N);
        for ($i = 0; $i < self::N; $i++) {
            $o = new stdClass();
            $o->f1 = $i;
            $o->f2 = $i + 1;
            $o->f3 = $i + 2;
            $o->f4 = $i + 3;
            $o->f5 = $i + 4;
            $rows[$i] = $o;
        }
        $this->rowOriented    = $rows;
        $this->rowMemoryBytes = memory_get_peak_usage(true) - $baseline;

        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage(true);

        $f1 = new SplFixedArray(self::N);
        $f2 = new SplFixedArray(self::N);
        $f3 = new SplFixedArray(self::N);
        $f4 = new SplFixedArray(self::N);
        $f5 = new SplFixedArray(self::N);
        for ($i = 0; $i < self::N; $i++) {
            $f1[$i] = $i;
            $f2[$i] = $i + 1;
            $f3[$i] = $i + 2;
            $f4[$i] = $i + 3;
            $f5[$i] = $i + 4;
        }
        $this->colF1 = $f1;
        $this->colF2 = $f2;
        $this->colF3 = $f3;
        $this->colF4 = $f4;
        $this->colF5 = $f5;
        $this->colMemoryBytes = memory_get_peak_usage(true) - $baseline;

        // Full-row scan baseline for both layouts (sum of all 5 fields).
        $t0 = hrtime(true);
        $s  = 0;
        foreach ($this->rowOriented as $r) {
            /** @phpstan-ignore-next-line */
            $s += $r->f1 + $r->f2 + $r->f3 + $r->f4 + $r->f5;
        }
        $this->rowFullScanNs = hrtime(true) - $t0;
        if ($s < 0) {
            throw new \RuntimeException('row scan vanished');
        }

        $t0 = hrtime(true);
        $s  = 0;
        for ($i = 0; $i < self::N; $i++) {
            $s += $f1[$i] + $f2[$i] + $f3[$i] + $f4[$i] + $f5[$i];
        }
        $this->colFullScanNs = hrtime(true) - $t0;
        if ($s < 0) {
            throw new \RuntimeException('col scan vanished');
        }
    }

    public function naive(): int
    {
        $sum = 0;
        foreach ($this->rowOriented as $r) {
            /** @phpstan-ignore-next-line */
            $sum += $r->f3;
        }
        return $sum;
    }

    public function optimized(): int
    {
        $sum = 0;
        $col = $this->colF3;
        foreach ($col as $v) {
            $sum += $v;
        }
        return $sum;
    }

    public function teardown(): void
    {
        unset(
            $this->rowOriented,
            $this->colF1, $this->colF2, $this->colF3, $this->colF4, $this->colF5,
        );
        gc_collect_cycles();
    }

    public function hypothesis(): string
    {
        return 'Per-column SplFixedArray scans the relevant field faster and uses less memory.';
    }

    public function extraReport(): array
    {
        return [
            [
                'metric'    => 'Memory (5M × 5 fields)',
                'naive'     => Stats::formatBytes($this->rowMemoryBytes),
                'optimized' => Stats::formatBytes($this->colMemoryBytes),
                'ratio'     => Stats::formatRatio((float) $this->rowMemoryBytes, (float) $this->colMemoryBytes),
            ],
            [
                'metric'    => 'Full-row scan (sum f1..f5)',
                'naive'     => Stats::formatNs($this->rowFullScanNs),
                'optimized' => Stats::formatNs($this->colFullScanNs),
                'ratio'     => Stats::formatRatio((float) $this->rowFullScanNs, (float) $this->colFullScanNs),
            ],
        ];
    }

    public function verdict(): string
    {
        return 'Columnar wins on analytical (single-column) workloads and uses far less '
            . 'memory because it avoids one PHP object per row. Row-oriented stays '
            . 'preferable when every query touches most fields, when rows are passed '
            . 'around between layers as DTOs, or when the working set is tiny.';
    }
}
