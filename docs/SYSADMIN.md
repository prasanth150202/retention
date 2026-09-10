# Project Odysseus — server administration

Handover notes for whoever runs the Hostinger account. Everything here is done
in hPanel; no shell access is required.

**Host:** `retention.digifyce.com` · **Stack:** PHP 8 + MariaDB · **Deploy:** Hostinger Git

---

## 1. Scheduled jobs

Six cron entries. Without them the site still accepts data but nothing is
ever processed — events pile up in a spool directory and the dashboard stays
empty.

### Find the PHP binary first

hPanel → **Advanced → Cron Jobs**. The interface usually offers a PHP command
type that fills the path in for you. If you are typing a raw command, the
binary is normally:

```
/usr/bin/php
```

Some Hostinger configurations expose version-specific paths such as
`/usr/bin/php8.3` or `/opt/alt/php83/usr/bin/php`. **The version matters** —
this code requires PHP 8.1 or newer and will fail on 7.4. To confirm which
version cron actually gets, add this as a one-off job and check the email it
sends you:

```
/usr/bin/php -v
```

### The six jobs

Replace `<account>` and `<site>` with the real values. All six take an
absolute path — cron does not run from the site directory.

The hourly three run in order — sync, then identity, then attribution — because
each uses what the one before it worked out. Twenty minutes apart is generous
for the volumes involved; if one overruns, the next simply picks up the
remainder on its following run.

| Schedule | Command | Purpose |
|---|---|---|
| `*/5 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/import.php` | Move captured events into the database |
| `0 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/sync.php` | Pull orders and customers from Shopify |
| `20 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/identity.php` | Work out which orders belong to the same person |
| `40 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/attribute.php` | Work out which campaign each order came from |
| `15 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/health_check.php --quiet` | Watch for silent failures and email alerts |
| `30 3 * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/purge.php` | Delete data for stores that uninstalled, once their retention period has passed |

**If your plan's minimum interval is 15 minutes**, change the first to
`*/15 * * * *`. The only consequence is that dashboard data lags by up to
fifteen minutes instead of five. Nothing is lost — events wait in the spool
until the importer runs.

### Why each one matters

**`import.php`** — the site's ingest endpoint deliberately writes events to a
file rather than the database, so that a database problem delays data instead
of destroying it. This job is what actually moves them. If it stops, events
accumulate on disk indefinitely; they are safe, but invisible.

**`sync.php`** — pulls order and customer records from Shopify. Revenue and
retention figures come from here, not from the browser. Safe to run when no
store is connected; it exits immediately.

**`identity.php`** — decides which orders came from the same shopper, and
numbers each person's orders 1, 2, 3. Every retention figure in the product is
a statement about somebody buying more than once, so if this stops running,
new orders keep arriving but repeat-purchase and cohort numbers quietly stop
moving. It runs after `sync.php` because it works on orders that sync has
already pulled. Chunked and resumable: a store with years of history is
drained over several runs rather than one that a time limit kills halfway.

**`attribute.php`** — decides which campaign gets credit for each order, and
sorts every order into a channel (Instagram, Email, Paid Search…). Runs after
`identity.php` because a repeat buyer's phone browsing only counts towards a
laptop purchase once both browsers are known to belong to the same person.
If it stops, the Campaigns tab freezes while revenue keeps arriving, so the
numbers look plausible and are wrong — which is worse than an empty tab.

**`purge.php`** — deletes data for stores that uninstalled, once the retention
period configured in `.env` has passed. Shopify's `shop/redact` webhook covers
the case where Shopify asks; this covers the case where nobody asks and the
data should go anyway. Both run the same deletion code, so they cannot drift.

**`health_check.php`** — **do not skip this one.** It is the only thing
watching for the failures that produce no error anywhere:

- An events database reaching its 3 GB size limit. When that happens MySQL
  refuses writes and data stops flowing. The alert fires at 80% — roughly two
  months of warning — and creating the next database is a five-minute job *if
  somebody knows to do it*.
- Another cron entry silently no longer running. A job that stops being
  invoked raises no error; the dashboard simply stops changing, which looks
  like a quiet week rather than a fault.
- Import failures piling up.

It emails `ALERT_EMAIL_TO` from `.env`, at most once every six hours per
issue.

---

## 2. Confirming they work

After adding the jobs, wait one cycle and check hPanel's cron log, or run this
in **phpMyAdmin** against the core database:

```sql
SELECT job_name, status, started_at, finished_at, message
FROM job_runs
ORDER BY started_at DESC
LIMIT 20;
```

Expect `import` roughly every 5 minutes, `health_check` hourly and `purge` daily, all with
status `ok`. `sync` appears once a store has been connected to Shopify.

If a job never appears, the usual causes are the wrong PHP binary path, a typo
in the file path, or a PHP version older than 8.1. hPanel emails the output of
a failed cron job — read that first; it usually names the problem exactly.

---

## 3. Yearly database rotation

Raw event data is stored in **one database per calendar year**, because
Hostinger caps each database at 3 GB.

Every January a new one is needed. `health_check` starts warning 45 days
beforehand and the alert becomes urgent at 14 days, so this should never be a
surprise — but it does need doing.

**To create next year's database:**

1. hPanel → **Databases → Create new database**
2. Name it so the full result is `<prefix>_ev_<YEAR>` — for example
   `<prefix>_ev_2027`. hPanel shows the full name as you type; match it.
3. Note the username and password it generates.
4. Edit `.env` (in the folder containing `README.md`, **not** inside
   `public_html`) and add:

```
DB_SHARD_2027_USER=<the new user>
DB_SHARD_2027_PASS=<the new password>
```

5. Open `/setup.php?token=<SETUP_TOKEN>` and press **Apply migrations**. You
   will need to add `SETUP_TOKEN` back to `.env` temporarily, then remove it.

If this is missed, the system does **not** lose data. Events that cannot be
routed to a database stay in the spool and the importer stops rather than
discarding them. But nothing new reaches the dashboard until it is fixed, so
do not let the alert sit.

---

## 4. Backups

| What | Why | Frequency |
|---|---|---|
| Core database | Orders, customers, all reports and settings | Nightly |
| Event databases | Raw behavioural data | Monthly |
| `odysseus-data/secrets/master.key` | **Irreplaceable — see below** | Once, offline |

**`master.key` deserves particular care.** It decrypts the stored Shopify API
tokens. It is not in version control and cannot be regenerated. If it is lost,
every connected store must be reconnected from scratch. It lives outside the
website directory precisely so a code deployment cannot delete it.

Keep one copy somewhere offline — a password manager entry is ideal. Do not
email it, and do not put it anywhere it would be transmitted or logged.

---

## 5. Directories that must not be deleted

Runtime data lives **outside** the website folder, in `odysseus-data/`, so
that deploying new code cannot remove it:

```
odysseus-data/
├── storage/
│   ├── spool/       events captured but not yet imported — DO NOT DELETE
│   ├── processed/   already imported, kept 30 days as a safety net
│   ├── locks/       stops two cron jobs running at once
│   └── logs/
└── secrets/
    ├── master.key       decrypts Shopify tokens
    ├── salts/           makes customer matching work
    └── geoip-city.mmdb  IP-to-city lookup, ~120 MB
```

Anything in `spool/` is data that exists nowhere else yet. Order data can
always be re-fetched from Shopify; **visitor behaviour cannot be recovered
from anywhere**. If disk space is tight, `processed/` is the safe thing to
clear.

---

## 6. Security notes

**Remote MySQL should be off.** It is only needed to run migrations from
outside the server. Now that the code is deployed and connects over
`localhost`, turn it off in hPanel → Databases → Remote MySQL. Hostinger's
remote access grants default to "any host", meaning the database is reachable
from anywhere on the internet with only a password in the way.

**`SETUP_TOKEN` should normally be absent from `.env`.** With it removed,
`/setup.php` refuses to do anything at all. Add it back only while performing
setup work, and remove it afterwards.

**`.env` must stay above `public_html`.** It holds the database passwords. A
bundled `.htaccess` blocks it from being served, but the real protection is
its location.

---

## 7. Who to contact

Technical questions: Ayman Thahir, Digifyce.

Full technical design, including why the database is split by year and what
each component does: [`TECHNICAL_PLAN.md`](../TECHNICAL_PLAN.md) in the
repository.
