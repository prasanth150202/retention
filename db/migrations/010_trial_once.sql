-- 010: a free trial is a free trial, not a renewable one.
--
-- subscribe() passed the configured trial days on every call, and nothing
-- anywhere recorded that a store had already had one. Shopify does not track
-- trial eligibility for an app — trialDays is whatever the app asks for on
-- each appSubscriptionCreate — so a merchant could subscribe, use the trial,
-- cancel, and subscribe again for another. Indefinitely, at no charge.
--
-- trial_ends_at cannot answer "have they had one" because it is overwritten
-- by each subscription and cleared when a plan carries no trial. This column
-- is written once and never cleared, including across an uninstall and
-- reinstall, which is exactly the loop it has to close.

ALTER TABLE tenants
    ADD COLUMN IF NOT EXISTS trial_started_at DATETIME NULL AFTER trial_ends_at;

-- Anyone who already has a trial recorded has had one. Without this the fix
-- would hand every existing store one more free trial on its next subscribe.
UPDATE tenants
   SET trial_started_at = COALESCE(trial_started_at, installed_at, UTC_TIMESTAMP())
 WHERE trial_ends_at IS NOT NULL
    OR billing_status IN ('trial', 'active', 'frozen', 'cancelled', 'expired');
