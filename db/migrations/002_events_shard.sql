-- =====================================================================
-- Project Odysseus — event shard schema
-- Applied to EACH odys_ev_YYYY database. One shard per calendar year.
--
-- Row-size budget (§6.2). Every column here is fixed-width and every
-- repeating string is interned into a dim_* table in odys_core:
--
--   row data (24 cols)                     ~117 bytes
--   record header + NULL bitmap              ~8
--   uq_event   (tenant_id, event_uid)       ~30
--   ix_tenant_time (tenant_id, occurred_at) ~26
--   ---------------------------------------------
--   uncompressed                           ~181
--   ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8 ~118-127
--
-- At 1.6M events/month (5 stores) that is ~200 MB/month, so a 3 GB
-- database holds roughly 14 months. One shard per year has headroom.
--
-- DO NOT add indexes to this table without recalculating the above.
-- Each additional secondary index costs ~26-31 bytes/row, which is
-- 1.5-2 months of shard lifetime.
-- =====================================================================

CREATE TABLE IF NOT EXISTS events (
  id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
  tenant_id       SMALLINT UNSIGNED NOT NULL,
  event_uid       BIGINT UNSIGNED  NOT NULL,   -- 64-bit digest of the source event id
  occurred_at     DATETIME         NOT NULL,   -- client timestamp, UTC
  received_at     DATETIME         NOT NULL,   -- server timestamp, UTC
  event_type      TINYINT UNSIGNED NOT NULL,   -- see EventType map below
  source          TINYINT UNSIGNED NOT NULL,   -- 1 = shopify_pixel, 2 = liquid
  visitor_key     INT UNSIGNED     NOT NULL,   -- -> odys_core.dim_visitor
  person_id       BIGINT UNSIGNED  NULL,       -- backfilled by identity_resolve (M3)
  customer_ref    BIGINT UNSIGNED  NULL,       -- Shopify customer id, liquid feed only
  path_id         INT UNSIGNED     NULL,       -- -> dim_path
  referrer_id     INT UNSIGNED     NULL,       -- -> dim_referrer
  campaign_id     INT UNSIGNED     NULL,       -- -> dim_campaign
  ua_id           INT UNSIGNED     NULL,       -- -> dim_useragent
  geo_id          INT UNSIGNED     NULL,       -- -> dim_geo
  product_id      BIGINT UNSIGNED  NULL,
  variant_id      BIGINT UNSIGNED  NULL,
  qty             SMALLINT UNSIGNED NULL,
  amount_minor    INT UNSIGNED     NULL,       -- paise
  currency_id     TINYINT UNSIGNED NULL,       -- -> dim_currency
  checkout_token  BIGINT UNSIGNED  NULL,       -- 64-bit digest; joins to orders
  order_ref       BIGINT UNSIGNED  NULL,
  search_term_id  INT UNSIGNED     NULL,       -- -> dim_search_term
  click_target_id INT UNSIGNED     NULL,       -- -> dim_click_target
  PRIMARY KEY (id),
  UNIQUE KEY uq_event (tenant_id, event_uid),
  KEY ix_tenant_time (tenant_id, occurred_at)
) ENGINE=InnoDB
  ROW_FORMAT=COMPRESSED KEY_BLOCK_SIZE=8
  DEFAULT CHARSET=ascii;

-- ---------------------------------------------------------------------
-- Why there is no PARTITION clause
-- ---------------------------------------------------------------------
-- MySQL requires every unique key to contain the partitioning column.
-- Partitioning on occurred_at would force:
--     PRIMARY KEY (id, occurred_at)
--     UNIQUE KEY  (tenant_id, event_uid, occurred_at)
-- which weakens the dedup guarantee that closes the audit's duplicate-
-- revenue defect (443 duplicate rows, +₹1,378 overstated).
--
-- The benefit would be partition pruning on the daily rollup scan, which
-- ix_tenant_time already serves, plus cheap partition drops, which we
-- never perform because data is retained indefinitely.
--
-- Dedup integrity beats a redundant optimisation. The yearly shard IS
-- the partition.
-- ---------------------------------------------------------------------

-- ---------------------------------------------------------------------
-- EventType map — mirrored in app/lib/EventType.php. Values are frozen;
-- append only, never renumber. Numbers are baked into stored rows.
-- ---------------------------------------------------------------------
--   Shopify pixel (source = 1)
--    1  page_viewed
--    2  collection_viewed
--    3  product_viewed
--    4  search_submitted
--    5  cart_viewed
--    6  product_added_to_cart
--    7  product_removed_from_cart
--    8  checkout_started
--    9  checkout_contact_info_submitted
--   10  checkout_address_info_submitted
--   11  checkout_shipping_info_submitted
--   12  payment_info_submitted
--   13  checkout_completed
--   14  clicked
--   15  form_submitted
--
--   Liquid snippet (source = 2) — its own namespace, deliberately
--   disjoint so it can never double-count the funnel (§5.2)
--   64  identify           (customer_id + orders_count + total_spent)
--   65  link_click         (element text + selector, which pixel cannot see)
--   66  internal_search    (term + result count)
--
--   NOT SUBSCRIBED, by design (§5.1): input_changed, input_blurred,
--   input_focused, alert_displayed. They carry raw element.value and
--   leaked 2,626 emails / ~30k phone numbers in the earlier export.
-- ---------------------------------------------------------------------
