<?php

declare(strict_types=1);

namespace PhpLlamaBench\Scaling;

interface ScaleBenchmark
{
    public function name(): string;

    /**
     * Build / load fixtures for scale n. Runs inside the *child* process.
     */
    public function setup(int $n): void;

    /**
     * @param "naive"|"optimized" $path
     * @return array<string, mixed>
     */
    public function run(string $path): array;

    public function teardown(): void;

    /**
     * Ordered list of [label, n] pairs the orchestrator should sweep.
     *
     * @return list<array{0:string, 1:int}>
     */
    public function scales(): array;

    /**
     * Smoke subset (used when the orchestrator is invoked with --smoke).
     *
     * @return list<array{0:string, 1:int}>
     */
    public function smokeScales(): array;

    /**
     * Name of the headline extra metric that goes into the CSV's last column.
     * Should be present in the run() metrics array.
     */
    public function headlineMetric(): string;
}
