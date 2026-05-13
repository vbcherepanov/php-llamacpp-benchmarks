<?php

declare(strict_types=1);

namespace PhpLlamaBench\Contract;

interface Benchmark
{
    public function name(): string;

    public function description(): string;

    public function setup(): void;

    public function naive(): mixed;

    public function optimized(): mixed;

    public function teardown(): void;

    public function iterations(): int;

    public function warmupIterations(): int;
}
