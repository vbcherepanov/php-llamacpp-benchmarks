<?php

declare(strict_types=1);

/**
 * Child process entry point for the scaling sweeps.
 *
 *   scale-worker.php <short_class_name> <n> <path>
 *
 * Loads the matching ScaleBenchmark, runs setup(n) + run(path) + teardown(),
 * and emits a single JSON object on stdout. On fatal error (including memory
 * exhaustion or kernel OOM) we want a recognisable exit code, so we register
 * a shutdown handler that maps E_ERROR / out-of-memory to exit 137.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PhpLlamaBench\Scaling\ScaleBenchmark;

ini_set('display_errors', 'stderr');
error_reporting(E_ALL);

register_shutdown_function(static function (): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    if ($err['type'] === E_ERROR) {
        $msg = $err['message'];
        fwrite(STDERR, "FATAL: $msg\n");
        // Allowed-memory and OOM-style fatals get the SIGKILL exit convention
        // so the parent classifies them as OOM, not generic CRASH.
        if (stripos($msg, 'Allowed memory size') !== false
            || stripos($msg, 'out of memory') !== false) {
            exit(137);
        }
        exit(1);
    }
});

if ($argc < 4) {
    fwrite(STDERR, "usage: scale-worker.php <class> <n> <path>\n");
    exit(2);
}

$shortClass = (string) $argv[1];
$n          = (int) $argv[2];
$path       = (string) $argv[3];

if ($path !== 'naive' && $path !== 'optimized') {
    fwrite(STDERR, "unknown path: $path\n");
    exit(2);
}

$fqcn = 'PhpLlamaBench\\Scaling\\' . $shortClass;
if (!class_exists($fqcn)) {
    fwrite(STDERR, "unknown class: $fqcn\n");
    exit(2);
}

$inst = new $fqcn();
if (!$inst instanceof ScaleBenchmark) {
    fwrite(STDERR, "$fqcn is not a ScaleBenchmark\n");
    exit(2);
}

try {
    $inst->setup($n);
    $metrics = $inst->run($path);
    $inst->teardown();
} catch (\Throwable $e) {
    fwrite(STDERR, 'EXCEPTION: ' . $e::class . ': ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}

$envelope = [
    'class'   => $shortClass,
    'n'       => $n,
    'path'    => $path,
    'metrics' => $metrics,
];

$json = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
fwrite(STDOUT, $json . "\n");
exit(0);
