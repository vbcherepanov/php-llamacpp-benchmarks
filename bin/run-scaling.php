<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpLlamaBench\Scaling\Scale_B01;
use PhpLlamaBench\Scaling\Scale_B02;
use PhpLlamaBench\Scaling\Scale_B05;
use PhpLlamaBench\Scaling\Scale_B06;
use PhpLlamaBench\Scaling\ScaleBenchmark;
use PhpLlamaBench\Scaling\SubprocessRunner;
use PhpLlamaBench\Stats;

/**
 * Orchestrator for the scaling sweeps. Spawns a fresh PHP subprocess per
 * (benchmark, scale, path) so kernel OOMs and segfaults can't bring us down.
 *
 *   php bin/run-scaling.php           # full sweep
 *   php bin/run-scaling.php --smoke   # smoke-test subset only
 *   php bin/run-scaling.php --only=B01,B02
 */

$smoke = false;
/** @var list<string>|null $only */
$only  = null;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--smoke') {
        $smoke = true;
        continue;
    }
    if (str_starts_with($arg, '--only=')) {
        $only = array_map('trim', explode(',', substr($arg, 7)));
        continue;
    }
    fwrite(STDERR, "unknown arg: $arg\n");
    exit(2);
}

/** @var list<ScaleBenchmark> $suite */
$suite = [
    new Scale_B01(),
    new Scale_B02(),
    new Scale_B05(),
    new Scale_B06(),
];
if ($only !== null) {
    $filter = $only;
    $suite = array_values(array_filter($suite, static fn(ScaleBenchmark $b) => in_array($b->name(), $filter, true)));
}

$runner   = new SubprocessRunner();
$resultsD = __DIR__ . '/../results';
$chartsD  = $resultsD . '/charts';
if (!is_dir($resultsD)) {
    mkdir($resultsD, 0o755, true);
}
if (!is_dir($chartsD)) {
    mkdir($chartsD, 0o755, true);
}

$csvPath = $resultsD . '/scaling.csv';
$mdPath  = $resultsD . '/scaling.md';

$csv = fopen($csvPath, 'wb');
if ($csv === false) {
    fwrite(STDERR, "cannot open $csvPath\n");
    exit(1);
}
fputcsv($csv, [
    'benchmark', 'scale_label', 'n', 'path', 'status',
    'wall_time_ns', 'peak_memory_bytes', 'load_time_ns',
    'extra_metric_name', 'extra_metric_value',
    'subprocess_elapsed_ns', 'exit_code', 'stderr_excerpt',
], ',', '"', '');

/** @var array<string, list<array<string, mixed>>> $byBench */
$byBench = [];

$dataScaleDir = __DIR__ . '/../data/scale';
$keepFixtureMax = 10_000_000; // keep ≤10M tiers, delete larger after the tier completes

foreach ($suite as $bench) {
    $name   = $bench->name();
    $scales = $smoke ? $bench->smokeScales() : $bench->scales();
    fwrite(STDOUT, "\n=== $name ===\n");

    foreach ($scales as [$label, $n]) {
        foreach (['naive', 'optimized'] as $path) {
            fwrite(STDOUT, sprintf("  %-5s %-9s ... ", $label, $path));
            $t0  = hrtime(true);
            $res = $runner->run('Scale_' . $name, $label, $n, $path);
            $dt  = hrtime(true) - $t0;

            $metrics = $res['metrics'] ?? [];
            $headlineName  = $bench->headlineMetric();
            $headlineValue = isset($metrics[$headlineName]) && is_scalar($metrics[$headlineName])
                ? (float) $metrics[$headlineName]
                : null;

            $status = (string) $res['status'];
            $row = [
                'benchmark'             => $name,
                'scale_label'           => $label,
                'n'                     => $n,
                'path'                  => $path,
                'status'                => $status,
                'wall_time_ns'          => isset($metrics['wall_ns']) && is_numeric($metrics['wall_ns'])
                    ? (int) $metrics['wall_ns'] : null,
                'peak_memory_bytes'     => isset($metrics['peak_memory_bytes']) && is_numeric($metrics['peak_memory_bytes'])
                    ? (int) $metrics['peak_memory_bytes'] : null,
                'load_time_ns'          => isset($metrics['load_time_ns']) && is_numeric($metrics['load_time_ns'])
                    ? (int) $metrics['load_time_ns'] : null,
                'extra_metric_name'     => $headlineName,
                'extra_metric_value'    => $headlineValue,
                'subprocess_elapsed_ns' => (int) $res['elapsed_ns'],
                'exit_code'             => (int) $res['exit_code'],
                'stderr_excerpt'        => (string) ($res['stderr'] ?? ''),
                'metrics'               => $metrics,
            ];
            $byBench[$name][] = $row;

            fputcsv($csv, [
                $row['benchmark'],
                $row['scale_label'],
                $row['n'],
                $row['path'],
                $row['status'],
                $row['wall_time_ns'] ?? '',
                $row['peak_memory_bytes'] ?? '',
                $row['load_time_ns'] ?? '',
                $row['extra_metric_name'],
                $row['extra_metric_value'] ?? '',
                $row['subprocess_elapsed_ns'],
                $row['exit_code'],
                $row['stderr_excerpt'],
            ], ',', '"', '');

            $summary = summariseRow($row, $bench);
            fwrite(STDOUT, sprintf(" %s   [%s, %s]\n", $status, $summary, Stats::formatNs($dt)));
        }

        // Disk cleanup for B01: drop large per-tier fixtures right after both
        // paths ran. Keep small tiers (≤10M) for sanity re-runs.
        if ($name === 'B01' && $n > $keepFixtureMax) {
            foreach ([
                $dataScaleDir . '/lookup_' . $n . '.bin',
                $dataScaleDir . '/lookup_' . $n . '.json',
            ] as $stale) {
                if (is_file($stale)) {
                    @unlink($stale);
                }
            }
        }
    }
}

fclose($csv);

writeScalingMd($mdPath, $byBench);

fwrite(STDOUT, "\nCSV : $csvPath\nMD  : $mdPath\n");

// Try to invoke the python plotter — fail soft, never block the report.
$pythonOk = invokePlotter(__DIR__ . '/plot-scaling.py', $csvPath, $chartsD);
if ($pythonOk) {
    fwrite(STDOUT, "Charts: $chartsD/scaling-*.png\n");
} else {
    fwrite(STDOUT, "Charts: skipped (matplotlib unavailable; see message above)\n");
}

/**
 * @param array<string, mixed> $row
 */
function summariseRow(array $row, ScaleBenchmark $b): string
{
    $status = (string) $row['status'];
    if ($status !== 'ok') {
        return $status;
    }
    /** @var array<string, mixed> $m */
    $m = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];

    return match ($b->name()) {
        'B01' => sprintf(
            'load=%s heap=%s',
            Stats::formatNs((float) ($m['load_time_ns'] ?? 0)),
            Stats::formatBytes((int) ($m['php_heap_after_load_bytes'] ?? 0)),
        ),
        'B02' => sprintf(
            'peak=%s iter=%s',
            Stats::formatBytes((int) ($m['peak_memory_bytes'] ?? 0)),
            Stats::formatNs((float) ($m['iterate_ns'] ?? 0)),
        ),
        'B05' => sprintf(
            'peak=%s wall=%s',
            Stats::formatBytes((int) ($m['peak_memory_bytes'] ?? 0)),
            Stats::formatNs((float) ($m['wall_ns'] ?? 0)),
        ),
        'B06' => sprintf(
            'ns/rec=%.1f scan=%s',
            (float) ($m['ns_per_record'] ?? 0),
            Stats::formatNs((float) ($m['scan_time_ns'] ?? 0)),
        ),
        default => 'ok',
    };
}

/**
 * @param array<string, list<array<string, mixed>>> $byBench
 */
function writeScalingMd(string $path, array $byBench): void
{
    $out  = "# Scaling Experiments\n\n";
    $out .= "Each row is one PHP subprocess: `php -d memory_limit=60G bin/scale-worker.php ...`.\n";
    $out .= "Hard timeout: 1200 s. Statuses: `ok`, `OOM` (PHP fatal or kernel SIGKILL),\n";
    $out .= "`TIMEOUT` (we sent SIGTERM/SIGKILL after 1200 s), `CRASH` (non-zero exit, other).\n\n";

    foreach ($byBench as $name => $rows) {
        $out .= renderBenchmarkSection($name, $rows);
    }

    file_put_contents($path, $out);
}

/**
 * @param list<array<string, mixed>> $rows
 */
function renderBenchmarkSection(string $name, array $rows): string
{
    $out = "## $name\n\n";

    /** @var array<string, array{naive: array<string, mixed>|null, optimized: array<string, mixed>|null}> $byScale */
    $byScale = [];
    foreach ($rows as $r) {
        $label = (string) $r['scale_label'];
        if (!isset($byScale[$label])) {
            $byScale[$label] = ['naive' => null, 'optimized' => null];
        }
        $path = (string) $r['path'];
        if ($path === 'naive') {
            $byScale[$label]['naive'] = $r;
        } elseif ($path === 'optimized') {
            $byScale[$label]['optimized'] = $r;
        }
    }

    switch ($name) {
        case 'B01':
            $out .= "| Scale | n | naive load | naive heap | naive p99 lookup | mmap load | mmap heap | mmap p99 lookup |\n";
            $out .= "|-------|---:|-----------:|-----------:|-----------------:|----------:|----------:|----------------:|\n";
            foreach ($byScale as $label => $pair) {
                $n = (int) ($pair['naive']['n'] ?? $pair['optimized']['n'] ?? 0);
                $out .= sprintf(
                    "| %s | %s | %s | %s | %s | %s | %s | %s |\n",
                    $label,
                    number_format($n),
                    cellNs($pair['naive'], 'load_time_ns'),
                    cellBytes($pair['naive'], 'php_heap_after_load_bytes'),
                    cellNs($pair['naive'], 'lookup_p99_ns'),
                    cellNs($pair['optimized'], 'load_time_ns'),
                    cellBytes($pair['optimized'], 'php_heap_after_load_bytes'),
                    cellNs($pair['optimized'], 'lookup_p99_ns'),
                );
            }
            break;

        case 'B02':
            $out .= "| Scale | n | naive peak | naive populate | naive iterate | SFA peak | SFA populate | SFA iterate |\n";
            $out .= "|-------|---:|----------:|---------------:|--------------:|---------:|-------------:|------------:|\n";
            foreach ($byScale as $label => $pair) {
                $n = (int) ($pair['naive']['n'] ?? $pair['optimized']['n'] ?? 0);
                $out .= sprintf(
                    "| %s | %s | %s | %s | %s | %s | %s | %s |\n",
                    $label,
                    number_format($n),
                    cellBytes($pair['naive'], 'peak_memory_bytes'),
                    cellNs($pair['naive'], 'populate_ns'),
                    cellNs($pair['naive'], 'iterate_ns'),
                    cellBytes($pair['optimized'], 'peak_memory_bytes'),
                    cellNs($pair['optimized'], 'populate_ns'),
                    cellNs($pair['optimized'], 'iterate_ns'),
                );
            }
            break;

        case 'B05':
            $out .= "| Scale | n | naive peak | naive wall | gen peak | gen wall |\n";
            $out .= "|-------|---:|----------:|-----------:|---------:|---------:|\n";
            foreach ($byScale as $label => $pair) {
                $n = (int) ($pair['naive']['n'] ?? $pair['optimized']['n'] ?? 0);
                $out .= sprintf(
                    "| %s | %s | %s | %s | %s | %s |\n",
                    $label,
                    number_format($n),
                    cellBytes($pair['naive'], 'peak_memory_bytes'),
                    cellNs($pair['naive'], 'wall_ns'),
                    cellBytes($pair['optimized'], 'peak_memory_bytes'),
                    cellNs($pair['optimized'], 'wall_ns'),
                );
            }
            break;

        case 'B06':
            $out .= "| Scale | n | row peak | row scan | row ns/rec | col peak | col scan | col ns/rec |\n";
            $out .= "|-------|---:|--------:|---------:|-----------:|---------:|---------:|-----------:|\n";
            foreach ($byScale as $label => $pair) {
                $n = (int) ($pair['naive']['n'] ?? $pair['optimized']['n'] ?? 0);
                $out .= sprintf(
                    "| %s | %s | %s | %s | %s | %s | %s | %s |\n",
                    $label,
                    number_format($n),
                    cellBytes($pair['naive'], 'peak_memory_bytes'),
                    cellNs($pair['naive'], 'scan_time_ns'),
                    cellFloat($pair['naive'], 'ns_per_record'),
                    cellBytes($pair['optimized'], 'peak_memory_bytes'),
                    cellNs($pair['optimized'], 'scan_time_ns'),
                    cellFloat($pair['optimized'], 'ns_per_record'),
                );
            }
            break;
    }

    $crossover = findCrossover($byScale);
    if ($crossover !== null) {
        $out .= "\n**Crossover point:** at $crossover.\n";
    } else {
        $out .= "\n**Crossover point:** none observed within the sweep — naive path completed every tier.\n";
    }

    $out .= "\n![scaling-$name](charts/scaling-$name.png)\n\n";
    return $out;
}

/**
 * @param array<string, array{naive: array<string, mixed>|null, optimized: array<string, mixed>|null}> $byScale
 */
function findCrossover(array $byScale): ?string
{
    foreach ($byScale as $label => $pair) {
        $naive = $pair['naive'];
        if ($naive === null) {
            continue;
        }
        $status = (string) ($naive['status'] ?? '');
        if (in_array($status, ['OOM', 'TIMEOUT', 'CRASH'], true)) {
            $optStatus = (string) ($pair['optimized']['status'] ?? '');
            $optWord   = $optStatus === 'ok' ? 'optimized path still completes' : "optimized path $optStatus";
            return sprintf('**%s** the naive path %s; %s', $label, $status, $optWord);
        }
        /** @var array<string, mixed> $nm */
        $nm = is_array($naive['metrics'] ?? null) ? $naive['metrics'] : [];
        if (($nm['status_reason'] ?? null) === 'SKIPPED_FIXTURE_TOO_LARGE') {
            return sprintf('**%s** fixture too large to materialise as JSON; only optimized path runs', $label);
        }
    }
    return null;
}

/**
 * @param array<string, mixed>|null $r
 */
function cellNs(?array $r, string $key): string
{
    if ($r === null) {
        return '—';
    }
    $status = (string) ($r['status'] ?? '');
    if ($status !== 'ok') {
        return '**' . $status . '**';
    }
    /** @var array<string, mixed> $m */
    $m = is_array($r['metrics'] ?? null) ? $r['metrics'] : [];
    if (isset($m['status_reason'])) {
        return '**SKIPPED**';
    }
    if (!isset($m[$key]) || !is_numeric($m[$key])) {
        return '—';
    }
    return Stats::formatNs((float) $m[$key]);
}

/**
 * @param array<string, mixed>|null $r
 */
function cellBytes(?array $r, string $key): string
{
    if ($r === null) {
        return '—';
    }
    $status = (string) ($r['status'] ?? '');
    if ($status !== 'ok') {
        return '**' . $status . '**';
    }
    /** @var array<string, mixed> $m */
    $m = is_array($r['metrics'] ?? null) ? $r['metrics'] : [];
    if (isset($m['status_reason'])) {
        return '**SKIPPED**';
    }
    if (!isset($m[$key]) || !is_numeric($m[$key])) {
        return '—';
    }
    return Stats::formatBytes((int) $m[$key]);
}

/**
 * @param array<string, mixed>|null $r
 */
function cellFloat(?array $r, string $key): string
{
    if ($r === null) {
        return '—';
    }
    $status = (string) ($r['status'] ?? '');
    if ($status !== 'ok') {
        return '**' . $status . '**';
    }
    /** @var array<string, mixed> $m */
    $m = is_array($r['metrics'] ?? null) ? $r['metrics'] : [];
    if (!isset($m[$key]) || !is_numeric($m[$key])) {
        return '—';
    }
    return sprintf('%.2f', (float) $m[$key]);
}

function invokePlotter(string $script, string $csvPath, string $chartsDir): bool
{
    $check = [];
    $rc    = 0;
    @exec('python3 -c "import matplotlib, pandas" 2>&1', $check, $rc);
    if ($rc !== 0) {
        fwrite(STDOUT, "\nmatplotlib/pandas unavailable in this environment.\n");
        fwrite(STDOUT, "Install (Debian/Ubuntu):\n");
        fwrite(STDOUT, "  apt-get install -y python3 python3-matplotlib python3-pandas\n");
        fwrite(STDOUT, "Or with pip:\n");
        fwrite(STDOUT, "  pip3 install matplotlib pandas\n");
        return false;
    }
    $cmd = sprintf('python3 %s %s %s', escapeshellarg($script), escapeshellarg($csvPath), escapeshellarg($chartsDir));
    fwrite(STDOUT, "Plotting: $cmd\n");
    passthru($cmd, $code);
    return $code === 0;
}
