# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

Mintsoft Sync is a PHP service that synchronises order, serial, and stock data between the **Mintsoft** warehouse management API, the **Ikonic** 3PL API, and a local MySQL database. It also validates serials against a separate "VL" MySQL database. It runs as cron jobs and a long-running Redis-backed queue worker — there is no web framework or HTTP layer.

## Commands

```bash
# Install dependencies
composer install

# Run order sync (cron entry point)
php bin/sync_orders.php

# Run the serial-fetch queue worker (managed by Supervisor, runs continuously)
php bin/worker.php

# Push serials to Ikonic 3PL (cron entry point)
php bin/sync_serials_to_ikonic.php

# Sync SellerFlex FBA stock levels into Mintsoft (cron entry point)
php bin/sync_sellerflex_stock.php
```

There are no tests, linter, or build step configured.

## Architecture

### Data Flow

1. **`sync_orders.php`** (cron) — Fetches recent orders from Mintsoft API. For each order, looks up the correct `ExternalOrderReference` via Ikonic API (Linnworks→Mintsoft integration sometimes gets it wrong) and patches Mintsoft if mismatched. Upserts orders/lines into local MySQL, then pushes jobs onto a Redis queue (`queue:serials`).

2. **`worker.php`** (Supervisor) — Pops jobs from `queue:serials`, fetches barcode-verified items (serial numbers) from Mintsoft for each order, validates each serial against the VL `serialNumbers` table (allocating unallocated serials), and stores them locally. Retries up to 3 times with exponential backoff; failures go to `queue:failed`.

3. **`sync_serials_to_ikonic.php`** (cron) — Picks up locally-stored serials that haven't been pushed to Ikonic yet (`synced_to_ikonic_at IS NULL`), calls `POST /3pl/update-order` on Ikonic with serial + tracking + SKU data. Auto-retries errors older than 1 hour.

4. **`sync_sellerflex_stock.php`** (cron) — Fetches all Mintsoft products, filters for `SellerFlex` category, gets FBA fulfillable quantities from Ikonic in bulk, then pushes stock updates back into Mintsoft via `BulkOnHandStockUpdate`. Batches of 50.

### Source Classes (`src/`)

- **`MintsoftClient`** — Guzzle wrapper for Mintsoft REST API. Handles pagination, rate limiting, and 429 backoff. All GET requests go through `request()`, but `updateExternalOrderReference` and `bulkUpdateStock` have standalone implementations that return structured error arrays instead of throwing.
- **`IkonicClient`** — Guzzle wrapper for Ikonic 3PL API. Auth via `X-API-Key` header. Never throws on API errors; always returns `['success' => bool, ...]` arrays.
- **`Database`** — PDO/MySQL with table prefix support (`DB_TABLE_PREFIX`, default `ms_`). Has `ensureConnected()` and `executeWithRetry()` for long-running worker resilience. Primary keys are `mintsoft_id`/`mintsoft_line_id` (not auto-increment), with `ON DUPLICATE KEY UPDATE` upserts throughout.
- **`Queue`** — Redis list-based job queue using Predis. Blocking pop with `blpop`. Queue names: `queue:orders`, `queue:serials`, `queue:failed`.
- **`RateLimiter`** — Sliding-window rate limiter backed by Redis sorted sets. Default 2 requests/second. Blocks with `usleep` polling.
- **`OrderMapper`** — Centralised field mapping from Mintsoft API response shapes to local DB schema. Handles inconsistent API key casing (`ID`/`Id`, `SKU`/`Sku`), nested objects, .NET `/Date()/` format parsing.
- **`SerialValidator`** — Connects to the VL database (`vl.serialNumbers` table), validates serials are unallocated, allocates them, and sends email alerts for missing/duplicate serials.
- **`ErrorLogger`** — Dual logger: writes to local Monolog and POSTs to a remote ErrorHub API (`X-ErrorHub-Token` auth). Never throws on logging failures.

### Configuration

All config is via `.env` (loaded by `vlucas/phpdotenv` in `config/config.php`). Copy `.env.example` to `.env`. Key sections: Mintsoft API, Ikonic API, MySQL, Redis, ErrorHub, rate limits, mail (SMTP for failure notifications).

### Database

MySQL with migrations in `migrations/` (plain SQL, applied manually). Tables use configurable prefix (default `ms_`): `orders`, `order_lines`, `serials`, `sync_log`, `sellerflex_skus`, `sellerflex_sync_runs`.

### Test Scripts (`bin/test_*.php`)

Ad-hoc test/debug scripts for individual API calls — not automated tests.
