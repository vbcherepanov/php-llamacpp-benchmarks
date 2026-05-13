<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$dsn  = getenv('DATABASE_URL') ?: 'pgsql:host=postgres;port=5432;dbname=bench';
$user = getenv('DB_USER') ?: 'bench';
$pass = getenv('DB_PASSWORD') ?: 'bench';

$pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);

$pdo->exec('DROP TABLE IF EXISTS imported_records');
$pdo->exec(<<<'SQL'
    CREATE TABLE imported_records (
        id            SERIAL PRIMARY KEY,
        source_id     INTEGER NOT NULL,
        first_name    TEXT NOT NULL,
        last_name     TEXT NOT NULL,
        email         TEXT NOT NULL,
        phone         TEXT NOT NULL,
        country_name  TEXT NOT NULL,
        country_iso   TEXT NOT NULL,
        city          TEXT NOT NULL,
        address       TEXT NOT NULL,
        postal_code   TEXT NOT NULL,
        company       TEXT NOT NULL,
        job_title     TEXT NOT NULL,
        signup_date   DATE NOT NULL
    )
SQL);

fwrite(STDOUT, "Table imported_records ready.\n");
