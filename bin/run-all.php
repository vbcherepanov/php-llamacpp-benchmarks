<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpLlamaBench\Bench\B01_Mmap;
use PhpLlamaBench\Bench\B02_SplFixedArray;
use PhpLlamaBench\Bench\B03_ObjectPool;
use PhpLlamaBench\Bench\B04_LookupTable;
use PhpLlamaBench\Bench\B05_Generator;
use PhpLlamaBench\Bench\B06_ColumnOriented;
use PhpLlamaBench\ReportWriter;
use PhpLlamaBench\Runner;
use PhpLlamaBench\Stats;

// Sanity-check the runtime so a misconfigured PHP doesn't silently produce
// meaningless numbers. Note Zend OPcache registers under 'Zend OPcache'
// while regular extensions use lowercase names.
$missing = [];
if (!extension_loaded('ffi')) {
    $missing[] = 'ffi';
}
if (!extension_loaded('Zend OPcache') && !function_exists('opcache_get_status')) {
    $missing[] = 'opcache';
}
if ($missing !== []) {
    fwrite(STDERR, 'ERROR: required extensions missing: ' . implode(', ', $missing) . "\n");
    exit(1);
}

$jit = false;
if (function_exists('opcache_get_status')) {
    $s = @opcache_get_status(false);
    if (is_array($s) && isset($s['jit']) && is_array($s['jit'])) {
        $jit = (bool) ($s['jit']['enabled'] ?? false);
    }
}
fprintf(
    STDERR,
    "PHP %s  ffi=%s  pdo_pgsql=%s  opcache=%s  jit=%s\n",
    PHP_VERSION,
    extension_loaded('ffi') ? 'on' : 'off',
    extension_loaded('pdo_pgsql') ? 'on' : 'off',
    function_exists('opcache_get_status') ? 'on' : 'off',
    $jit ? 'on' : 'OFF (numbers will not be representative)',
);

$runner = new Runner();
/** @var list<\PhpLlamaBench\Contract\Benchmark> $suite */
$suite = [
    new B02_SplFixedArray(),
    new B05_Generator(),
    new B06_ColumnOriented(),
    new B03_ObjectPool(),
    new B04_LookupTable(),
    new B01_Mmap(),
];

$results = [];
foreach ($suite as $b) {
    fwrite(STDERR, "→ " . $b->name() . " ...\n");
    $t0 = hrtime(true);
    $r  = $runner->run($b);
    $dt = hrtime(true) - $t0;
    $results[] = $r;
    fwrite(STDERR, sprintf(
        "  done in %s — naive p50 %s, optimized p50 %s (%s)\n",
        Stats::formatNs($dt),
        Stats::formatNs((float) $r['naive']['p50_ns']),
        Stats::formatNs((float) $r['optimized']['p50_ns']),
        Stats::formatRatio((float) $r['naive']['p50_ns'], (float) $r['optimized']['p50_ns']),
    ));
}

(new ReportWriter())->write($results, null, __DIR__ . '/../results');

fwrite(STDERR, "\nReport written to results/results.md (+ results.json).\n");
