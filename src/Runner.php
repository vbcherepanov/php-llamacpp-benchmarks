<?php

declare(strict_types=1);

namespace PhpLlamaBench;

use PhpLlamaBench\Contract\Benchmark;
use PhpLlamaBench\Contract\HasExtraReport;

final class Runner
{
    public function __construct(
        private readonly int $defaultWarmup = 100,
        private readonly int $defaultIterations = 1000,
    ) {}

    /**
     * @return array{
     *     name: string,
     *     description: string,
     *     iterations: int,
     *     warmup: int,
     *     hypothesis: string,
     *     verdict: string,
     *     naive: array<string, mixed>,
     *     optimized: array<string, mixed>,
     *     extra: list<array<string, mixed>>
     * }
     */
    public function run(Benchmark $b): array
    {
        $b->setup();

        $warmup = $b->warmupIterations() > 0 ? $b->warmupIterations() : $this->defaultWarmup;
        $iters  = $b->iterations() > 0 ? $b->iterations() : $this->defaultIterations;

        $naive     = $this->runVariant($b, 'naive', $warmup, $iters);
        $optimized = $this->runVariant($b, 'optimized', $warmup, $iters);

        $hypothesis = $b instanceof HasExtraReport ? $b->hypothesis() : '';
        $extra      = $b instanceof HasExtraReport ? $b->extraReport() : [];
        $verdict    = $b instanceof HasExtraReport ? $b->verdict() : '';

        $b->teardown();

        return [
            'name'        => $b->name(),
            'description' => $b->description(),
            'iterations'  => $iters,
            'warmup'      => $warmup,
            'hypothesis'  => $hypothesis,
            'verdict'     => $verdict,
            'naive'       => $naive,
            'optimized'   => $optimized,
            'extra'       => $extra,
        ];
    }

    /**
     * @return array{
     *     samples_ns: list<int>,
     *     min_ns: int,
     *     p50_ns: float,
     *     p95_ns: float,
     *     p99_ns: float,
     *     max_ns: int,
     *     mean_ns: float,
     *     stddev_ns: float,
     *     peak_memory_bytes: int,
     *     sample_result: mixed
     * }
     */
    private function runVariant(Benchmark $b, string $variant, int $warmup, int $iters): array
    {
        gc_collect_cycles();
        memory_reset_peak_usage();

        for ($i = 0; $i < $warmup; $i++) {
            $this->call($b, $variant);
        }

        gc_collect_cycles();
        memory_reset_peak_usage();

        /** @var list<int> $samples */
        $samples = [];
        $sample  = null;
        for ($i = 0; $i < $iters; $i++) {
            $start = hrtime(true);
            $r = $this->call($b, $variant);
            $end = hrtime(true);
            $samples[] = $end - $start;
            if ($i === 0) {
                $sample = $r;
            }
        }

        $peak = memory_get_peak_usage(true);
        sort($samples);

        return [
            'samples_ns'        => $samples,
            'min_ns'            => $samples[0],
            'p50_ns'            => Stats::percentile($samples, 50),
            'p95_ns'            => Stats::percentile($samples, 95),
            'p99_ns'            => Stats::percentile($samples, 99),
            'max_ns'            => $samples[count($samples) - 1],
            'mean_ns'           => Stats::mean($samples),
            'stddev_ns'         => Stats::stddev($samples),
            'peak_memory_bytes' => $peak,
            'sample_result'     => $sample,
        ];
    }

    private function call(Benchmark $b, string $variant): mixed
    {
        return match ($variant) {
            'naive'     => $b->naive(),
            'optimized' => $b->optimized(),
            default     => throw new \InvalidArgumentException('unknown variant: ' . $variant),
        };
    }
}
