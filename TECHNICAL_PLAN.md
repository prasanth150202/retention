# Project Odysseus — Technical Plan

**Multi-client Shopify retention, funnel & attribution platform**

| | |
|---|---|
| **Status** | Draft for review — pre-implementation |
| **Owner** | Ayman Thahir (Digifyce) |
| **Date** | 2026-09-08 |
| **Target** | 3–5 Shopify stores in year one |
| **Platform** | Hostinger shared hosting (hPanel), PHP 8.x + MySQL 8 / MariaDB |
| **Supersedes** | `Shopify_Pixel_V2/MULTI_CLIENT_PLAN.md` (assumed Cloudflare Workers + Postgres + Next.js) |

---

## 1. Summary

One platform tracking multiple client Shopify stores end to end: page view → product view → add to cart → checkout → purchase → repeat purchase, with UTM/campaign attribution and geography, feeding a multi-tenant database and a staff-facing dashboard.

Three data feeds per store:

1. **Shopify Custom Pixel** (Settings → Customer events) — the funnel spine. The only source that can see checkout.
2. **Liquid snippet** in `theme.liquid` — logged-in customer identity, click detail, internal search. The only source that can see the DOM.
3. **Admin API** (hourly poll) — orders, customers, products, abandoned checkouts, and Shopify's own `customerJourneySummary` attribution.

They are joined by `checkout_token` and a derived `person_id`.

### 1.1 What makes this build different from a generic analytics app

The hosting platform cannot run a daemon, cannot run Node, and caps each MySQL database at 3 GB. Every significant design decision below traces back to one of those three facts. Read §3 before judging anything else.

---

## 2. Prior art in this workspace

| Artefact | Status | Disposition |
|---|---|---|
| `Shopify_Pixel_V2/collector.js` | Node + Express + better-sqlite3 | **Rewrite in PHP.** No daemons on shared hosting. |
| `Shopify_Pixel_V2/tracker/tracker.js` | 363-line liquid tracker, `standard`/`firehose` modes | **Reduce scope.** Keep identity + clicks + search; drop pageview duplication and firehose. |
| `Shopify_Pixel_V2/src/geo.js` | Per-request call to an external IP-geo API | **Replace.** Local MaxMind GeoLite2 `.mmdb`. Network calls per event are unviable. |
| `Shopify_Pixel_V2/schema.sql` | SQLite, single-tenant | **Replace.** Multi-tenant MySQL, see §6. |
| `shopify_key_fetch/shopify_token_exchange.py` | Working OAuth code exchange, HMAC + state verified | **Port to PHP.** Logic is sound; `redirect_uri` and scope list change. |
| `SHOPIFY_API_GET_CODE/` | Node/Express variant of the same flow | Reference only. |
| `Pixel Analysis Shopify/scratch/AUDIT_REPORT.md` | Independent audit of a real 31-day dataset | **Primary source of capacity and correctness requirements.** Cited throughout. |

The audit is the most valuable artefact here. It supplies real volume numbers (§14) and documents three defects this design explicitly prevents: duplicate-event revenue inflation, funnel step inversion, and channel misattribution.

---

## 3. Constraints

| Constraint | Verified? | Consequence |
|---|---|---|
| No root, no long-running processes | Yes — hPanel shared | All work is request-scoped PHP or cron. No queue daemon, no Node. |
| MySQL 3 GB **per database** | **Confirmed 2026-09-08** | Raw events shard across yearly databases (§6.1). |
| Up to 100 databases available | User-reported | Sharding viable well beyond a decade. |
| No build step on the server | Required | No Vite/npm. Server-rendered PHP + CDN libraries (§11). |
| PHP execution time limits | Assumed 60–120s web, unbounded CLI | Every cron job is chunked and resumable regardless. |
| Cron minimum interval | **Unverified** — 5 or 15 min | Ingest lag equals one interval. See §16. |
| Retain all data indefinitely | Required | Nothing is deleted. Shards rotate; rollups are permanent. |
| Reporting is aggregate-only | Decided | Dashboard reads rollups exclusively. No live queries against raw shards. |
| PII stored hashed, never recoverable | Decided | Identity matching by HMAC digest (§9). No contact export capability. |
| All INR initially | Decided | Currency columns present from day one; no FX conversion built. |

---

## 4. Architecture

```
 CLIENT STORE
   |
   +-- Shopify Custom Pixel  --------+
   |     funnel spine, checkout,     |  batched sendBeacon
   |     thank-you page              |  (text/plain, no CORS preflight)
   |                                 |
   +-- Liquid snippet in theme ------+
   |     customer.id, clicks,        v
   |     search, _shopify_y bridge   retention.digifyce.com/c.php
   |                                   - validate tenant + write key
   |                                   - Origin check vs shop domain
   |                                   - rate limit + size cap
   |                                   - append 1 line, return 204
   |                                   - NEVER touches MySQL
   |                                         |
   |                                         v
   |                            spool/<tenant>/<YYYYMMDDHH>.ndjson
   |                                         |
   |                                  cron */5  import.php
   |                                   - geo enrich (local mmdb)
   |                                   - dimension lookup/intern
   |                                   - INSERT IGNORE on event_uid
   |                                   - move file to processed/
   |                                         |
   +-- Admin API (OAuth token) ---------+    |
         cron hourly  sync.php          |    |
          - orders + line items         |    |
          - customers                   |    |
          - products                    |    |
          - abandoned checkouts         |    |
          - customerJourneySummary      |    |
                                        v    v
                              +-------------------------+
                              |  odys_core  (permanent) |
                              |  odys_ev_YYYY (shards)  |
                              +-------------------------+
                                        |
                    cron  identity_resolve.php  -> person_id, order_sequence
                    cron  attribution.php       -> 4 attribution models
                    cron  rollup_*.php          -> rollup_daily_*
                                        |
                                        v
                        retention.digifyce.com  (PHP + Alpine + ECharts)
                        reads rollups only; server-rendered
```

### 4.1 Host and paths

Single host: **`retention.digifyce.com`**. Roles are separated by path rather than subdomain.

| Path | Role | Notes |
|---|---|---|
| `/c.php` | Pixel + Liquid ingest | Standalone. No session, no auth bootstrap, no framework include. Short path because it is repeated in every beacon payload on every store. |
| `/oauth/callback.php` | OAuth redirect target | Must exactly match the Allowed redirection URL in the Partner Dashboard app config. |
| `/` | Dashboard | The only authenticated surface. |

`app/`, `config/`, `db/`, `storage/` and `secrets/` all sit **outside** the document root (§11.2).

Trade-off accepted: ingest and dashboard share a PHP worker pool, so a burst of pixel traffic competes with dashboard rendering. At 5 stores this is immaterial — ~2,200 events/hour arrive batched, and `/c.php` returns in under 10 ms without touching MySQL. If a much larger store is onboarded, moving `/c.php` to its own subdomain is a DNS change plus one config value, not a rewrite.

### 4.2 Deployment

**Hostinger Git**, not FTP. FTP is a transfer protocol, not version control — no history, no rollback, no record of what is actually running. Its characteristic failure is a hotfix applied on the server that nobody pulls back, silently overwritten on the next upload.

| | |
|---|---|
| Method | Push to GitHub → deploy from hPanel (or its webhook for auto-deploy) |
| Deploy target | `~/domains/retention.digifyce.com/` — the repo root, with `public_html/` **inside** it |
| Build step | None. Hostinger Git runs no build, which is exactly why §11.1 chose a no-build frontend. |
| Repository | `github.com/prasanth150202/retention` |
| Never in git | `.env`, `secrets/`, `storage/` — see `.gitignore` |

**Credential handling.** `config/config.php` is tracked and holds only structural settings — paths, ingest limits, storage thresholds, Shopify scopes — so they are reviewable in version control. Every credential comes from `.env` at the repo root, which is gitignored, sits above `public_html`, and is the single file the server admin fills in from `.env.example`.

A `.env` rather than a PHP config file is deliberate: a hand-edited PHP array with one missing comma is a fatal parse error that takes the whole site down, whereas a malformed `.env` line is inert data. `app/lib/Env.php` reads it with a dependency-free parser (there is no composer install on the server) and keeps values in a private array rather than `$_ENV`/`getenv()`, so a stray `phpinfo()` or `var_dump()` cannot leak the database password.

Because the document root is `.../retention.digifyce.com/public_html`, everything at the repo root outside `public_html/` is deployed but not web-reachable. That is what makes a single git deploy safely cover both public and private code.

`storage/` and `secrets/` are created once on the server by hand and are never tracked: `storage/` holds visitor data, and `secrets/master.key` must be backed up offline, because losing it makes every stored Shopify token permanently unrecoverable.

FTP remains available as an emergency hotfix path. Anything changed that way must be pulled back into git immediately, or the next deploy reverts it.

---

## 5. Data sources

### 5.1 Shopify Custom Pixel — capabilities and hard limits

The custom pixel executes in a **sandboxed iframe with its own empty `document`**. It sits adjacent to the storefront, not inside it. Everything below follows from that.

**Cannot do, at all:**

- Read the storefront DOM. No element text, no CSS selector paths, no data attributes.
- Read `{{ customer.id }}` or any Liquid value. `init.data.customer` exists in the API but is gated behind protected-customer-data access and frequently returns null.
- Scroll depth, hover, time-on-page, exit intent, element visibility — not emitted, not synthesisable.
- Read arbitrary first-party cookies. Only a restricted async `browser.cookie` API is available.
- Fire when consent is withheld in gated regions.

**Event vocabulary is fixed.** Subscribing outside this list is impossible:

`page_viewed` · `collection_viewed` · `product_viewed` · `search_submitted` · `cart_viewed` · `product_added_to_cart` · `product_removed_from_cart` · `checkout_started` · `checkout_contact_info_submitted` · `checkout_address_info_submitted` · `checkout_shipping_info_submitted` · `payment_info_submitted` · `checkout_completed` · `clicked` · `form_submitted` · `input_changed` · `input_blurred` · `input_focused` · `alert_displayed`

**Subscribed events:** all of the above **except** `input_changed`, `input_blurred`, `input_focused`, `alert_displayed`.

> The input events carry raw `element.value`. In the earlier madminimalist raw export they leaked **2,626 email addresses and roughly 30,000 phone numbers** into a CSV. We do not subscribe to them. If a future requirement forces it, `element.value` must be dropped server-side before the row is written.

`clicked` is subscribed but low-value: it exposes only `id`, `href`, `value`, `type`, `name`, `tagName` — no element text. Useful click detail comes from the Liquid feed.

### 5.2 Liquid snippet — what it adds

| Capability | Pixel | Liquid |
|---|---|---|
| Logged-in `customer.id` while browsing | ✗ | ✓ unconditional, server-rendered |
| `customer.orders_count`, `total_spent`, `tags` | ✗ | ✓ |
| Element text + selector path on click | ✗ | ✓ |
| Internal search terms with result counts | partial | ✓ |
| Read `_shopify_y` cookie (the join bridge) | ✗ | ✓ |
| **Checkout and thank-you pages** | ✓ **sole source** | ✗ **impossible** |

`theme.liquid` is storefront-only. `checkout.liquid` was Plus-only and was deprecated for the thank-you / order-status page in August 2025. There is no path to Liquid on checkout.

**Final scope of the snippet:** identity, clicks, internal search. It emits its own event namespace and **must not** emit `page_viewed` or `product_viewed` — duplicating the pixel's funnel events would double-count every step.

Estimated volume: ~80,000 events/month/store, against the pixel's ~243,000.

**Rejected:** scroll depth (~+140k events/month/store, roughly halves shard lifetime) and firehose hover tracking (10–50×, non-viable on this platform).

### 5.3 Admin API

**Scopes — 8, down from the 75 in `shopify_token_exchange.py`:**

```
read_all_orders     read_orders        read_customers     read_products
read_checkouts      read_inventory     read_price_rules   read_locales
```

Two blocking prerequisites, both requiring Partner Dashboard action before any client can onboard:

1. **`read_all_orders` must be requested in App setup.** Without it the API returns **only the last 60 days of orders**. Every retention, cohort and LTV metric in this document is empty without it.
2. **`read_customers` is protected customer data** and requires a data-use declaration on the app.

Neither is instant. Both should be filed before Phase 3 begins.

> The existing 75-scope list requests gift card transactions, Shopify Payments payouts, bank accounts, disputes, themes and translations. Beyond being unnecessary, a consent screen of that breadth is a credible reason for a client's developer to refuse the install.

### 5.4 The join model

```
  Liquid: reads _shopify_y  ─────────────┐
                                         ├──► visitor_key  (unifies both feeds)
  Pixel:  event.clientId (from _shopify_y)┘
                                         │
  Pixel checkout_completed ──► checkout.token ──┐
                                                ├──► order
  Admin API order ──────────► checkout_token ───┘
                                                │
  order ──► customer_id / phone / email ──► person_id ──► order_sequence (1st, 2nd, 3rd…)
```

The pixel's `clientId` derives from the `_shopify_y` cookie, which the Liquid snippet reads directly. That is what makes a single visitor identity across two feeds possible.

---

## 6. Database design

### 6.1 Sharding

| Database | Contents | Growth | Backup |
|---|---|---|---|
| `odys_core` | Everything except raw events | Slow — dominated by rollups, ~150–250 MB/year | Nightly, 14 days + monthly forever |
| `odys_ev_2026` | Raw events for 2026 | ~208 MB/month at 5 stores | Monthly |
| `odys_ev_2027` | Raw events for 2027 | " | Monthly |
| … | One per year, created ahead of time | | |

A single MySQL user is granted on all databases so cross-shard `UNION ALL` is possible. `shard_registry` maps date ranges to database names; the router resolves a requested range to the minimum set of databases.

In practice the dashboard never crosses shards, because it reads rollups from `odys_core`. Cross-shard queries exist only for rollup recomputation.

```sql
CREATE TABLE shard_registry (
  shard_name       VARCHAR(64)     NOT NULL,
  date_from        DATE            NOT NULL,
  date_to          DATE            NOT NULL,
  is_writable      TINYINT(1)      NOT NULL DEFAULT 1,
  bytes_used       BIGINT UNSIGNED NULL,
  bytes_checked_at DATETIME        NULL,
  PRIMARY KEY (shard_name),
  KEY ix_range (date_from, date_to)
) ENGINE=InnoDB;
```

### 6.2 Row-size budget

The 3 GB ceiling is survivable only if the events row is engineered for size. Governing rules:

1. **No raw JSON payload is ever stored.** Every event is shredded into typed columns at import. Largest single saving — storing the Shopify payload verbatim costs ~2,000 bytes/row and would exhaust a shard in about one month.
2. **Every repeating string is normalised** into a `dim_*` lookup and referenced by a 4-byte INT: page path, referrer, UTM tuple, user agent, geo, search term, click target.
3. **High-entropy identifiers are surrogated.** `visitor_id` and `session_id` arrive UUID-like; they are interned into `dim_visitor` / `dim_session` and stored as INTs. A 16-byte binary hash in the row *and* in an index costs far more than a 4-byte key plus a small dimension table.
4. **Money is stored as minor units in an INT** (paise), not `DECIMAL`.
5. **Only two secondary indexes exist**, because the dashboard never queries raw events. Indexes serve dedup and the daily rollup scan, nothing else.

| Component | Bytes |
|---|---|
| Row data (25 columns, all fixed-width) | ~121 |
| Record header + NULL bitmap | ~8 |
| `uq_event` unique index (dedup) | ~30 |
| `ix_tenant_time` (rollup scan) | ~26 |
| **Uncompressed total** | **~185** |
| **With `ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8`** | **~120–130** |

At 1.6M events/month across 5 stores: **~208 MB/month → a 3 GB shard lasts approximately 14 months.** One shard per calendar year leaves comfortable headroom.

Two indexes were deliberately **not** created — `(tenant_id, visitor_id, occurred_at)` and `(tenant_id, event_type, occurred_at)`. They would add ~58 bytes/row, cutting shard lifetime to roughly 10 months, and exist only to serve per-visitor lookups the dashboard does not perform. **If a journey-replay screen is ever added, this decision must be revisited and shard lifetime recalculated.**

### 6.3 Events table (per shard)

```sql
CREATE TABLE events (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       SMALLINT UNSIGNED NOT NULL,
  event_uid       BIGINT UNSIGNED NOT NULL,   -- 64-bit digest of the source event id
  occurred_at     DATETIME        NOT NULL,   -- client timestamp, UTC
  received_at     DATETIME        NOT NULL,   -- server timestamp, UTC
  event_type      TINYINT UNSIGNED NOT NULL,  -- enum in code, see EventType
  source          TINYINT UNSIGNED NOT NULL,  -- 1 = shopify_pixel, 2 = liquid
  visitor_key     INT UNSIGNED    NOT NULL,   -- -> dim_visitor
  session_key     INT UNSIGNED    NOT NULL,   -- -> dim_session
  person_id       BIGINT UNSIGNED NULL,       -- backfilled by identity_resolve
  customer_ref    BIGINT UNSIGNED NULL,       -- Shopify customer id, liquid only
  path_id         INT UNSIGNED    NULL,       -- -> dim_path
  referrer_id     INT UNSIGNED    NULL,       -- -> dim_referrer
  campaign_id     INT UNSIGNED    NULL,       -- -> dim_campaign
  ua_id           INT UNSIGNED    NULL,       -- -> dim_useragent
  geo_id          INT UNSIGNED    NULL,       -- -> dim_geo
  product_id      BIGINT UNSIGNED NULL,
  variant_id      BIGINT UNSIGNED NULL,
  qty             SMALLINT UNSIGNED NULL,
  amount_minor    INT UNSIGNED    NULL,       -- paise
  currency_id     TINYINT UNSIGNED NULL,
  checkout_token  BIGINT UNSIGNED NULL,       -- 64-bit digest; joins to orders
  order_ref       BIGINT UNSIGNED NULL,
  search_term_id  INT UNSIGNED    NULL,       -- -> dim_search_term
  click_target_id INT UNSIGNED    NULL,       -- -> dim_click_target
  PRIMARY KEY (id),
  UNIQUE KEY uq_event (tenant_id, event_uid),
  KEY ix_tenant_time (tenant_id, occurred_at)
) ENGINE=InnoDB
  ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8
  DEFAULT CHARSET=ascii;
```

`event_uid` is a 64-bit digest of the source event id. At ~20M rows the birthday collision probability is on the order of 1e-8, and the failure mode (one dropped duplicate) is benign.

`uq_event` permanently closes the defect the audit found: **443 duplicate rows, of which 2 duplicate `checkout_completed` pairs inflated revenue by ₹1,378 and order count by 2.** Import uses `INSERT IGNORE`; duplicates are counted and reported, never stored.

Monthly `RANGE (TO_DAYS(occurred_at))` partitioning is applied **if** the server permits it (§16). If not, the yearly shard is itself the partition; nothing is lost except cheap month-drop, which we never do.

### 6.4 Core schema — key tables

```sql
CREATE TABLE tenants (
  tenant_id        SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_domain      VARCHAR(255) NOT NULL,
  display_name     VARCHAR(128) NOT NULL,
  admin_token_enc  VARBINARY(512) NOT NULL,   -- AES-256-GCM, key outside webroot
  write_key        CHAR(32)     NOT NULL,     -- public; identifies tenant, is NOT a secret
  pii_salt_ref     VARCHAR(64)  NOT NULL,     -- names a salt held in secrets/, never stored here
  currency         CHAR(3)      NOT NULL DEFAULT 'INR',
  iana_timezone    VARCHAR(64)  NOT NULL DEFAULT 'Asia/Kolkata',
  abandon_window_h SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  status           ENUM('active','paused','disabled') NOT NULL DEFAULT 'active',
  installed_at     DATETIME     NOT NULL,
  PRIMARY KEY (tenant_id),
  UNIQUE KEY uq_shop (shop_domain)
) ENGINE=InnoDB;

CREATE TABLE persons (
  person_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    SMALLINT UNSIGNED NOT NULL,
  merged_into  BIGINT UNSIGNED NULL,          -- non-null => this person was absorbed
  first_seen   DATETIME NOT NULL,
  last_seen    DATETIME NOT NULL,
  PRIMARY KEY (person_id),
  KEY ix_tenant (tenant_id, merged_into)
) ENGINE=InnoDB;

CREATE TABLE identity_keys (
  tenant_id  SMALLINT UNSIGNED NOT NULL,
  key_type   ENUM('shopify_customer','phone','email') NOT NULL,
  key_hash   BINARY(16) NOT NULL,             -- HMAC-SHA256(normalised, tenant salt)[0:16]
  person_id  BIGINT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, key_type, key_hash),
  KEY ix_person (person_id)
) ENGINE=InnoDB;

CREATE TABLE person_merges (
  merge_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  survivor_id BIGINT UNSIGNED NOT NULL,
  absorbed_id BIGINT UNSIGNED NOT NULL,
  reason      VARCHAR(64) NOT NULL,           -- which key_type triggered it
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (merge_id),
  KEY ix_survivor (tenant_id, survivor_id)
) ENGINE=InnoDB;

CREATE TABLE orders (
  tenant_id          SMALLINT UNSIGNED NOT NULL,
  order_id           BIGINT UNSIGNED NOT NULL,
  order_number       VARCHAR(32) NULL,
  person_id          BIGINT UNSIGNED NULL,
  shopify_customer_id BIGINT UNSIGNED NULL,
  checkout_token     BIGINT UNSIGNED NULL,
  visitor_key        INT UNSIGNED NULL,       -- resolved via checkout_token
  created_at         DATETIME NOT NULL,
  processed_at       DATETIME NULL,
  cancelled_at       DATETIME NULL,
  financial_status   VARCHAR(32) NULL,
  fulfillment_status VARCHAR(32) NULL,
  currency           CHAR(3) NOT NULL,
  subtotal_minor     INT UNSIGNED NULL,
  total_minor        INT UNSIGNED NULL,
  discount_minor     INT UNSIGNED NULL,
  refunded_minor     INT UNSIGNED NOT NULL DEFAULT 0,
  order_sequence     SMALLINT UNSIGNED NULL,  -- 1 = first order for this person
  landing_site       TEXT NULL,
  referring_site     TEXT NULL,
  source_name        VARCHAR(64) NULL,
  PRIMARY KEY (tenant_id, order_id),
  KEY ix_person_created (tenant_id, person_id, created_at),
  KEY ix_checkout (tenant_id, checkout_token),
  KEY ix_created (tenant_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE order_attribution (
  tenant_id     SMALLINT UNSIGNED NOT NULL,
  order_id      BIGINT UNSIGNED NOT NULL,
  model         ENUM('pixel_first','pixel_last','shopify_first','shopify_last') NOT NULL,
  campaign_id   INT UNSIGNED NULL,
  channel       VARCHAR(64) NULL,             -- resolved via channel_rules
  landing_path_id INT UNSIGNED NULL,
  touch_at      DATETIME NULL,
  PRIMARY KEY (tenant_id, order_id, model),
  KEY ix_campaign (tenant_id, model, campaign_id)
) ENGINE=InnoDB;

CREATE TABLE order_journey_moments (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NOT NULL,
  seq         SMALLINT UNSIGNED NOT NULL,
  occurred_at DATETIME NULL,
  campaign_id INT UNSIGNED NULL,
  referrer_id INT UNSIGNED NULL,
  path_id     INT UNSIGNED NULL,
  PRIMARY KEY (tenant_id, order_id, seq)
) ENGINE=InnoDB;

CREATE TABLE channel_rules (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  priority    SMALLINT UNSIGNED NOT NULL,
  match_field ENUM('utm_source','utm_medium','utm_campaign',
                   'referrer_host','landing_path','has_gclid','has_fbclid') NOT NULL,
  match_op    ENUM('equals','contains','regex','exists') NOT NULL,
  match_value VARCHAR(255) NULL,
  channel     VARCHAR(64) NOT NULL,
  enabled     TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, priority)
) ENGINE=InnoDB;
```

Also in core: `staff_users`, `oauth_state`, `sync_cursors`, `customers`, `order_line_items`, `abandoned_checkouts`, `abandoned_checkout_items`, `products`, and the dimension tables `dim_visitor`, `dim_session`, `dim_path`, `dim_referrer`, `dim_campaign`, `dim_useragent`, `dim_geo`, `dim_search_term`, `dim_click_target`.

### 6.5 Rollup tables

All keyed `(tenant_id, day, …)`, all in `odys_core`, all permanent. These are the only tables the dashboard reads.

| Table | Grain | Serves |
|---|---|---|
| `rollup_daily_kpi` | tenant × day | Overview tiles, trend chart |
| `rollup_daily_funnel` | tenant × day × step | Funnel tab |
| `rollup_daily_campaign` | tenant × day × campaign × **model** | Campaigns tab |
| `rollup_daily_channel` | tenant × day × channel × **model** | Channel summary |
| `rollup_daily_landing` | tenant × day × path | Landing page performance |
| `rollup_daily_product` | tenant × day × product | Products tab |
| `rollup_daily_geo` | tenant × day × country/region/city | Geography |
| `rollup_daily_device` | tenant × day × device/browser/os | Device |
| `rollup_daily_abandon` | tenant × day × stage | Abandonment tab |
| `rollup_cohort_repeat` | tenant × cohort_month × days_since_first | Retention curve |
| `rollup_campaign_cohort` | tenant × acquisition_campaign × cohort_month × days_since_first | Repeat rate **by acquiring campaign** |
| `rollup_person_orders` | tenant × order_count_bucket | Lifetime 1 / 2 / 3+ distribution |

`rollup_daily_campaign` is stored **once per attribution model**, so the UI's model toggle is a `WHERE model = ?` rather than a recomputation. Sizing: 5 tenants × ~50 campaigns × 4 models × 365 days ≈ 365k rows/year at ~80 bytes ≈ **30 MB/year**. Acceptable.

`rollup_campaign_cohort` is the metric that makes this a retention platform rather than a traffic report: it answers *which campaigns acquire customers who come back*.

---

## 7. Ingestion

### 7.1 `collect.php` contract

| | |
|---|---|
| Method | `POST` |
| Content-Type | `text/plain` (deliberate — avoids a CORS preflight on `sendBeacon`) |
| Body | JSON array of event objects, max 64 KB |
| Response | `204 No Content` on accept, `400` malformed, `403` rejected, `429` rate-limited |
| Latency budget | < 10 ms p99 |
| MySQL | **Never touched** |

Processing order, fail-fast:

1. Reject if body > 64 KB.
2. Parse `write_key`, look up tenant from an in-process static map refreshed from a small generated PHP config file (not a DB query).
3. **Origin check** — `Origin`/`Referer` host must match the tenant's `shop_domain` or its custom domain. Reject otherwise.
4. **Rate limit** — file-backed token bucket keyed by `hash(ip) + minute`. Cheap, no MySQL, self-expiring.
5. Append one NDJSON line per event to `spool/<tenant_id>/<YYYYMMDDHH>.ndjson` via `file_put_contents(..., FILE_APPEND | LOCK_EX)`.
6. Return `204`.

**One rotating file per tenant per hour** — not one file per request. At 1.6M events/month, per-request files would generate ~160,000 files/month and breach the account inode limit.

### 7.2 On the "secret"

`Shopify_Pixel_V2/MULTI_CLIENT_PLAN.md` §3 embeds a per-tenant `SECRET` in the pixel snippet and describes it as authentication. **It is not.** The snippet is served to every visitor and is readable via View Source. Anyone can extract it and post fabricated events.

It is renamed `write_key` throughout this design to prevent that misreading. Its only jobs are tenant identification and filtering unsolicited noise. The actual protections are the Origin check, the rate limit and the size cap (§7.1 steps 3–5).

### 7.3 `import.php` (cron)

Runs under a `flock()` lock so overlapping invocations are impossible.

1. List `spool/*/` files whose hour has closed (never the currently-writing file).
2. Stream line by line — never `file_get_contents` a whole spool file.
3. Geo-enrich from a local **MaxMind GeoLite2-City `.mmdb`** using a pure-PHP reader. No network call, no per-event API rate limit, ~10 µs per lookup.
4. Intern dimension values (`dim_path`, `dim_campaign`, …) through a request-lifetime memo cache plus `INSERT … ON DUPLICATE KEY UPDATE`.
5. Batch `INSERT IGNORE` into the writable shard, 500 rows per statement.
6. Record per-file counts: accepted, duplicate-ignored, malformed.
7. Move the file to `processed/`. **Only on a clean commit.**

**Failure policy — fail loud, never silent.** If the shard rejects writes (size ceiling, connection failure), the file is left in `spool/`, an alert is raised, and the next run retries it. Under no circumstance is a spool file deleted or moved without a confirmed insert. This is the single most important operational rule in the system, and it is why §15's shard alarm is a Phase 1 deliverable rather than a Phase 7 one.

`processed/` files are retained **30 days** as a replay source. If an import bug corrupts derived data, the raw feed can be re-imported. This is the safety net that makes an aggressive shred-on-import design acceptable.

---

## 8. Onboarding and OAuth

Partner Dashboard app, custom distribution. Flow:

```
 1. Staff form (dash/onboard.php)
      shop domain, client_id, client_secret
        -> row in oauth_state (state nonce, expiry 15 min, client_secret held encrypted)
        -> render install URL for the client

 2. Client approves on their store

 3. Shopify -> retention.digifyce.com/oauth/callback.php?code=…&hmac=…&state=…
      a. state exists, unexpired, matches
      b. HMAC-SHA256 over sorted query params, keyed by client_secret  (constant-time compare)
      c. shop param matches the domain we initiated with
      d. POST /admin/oauth/access_token  -> access_token
      e. encrypt token (AES-256-GCM), insert tenants row
      f. seed default channel_rules
      g. queue backfill

 4. Callback page renders TWO copy-paste blocks, pre-filled with tenant_id + write_key:
      - Custom Pixel JS   -> Settings > Customer events > Add custom pixel
      - Liquid snippet    -> theme.liquid, before </body>

 5. Staff verifies: events landing, orders syncing, one checkout_token joining a test order
```

This is a direct port of `shopify_key_fetch/shopify_token_exchange.py`. Its HMAC and state verification are correct and are reproduced as-is. Two changes: `redirect_uri` moves from `http://localhost:8765/callback` to the public HTTPS callback (which must be whitelisted in the Partner app config), and the scope list is cut from 75 to 8.

**Token at rest:** AES-256-GCM via `openssl_encrypt`, key material in `secrets/master.key`, mode `0600`, outside every document root. The key is never in the database — a database dump alone must not yield usable tokens.

---

## 9. Identity resolution

Grouping orders by Shopify `customer_id` alone materially undercounts repeat buyers: guest checkout produces orders with no customer record, and the same buyer routinely appears as multiple Shopify customers. A `person_id` layer sits above Shopify's customers.

**Normalisation:**

| Key | Rule |
|---|---|
| `shopify_customer` | Numeric id as-is |
| `phone` | Strip all non-digits; drop a leading `91` when the remainder is 10 digits; take the last 10. Reject if fewer than 10. |
| `email` | `trim` + `lowercase` only. **No** Gmail dot-stripping or plus-address rewriting — both are guesses that create false merges. |

**Hashing:** `HMAC-SHA256(normalised_value, tenant_salt)` truncated to 16 bytes. HMAC rather than plain SHA-256 so a stolen database cannot be rainbow-tabled back to phone numbers; a per-tenant salt so identities cannot be correlated across clients. Salts live in `secrets/`, never in the database.

**Merge algorithm** (union-find), per order:

1. Compute all available key hashes.
2. Look up existing `person_id`s in `identity_keys`.
3. Zero matches → create a person, insert keys.
4. One match → attach.
5. **Two or more matches → merge.** Lowest `person_id` survives; absorbed rows get `merged_into` set; all `identity_keys` repoint to the survivor; a `person_merges` row records which key type triggered it.
6. Recompute `order_sequence` for the survivor.

```sql
UPDATE orders o
JOIN (
  SELECT order_id,
         ROW_NUMBER() OVER (PARTITION BY person_id ORDER BY created_at, order_id) AS rn
  FROM orders
  WHERE tenant_id = :t AND person_id = :p AND cancelled_at IS NULL
) r ON r.order_id = o.order_id
SET o.order_sequence = r.rn
WHERE o.tenant_id = :t;
```

**Known limitations, to be stated in any client-facing methodology note:**

- Shared household or family phone numbers will occasionally merge two real people.
- Orders with neither phone nor email remain singletons and depress the measured repeat rate.
- Cancelled orders are excluded from sequencing. Refunded orders are **included** by default (the purchase occurred); this is a per-tenant setting.
- Merges are logged and therefore reversible, but reversal is a manual operation.

---

## 10. Attribution

### 10.1 Four models, stored side by side

| Model | Source | Notes |
|---|---|---|
| `pixel_first` | Earliest `page_viewed` campaign for the visitor | Our own first-touch |
| `pixel_last` | Campaign on the last session preceding the order | Our own last-touch |
| `shopify_first` | `customerJourneySummary.firstVisit` | Survives cookie clearing and ad blockers |
| `shopify_last` | `customerJourneySummary.lastVisit` | " |

Shopify's `customerJourneySummary` also yields `daysToConversion` and `momentsCount`, feeding the "visits before purchase" panel with no pixel dependency whatsoever. `moments` are stored in `order_journey_moments` — roughly 312 orders/month/store × ~10 moments ≈ 3k rows/month/store, negligible.

> **Verify in Phase 3:** whether `customerJourneySummary` is retrievable inside a bulk operation. If not, it is fetched per-order on the incremental sync only, and backfilled orders carry pixel attribution alone. `ready: false` responses must be re-queued, not treated as null.

Storing all four is cheap at rollup grain and lets a client be shown *"first-touch says Instagram, Shopify says organic search"* rather than one number presented as truth.

### 10.2 Channel rules engine

The audit found that a hardcoded `CASE WHEN` checking Instagram before Facebook booked **392 visitors who had Instagram UTMs and a Facebook referrer entirely to Instagram**. That class of defect is invisible in a query nobody re-reads.

Rules therefore live in `channel_rules`, ordered by `priority`, first match wins. The Campaigns tab exposes them as a reorderable list with a **live reclassification preview** showing how many visitors move before the change is saved. Raw `utm_source` / `utm_medium` remain visible alongside the grouped channel at all times, as the ungrouped source of truth.

**Unattributed diagnostics.** The audit's Direct/Untracked bucket was 1,437 visitors converting at 0.07% — a figure inconsistent with genuine direct traffic and far more consistent with stripped attribution. The tab therefore splits it into *recoverable* (a `gclid` or `fbclid` is present but UTMs were stripped) and *genuinely unknown*.

---

## 11. Frontend

### 11.1 Stack and rationale

| Layer | Choice |
|---|---|
| Rendering | Server-rendered PHP templates |
| Interactivity | Alpine.js 3, CDN |
| Charts | Apache ECharts 5, CDN |
| State | Query-string driven; filter change is a full page load |
| Build step | **None** |

**Why no build step**, given local builds were technically permitted: deployment is a file copy in hPanel; a query can be fixed on the server from a phone; there is no `src/` versus `dist/` divergence and no ambiguity about which version is deployed; there is no `node_modules` to rot over a year of low-touch maintenance. A dashboard of this shape is ~90% server-rendered tables and charts, so an SPA buys little and costs a laptop dependency on every change.

**Why ECharts over Chart.js:** funnel charts and cohort heatmaps are both required and Chart.js supports neither natively. ECharts also ships a geo map, removing a third dependency.

**Why full page loads over AJAX:** filter state is bookmarkable and shareable, there is no client-side state to desynchronise, and every view is trivially reproducible from a URL when debugging a client's question.

### 11.2 Layout

Repo root deploys to `~/domains/retention.digifyce.com/`. Only `public_html/` is web-reachable.

```
public_html/               <- document root, the ONLY public directory
    index.php                  dashboard router
    c.php                      ingest endpoint (standalone, no includes but config)
    oauth/callback.php
    assets/app.css
    assets/app.js              Alpine components only

app/                       <- deployed, not web-reachable
    views/     overview funnel campaigns products retention abandonment geo stores
    lib/       Config Db Shard Auth Crypto Hash EventType
               ShopifyClient Identity Attribution Rollup
    cron/      import sync rollup_today rollup_reclose identity_resolve
               attribution health_check shard_check
config/
    config.php                 tracked — structural config, NO secrets
db/
    migrations/                001_core  002_events_shard  003_seed_defaults
bin/
    migrate.php  keygen.php
.env.example               <- tracked template
.env                       <- NOT in git, the only file the admin edits

storage/                   <- NOT in git, created on the server
    spool/  processed/  failed/  locks/  logs/
secrets/                   <- NOT in git, 0600, backed up OFFLINE
    master.key  salts/  GeoLite2-City.mmdb
```

### 11.3 The eight tabs

Every tab follows one structure: **4 KPI tiles → 1 primary chart → 1 dense sortable table with expandable rows → a collapsed Methodology accordion** stating the exact definition and query behind each figure. The accordion keeps the surface clean while making every number defensible in a client review.

Global filters: any-date-to-any-date, store switcher, device, channel, new vs returning.

| Tab | Primary chart | Main table |
|---|---|---|
| **Overview** | KPI trend | Day-by-day summary |
| **Funnel** | ECharts funnel, dual-count (§12.1) | Step, reached, strict-path, drop-off % |
| **Campaigns & UTM** | Revenue by channel, stacked area, model toggle | source/medium/campaign/content/term × 11 metrics |
| **Products** | Top products by revenue | Views, ATC, purchases, units, revenue, abandons, view→cart→buy |
| **Retention** | Cohort heatmap | Lifetime 1/2/3+ distribution, median days between orders |
| **Abandonment** | Checkout micro-funnel (§12.2) | Most abandoned products, value at risk |
| **Geography & Device** | ECharts geo map | City/region, device, browser, OS |
| **Stores** | — | Onboarding, snippet display, sync status, channel rules editor |

### 11.4 Campaigns & UTM — detail

Requested as a first-class tab.

**KPI tiles:** tracked revenue %, top campaign by revenue, blended visitor→order CVR, unattributed share.

**Main table columns:** visitors · sessions · product views · add-to-cart · checkouts started · orders · CVR · revenue · AOV · new vs returning customers acquired · **90-day repeat rate of customers this campaign acquired**.

That last column is the tab's reason for existing. Rows expand into landing pages, then into products purchased.

**Supporting panels:**

1. **Channel rules editor** — reorderable, with live reclassification preview.
2. **Attribution comparison** — the same orders under all four models side by side, making disagreement explicit rather than discovered mid-meeting.
3. **Unattributed diagnostics** — recoverable versus genuinely unknown.
4. **Path to purchase** — `daysToConversion` and `momentsCount` distributions.

**Deliberately not built now:** ad spend entry and ROAS. It is the obvious next step and the schema leaves room, but it requires a spend-entry UI or ad-platform integrations that are out of scope.

---

## 12. Metric definitions

The most review-sensitive section. Every dashboard figure resolves to exactly one row here, and the Methodology accordion in the UI renders from it.

### 12.1 Funnel

Distinct visitors per step per day, from pixel events.

| Step | Event |
|---|---|
| 1 Visitors | `page_viewed` |
| 2 Product viewers | `product_viewed` |
| 3 Added to cart | `product_added_to_cart` |
| 4 Reached checkout | `checkout_started` |
| 5 Entered payment | `payment_info_submitted` |
| 6 Purchased | `checkout_completed` |

**Steps are not nested, and this is not a defect.** In the audited dataset:

| Step | Visitors |
|---|---|
| Viewed a page | 7,772 |
| Viewed a product | 4,688 |
| Added to cart | 746 |
| **Reached checkout** | **1,182** ← exceeds the step above |
| Purchased | 306 |

Shopify's *Buy it Now*, Shop Pay, PayPal and Google Pay express buttons take a shopper from the product page directly to checkout without ever adding to cart. 436 shoppers did exactly that.

Every funnel view therefore reports **two counts per step**:

- **Reached** — visitors who fired this step's event at all.
- **Strict path** — visitors who fired every prior step in order.

plus an explicit callout: *"436 visitors used express checkout and skipped the cart."* This converts a chart that reads as broken into a quantified fact about express-checkout usage.

### 12.2 Abandonment

Three distinct figures, never merged into one "abandoned cart rate".

| Metric | Numerator | Denominator | Source | Window |
|---|---|---|---|---|
| **Cart abandonment** | Added to cart, never reached checkout | Visitors who added to cart | Pixel | `abandon_window_h`, default 24h |
| **Checkout abandonment** | Reached checkout, never completed | Visitors who reached checkout | Pixel | same |
| **Recoverable abandoned checkouts** | Shopify abandoned-checkout records not converted | Shopify checkouts created | Admin API | Shopify's own |

The third tile carries a **"matches Shopify Admin → Abandoned checkouts"** badge. This is its purpose: when a client says *"Shopify shows 45 and you show 876"*, one tile matches Shopify exactly and the discrepancy is explained rather than argued.

**Why the numbers legitimately differ:** Shopify only creates an abandoned-checkout record once the shopper submits contact information. A shopper who lands on checkout and leaves immediately never generates one. Shopify structurally cannot see the earliest and largest portion of checkout drop-off. The pixel can.

That gap is presented as the **checkout micro-funnel**, the Abandonment tab's primary chart:

```
checkout_started                   1,182
   |   <- invisible to Shopify entirely
contact_info_submitted                 ?
   |   <- Shopify's records begin here
address_info_submitted                 ?
   |
shipping_info_submitted                ?
   |   <- typically the largest drop in Indian D2C (shipping cost reveal)
payment_info_submitted                 ?
   |
checkout_completed                   306
```

Each step shows count, % of previous, and % of `checkout_started`. Abandonment stops being one contested number and becomes five honest ones, each pointing at a specific fix: a large drop at shipping is a shipping-cost problem; a large drop at payment is a payment-options problem.

**Window rationale.** 24 hours by default. A shopper who adds at 23:00 and buys at 09:00 has not abandoned. A 7-day window would leave yesterday's figure mutating for a week, which erodes trust in a dashboard faster than any inaccuracy. The most recent 24 hours are flagged **provisional** in the UI. Stored per tenant as `tenants.abandon_window_h`.

**Most abandoned products:** primary source is line items on Shopify's abandoned checkouts (carries real price and quantity); secondary source is pixel add-to-carts that never converted, covering the shoppers Shopify never saw. Both shown, both labelled.

### 12.3 Retention

| Metric | Definition |
|---|---|
| Lifetime order distribution | Count of persons with exactly 1, 2, 3, 4+ non-cancelled orders |
| Cohort repeat rate | Of persons whose `order_sequence = 1` fell in month M, the % with `order_sequence ≥ 2` within 30 / 60 / 90 / 180 days |
| Median days between orders | Median of `created_at(n+1) − created_at(n)` across all consecutive pairs |
| Repeat rate by acquiring campaign | Cohort repeat rate partitioned by the campaign attributed to the person's first order |

Lifetime distribution is easy to read but structurally flatters older cohorts, which have had longer to reorder. The cohort curve is the comparable one. Both are shown, with that caveat in the Methodology accordion.

### 12.4 Revenue

| Metric | Definition |
|---|---|
| Revenue | `SUM(total_minor)` on non-cancelled orders, minor units, divided for display |
| Net revenue | Revenue − `refunded_minor` |
| AOV | Revenue ÷ non-cancelled order count |
| CVR | Distinct purchasers ÷ distinct visitors, same period |

All monetary values are stored and summed as integer minor units. Floating-point currency arithmetic appears nowhere in the codebase.

---

## 13. Scheduled jobs

| Schedule | Job | Lock | Notes |
|---|---|---|---|
| `*/5 * * * *` | `import.php` | yes | Spool → events. Drops to `*/15` if the plan enforces it. |
| `0 * * * *` | `sync.php` | yes per tenant | Admin API incremental, cursor-based |
| `*/15 * * * *` | `rollup_today.php` | yes | Current day only |
| `30 2 * * *` | `rollup_reclose.php` | yes | Full recompute of the last **3 days** |
| `0 3 * * *` | `identity_resolve.php` | yes | New/changed orders → persons, merges, sequences |
| `15 3 * * *` | `attribution.php` | yes | Four models per new order |
| `45 3 * * *` | `health_check.php` | no | Alerts, §15 |
| `0 4 1 * *` | `shard_check.php` | yes | Ensure next shard exists and is migrated |

**Three-day reclose** exists because both feeds arrive late: spool files can be retried across hours, and Shopify orders are updated (payment capture, refunds, cancellations) after creation. Any rollup within 3 days of now is provisional and recomputed nightly.

**Every job holds a `flock()` lock.** On shared hosting a slow run will otherwise still be executing when the next fires, and concurrent rollup writers produce silently wrong aggregates.

**Every job is chunked and resumable** via `sync_cursors`, regardless of whether CLI PHP enforces a time limit, so that a mid-run kill is always recoverable.

### 13.1 Admin API sync

**Backfill (once per tenant, full history):** GraphQL **bulk operations**. Submit the query, Shopify materialises JSONL server-side, poll `currentBulkOperation`, stream the result down and parse line by line. This sidesteps PHP execution limits entirely — the correct answer to "pull all history" on a platform that cannot run a long process. Only one bulk operation may run per shop at a time; the queue is serialised per tenant.

**Incremental (hourly):** cursor-paginated GraphQL filtered on `updated_at > watermark`, watermark held per tenant per resource in `sync_cursors`, advanced only after a chunk commits.

**Rate limiting:** Shopify's GraphQL limits are cost-based and vary by plan. The client reads `extensions.cost.throttleStatus` from every response and sleeps against `currentlyAvailable` rather than assuming fixed numbers.

---

## 14. Capacity

Basis: `Pixel Analysis Shopify/scratch/AUDIT_REPORT.md` — madminimalist.com, 2026-05-20 to 2026-06-20.

| Measured | Value |
|---|---|
| Pixel events, 31 days, 1 store | 242,910 |
| Unique visitors | 7,793 |
| Orders | 312 (deduplicated; 314 as originally reported) |

| Projected | Value |
|---|---|
| Pixel events / store / month | ~243,000 |
| Liquid events / store / month | ~80,000 |
| Total / store / month | ~323,000 |
| **Total, 5 stores / month** | **~1,600,000** |
| Bytes per row, compressed | ~130 |
| **Storage / month** | **~208 MB** |
| **3 GB shard lifetime** | **~14 months** |
| Rollups, all tables | ~150–250 MB/year |
| `odys_core` after 5 years | well under 3 GB |

Headroom: a 2× traffic increase across all stores shortens shard life to ~7 months, which the yearly-shard scheme absorbs by rotating semi-annually — a `shard_registry` row, not a redesign.

---

## 15. Security, privacy, operations

### 15.1 Security

| Surface | Control |
|---|---|
| Admin API tokens | AES-256-GCM, key in `secrets/master.key` (0600, outside webroot), never in the database |
| PII | HMAC-SHA256 + per-tenant salt, truncated to 16 bytes. Raw email/phone never written. |
| Pixel endpoint | Origin check, per-IP rate limit, 64 KB size cap. The `write_key` is explicitly not a credential (§7.2). |
| OAuth callback | `state` nonce with 15-minute expiry, constant-time HMAC comparison, shop-domain match |
| Staff auth | `password_hash()` bcrypt, session cookie `HttpOnly` + `Secure` + `SameSite=Lax`, CSRF token on every POST |
| SQL | Prepared statements throughout. The query layer refuses to execute a tenant-scoped query without a bound `tenant_id`. |
| Multi-tenancy | Enforced in the query layer, not by convention. MySQL has no RLS here, so the guard is code-level and must be tested. |

### 15.2 Privacy

- No raw contact details are stored anywhere, so the dashboard cannot export a contact list. This is a deliberate capability reduction that removes most DPDP exposure.
- The pixel does not subscribe to input-value events (§5.1).
- Under India's DPDP Act, Digifyce is a processor acting for each client. A data processing agreement per client and disclosure of sub-processors (Hostinger, MaxMind) remain required.
- Shopify's consent gating applies in EEA/UK. It does not apply to India by default; a notice-and-consent position for Indian traffic is a client-side decision the Liquid snippet must respect once made.
- Deletion on request is supported by `person_id` and `visitor_key`, across both core and shards.

### 15.3 Monitoring — `health_check.php`

Email alert on any of:

| Condition | Threshold |
|---|---|
| Shard size | > 2.4 GB (80% of ceiling) |
| Spool backlog | oldest unprocessed file > 30 min, or > 500 files |
| Sync lag | any tenant > 3 hours since last successful sync |
| Rollup staleness | last successful run > 2 hours |
| Import errors | any malformed or failed rows in the last hour |
| Token invalid | any `401` from the Admin API |

**The shard alarm is a Phase 1 deliverable, not Phase 7.** The failure it prevents is the worst one available: the shard fills, MySQL refuses writes, and if the importer's error path is wrong, events are discarded permanently. Discovery would come weeks later via a flat dashboard, and the data would be unrecoverable. Pairing the alarm with the fail-loud policy of §7.3 is what makes this safe from the first week of ingest.

### 15.4 Backups

| Target | Frequency | Retention |
|---|---|---|
| `odys_core` | Nightly `mysqldump`, gzipped | 14 daily + 1 monthly forever |
| Event shards | Monthly | Forever |
| `secrets/` | On change, offline | Forever — **losing `master.key` renders every stored token unrecoverable** |
| `spool/processed/` | Continuous | 30 days, as a replay source |

---

## 16. Open questions — verify before or during Phase 0

Each has a stated fallback, so none blocks the start of work.

| # | Question | Fallback if unfavourable |
|---|---|---|
| 1 | Cron minimum interval on this plan (5 or 15 min)? | Ingest lag becomes 15 min. No design change. |
| 2 | Is InnoDB `RANGE` partitioning available? | Yearly shard is the partition. No functionality lost. |
| 3 | Is `ROW_FORMAT=COMPRESSED` permitted? | Row grows to ~185 bytes; shard life falls to ~9 months; rotate semi-annually. |
| 4 | Is `LOAD DATA LOCAL INFILE` enabled? | Fall back to 500-row batched `INSERT IGNORE`. Slower, still adequate. |
| 5 | ~~Is the 3 GB limit per database or account-wide?~~ | **RESOLVED 2026-09-08 — per database.** Yearly sharding stands; raw events remain in MySQL. No design change. |
| 6 | PHP version and extensions (`openssl`, `pdo_mysql`, `mbstring`, `zlib`)? | `openssl` is non-negotiable for token encryption. |
| 7 | Does `customerJourneySummary` work inside bulk operations? | Per-order fetch on incremental sync only; backfilled orders carry pixel attribution alone. |
| 8 | Account inode limit? | Governs `processed/` retention; reduce from 30 days if tight. |

Question 5 was the only one that changes the architecture. It is resolved.

### 16.1 Schema refinements made during implementation

| Change | Reason |
|---|---|
| `session_key` **removed** from `events`; `dim_session` dropped | Sessions are derived at rollup time with a window function over `(visitor_key, occurred_at)`. Assigning a session at import is fragile because spool files can be retried out of order, which would split or merge sessions incorrectly. Removing it also saves 4 bytes/row and one dimension table. Session-scoped attribution (landing page, referrer) is unaffected — it is computed in the same rollup pass. |
| Database names are config-driven | Hostinger prefixes every database with the account id (`u123456789_`). The documented names (`odys_core`, `odys_ev_2026`) are logical names mapped to real ones in config. |
| Migration runner cannot create databases | hPanel shared hosting does not permit `CREATE DATABASE` over SQL. The runner detects a missing shard and prints the exact name to create in hPanel, then proceeds. See §17 M1 runbook. |

---

## 17. Build phases

| Phase | Deliverable | Acceptance |
|---|---|---|
| **0** | Core schema, shard registry, secrets, staff login, query layer with tenant guard | Schema migrates clean; a tenant-scoped query without `tenant_id` throws |
| **1** | `collect.php`, spool, `import.php`, geo, **health_check + shard alarm** | Events from one store land in a shard; a forced shard-full condition retains spool files and alerts |
| **2** | OAuth onboarding, token encryption, snippet generation | A real store onboards end to end; token decrypts and authenticates |
| **3** | Bulk backfill, hourly sync, `customerJourneySummary` | Full order history present; hourly delta within 1 hour of Shopify |
| **4** | Identity resolution, `order_sequence`, four attribution models | Known repeat customer resolves to one `person_id` across guest and account orders |
| **5** | All rollup jobs, 3-day reclose | Rollup totals reconcile to raw within ±0 for a closed day |
| **6** | Eight dashboard tabs | Every figure traceable to §12; funnel shows dual counts; abandonment shows three tiles |
| **7** | Hardening — backups, rotation runbook, DPDP notice, methodology copy | Restore rehearsed from backup; shard rotation performed once in anger |

Phases 0–1 produce live pixel data from one store. Phase 3 is where revenue and campaign figures first appear. Phase 4 is where retention becomes real.

---

## 18. Decisions log

| # | Decision | Rationale |
|---|---|---|
| 1 | PHP + MySQL, no Node | Platform cannot run daemons |
| 2 | Yearly event-DB shards | 3 GB per-database ceiling |
| 3 | Shred events at import, never store raw JSON | 15× storage difference; sole reason a 3 GB shard lasts 14 months |
| 4 | Only two indexes on `events` | Dashboard reads rollups only. **Revisit if journey replay is ever added.** |
| 5 | Spool to file, import by cron | Ingest survives MySQL being unavailable; avoids connection limits |
| 6 | One spool file per tenant per hour | Per-request files would breach the inode limit |
| 7 | Local GeoLite2 over an IP-geo API | Per-event network calls are unviable |
| 8 | Both pixel and Liquid feeds | Neither alone covers both checkout and identity |
| 9 | Liquid limited to identity + clicks + search | Scroll/firehose would halve shard lifetime for low value |
| 10 | Hourly polling, no webhooks | User decision. Webhooks remain an additive change. |
| 11 | Four attribution models stored side by side | Cheap at rollup grain; makes disagreement visible |
| 12 | Channel rules in a table, not a `CASE` | The audit's 392-visitor misattribution must not recur silently |
| 13 | `person_id` above Shopify `customer_id` | Guest checkout otherwise undercounts repeat buyers |
| 14 | Hashed PII, no raw contacts | No export requirement; removes most DPDP exposure |
| 15 | Three abandonment figures, never one | One must match Shopify's admin exactly or the dashboard loses credibility |
| 16 | 24-hour abandonment window, per-tenant | Longer windows leave yesterday's number mutating for a week |
| 17 | No build step; PHP + Alpine + ECharts | Optimised for solo maintenance over FTP |
| 18 | Shard alarm in Phase 1, not Phase 7 | Silent data loss is possible from week one |
| 19 | `write_key`, not `SECRET` | The prior plan's naming implied a guarantee a public string cannot provide |
| 20 | 8 scopes, not 75 | Least privilege; a 75-scope consent screen invites refusal |

---

## 19. Explicitly out of scope

- Client-facing logins. Schema and query layer are tenant-scoped from day one so this is additive, but no accounts, roles or branding are built.
- Individual visitor journey replay. This is what permits the two-index events table (§6.2).
- Ad spend and ROAS.
- Multi-currency conversion. Columns exist; no FX logic.
- Webhooks.
- Contact export or any outreach capability.
