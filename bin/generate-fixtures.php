<?php

declare(strict_types=1);

/**
 * Deterministic fixture generator.
 *
 * Output:
 *   data/lookup.bin       — 10M × (uint32 id, uint32 value) = 80 MB packed binary
 *   data/lookup.json      — same values in JSON array form (id is the index)
 *   data/records.csv      — 100K rows, 12 columns, ~10% duplicates by email
 *   data/countries.bin    — fixed-width binary [name:32][iso:2] × N
 *   data/countries.json   — same data as { "Country Name": "XX" } map
 *
 * All RNG is seeded — re-running produces identical bytes.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$dataDir = __DIR__ . '/../data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0o755, true);
}

generateLookupFixtures($dataDir);
generateCountryFixtures($dataDir);
generateRecordsCsv($dataDir);

fwrite(STDOUT, "Fixtures generated.\n");

/**
 * 10M (id, value) pairs. Binary stores both as packed uint32 LE; JSON stores
 * value-only array (id == index). Same seed → identical content.
 */
function generateLookupFixtures(string $dataDir): void
{
    $binPath  = $dataDir . '/lookup.bin';
    $jsonPath = $dataDir . '/lookup.json';

    fwrite(STDOUT, "Generating lookup.bin and lookup.json (10M records)...\n");

    mt_srand(0xC0FFEE);

    $bin  = fopen($binPath, 'wb');
    $json = fopen($jsonPath, 'wb');
    if ($bin === false || $json === false) {
        throw new RuntimeException('failed to open lookup output files');
    }

    fwrite($json, '[');

    $batchBin  = '';
    $batchJson = '';
    $total     = 10_000_000;
    $chunk     = 100_000;
    $t0        = microtime(true);

    for ($i = 0; $i < $total; $i++) {
        $value = mt_rand(0, 0x7FFFFFFF); // signed 32-bit positive, easy for JSON & FFI
        $batchBin .= pack('VV', $i, $value);
        $batchJson .= $i === 0 ? (string) $value : (',' . $value);

        if ((($i + 1) % $chunk) === 0) {
            fwrite($bin, $batchBin);
            fwrite($json, $batchJson);
            $batchBin  = '';
            $batchJson = '';
        }
    }
    if ($batchBin !== '') {
        fwrite($bin, $batchBin);
    }
    if ($batchJson !== '') {
        fwrite($json, $batchJson);
    }
    fwrite($json, ']');

    fclose($bin);
    fclose($json);

    fwrite(STDOUT, sprintf(
        "  bin  = %s  (%.2f s)\n  json = %s\n",
        humanBytes((int) filesize($binPath)),
        microtime(true) - $t0,
        humanBytes((int) filesize($jsonPath)),
    ));
}

/**
 * ~250 countries. Real ISO codes for ~50 well-known countries plus filler
 * synthetic entries so the table size matches the spec.
 */
function generateCountryFixtures(string $dataDir): void
{
    fwrite(STDOUT, "Generating country fixtures...\n");

    $real = [
        'United States'     => 'US', 'Canada'           => 'CA', 'Mexico'        => 'MX',
        'United Kingdom'    => 'GB', 'France'           => 'FR', 'Germany'       => 'DE',
        'Italy'             => 'IT', 'Spain'            => 'ES', 'Portugal'      => 'PT',
        'Netherlands'       => 'NL', 'Belgium'          => 'BE', 'Switzerland'   => 'CH',
        'Austria'           => 'AT', 'Sweden'           => 'SE', 'Norway'        => 'NO',
        'Denmark'           => 'DK', 'Finland'          => 'FI', 'Iceland'       => 'IS',
        'Ireland'           => 'IE', 'Poland'           => 'PL', 'Czechia'       => 'CZ',
        'Slovakia'          => 'SK', 'Hungary'          => 'HU', 'Romania'       => 'RO',
        'Bulgaria'          => 'BG', 'Greece'           => 'GR', 'Croatia'       => 'HR',
        'Slovenia'          => 'SI', 'Serbia'           => 'RS', 'Ukraine'       => 'UA',
        'Russia'            => 'RU', 'Belarus'          => 'BY', 'Latvia'        => 'LV',
        'Lithuania'         => 'LT', 'Estonia'          => 'EE', 'Turkey'        => 'TR',
        'Israel'            => 'IL', 'Saudi Arabia'     => 'SA', 'UAE'           => 'AE',
        'Egypt'             => 'EG', 'Morocco'          => 'MA', 'South Africa'  => 'ZA',
        'Nigeria'           => 'NG', 'Kenya'            => 'KE', 'India'         => 'IN',
        'Pakistan'          => 'PK', 'Bangladesh'       => 'BD', 'China'         => 'CN',
        'Japan'             => 'JP', 'South Korea'      => 'KR', 'Vietnam'       => 'VN',
        'Thailand'          => 'TH', 'Indonesia'        => 'ID', 'Philippines'   => 'PH',
        'Malaysia'          => 'MY', 'Singapore'        => 'SG', 'Australia'     => 'AU',
        'New Zealand'       => 'NZ', 'Brazil'           => 'BR', 'Argentina'     => 'AR',
        'Chile'             => 'CL', 'Colombia'         => 'CO', 'Peru'          => 'PE',
        'Venezuela'         => 'VE', 'Cuba'             => 'CU', 'Uruguay'       => 'UY',
    ];

    // Pad with deterministic synthetic countries to reach the 250-entry target.
    $countries = $real;
    $i = 0;
    while (count($countries) < 250) {
        $name = sprintf('Republic of %s', synthCountryName($i));
        if (!isset($countries[$name])) {
            $countries[$name] = sprintf('Z%d', $i % 100);
        }
        $i++;
    }

    $jsonPath = $dataDir . '/countries.json';
    file_put_contents(
        $jsonPath,
        json_encode($countries, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT),
    );

    // Binary: fixed-width [name(32)][iso(2)] = 34 bytes per record.
    // Bigger fields would suit larger tables; 32 is enough here.
    $binPath = $dataDir . '/countries.bin';
    $bin = fopen($binPath, 'wb');
    if ($bin === false) {
        throw new RuntimeException('failed to open countries.bin');
    }
    foreach ($countries as $name => $iso) {
        $nameBytes = substr($name, 0, 32);
        $nameBytes = str_pad($nameBytes, 32, "\0");
        $isoBytes  = str_pad(substr($iso, 0, 2), 2, "\0");
        fwrite($bin, $nameBytes . $isoBytes);
    }
    fclose($bin);

    fwrite(STDOUT, sprintf(
        "  countries.json = %s, countries.bin = %s (%d entries)\n",
        humanBytes((int) filesize($jsonPath)),
        humanBytes((int) filesize($binPath)),
        count($countries),
    ));
}

function synthCountryName(int $i): string
{
    $a = ['North', 'South', 'East', 'West', 'New', 'Old', 'Upper', 'Lower'];
    $b = ['Vania', 'Storia', 'Mira', 'Lonia', 'Crestia', 'Voria', 'Trinia', 'Mossland'];
    return $a[$i % 8] . ' ' . $b[($i >> 3) % 8] . ((string) (1 + (int) ($i / 64)));
}

/**
 * 100K rows × 12 columns, with deliberate dirt:
 *   - mixed case + surrounding whitespace
 *   - phones with arbitrary punctuation
 *   - ~10% rows are exact duplicates by email (canonical address)
 */
function generateRecordsCsv(string $dataDir): void
{
    $path = $dataDir . '/records.csv';
    fwrite(STDOUT, "Generating records.csv (100K rows)...\n");

    mt_srand(0xBADBEEF);

    $first = ['Alice','Bob','Carol','David','Eve','Frank','Grace','Henry',
              'Iris','Jack','Karen','Leo','Maria','Noah','Olga','Peter',
              'Quinn','Rita','Steve','Tina','Ulysses','Vera','Walt','Xenia',
              'Yves','Zoe'];
    $last  = ['Smith','Jones','Brown','Taylor','Williams','Wilson','Davies',
              'Evans','Thomas','Roberts','Walker','Wright','Robinson','Wood'];
    $countries = ['United States','United Kingdom','Germany','France','Italy',
                  'Spain','Netherlands','Poland','Brazil','Canada','India',
                  'Japan','Australia','Mexico','Sweden','Argentina'];
    $companies = ['Acme','Globex','Initech','Umbrella','Soylent','Stark',
                  'Wayne','Wonka','Hooli','Pied Piper','Vandelay'];
    $titles    = ['Engineer','Manager','Director','Analyst','Designer',
                  'Architect','Lead','Specialist','Coordinator','Consultant'];

    $fh = fopen($path, 'wb');
    if ($fh === false) {
        throw new RuntimeException('failed to open records.csv');
    }
    fputcsv($fh, [
        'id','first_name','last_name','email','phone','country_name','city',
        'address','postal_code','company','job_title','signup_date',
    ], ',', '"', '');

    /** @var list<string> $emailsSeen */
    $emailsSeen = [];
    $total = 100_000;

    for ($i = 0; $i < $total; $i++) {
        $fn = $first[mt_rand(0, count($first) - 1)];
        $ln = $last[mt_rand(0, count($last) - 1)];

        // Roughly 10% of rows reuse a previously seen email (a real duplicate).
        if ($i >= 1000 && mt_rand(1, 100) <= 10) {
            $email = $emailsSeen[mt_rand(0, count($emailsSeen) - 1)];
        } else {
            $email = sprintf('%s.%s%d@example.com', strtolower($fn), strtolower($ln), $i);
            $emailsSeen[] = $email;
        }

        $rec = [
            $i,
            randomCase($fn, ' '),
            randomCase($ln, ' '),
            randomCase($email, ''),
            randomPhone(),
            $countries[mt_rand(0, count($countries) - 1)],
            'City' . mt_rand(1, 500),
            mt_rand(1, 9999) . ' Main St',
            sprintf('%05d', mt_rand(0, 99999)),
            $companies[mt_rand(0, count($companies) - 1)],
            $titles[mt_rand(0, count($titles) - 1)],
            sprintf('20%02d-%02d-%02d', mt_rand(15, 24), mt_rand(1, 12), mt_rand(1, 28)),
        ];
        fputcsv($fh, $rec, ',', '"', '');
    }
    fclose($fh);

    fwrite(STDOUT, sprintf("  records.csv = %s\n", humanBytes((int) filesize($path))));
}

function randomCase(string $s, string $pad): string
{
    if (mt_rand(0, 1) === 0) {
        $s = strtoupper($s);
    }
    if (mt_rand(0, 3) === 0) {
        $s = $pad . $s . $pad;
    }
    return $s;
}

function randomPhone(): string
{
    $patterns = [
        '+%d (%03d) %03d-%04d',
        '%d-%03d-%03d-%04d',
        '(%d) %03d.%03d.%04d',
    ];
    $p = $patterns[mt_rand(0, count($patterns) - 1)];
    return sprintf($p, mt_rand(1, 99), mt_rand(0, 999), mt_rand(0, 999), mt_rand(0, 9999));
}

function humanBytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $b = (float) $bytes;
    while ($b >= 1024.0 && $i < count($units) - 1) {
        $b /= 1024.0;
        $i++;
    }
    return sprintf('%.2f %s', $b, $units[$i]);
}
