<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use PhpLlamaBench\CaseStudy\NaiveImporter;
use PhpLlamaBench\CaseStudy\OptimizedImporter;
use PhpLlamaBench\ReportWriter;
use PhpLlamaBench\Stats;

$dsn  = getenv('DATABASE_URL') ?: 'pgsql:host=postgres;port=5432;dbname=bench';
$user = getenv('DB_USER') ?: 'bench';
$pass = getenv('DB_PASSWORD') ?: 'bench';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$csv         = __DIR__ . '/../data/records.csv';
$countryJson = __DIR__ . '/../data/countries.json';
$countryBin  = __DIR__ . '/../data/countries.bin';

foreach ([$csv, $countryJson, $countryBin] as $p) {
    if (!is_file($p)) {
        fwrite(STDERR, "Missing fixture: $p — run `make fixtures` first.\n");
        exit(1);
    }
}

$summaries = [];

foreach ([
    ['naive',     fn(): NaiveImporter     => new NaiveImporter($csv, $countryJson, $pdo)],
    ['optimized', fn(): OptimizedImporter => new OptimizedImporter($csv, $countryBin, $pdo)],
] as [$label, $factory]) {
    fwrite(STDERR, "→ $label importer ...\n");

    $pdo->exec('TRUNCATE imported_records RESTART IDENTITY');

    gc_collect_cycles();
    memory_reset_peak_usage();

    $importer = $factory();
    $r        = $importer->import();

    $peak       = memory_get_peak_usage(true);
    $samples    = $r['per_record_ns'];
    sort($samples);
    $throughput = $r['records'] / max(1e-9, $r['wall_ns'] / 1e9);

    $countStmt = $pdo->query('SELECT COUNT(*) FROM imported_records');
    if ($countStmt === false) {
        throw new RuntimeException('count query failed');
    }
    $rowsInDb = (int) $countStmt->fetchColumn();

    $summaries[$label] = [
        'records'     => $r['records'],
        'inserts'     => $r['inserts'],
        'rows_in_db'  => $rowsInDb,
        'wall_ns'     => $r['wall_ns'],
        'p50_ns'      => \PhpLlamaBench\Stats::percentile($samples, 50),
        'p95_ns'      => \PhpLlamaBench\Stats::percentile($samples, 95),
        'p99_ns'      => \PhpLlamaBench\Stats::percentile($samples, 99),
        'throughput'  => $throughput,
        'peak_memory' => $peak,
    ];

    fwrite(STDERR, sprintf(
        "  records=%d inserts=%d wall=%s peak=%s throughput=%.0f rec/s\n",
        $r['records'],
        $r['inserts'],
        Stats::formatNs($r['wall_ns']),
        Stats::formatBytes($peak),
        $throughput,
    ));
}

$resultsDir = __DIR__ . '/../results';
if (!is_dir($resultsDir)) {
    mkdir($resultsDir, 0o755, true);
}

$existing = [];
$mdPath   = $resultsDir . '/results.md';
if (is_file($mdPath)) {
    $existing = file_get_contents($mdPath);
}
$reportWriter = new ReportWriter();
$reportWriter->write([], $summaries, $resultsDir);

// Append case-study section to the bench results.md if it exists, so a full
// run produces a single combined report.
if (is_file($resultsDir . '/case_study_only.md')) {
    @unlink($resultsDir . '/case_study_only.md');
}
$csOnly = $resultsDir . '/results.md';
if (is_string($existing) && $existing !== '') {
    $newPart = (string) file_get_contents($csOnly);
    // results.md from the case-study run starts with the env block; strip it
    // and append only the case-study section.
    $marker = '## Case Study';
    $pos = strpos($newPart, $marker);
    if ($pos !== false) {
        $combined = $existing . "\n" . substr($newPart, $pos);
        file_put_contents($mdPath, $combined);
    }
}

fwrite(STDERR, "Case study done. See results/results.md.\n");
