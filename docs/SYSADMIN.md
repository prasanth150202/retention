# Project Odysseus — server administration

Handover notes for whoever runs the Hostinger account. Everything here is done
in hPanel; no shell access is required.

**Host:** `retention.digifyce.com` · **Stack:** PHP 8 + MariaDB · **Deploy:** Hostinger Git

---

## 1. Scheduled jobs

Seven cron entries. Without them the site still accepts data but nothing is
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

### The seven jobs

Replace `<account>` and `<site>` with the real values. All seven take an
absolute path — cron does not run from the site directory.

Four of them form an hourly chain that must run in this order:

```
sync  ->  identity  ->  attribute  ->  rollup
:00        :15           :30            :45
```

Each uses what the one before it worked out — attribution needs to know who a
buyer is, and the rollups need to know which campaign got the credit. Fifteen
minutes apart is generous for the volumes involved, and each job takes a lock,
so an overrunning job is never run twice; the next hour picks up the remainder.

| Schedule | Command | Purpose |
|---|---|---|
| `*/5 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/import.php` | Move captured events into the database |
| `0 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/sync.php` | Pull orders and customers from Shopify |
| `15 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/identity.php` | Work out which orders belong to the same person |
| `30 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/attribute.php` | Work out which campaign each order came from |
| `45 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/rollup.php` | Aggregate everything into the tables the dashboard reads |
| `5 * * * *` | `/usr/bin/php /home/<account>/domains/<site>/public_html/app/cron/health_check.php --quiet` | Watch for silent failures and email alerts |
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

**`rollup.php`** — aggregates events and orders into the tables the dashboard
reads. Nothing in the UI queries raw events, so if this stops, the dashboard
freezes while data keeps arriving: it looks like a quiet week rather than a
fault. Days inside a three-day window are recomputed on every run, because late
events and refunds keep arriving; older days are written once and left alone.

On a store with history the first run has a lot of days to get through, so it
does ten at a time and continues on the next run. That is deliberate — a single
run that tried to do a year would hit the host's time limit and finish nothing.

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

---

## 8. Billing

Shopify runs the subscription. It takes the trial, the money, the retries on a
failed payment, and the cancellation when a merchant uninstalls. This app only
reads that state back, so there is nothing here to reconcile and no payment
data on this server.

Four settings in `.env`:

| Setting | Meaning |
|---|---|
| `BILLING_ENABLED` | **Leave `false` until the App Store listing is live.** With it on, any store without a subscription is locked out of the dashboard — including your own test stores, with no explanation on screen. |
| `BILLING_TEST` | Creates subscriptions that bill nothing. Needed for development and for Shopify's app review. **Must be `false` in production**, or nobody is ever charged. |
| `BILLING_PRICE` / `BILLING_CURRENCY` | Must match the App Store listing exactly. A mismatch is a rejection. |
| `BILLING_TRIAL_DAYS` | Nothing is charged during the trial and uninstalling cancels it. |

**Tracking does not stop when billing lapses.** Only the dashboard is gated.
Events keep being collected and rolled up, because a gap in a merchant's
history is permanent and they may subscribe later. Data for stores that
*uninstall* is a different question, governed by `UNINSTALL_PURGE_DAYS`.

### If a merchant says they have paid but cannot get in

1. The `app_subscriptions/update` webhook is what unlocks the dashboard
   immediately. If it did not arrive, the hourly `sync.php` re-reads billing
   state for any store not checked in six hours — so waiting an hour fixes it
   on its own.
2. To force it now, run `sync.php` by hand.
3. The store's billing history is on their Plan page, and in the
   `billing_events` table: every status change, when it happened, and whether
   it came from a webhook, a scheduled read, or the app itself.

`billing_events` is not an accounting record. Shopify is the authority on what
was actually charged. It exists so a question about a charge has something to
look at.

---

## 9. Two database settings that matter

### Strict mode is set by the application, not the server

The Hostinger MySQL server runs **without** strict mode:

```
NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION
```

Without strict mode MySQL does not reject a value that will not fit — it
coerces it and carries on. An order total larger than its column is clamped to
the column maximum, an over-long string is truncated, an impossible date
becomes zeroes. Each writes a wrong number with no error anywhere, and every
figure derived from it is then confidently incorrect.

The application therefore sets strict mode **on every connection it opens**, so
it does not depend on how the server is tuned:

```sql
SET SESSION sql_mode = 'STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'
```

Nothing needs doing about this. It is written down because if somebody ever
changes the server's global mode, or wonders why an insert now errors where it
used to pass, this is why — and the erroring version is the correct one.

`bin/selftest.php` verifies it by writing an out-of-range value to a temporary
table and requiring the write to fail.

### If a value ever is too large

`orders.total_minor` is `INT UNSIGNED`: about ₹4.29 crore, or $42.9M, for a
single order. Beyond any plausible order for this market. With strict mode on,
an order above it fails loudly and appears in `job_runs` rather than being
silently clamped — so if that ever shows up in the health check, the column
needs widening to `BIGINT UNSIGNED`, not the strictness relaxing.

---

## 10. A new store's first events

The ingest endpoint resolves a store's write key from a generated file
(`storage/tenants.php`) rather than a database query, so that a page view never
touches MySQL. That file is rewritten:

- by `import.php`, every run, and
- by the OAuth callback, the moment a store installs.

The second one matters. On a key it does not recognise the endpoint rebuilds
the file — but not if it was rebuilt in the last minute, because otherwise
anyone posting random keys could force a query and a file write per request. A
store that installed inside that minute would have its first events refused,
and the pixel uses `sendBeacon`, which does not retry.

If a newly installed store appears to be collecting nothing, check that
`storage/tenants.php` exists, is writable, and contains that store's write key.
Running `import.php` by hand rebuilds it.
