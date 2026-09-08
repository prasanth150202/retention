-- =====================================================================
-- Project Odysseus — core schema
-- Target: odys_core  (logical name; real name is config-driven, see §16.1)
-- Engine: InnoDB, MySQL 8 / MariaDB 10.4+
--
-- Conventions
--   * All DATETIME values are UTC. Display timezone is per-tenant.
--   * All money is stored as INT minor units (paise). No DECIMAL, no float.
--   * No FOREIGN KEYs: shards live in other databases, and FK overhead on
--     shared hosting is not worth it. Referential integrity is enforced in
--     the query layer.
--   * Every tenant-scoped table leads its PRIMARY KEY with tenant_id.
--   * Hash columns are BINARY(16) = first 16 bytes of an HMAC/SHA digest.
-- =====================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
  filename    VARCHAR(191) NOT NULL,
  applied_at  DATETIME     NOT NULL,
  checksum    CHAR(64)     NOT NULL,
  PRIMARY KEY (filename)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;


-- ---------------------------------------------------------------------
-- Tenancy, auth, ops
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS tenants (
  tenant_id         SMALLINT UNSIGNED NOT NULL AUTO_INCREMENT,
  shop_domain       VARCHAR(191)   NOT NULL,          -- foo.myshopify.com
  custom_domain     VARCHAR(191)   NULL,              -- foo.com, for Origin checks
  display_name      VARCHAR(128)   NOT NULL,
  admin_token_enc   VARBINARY(512) NULL,              -- AES-256-GCM; NULL until OAuth (M2)
  token_scopes      TEXT           NULL,
  write_key         CHAR(32)       NOT NULL,          -- PUBLIC. Identifies tenant. NOT a secret.
  pii_salt_ref      VARCHAR(64)    NOT NULL,          -- names a salt in secrets/; salt is never stored here
  currency          CHAR(3)        NOT NULL DEFAULT 'INR',
  iana_timezone     VARCHAR(64)    NOT NULL DEFAULT 'Asia/Kolkata',
  abandon_window_h  SMALLINT UNSIGNED NOT NULL DEFAULT 24,
  refunded_counts_as_order TINYINT(1) NOT NULL DEFAULT 1,
  status            ENUM('active','paused','disabled') NOT NULL DEFAULT 'active',
  installed_at      DATETIME       NOT NULL,
  backfill_state    ENUM('pending','running','done','failed') NOT NULL DEFAULT 'pending',
  PRIMARY KEY (tenant_id),
  UNIQUE KEY uq_shop (shop_domain),
  UNIQUE KEY uq_write_key (write_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS staff_users (
  user_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(191) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,                -- password_hash(), bcrypt
  display_name  VARCHAR(128) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  created_at    DATETIME     NOT NULL,
  last_login_at DATETIME     NULL,
  PRIMARY KEY (user_id),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- OAuth handshake state. Rows expire in 15 minutes and are swept by cron.
CREATE TABLE IF NOT EXISTS oauth_state (
  state             CHAR(48)       NOT NULL,
  shop_domain       VARCHAR(191)   NOT NULL,
  client_id         VARCHAR(191)   NOT NULL,
  client_secret_enc VARBINARY(512) NOT NULL,
  created_at        DATETIME       NOT NULL,
  expires_at        DATETIME       NOT NULL,
  consumed_at       DATETIME       NULL,
  PRIMARY KEY (state),
  KEY ix_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS shard_registry (
  shard_name        VARCHAR(64)     NOT NULL,         -- logical name, e.g. odys_ev_2026
  physical_name     VARCHAR(128)    NOT NULL,         -- real DB name incl. host prefix
  date_from         DATE            NOT NULL,
  date_to           DATE            NOT NULL,
  is_writable       TINYINT(1)      NOT NULL DEFAULT 1,
  is_provisioned    TINYINT(1)      NOT NULL DEFAULT 0, -- DB exists and tables created
  bytes_used        BIGINT UNSIGNED NULL,
  bytes_limit       BIGINT UNSIGNED NOT NULL DEFAULT 3221225472,  -- 3 GiB
  bytes_checked_at  DATETIME        NULL,
  PRIMARY KEY (shard_name),
  KEY ix_range (date_from, date_to)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS sync_cursors (
  tenant_id     SMALLINT UNSIGNED NOT NULL,
  resource      VARCHAR(48)  NOT NULL,                -- orders | customers | products | checkouts
  watermark_at  DATETIME     NULL,                    -- updated_at high-water mark
  cursor_token  VARCHAR(512) NULL,                    -- GraphQL page cursor, mid-run
  bulk_op_id    VARCHAR(128) NULL,                    -- in-flight bulk operation
  last_run_at   DATETIME     NULL,
  last_ok_at    DATETIME     NULL,
  last_error    TEXT         NULL,
  PRIMARY KEY (tenant_id, resource)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS job_runs (
  job_run_id  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_name    VARCHAR(64)  NOT NULL,
  tenant_id   SMALLINT UNSIGNED NULL,
  started_at  DATETIME     NOT NULL,
  finished_at DATETIME     NULL,
  status      ENUM('running','ok','failed','skipped_locked') NOT NULL DEFAULT 'running',
  rows_in     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rows_out    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  message     TEXT         NULL,
  PRIMARY KEY (job_run_id),
  KEY ix_job_time (job_name, started_at),
  KEY ix_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- One row per spool file. This is the ingest audit trail: a file is only
-- moved to processed/ after its row here reaches status='ok'.
CREATE TABLE IF NOT EXISTS import_log (
  import_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    SMALLINT UNSIGNED NOT NULL,
  spool_file   VARCHAR(255) NOT NULL,
  shard_name   VARCHAR(64)  NULL,
  accepted     INT UNSIGNED NOT NULL DEFAULT 0,
  duplicates   INT UNSIGNED NOT NULL DEFAULT 0,
  malformed    INT UNSIGNED NOT NULL DEFAULT 0,
  started_at   DATETIME     NOT NULL,
  finished_at  DATETIME     NULL,
  status       ENUM('running','ok','failed') NOT NULL DEFAULT 'running',
  message      TEXT         NULL,
  PRIMARY KEY (import_id),
  UNIQUE KEY uq_file (tenant_id, spool_file),
  KEY ix_status (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Deduplicated alert state, so health_check does not email every 15 minutes.
CREATE TABLE IF NOT EXISTS alerts (
  alert_key       VARCHAR(128) NOT NULL,              -- e.g. shard_full:odys_ev_2026
  severity        ENUM('warn','critical') NOT NULL,
  first_raised_at DATETIME     NOT NULL,
  last_raised_at  DATETIME     NOT NULL,
  last_notified_at DATETIME    NULL,
  raise_count     INT UNSIGNED NOT NULL DEFAULT 1,
  resolved_at     DATETIME     NULL,
  last_message    TEXT         NULL,
  PRIMARY KEY (alert_key),
  KEY ix_open (resolved_at, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- Dimensions  (interned strings — see §6.2 row-size budget)
-- Every dim uses a hash unique key so long values never hit index limits.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS dim_visitor (
  visitor_key    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  visitor_hash   BINARY(16)      NOT NULL,            -- digest of _shopify_y / pixel clientId
  person_id      BIGINT UNSIGNED NULL,                -- set by identity_resolve (M3)
  customer_ref   BIGINT UNSIGNED NULL,                -- Shopify customer id seen via liquid
  first_seen_at  DATETIME        NOT NULL,
  last_seen_at   DATETIME        NOT NULL,
  PRIMARY KEY (visitor_key),
  UNIQUE KEY uq_visitor (tenant_id, visitor_hash),
  KEY ix_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS dim_path (
  path_id    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id  SMALLINT UNSIGNED NOT NULL,
  path_hash  BINARY(16)   NOT NULL,
  path       VARCHAR(512) NOT NULL,
  page_type  VARCHAR(32)  NULL,                       -- product | collection | cart | home | other
  PRIMARY KEY (path_id),
  UNIQUE KEY uq_path (tenant_id, path_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_referrer (
  referrer_id   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id     SMALLINT UNSIGNED NOT NULL,
  referrer_hash BINARY(16)   NOT NULL,
  referrer_host VARCHAR(191) NULL,
  referrer_url  VARCHAR(512) NULL,
  PRIMARY KEY (referrer_id),
  UNIQUE KEY uq_referrer (tenant_id, referrer_hash),
  KEY ix_host (tenant_id, referrer_host)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_campaign (
  campaign_id  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id    SMALLINT UNSIGNED NOT NULL,
  tuple_hash   BINARY(16)   NOT NULL,                 -- digest of the 5-tuple + click ids
  utm_source   VARCHAR(191) NULL,
  utm_medium   VARCHAR(191) NULL,
  utm_campaign VARCHAR(191) NULL,
  utm_content  VARCHAR(191) NULL,
  utm_term     VARCHAR(191) NULL,
  has_gclid    TINYINT(1)   NOT NULL DEFAULT 0,
  has_fbclid   TINYINT(1)   NOT NULL DEFAULT 0,
  PRIMARY KEY (campaign_id),
  UNIQUE KEY uq_tuple (tenant_id, tuple_hash),
  KEY ix_source (tenant_id, utm_source)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_useragent (
  ua_id       INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  ua_hash     BINARY(16)   NOT NULL,
  device_type TINYINT UNSIGNED NOT NULL DEFAULT 0,    -- 0 unknown 1 mobile 2 desktop 3 tablet 4 bot
  browser     VARCHAR(48)  NULL,
  os          VARCHAR(48)  NULL,
  PRIMARY KEY (ua_id),
  UNIQUE KEY uq_ua (tenant_id, ua_hash),
  KEY ix_device (tenant_id, device_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_geo (
  geo_id      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  geo_hash    BINARY(16)   NOT NULL,                  -- shared across tenants; no PII
  country     CHAR(2)      NULL,
  region      VARCHAR(96)  NULL,
  city        VARCHAR(96)  NULL,
  lat         DECIMAL(8,5) NULL,
  lon         DECIMAL(8,5) NULL,
  PRIMARY KEY (geo_id),
  UNIQUE KEY uq_geo (geo_hash),
  KEY ix_country (country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_search_term (
  search_term_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  term_hash      BINARY(16)   NOT NULL,
  term           VARCHAR(255) NOT NULL,
  PRIMARY KEY (search_term_id),
  UNIQUE KEY uq_term (tenant_id, term_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_click_target (
  click_target_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id       SMALLINT UNSIGNED NOT NULL,
  target_hash     BINARY(16)   NOT NULL,
  label           VARCHAR(255) NULL,                  -- element text, liquid feed only
  selector        VARCHAR(255) NULL,
  href            VARCHAR(512) NULL,
  PRIMARY KEY (click_target_id),
  UNIQUE KEY uq_target (tenant_id, target_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS dim_currency (
  currency_id TINYINT UNSIGNED NOT NULL AUTO_INCREMENT,
  code        CHAR(3) NOT NULL,
  minor_units TINYINT UNSIGNED NOT NULL DEFAULT 2,
  PRIMARY KEY (currency_id),
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;


-- ---------------------------------------------------------------------
-- Identity  (see §9)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS persons (
  person_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  merged_into BIGINT UNSIGNED NULL,                   -- non-null => absorbed by another person
  first_seen  DATETIME NOT NULL,
  last_seen   DATETIME NOT NULL,
  PRIMARY KEY (person_id),
  KEY ix_tenant_live (tenant_id, merged_into)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS identity_keys (
  tenant_id  SMALLINT UNSIGNED NOT NULL,
  key_type   ENUM('shopify_customer','phone','email') NOT NULL,
  key_hash   BINARY(16)      NOT NULL,                -- HMAC-SHA256(normalised, tenant salt)[0:16]
  person_id  BIGINT UNSIGNED NOT NULL,
  created_at DATETIME        NOT NULL,
  PRIMARY KEY (tenant_id, key_type, key_hash),
  KEY ix_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS person_merges (
  merge_id    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  survivor_id BIGINT UNSIGNED NOT NULL,
  absorbed_id BIGINT UNSIGNED NOT NULL,
  reason      VARCHAR(64)     NOT NULL,               -- key_type that triggered the merge
  created_at  DATETIME        NOT NULL,
  PRIMARY KEY (merge_id),
  KEY ix_survivor (tenant_id, survivor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- Commerce  (Admin API, source of truth for revenue)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS customers (
  tenant_id           SMALLINT UNSIGNED NOT NULL,
  shopify_customer_id BIGINT UNSIGNED NOT NULL,
  person_id           BIGINT UNSIGNED NULL,
  email_hash          BINARY(16)   NULL,
  phone_hash          BINARY(16)   NULL,
  created_at          DATETIME     NULL,
  orders_count        INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent_minor   BIGINT UNSIGNED NOT NULL DEFAULT 0,
  accepts_marketing   TINYINT(1)   NULL,
  tags                VARCHAR(512) NULL,
  country             CHAR(2)      NULL,
  city                VARCHAR(96)  NULL,
  synced_at           DATETIME     NOT NULL,
  PRIMARY KEY (tenant_id, shopify_customer_id),
  KEY ix_person (tenant_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS orders (
  tenant_id           SMALLINT UNSIGNED NOT NULL,
  order_id            BIGINT UNSIGNED NOT NULL,
  order_number        VARCHAR(32)     NULL,
  person_id           BIGINT UNSIGNED NULL,
  shopify_customer_id BIGINT UNSIGNED NULL,
  checkout_token      BIGINT UNSIGNED NULL,           -- 64-bit digest; joins to events
  visitor_key         INT UNSIGNED    NULL,           -- resolved via checkout_token
  created_at          DATETIME        NOT NULL,
  processed_at        DATETIME        NULL,
  cancelled_at        DATETIME        NULL,
  financial_status    VARCHAR(32)     NULL,
  fulfillment_status  VARCHAR(32)     NULL,
  currency            CHAR(3)         NOT NULL DEFAULT 'INR',
  subtotal_minor      INT UNSIGNED    NULL,
  total_minor         INT UNSIGNED    NULL,
  discount_minor      INT UNSIGNED    NULL,
  refunded_minor      INT UNSIGNED    NOT NULL DEFAULT 0,
  discount_codes      VARCHAR(255)    NULL,
  order_sequence      SMALLINT UNSIGNED NULL,         -- 1 = this person's first order
  landing_site        VARCHAR(512)    NULL,
  referring_site      VARCHAR(512)    NULL,
  source_name         VARCHAR(64)     NULL,
  journey_ready       TINYINT(1)      NOT NULL DEFAULT 0,  -- customerJourneySummary fetched
  days_to_conversion  SMALLINT UNSIGNED NULL,
  moments_count       SMALLINT UNSIGNED NULL,
  synced_at           DATETIME        NOT NULL,
  PRIMARY KEY (tenant_id, order_id),
  KEY ix_person_created (tenant_id, person_id, created_at),
  KEY ix_checkout (tenant_id, checkout_token),
  KEY ix_created (tenant_id, created_at),
  KEY ix_journey_pending (tenant_id, journey_ready, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_line_items (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  order_id       BIGINT UNSIGNED NOT NULL,
  line_id        BIGINT UNSIGNED NOT NULL,
  product_id     BIGINT UNSIGNED NULL,
  variant_id     BIGINT UNSIGNED NULL,
  title          VARCHAR(255) NULL,
  sku            VARCHAR(96)  NULL,
  quantity       SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  price_minor    INT UNSIGNED NULL,
  discount_minor INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (tenant_id, order_id, line_id),
  KEY ix_product (tenant_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abandoned_checkouts (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  checkout_id    BIGINT UNSIGNED NOT NULL,
  checkout_token BIGINT UNSIGNED NULL,
  person_id      BIGINT UNSIGNED NULL,
  visitor_key    INT UNSIGNED    NULL,
  email_hash     BINARY(16)      NULL,
  phone_hash     BINARY(16)      NULL,
  created_at     DATETIME        NOT NULL,
  abandoned_at   DATETIME        NULL,
  completed_at   DATETIME        NULL,
  recovered_order_id BIGINT UNSIGNED NULL,
  currency       CHAR(3)         NOT NULL DEFAULT 'INR',
  total_minor    INT UNSIGNED    NULL,
  synced_at      DATETIME        NOT NULL,
  PRIMARY KEY (tenant_id, checkout_id),
  KEY ix_created (tenant_id, created_at),
  KEY ix_token (tenant_id, checkout_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS abandoned_checkout_items (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  checkout_id BIGINT UNSIGNED NOT NULL,
  line_no     SMALLINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NULL,
  variant_id  BIGINT UNSIGNED NULL,
  title       VARCHAR(255) NULL,
  quantity    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  price_minor INT UNSIGNED NULL,
  PRIMARY KEY (tenant_id, checkout_id, line_no),
  KEY ix_product (tenant_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(255) NULL,
  handle      VARCHAR(255) NULL,
  vendor      VARCHAR(128) NULL,
  product_type VARCHAR(128) NULL,
  status      VARCHAR(32)  NULL,
  created_at  DATETIME     NULL,
  synced_at   DATETIME     NOT NULL,
  PRIMARY KEY (tenant_id, product_id),
  KEY ix_handle (tenant_id, handle)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_variants (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  variant_id  BIGINT UNSIGNED NOT NULL,
  product_id  BIGINT UNSIGNED NOT NULL,
  title       VARCHAR(255) NULL,
  sku         VARCHAR(96)  NULL,
  price_minor INT UNSIGNED NULL,
  synced_at   DATETIME     NOT NULL,
  PRIMARY KEY (tenant_id, variant_id),
  KEY ix_product (tenant_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- Attribution  (see §10)
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS order_attribution (
  tenant_id       SMALLINT UNSIGNED NOT NULL,
  order_id        BIGINT UNSIGNED NOT NULL,
  model           ENUM('pixel_first','pixel_last','shopify_first','shopify_last') NOT NULL,
  campaign_id     INT UNSIGNED NULL,
  channel         VARCHAR(64)  NULL,                  -- resolved via channel_rules
  landing_path_id INT UNSIGNED NULL,
  referrer_id     INT UNSIGNED NULL,
  touch_at        DATETIME     NULL,
  PRIMARY KEY (tenant_id, order_id, model),
  KEY ix_campaign (tenant_id, model, campaign_id),
  KEY ix_channel (tenant_id, model, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS order_journey_moments (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  order_id    BIGINT UNSIGNED NOT NULL,
  seq         SMALLINT UNSIGNED NOT NULL,
  occurred_at DATETIME     NULL,
  campaign_id INT UNSIGNED NULL,
  referrer_id INT UNSIGNED NULL,
  path_id     INT UNSIGNED NULL,
  PRIMARY KEY (tenant_id, order_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ordered rules, first match wins. Exposed in the UI with a live
-- reclassification preview — see §10.2 and the audit's 392-visitor defect.
CREATE TABLE IF NOT EXISTS channel_rules (
  tenant_id   SMALLINT UNSIGNED NOT NULL,
  priority    SMALLINT UNSIGNED NOT NULL,
  match_field ENUM('utm_source','utm_medium','utm_campaign',
                   'referrer_host','landing_path','has_gclid','has_fbclid') NOT NULL,
  match_op    ENUM('equals','contains','regex','exists') NOT NULL,
  match_value VARCHAR(255) NULL,
  channel     VARCHAR(64)  NOT NULL,
  enabled     TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (tenant_id, priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- ---------------------------------------------------------------------
-- Rollups  (the ONLY tables the dashboard reads — see §6.5)
-- is_provisional = 1 while the day is inside the 3-day reclose window.
-- ---------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS rollup_daily_kpi (
  tenant_id         SMALLINT UNSIGNED NOT NULL,
  stat_date         DATE NOT NULL,
  visitors          INT UNSIGNED NOT NULL DEFAULT 0,
  sessions          INT UNSIGNED NOT NULL DEFAULT 0,
  pageviews         INT UNSIGNED NOT NULL DEFAULT 0,
  product_viewers   INT UNSIGNED NOT NULL DEFAULT 0,
  atc_visitors      INT UNSIGNED NOT NULL DEFAULT 0,
  checkout_visitors INT UNSIGNED NOT NULL DEFAULT 0,
  payment_visitors  INT UNSIGNED NOT NULL DEFAULT 0,
  purchasers        INT UNSIGNED NOT NULL DEFAULT 0,
  orders            INT UNSIGNED NOT NULL DEFAULT 0,
  units             INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  refunded_minor    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  new_customers     INT UNSIGNED NOT NULL DEFAULT 0,
  repeat_customers  INT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional    TINYINT(1) NOT NULL DEFAULT 1,
  computed_at       DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- step: 1 page 2 product 3 atc 4 checkout 5 payment 6 purchase
CREATE TABLE IF NOT EXISTS rollup_daily_funnel (
  tenant_id        SMALLINT UNSIGNED NOT NULL,
  stat_date        DATE NOT NULL,
  step             TINYINT UNSIGNED NOT NULL,
  reached_visitors INT UNSIGNED NOT NULL DEFAULT 0,   -- fired this step at all
  strict_visitors  INT UNSIGNED NOT NULL DEFAULT 0,   -- fired every prior step in order
  events           INT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional   TINYINT(1) NOT NULL DEFAULT 1,
  computed_at      DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, step)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS rollup_daily_campaign (
  tenant_id           SMALLINT UNSIGNED NOT NULL,
  stat_date           DATE NOT NULL,
  model               ENUM('pixel_first','pixel_last','shopify_first','shopify_last') NOT NULL,
  campaign_id         INT UNSIGNED NOT NULL DEFAULT 0,  -- 0 = unattributed
  visitors            INT UNSIGNED NOT NULL DEFAULT 0,
  sessions            INT UNSIGNED NOT NULL DEFAULT 0,
  product_views       INT UNSIGNED NOT NULL DEFAULT 0,
  atc                 INT UNSIGNED NOT NULL DEFAULT 0,
  checkouts_started   INT UNSIGNED NOT NULL DEFAULT 0,
  orders              INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  new_customers       INT UNSIGNED NOT NULL DEFAULT 0,
  returning_customers INT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional      TINYINT(1) NOT NULL DEFAULT 1,
  computed_at         DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, model, campaign_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS rollup_daily_channel (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  stat_date      DATE NOT NULL,
  model          ENUM('pixel_first','pixel_last','shopify_first','shopify_last') NOT NULL,
  channel        VARCHAR(64) NOT NULL,
  visitors       INT UNSIGNED NOT NULL DEFAULT 0,
  orders         INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional TINYINT(1) NOT NULL DEFAULT 1,
  computed_at    DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, model, channel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rollup_daily_landing (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  stat_date      DATE NOT NULL,
  path_id        INT UNSIGNED NOT NULL,
  visitors       INT UNSIGNED NOT NULL DEFAULT 0,
  orders         INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional TINYINT(1) NOT NULL DEFAULT 1,
  computed_at    DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, path_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS rollup_daily_product (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  stat_date      DATE NOT NULL,
  product_id     BIGINT UNSIGNED NOT NULL,
  views          INT UNSIGNED NOT NULL DEFAULT 0,
  viewers        INT UNSIGNED NOT NULL DEFAULT 0,
  atc            INT UNSIGNED NOT NULL DEFAULT 0,
  purchases      INT UNSIGNED NOT NULL DEFAULT 0,
  units          INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  abandons       INT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional TINYINT(1) NOT NULL DEFAULT 1,
  computed_at    DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS rollup_daily_geo (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  stat_date      DATE NOT NULL,
  geo_id         INT UNSIGNED NOT NULL,
  visitors       INT UNSIGNED NOT NULL DEFAULT 0,
  orders         INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional TINYINT(1) NOT NULL DEFAULT 1,
  computed_at    DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, geo_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS rollup_daily_device (
  tenant_id      SMALLINT UNSIGNED NOT NULL,
  stat_date      DATE NOT NULL,
  device_type    TINYINT UNSIGNED NOT NULL,
  browser        VARCHAR(48) NOT NULL DEFAULT '',
  os             VARCHAR(48) NOT NULL DEFAULT '',
  visitors       INT UNSIGNED NOT NULL DEFAULT 0,
  orders         INT UNSIGNED NOT NULL DEFAULT 0,
  revenue_minor  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  is_provisional TINYINT(1) NOT NULL DEFAULT 1,
  computed_at    DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, device_type, browser, os)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- stage: 1 cart_created 2 checkout_started 3 contact 4 address 5 shipping
--        6 payment 7 completed   (see §12.2 checkout micro-funnel)
CREATE TABLE IF NOT EXISTS rollup_daily_abandon (
  tenant_id       SMALLINT UNSIGNED NOT NULL,
  stat_date       DATE NOT NULL,
  stage           TINYINT UNSIGNED NOT NULL,
  entered         INT UNSIGNED NOT NULL DEFAULT 0,
  advanced        INT UNSIGNED NOT NULL DEFAULT 0,
  abandoned       INT UNSIGNED NOT NULL DEFAULT 0,
  value_minor     BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- value at risk, API-sourced where available
  shopify_records INT UNSIGNED NOT NULL DEFAULT 0,      -- the "matches Shopify Admin" number
  is_provisional  TINYINT(1) NOT NULL DEFAULT 1,
  computed_at     DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, stat_date, stage)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- days_bucket: 30 | 60 | 90 | 180
CREATE TABLE IF NOT EXISTS rollup_cohort_repeat (
  tenant_id    SMALLINT UNSIGNED NOT NULL,
  cohort_month DATE NOT NULL,                          -- first day of the month
  days_bucket  SMALLINT UNSIGNED NOT NULL,
  cohort_size  INT UNSIGNED NOT NULL DEFAULT 0,
  reordered    INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at  DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, cohort_month, days_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

CREATE TABLE IF NOT EXISTS rollup_campaign_cohort (
  tenant_id    SMALLINT UNSIGNED NOT NULL,
  campaign_id  INT UNSIGNED NOT NULL DEFAULT 0,
  cohort_month DATE NOT NULL,
  days_bucket  SMALLINT UNSIGNED NOT NULL,
  cohort_size  INT UNSIGNED NOT NULL DEFAULT 0,
  reordered    INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at  DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, campaign_id, cohort_month, days_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;

-- order_count_bucket: 1, 2, 3, 4 (means 4+)
CREATE TABLE IF NOT EXISTS rollup_person_orders (
  tenant_id          SMALLINT UNSIGNED NOT NULL,
  as_of_date         DATE NOT NULL,
  order_count_bucket TINYINT UNSIGNED NOT NULL,
  persons            INT UNSIGNED NOT NULL DEFAULT 0,
  computed_at        DATETIME NOT NULL,
  PRIMARY KEY (tenant_id, as_of_date, order_count_bucket)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
