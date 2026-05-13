<?php

declare(strict_types=1);

namespace PhpLlamaBench\CaseStudy;

use PDO;
use RuntimeException;

/**
 * Naive importer — recognisable shape from many real-world projects:
 *   - fgetcsv into a full in-memory array
 *   - one `new Record` per row, normalised in-place
 *   - dedupe via assoc array
 *   - country lookup via array loaded from JSON
 *   - single-row INSERTs
 */
final class NaiveImporter
{
    /** @var array<string, string> */
    private array $countryMap = [];

    public function __construct(
        private readonly string $csvPath,
        private readonly string $countryJsonPath,
        private readonly PDO    $pdo,
    ) {}

    /**
     * @return array{
     *     records: int,
     *     inserts: int,
     *     wall_ns: int,
     *     per_record_ns: list<int>
     * }
     */
    public function import(): array
    {
        $t0 = hrtime(true);

        // 1. Load CSV fully into memory.
        $rows = [];
        $fh = fopen($this->csvPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('cannot open csv');
        }
        $header = fgetcsv($fh, 0, ',', '"', '');
        if ($header === false) {
            throw new RuntimeException('csv missing header');
        }
        while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($fh);

        // 2. Country lookup.
        $countryJson = file_get_contents($this->countryJsonPath);
        if ($countryJson === false) {
            throw new RuntimeException('cannot read countries.json');
        }
        /** @var array<string, string> $countryMap */
        $countryMap = json_decode($countryJson, true);
        $this->countryMap = $countryMap;

        // 3. Normalise + build Record objects.
        /** @var list<Record> $records */
        $records = [];
        $perRecord = [];
        foreach ($rows as $r) {
            $tr0 = hrtime(true);
            $rec = new Record(
                sourceId:    (int) $r[0],
                firstName:   trim($r[1]),
                lastName:    trim($r[2]),
                email:       strtolower(trim($r[3])),
                phone:       (string) preg_replace('/\D+/', '', $r[4]),
                countryName: trim($r[5]),
                countryIso:  $this->lookupIso(trim($r[5])),
                city:        trim($r[6]),
                address:     trim($r[7]),
                postalCode:  trim($r[8]),
                company:     trim($r[9]),
                jobTitle:    trim($r[10]),
                signupDate:  trim($r[11]),
            );
            $records[] = $rec;
            $perRecord[] = hrtime(true) - $tr0;
        }

        // 4. Dedupe by email — keep first occurrence.
        $seen = [];
        $unique = [];
        foreach ($records as $rec) {
            if (isset($seen[$rec->email])) {
                continue;
            }
            $seen[$rec->email] = true;
            $unique[] = $rec;
        }

        // 5. Single-row INSERTs inside one transaction (still the naive
        //    shape — most "naive" code at least gets the transaction right).
        $sql = <<<'SQL'
            INSERT INTO imported_records
              (source_id, first_name, last_name, email, phone,
               country_name, country_iso, city, address, postal_code,
               company, job_title, signup_date)
            VALUES
              (:source_id, :first_name, :last_name, :email, :phone,
               :country_name, :country_iso, :city, :address, :postal_code,
               :company, :job_title, :signup_date)
            SQL;
        $stmt = $this->pdo->prepare($sql);
        $this->pdo->beginTransaction();
        $inserted = 0;
        foreach ($unique as $r) {
            $stmt->execute([
                'source_id'    => $r->sourceId,
                'first_name'   => $r->firstName,
                'last_name'    => $r->lastName,
                'email'        => $r->email,
                'phone'        => $r->phone,
                'country_name' => $r->countryName,
                'country_iso'  => $r->countryIso,
                'city'         => $r->city,
                'address'      => $r->address,
                'postal_code'  => $r->postalCode,
                'company'      => $r->company,
                'job_title'    => $r->jobTitle,
                'signup_date'  => $r->signupDate,
            ]);
            $inserted++;
        }
        $this->pdo->commit();

        return [
            'records'       => count($records),
            'inserts'       => $inserted,
            'wall_ns'       => hrtime(true) - $t0,
            'per_record_ns' => $perRecord,
        ];
    }

    private function lookupIso(string $countryName): string
    {
        // Linear scan would be O(N) — the JSON we loaded is a hash map, so
        // PHP's array isset/get is O(1). Still naive in shape: full array in
        // memory, no shared cache between worker processes.
        return $this->countryMap[$countryName] ?? 'ZZ';
    }
}
