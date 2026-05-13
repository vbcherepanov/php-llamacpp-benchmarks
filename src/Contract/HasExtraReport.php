<?php

declare(strict_types=1);

namespace PhpLlamaBench\Contract;

/**
 * Optional contract for benchmarks that want to publish sub-measurements
 * (load time, cross-process time, throughput-of-three-variants, etc.)
 * in addition to the standard per-iteration timing the Runner captures.
 */
interface HasExtraReport
{
    /**
     * Each entry is a row in the per-benchmark Markdown table.
     *
     * @return list<array{
     *     metric: string,
     *     naive: string,
     *     optimized: string,
     *     ratio: string,
     *     raw?: array<string, int|float|string>
     * }>
     */
    public function extraReport(): array;

    public function verdict(): string;

    public function hypothesis(): string;
}
