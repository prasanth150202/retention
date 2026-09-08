# Project Odysseus

Multi-client Shopify retention, funnel and attribution platform for Digifyce.

Full design: **[TECHNICAL_PLAN.md](TECHNICAL_PLAN.md)** — read §3 (constraints) before judging any design decision, because every one of them traces back to a platform limit.

| | |
|---|---|
| Host | `retention.digifyce.com` (Hostinger shared, hPanel) |
| Stack | PHP 8 + MySQL, no build step, no Node |
| Deploy | Hostinger Git → `~/domains/retention.digifyce.com/` |
| Status | **M1 in progress** — schema and migration runner done, verified against MariaDB 10.11 |

---

## Setup for the server admin

You only need to edit **one file**: `.env`. Everything else is in version control.

### 1. Create the databases

You need **two** databases to start, and one more each January.

| Database | Holds | Rotates? |
|---|---|---|
| `<prefix>_retention` | Core — stores, orders, customers, all reports and settings | Never |
| `<prefix>_ev_2026` | Raw pixel events for 2026 only | Yearly |
| `<prefix>_ev_2027` | Raw pixel events for 2027 | Create by Dec 2027 |

hPanel prepends your account id to whatever name you type and shows the full result — match it to what you put in `.env`.

**Why events live apart from everything else.** Events are ~99% of the data. Hostinger caps each database at 3 GB; at projected volume that is roughly 14 months of events for five stores. If events shared the core database, filling it would stop order syncing, the dashboard and settings too. Kept separate, a full shard stops only ingest — orders keep syncing and the dashboard keeps working while you get an alert with weeks of runway.

**Credentials.** Hostinger issues one credential per database, so each database has its own user and password. That is fine here: no query ever spans two databases. Event rows store only integer ids, and the names those ids refer to live in the core database, so each is read on its own connection. If your host *does* allow one user across several databases, that works too — just omit the per-shard blocks in `.env`.

**Remote MySQL.** Only needed if you run migrations from your own machine. Once the code is deployed, everything connects to `localhost` and remote access can be turned off — which is worth doing, since Hostinger's remote grants default to `@%` (reachable from any host on the internet).

### 2. Configure

```bash
cp .env.example .env
```

`DB_CORE` and `DB_SHARD` are the **full** database names as hPanel displays them. Keep the literal `{year}` in `DB_SHARD` — the application substitutes it to work out which database a given date belongs in. Add a credential block per shard year:

```
DB_USER=u123456789_retention          # core database
DB_PASS=…
DB_CORE=u123456789_retention
DB_SHARD=u123456789_ev_{year}

DB_SHARD_2026_USER=u123456789_ev_2026 # this shard's own credential
DB_SHARD_2026_PASS=…
```

A year with no `DB_SHARD_<YEAR>_*` block falls back to `DB_USER` / `DB_PASS`.

Leave the Shopify values blank for now — they are only needed to onboard a store.

Running `migrate.php` against more than one environment? Use `--env=.env.production` rather than swapping files around.

`.env` lives at the repo root, which is **above** `public_html` and therefore not web-reachable. Do not move it.

### 3. Create runtime directories

These are not in git, because spool files contain visitor data:

```bash
mkdir -p storage/{spool,processed,failed,locks,logs}
chmod 700 storage
```

### 4. Generate the encryption key

```bash
php bin/keygen.php
```

This writes `secrets/master.key`, which encrypts stored Shopify API tokens.

**Back it up offline.** It is not in git and cannot be regenerated — losing it makes every stored token permanently unrecoverable and forces every store to be re-onboarded.

### 5. Run migrations

```bash
php bin/migrate.php --dry-run    # inspect first
php bin/migrate.php
```

If a shard database is missing, the runner prints the exact name to create in hPanel and exits with code 2 rather than half-applying. Create it, grant the user, re-run.

### 6. Geo database

Download `GeoLite2-City.mmdb` from MaxMind (free account) and place it in `secrets/`. Required before the first import run; not needed to migrate.

---

## Layout

Only `public_html/` is web-reachable. Everything else is deployed but private.

```
public_html/          document root — dashboard, c.php ingest, oauth callback
app/lib/              Env, Config, Db, Shard, Crypto, Hash, EventType, …
app/cron/             import, sync, rollups, identity, attribution, health_check
config/config.php     structural config (tracked, no secrets)
db/migrations/        001_core  002_events_shard  003_seed_defaults
bin/                  migrate.php  keygen.php
storage/              NOT in git — spool, processed, locks, logs
secrets/              NOT in git — master.key, salts, GeoLite2-City.mmdb
.env                  NOT in git — database credentials
```

---

## Local development

No Docker, no admin rights, no installers — both dependencies run from zip
extractions in a user directory.

### PHP 8.3

Download the **NTS x64** build from <https://windows.php.net/downloads/releases/>,
extract it, then create `php.ini` next to `php.exe` with:

```ini
extension_dir = "<php-dir>\ext"
extension=openssl      ; token encryption (bin/keygen.php)
extension=pdo_mysql    ; all database access
extension=mbstring
extension=curl         ; Shopify Admin API client
extension=fileinfo
memory_limit = 512M
date.timezone = UTC
```

`openssl` and `pdo_mysql` are not optional — `keygen.php` and every database
call fail without them.

### MariaDB 10.11

Use the **zip** distribution, not the MSI: it needs no service and no
elevation. 10.11 LTS is chosen to approximate Hostinger's shared MariaDB —
confirm the production version with `SELECT VERSION()` and re-test here if it
differs materially.

```bash
bin/mariadb-install-db.exe --datadir=<data-dir> --port=3307
bin/mariadbd.exe --datadir=<data-dir> --port=3307        # leave running
```

Port 3307 avoids colliding with any existing MySQL.

Then create the three databases and one user granted on all of them (mirroring
the hPanel setup above), point `.env` at `127.0.0.1:3307`, and run
`php bin/migrate.php`.

### Verifying a schema change

```bash
php -l <file>                    # syntax
php bin/migrate.php --dry-run    # statement count, nothing written
php bin/migrate.php              # apply
php bin/migrate.php              # re-run: everything should report [skip]
```

Confirm the events table kept its compression, because the ~14-month shard
lifetime depends on it:

```sql
SELECT ROW_FORMAT, CREATE_OPTIONS FROM information_schema.TABLES
WHERE TABLE_SCHEMA='<your DB_SHARD for 2026>' AND TABLE_NAME='events';
-- expect: Compressed | row_format=COMPRESSED key_block_size=8
```

---

## Notes for developers

**Databases are sharded by year.** Raw events go to `odys_ev_<YEAR>`; everything else lives in `odys_core`. Hostinger caps each database at 3 GB, and at projected volume one shard holds roughly 14 months. `shard_registry` maps date ranges to physical database names.

**Do not add indexes to the `events` table** without recalculating the row-size budget in `db/migrations/002_events_shard.sql`. Each secondary index costs ~26–31 bytes per row, which is 1.5–2 months of shard lifetime.

**The dashboard reads rollup tables only.** It never queries raw events. That constraint is what allows the events table to carry just two indexes.

**Money is stored as integer minor units** (paise). No `DECIMAL`, no floats, anywhere.

**All timestamps are UTC.** Display timezone is per-tenant on the `tenants` row.

**The `write_key` in the pixel snippet is public**, not a credential — it is readable via View Source on any client storefront. The real protections are the Origin check, rate limiting and the payload size cap. See TECHNICAL_PLAN.md §7.2.

Migrations are tracked by filename and checksum. All statements are idempotent, so editing an applied migration re-runs it — but after go-live, make schema changes in a **new** numbered file instead.
