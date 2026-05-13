<?php

declare(strict_types=1);

namespace PhpLlamaBench\Bench;

use PhpLlamaBench\Contract\Benchmark;
use PhpLlamaBench\Contract\HasExtraReport;
use PhpLlamaBench\Stats;

final class Point3D
{
    public function __construct(
        public float $x = 0.0,
        public float $y = 0.0,
        public float $z = 0.0,
    ) {}
}

/**
 * B03: Object pool vs `new` in a hot loop.
 *
 * Hypothesis: pooling short-lived value objects cuts GC pressure and total time.
 *
 * llama.cpp parallel: per-token state objects are reused across the decode loop
 * rather than re-allocated. The pool is a tiny one-line idea that PHP's
 * generational allocator usually handles well — the question is *how* well.
 */
final class B03_ObjectPool implements Benchmark, HasExtraReport
{
    private const N_POINTS    = 1_000_000;
    private const POOL_SIZE   = 8;
    private const STEPS_PER_PT = 5;

    /** @var list<Point3D> */
    private array $pool;
    private int $naiveGcRunsDelta = 0;
    private int $optGcRunsDelta   = 0;

    public function name(): string
    {
        return 'B03_ObjectPool';
    }

    public function description(): string
    {
        return '~5M Point3D allocations in a hot loop, with and without an object pool';
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
        $this->pool = [];
        for ($i = 0; $i < self::POOL_SIZE; $i++) {
            $this->pool[] = new Point3D();
        }

        // Capture GC delta per path on a single representative run.
        $before = gc_status()['runs'];
        $this->naive();
        $this->naiveGcRunsDelta = gc_status()['runs'] - $before;

        $before = gc_status()['runs'];
        $this->optimized();
        $this->optGcRunsDelta = gc_status()['runs'] - $before;
    }

    /**
     * Naive: `new Point3D(...)` for every intermediate.
     */
    public function naive(): float
    {
        $total = 0.0;
        for ($i = 0; $i < self::N_POINTS; $i++) {
            $p1 = new Point3D((float) $i, (float) ($i + 1), (float) ($i + 2));
            // rotation step
            $p2 = new Point3D($p1->y, -$p1->x, $p1->z);
            // translation
            $p3 = new Point3D($p2->x + 1.0, $p2->y + 2.0, $p2->z + 3.0);
            // scaling
            $p4 = new Point3D($p3->x * 0.5, $p3->y * 0.5, $p3->z * 0.5);
            // final blend
            $p5 = new Point3D($p4->x + $p4->y, $p4->y + $p4->z, $p4->z + $p4->x);
            $total += $p5->x + $p5->y + $p5->z;
        }
        return $total;
    }

    /**
     * Optimized: a pool of 5 reused Point3D instances per iteration.
     */
    public function optimized(): float
    {
        $total = 0.0;
        $pool  = $this->pool;
        for ($i = 0; $i < self::N_POINTS; $i++) {
            $p1 = $pool[0];
            $p1->x = (float) $i;
            $p1->y = (float) ($i + 1);
            $p1->z = (float) ($i + 2);

            $p2 = $pool[1];
            $p2->x = $p1->y;
            $p2->y = -$p1->x;
            $p2->z = $p1->z;

            $p3 = $pool[2];
            $p3->x = $p2->x + 1.0;
            $p3->y = $p2->y + 2.0;
            $p3->z = $p2->z + 3.0;

            $p4 = $pool[3];
            $p4->x = $p3->x * 0.5;
            $p4->y = $p3->y * 0.5;
            $p4->z = $p3->z * 0.5;

            $p5 = $pool[4];
            $p5->x = $p4->x + $p4->y;
            $p5->y = $p4->y + $p4->z;
            $p5->z = $p4->z + $p4->x;

            $total += $p5->x + $p5->y + $p5->z;
        }
        return $total;
    }

    public function teardown(): void
    {
        unset($this->pool);
        gc_collect_cycles();
    }

    public function hypothesis(): string
    {
        return 'A pool of 5 Point3D instances cuts GC runs to ~0 and improves wall time.';
    }

    public function extraReport(): array
    {
        return [
            [
                'metric'    => 'GC cycle runs (per iteration)',
                'naive'     => (string) $this->naiveGcRunsDelta,
                'optimized' => (string) $this->optGcRunsDelta,
                'ratio'     => $this->naiveGcRunsDelta === 0 && $this->optGcRunsDelta === 0
                    ? 'no cycles either way'
                    : Stats::formatRatio((float) $this->naiveGcRunsDelta, (float) max(1, $this->optGcRunsDelta)),
            ],
            [
                'metric'    => 'Allocation count (5 per outer iter)',
                'naive'     => number_format(self::N_POINTS * self::STEPS_PER_PT) . ' new Point3D',
                'optimized' => '0 new Point3D (pool reused)',
                'ratio'     => '∞',
            ],
        ];
    }

    public function verdict(): string
    {
        return 'Pooling 5 reusable Point3D instances avoids 5M allocations and '
            . 'shaves wall-time meaningfully even in a CLI benchmark. PHP\'s '
            . 'GC does not run cycles for acyclic short-lived objects, so '
            . 'gc_status() looks identical — the saved time comes from the '
            . 'allocator path, not from sweeping. The pattern matters most in '
            . 'long-running workers (queues, websockets, daemons) where '
            . 'allocations otherwise compound into tail-latency spikes.';
    }
}
