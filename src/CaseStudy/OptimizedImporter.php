<?php

declare(strict_types=1);

namespace PhpLlamaBench\CaseStudy;

use FFI;
use Generator;
use PDO;
use RuntimeException;
use SplFixedArray;

/**
 * Optimized importer applying the techniques from the benchmark suite:
 *
 *   - Generator CSV reader (B05)
 *   - Object pool for Record (B03), pool size = batch size
 *   - Dedupe via SplFixedArray + linear probing (B02 spirit)
 *   - mmap'd binary country table (B01)
 *   - Batched multi-VALUES INSERT
 *
 * Same observable behaviour as NaiveImporter; smaller heap, fewer round-trips.
 */
final class OptimizedImporter
{
    private const BATCH_SIZE     = 1_000;
    private const DEDUP_CAPACITY = 262_144;          // power of two, ~2.6× expected uniques
    private const DEDUP_MASK     = self::DEDUP_CAPACITY - 1;
    private const COUNTRY_REC_SZ = 34;               // 32-byte name + 2-byte iso

    private FFI $ffi;
    /** @var FFI\CData void* */
    private $countryPtr;
    /** @var FFI\CData uint8_t* */
    private $countryU8;
    private int $countrySize  = 0;
    private int $countryCount = 0;

    /** @var array<string, string> hot lookup cache */
    private array $countryCache = [];

    /** @var SplFixedArray<?string> */
    private SplFixedArray $dedup;

    /** @var list<Record> reusable Record instances */
    private array $pool;

    public function __construct(
        private readonly string $csvPath,
        private readonly string $countryBinPath,
        private readonly PDO    $pdo,
    ) {
        $this->ffi = $this->openLibc();
        $this->mmapCountries();

        $this->dedup = new SplFixedArray(self::DEDUP_CAPACITY);
        $this->pool  = [];
        for ($i = 0; $i < self::BATCH_SIZE; $i++) {
            $this->pool[] = new Record();
        }
    }

    public function __destruct()
    {
        if ($this->countrySize > 0) {
            $this->ffi->munmap($this->countryPtr, $this->countrySize);
        }
    }

    private function openLibc(): FFI
    {
        $libc = match (PHP_OS_FAMILY) {
            'Darwin' => 'libc.dylib',
            'Linux'  => 'libc.so.6',
            default  => throw new RuntimeException('Unsupported OS for FFI mmap: ' . PHP_OS_FAMILY),
        };
        return FFI::cdef(<<<'CDEF'
            void *mmap(void *addr, size_t length, int prot, int flags, int fd, long offset);
            int munmap(void *addr, size_t length);
            int open(const char *pathname, int flags);
            int close(int fd);
            CDEF, $libc);
    }

    private function mmapCountries(): void
    {
        $size = (int) filesize($this->countryBinPath);
        if ($size === 0 || ($size % self::COUNTRY_REC_SZ) !== 0) {
            throw new RuntimeException('countries.bin invalid size: ' . $size);
        }
        $fd = $this->ffi->open($this->countryBinPath, 0);
        if ($fd < 0) {
            throw new RuntimeException('open(countries.bin) failed');
        }
        $ptr = $this->ffi->mmap(null, $size, 1, 2, $fd, 0);
        $this->ffi->close($fd);

        $u8 = $this->ffi->cast('uint8_t*', $ptr);
        if ($u8 === null) {
            throw new RuntimeException('countries.bin cast returned null');
        }
        $this->countryPtr   = $ptr;
        $this->countryU8    = $u8;
        $this->countrySize  = $size;
        $this->countryCount = intdiv($size, self::COUNTRY_REC_SZ);
    }

    /**
     * @return Generator<int, array<int, string>>
     */
    private function streamCsv(): Generator
    {
        $fh = fopen($this->csvPath, 'rb');
        if ($fh === false) {
            throw new RuntimeException('cannot open csv');
        }
        try {
            $header = fgetcsv($fh, 0, ',', '"', '');
            if ($header === false) {
                throw new RuntimeException('csv missing header');
            }
            while (($row = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                yield $row;
            }
        } finally {
            fclose($fh);
        }
    }

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

        $stmtFull = $this->pdo->prepare($this->batchInsertSql(self::BATCH_SIZE));

        $this->pdo->beginTransaction();

        $records   = 0;
        $inserted  = 0;
        $perRecord = [];
        $slot      = 0;

        foreach ($this->streamCsv() as $row) {
            $tr0 = hrtime(true);
            $records++;

            $email = strtolower(trim((string) $row[3]));
            if ($this->isDuplicate($email)) {
                $perRecord[] = hrtime(true) - $tr0;
                continue;
            }

            $r = $this->pool[$slot];
            $r->sourceId    = (int) $row[0];
            $r->firstName   = trim((string) $row[1]);
            $r->lastName    = trim((string) $row[2]);
            $r->email       = $email;
            $r->phone       = (string) preg_replace('/\D+/', '', (string) $row[4]);
            $r->countryName = trim((string) $row[5]);
            $r->countryIso  = $this->lookupIso($r->countryName);
            $r->city        = trim((string) $row[6]);
            $r->address     = trim((string) $row[7]);
            $r->postalCode  = trim((string) $row[8]);
            $r->company     = trim((string) $row[9]);
            $r->jobTitle    = trim((string) $row[10]);
            $r->signupDate  = trim((string) $row[11]);

            $slot++;
            $perRecord[] = hrtime(true) - $tr0;

            if ($slot === self::BATCH_SIZE) {
                $stmtFull->execute($this->buildParams($slot));
                $inserted += $slot;
                $slot = 0;
            }
        }

        if ($slot > 0) {
            $tailStmt = $this->pdo->prepare($this->batchInsertSql($slot));
            $tailStmt->execute($this->buildParams($slot));
            $inserted += $slot;
        }

        $this->pdo->commit();

        return [
            'records'       => $records,
            'inserts'       => $inserted,
            'wall_ns'       => hrtime(true) - $t0,
            'per_record_ns' => $perRecord,
        ];
    }

    /**
     * Open-addressing hash table over SplFixedArray with linear probing.
     */
    private function isDuplicate(string $email): bool
    {
        $h = crc32($email) & self::DEDUP_MASK;
        while (true) {
            $cell = $this->dedup[$h];
            if ($cell === null) {
                $this->dedup[$h] = $email;
                return false;
            }
            if ($cell === $email) {
                return true;
            }
            $h = ($h + 1) & self::DEDUP_MASK;
        }
    }

    private function lookupIso(string $name): string
    {
        if (isset($this->countryCache[$name])) {
            return $this->countryCache[$name];
        }
        // mmap'd table is small (~250 records) — a linear scan is faster than
        // building an in-PHP hash index, especially across worker processes
        // that share the kernel page cache.
        $u8 = $this->countryU8;
        $count = $this->countryCount;
        $needle = $name . str_repeat("\0", max(0, 32 - strlen($name)));
        $needle = substr($needle, 0, 32);

        for ($r = 0; $r < $count; $r++) {
            $base = $r * self::COUNTRY_REC_SZ;
            $eq = true;
            for ($i = 0; $i < 32; $i++) {
                if (chr($u8[$base + $i]) !== $needle[$i]) {
                    $eq = false;
                    break;
                }
            }
            if ($eq) {
                $isoRaw = chr($u8[$base + 32]) . chr($u8[$base + 33]);
                $iso    = rtrim($isoRaw, "\0");
                $this->countryCache[$name] = $iso;
                return $iso;
            }
        }
        $this->countryCache[$name] = 'ZZ';
        return 'ZZ';
    }

    private function batchInsertSql(int $rows): string
    {
        $tuple = '(' . implode(',', array_fill(0, 13, '?')) . ')';
        return 'INSERT INTO imported_records ('
            . 'source_id, first_name, last_name, email, phone, '
            . 'country_name, country_iso, city, address, postal_code, '
            . 'company, job_title, signup_date) VALUES '
            . implode(',', array_fill(0, $rows, $tuple));
    }

    /**
     * @return list<int|string>
     */
    private function buildParams(int $rows): array
    {
        $params = [];
        for ($i = 0; $i < $rows; $i++) {
            $r = $this->pool[$i];
            $params[] = $r->sourceId;
            $params[] = $r->firstName;
            $params[] = $r->lastName;
            $params[] = $r->email;
            $params[] = $r->phone;
            $params[] = $r->countryName;
            $params[] = $r->countryIso;
            $params[] = $r->city;
            $params[] = $r->address;
            $params[] = $r->postalCode;
            $params[] = $r->company;
            $params[] = $r->jobTitle;
            $params[] = $r->signupDate;
        }
        return $params;
    }
}
