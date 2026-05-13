# PHP Performance Benchmarks Inspired by llama.cpp

Six micro-benchmarks plus one realistic case study that translate ideas from
`llama.cpp` (mmap'd weights, flat dense buffers, value pools, table dispatch,
token streaming, columnar layout) into idiomatic PHP 8.4 and measure what
they actually buy you.

The numbers are publication-grade in spirit: warmup → measured iterations →
`hrtime(true)` → `memory_get_peak_usage(true)` → percentile reporting via
linear interpolation. No cooking. A 1.2× win shows up as 1.2×.

## Layout

```
.
├── docker-compose.yml      php + postgres
├── Dockerfile              php:8.4-cli + opcache + jit + ffi + pdo_pgsql
├── docker/php.ini          jit=tracing, memory_limit=-1, ffi.enable=true
├── Makefile                make build / fixtures / db / bench / case-study
├── composer.json
├── phpstan.neon            level 8, strict
├── src/
│   ├── Contract/Benchmark.php
│   ├── Contract/HasExtraReport.php
│   ├── Stats.php           percentile / mean / stddev / formatters
│   ├── Runner.php          warmup + measured iterations
│   ├── ReportWriter.php    results.md + results.json
│   ├── Bench/B0[1-6]_*.php
│   └── CaseStudy/{Record, NaiveImporter, OptimizedImporter}.php
├── bin/
│   ├── generate-fixtures.php   deterministic via mt_srand
│   ├── setup-db.php            drops + creates imported_records
│   ├── run-all.php             B01..B06
│   ├── run-case-study.php      naive vs optimized importer
│   └── _b01_child_loader.php   spawned by B01 to measure cross-process load
├── data/                       fixtures (gitignored)
└── results/                    results.md + results.json (gitignored)
```

## Quick start

```bash
make build        # build the php image (one-off)
make all          # install, fixtures, db, bench, case-study
```

Or step-by-step:

```bash
make install      # composer install in container
make fixtures     # ~30s, builds data/lookup.{bin,json}, records.csv, countries.{bin,json}
make db           # starts postgres, creates imported_records table
make bench        # B01..B06; writes results/results.md
make case-study   # naive vs optimized importer; appends to results.md
```

When you're done:

```bash
make down         # stop containers, drop volumes
```

## What each benchmark tests

| ID  | Technique                     | What's measured                                           |
|-----|-------------------------------|-----------------------------------------------------------|
| B01 | FFI mmap                      | 10M-entry table: load time, heap, lookup p50/p95/p99, cross-process cold start |
| B02 | SplFixedArray                 | 10M ints: memory, population, full iteration, random R/W  |
| B03 | Object pool                   | 5M Point3D allocations vs reused pool, GC delta           |
| B04 | Lookup table vs match vs switch | 10M classifications, three implementations side-by-side |
| B05 | Generator                     | 5M records: materialised array vs streaming, peak memory  |
| B06 | Column- vs row-oriented       | 5M records × 5 fields: single-column scan + full-row scan |

## Case study

`bin/run-case-study.php` runs both importers against the same 100K-row CSV and
the same Postgres table:

- naive: full-array CSV read, `new Record()` per row, assoc-array dedupe,
  JSON-loaded country map, single-row INSERTs.
- optimized: Generator CSV reader (B05), pooled Record (B03),
  SplFixedArray-backed dedupe with linear probing (B02), mmap'd binary
  country table (B01), 1000-row multi-VALUES INSERT.

## Honesty notes

- Benchmarks run inside Docker (`php:8.4-cli-bookworm`, opcache + JIT
  enabled). Run the suite a few times — first-run numbers are noisier.
- B01 measures *warm* load time (kernel page cache primed). Real cold-disk
  numbers depend on storage and are out of scope for an in-process bench.
- B03's pool win is usually modest in a one-shot CLI script. The pattern's
  real value shows up in long-running workers (queues, websockets).
- B04 may show a *smaller* win than C-style intuition predicts because PHP
  8.3's JIT compiles `match` very well. The article will discuss this.

## Requirements (in the container)

PHP 8.4, ext-ffi, ext-pdo_pgsql, opcache + JIT, PostgreSQL 16. All wired up
in `docker-compose.yml`.

## Static analysis

```bash
make stan         # phpstan level 8
```
