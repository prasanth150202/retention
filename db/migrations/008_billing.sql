-- =====================================================================
-- Billing: the rest of what Shopify's subscription state can be
--
-- 005 gave billing_status five values. Shopify's AppSubscriptionStatus has
-- six, and three of them do not map onto anything 005 had:
--
--   PENDING   created, awaiting the merchant's approval. Not 'none' — the
--             merchant has chosen a plan and the page must offer them the
--             confirmation link again rather than the plan chooser.
--   DECLINED  the merchant refused the charge. Terminal.
--   EXPIRED   not approved within two days. Terminal.
--
-- Collapsing those into 'cancelled' would make the app tell a merchant who
-- is one click from paying that their subscription was cancelled.
--
-- 'trial' stays a status of ours rather than Shopify's: a trialling
-- subscription is ACTIVE to Shopify, with trialDays set. We derive it.
-- =====================================================================

ALTER TABLE tenants
    MODIFY COLUMN billing_status
        ENUM('none','pending','trial','active','frozen','cancelled','declined','expired')
        NOT NULL DEFAULT 'none';


ALTER TABLE tenants
    -- Where to send a merchant who started a subscription and did not finish
    -- approving it. Shopify's confirmation URL is single-use per subscription
    -- but valid until the subscription expires, so storing it saves creating a
    -- second subscription for someone who closed the tab.
    ADD COLUMN IF NOT EXISTS billing_confirm_url VARCHAR(512) NULL AFTER subscription_gid,

    -- A subscription created with test:true bills nothing. It is how the app
    -- is developed and reviewed, and it must never be counted as revenue or
    -- mistaken for a paying store.
    ADD COLUMN IF NOT EXISTS subscription_test TINYINT(1) NOT NULL DEFAULT 0
        AFTER billing_confirm_url,

    -- When the current paid period ends. Shopify handles dunning; this is
    -- only so the app can tell a merchant what they have paid for.
    ADD COLUMN IF NOT EXISTS current_period_end DATETIME NULL AFTER subscription_test,

    -- Last time subscription state was read from Shopify. Webhooks are the
    -- primary signal; this backs the periodic re-read that catches a webhook
    -- that never arrived.
    ADD COLUMN IF NOT EXISTS billing_checked_at DATETIME NULL AFTER current_period_end;


-- ---------------------------------------------------------------------
-- An audit trail for money.
--
-- Shopify owns the subscription and is the authority on what was charged.
-- This is not an accounting system; it exists so that "when did this store
-- start paying, and what happened since" can be answered without asking
-- Shopify, and so a support conversation about a disputed charge has
-- something to look at.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS billing_events (
    event_id     BIGINT UNSIGNED   NOT NULL AUTO_INCREMENT,
    tenant_id    SMALLINT UNSIGNED NOT NULL,
    occurred_at  DATETIME          NOT NULL,
    source       ENUM('app','webhook','sync') NOT NULL,
    status_from  VARCHAR(16)       NULL,
    status_to    VARCHAR(16)       NOT NULL,
    plan         VARCHAR(32)       NULL,
    subscription_gid VARCHAR(255)  NULL,
    note         VARCHAR(255)      NULL,
    PRIMARY KEY (event_id),
    KEY ix_tenant (tenant_id, occurred_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
