-- =====================================================================
-- Public App Store app: expiring tokens, billing, uninstall lifecycle
--
-- Three groups of columns, added together because they all arrive with the
-- same decision (public distribution) and adding them separately would mean
-- three migrations against a live table.
--
-- MariaDB syntax (ADD COLUMN IF NOT EXISTS) keeps this idempotent, as every
-- migration here must be — the runner re-applies a file whose checksum has
-- changed.
-- =====================================================================


-- ---------------------------------------------------------------------
-- 1. Expiring access tokens
--
-- New public apps CANNOT use non-expiring offline access tokens for the
-- GraphQL Admin API; existing ones lose them on 2027-01-01. Custom apps are
-- exempt, which is why this was not needed until distribution changed.
--
-- Tokens now last about an hour and come with a refresh token valid 90 days.
-- Without these columns the app authenticates once and then breaks exactly
-- one hour later, which is a poor way to discover the requirement.
--
-- The refresh token is encrypted like the access token: it mints new access
-- tokens, so it is exactly as sensitive.
-- ---------------------------------------------------------------------
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS refresh_token_enc  VARBINARY(512) NULL AFTER admin_token_enc,
    ADD COLUMN IF NOT EXISTS token_expires_at   DATETIME       NULL AFTER refresh_token_enc,
    ADD COLUMN IF NOT EXISTS refresh_expires_at DATETIME       NULL AFTER token_expires_at;

-- Finding stores whose token is about to expire, or whose refresh window is
-- closing and will need a reinstall.
CREATE INDEX IF NOT EXISTS ix_token_expiry ON tenants (token_expires_at);


-- ---------------------------------------------------------------------
-- 2. Billing
--
-- Nothing reads these yet. They exist now because adding columns to a table
-- with live installs, at the moment revenue starts depending on them, is the
-- worst time to do it.
--
-- Shopify owns the subscription: it handles trials, plan changes, failed
-- payments and dunning. The app only records what Shopify reports, which is
-- why this is a handful of columns rather than a billing system.
-- ---------------------------------------------------------------------
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS plan             VARCHAR(32) NOT NULL DEFAULT 'free' AFTER status,
    ADD COLUMN IF NOT EXISTS billing_status   ENUM('none','trial','active','frozen','cancelled')
                                              NOT NULL DEFAULT 'none' AFTER plan,
    ADD COLUMN IF NOT EXISTS trial_ends_at    DATETIME    NULL AFTER billing_status,
    ADD COLUMN IF NOT EXISTS subscription_gid VARCHAR(255) NULL AFTER trial_ends_at;


-- ---------------------------------------------------------------------
-- 3. Uninstall and retention
--
-- On the App Store merchants install, trial for a few days and uninstall.
-- Keeping their raw events indefinitely is storage nobody is paying for and
-- personal data held with no ongoing relationship to justify it.
--
-- shop/redact is mandatory and must be honoured within 30 days, so the
-- machinery is required regardless. purge_after records WHEN to delete;
-- the policy that sets it is configurable and still open.
--
-- uninstalled_at is kept rather than the row deleted: a merchant who
-- reinstalls should not look like a brand new store, and the audit trail
-- matters if a redaction request is ever questioned.
-- ---------------------------------------------------------------------
ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS uninstalled_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS purge_after    DATETIME NULL,
    ADD COLUMN IF NOT EXISTS purged_at      DATETIME NULL;

CREATE INDEX IF NOT EXISTS ix_purge_due ON tenants (purge_after, purged_at);

-- 'uninstalled' is a distinct state from 'disabled': the merchant removed the
-- app, rather than us switching it off. Only the former starts a purge clock.
ALTER TABLE tenants
    MODIFY COLUMN status ENUM('active','paused','disabled','uninstalled')
                  NOT NULL DEFAULT 'active';


-- ---------------------------------------------------------------------
-- 4. Compliance request log
--
-- Shopify requires a response to customers/data_request within 30 days and
-- deletion for customers/redact and shop/redact. Recording each request is
-- what makes it possible to prove the obligation was met, which is the whole
-- point of having the obligation.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS compliance_requests (
    request_id   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id    SMALLINT UNSIGNED NULL,          -- null if the shop is unknown to us
    shop_domain  VARCHAR(191) NOT NULL,
    topic        ENUM('customers/data_request','customers/redact','shop/redact') NOT NULL,
    payload_hash BINARY(16)   NOT NULL,           -- dedupe; Shopify retries webhooks
    subject_ref  VARCHAR(191) NULL,               -- customer id or email hash, as applicable
    received_at  DATETIME     NOT NULL,
    completed_at DATETIME     NULL,
    rows_deleted INT UNSIGNED NOT NULL DEFAULT 0,
    notes        TEXT         NULL,
    PRIMARY KEY (request_id),
    UNIQUE KEY uq_request (topic, payload_hash),
    KEY ix_outstanding (completed_at, received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
