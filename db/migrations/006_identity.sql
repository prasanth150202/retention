-- =====================================================================
-- Identity resolution: hashed contact keys on orders
--
-- Grouping orders by Shopify's customer_id alone materially undercounts
-- repeat buyers. Guest checkout produces orders with no customer record at
-- all, and the same person routinely appears as several Shopify customers.
-- In Indian D2C, where guest checkout is the norm and phone is the reliable
-- identifier, this is not an edge case.
--
-- So each order carries its own hashed contact keys, and person_id is
-- resolved from those rather than from customer_id.
--
-- The hashes are HMAC-SHA256 with a per-store salt, truncated to 16 bytes.
-- Deterministic, so two orders from the same person match; irreversible, so
-- the plaintext is never stored and cannot be recovered.
-- =====================================================================

ALTER TABLE orders
    ADD COLUMN IF NOT EXISTS email_hash BINARY(16) NULL AFTER shopify_customer_id,
    ADD COLUMN IF NOT EXISTS phone_hash BINARY(16) NULL AFTER email_hash;

-- Resolution scans orders that have no person yet; without this it is a full
-- table scan on every run.
CREATE INDEX IF NOT EXISTS ix_unresolved ON orders (tenant_id, person_id, created_at);


-- ---------------------------------------------------------------------
-- Which orders still need resolving, and which persons need resequencing.
--
-- A merge changes the order_sequence of every order belonging to the
-- surviving person, so the work is "recompute this person" rather than
-- "recompute this order". Tracking it explicitly means a merge that happens
-- during a partial run is not forgotten when the run is resumed.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS resequence_queue (
    tenant_id  SMALLINT UNSIGNED NOT NULL,
    person_id  BIGINT UNSIGNED   NOT NULL,
    queued_at  DATETIME          NOT NULL,
    PRIMARY KEY (tenant_id, person_id)
) ENGINE=InnoDB DEFAULT CHARSET=ascii;
