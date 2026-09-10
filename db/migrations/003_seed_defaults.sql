-- =====================================================================
-- Project Odysseus — seed data (idempotent)
-- Applied to odys_core.
-- =====================================================================

INSERT INTO dim_currency (currency_id, code, minor_units) VALUES
  (1, 'INR', 2),
  (2, 'USD', 2),
  (3, 'AED', 2),
  (4, 'GBP', 2),
  (5, 'EUR', 2)
ON DUPLICATE KEY UPDATE minor_units = VALUES(minor_units);


-- ---------------------------------------------------------------------
-- Default channel rules, held under tenant_id = 0 and copied to each
-- new tenant at onboarding. Editable per tenant thereafter.
--
-- Ordering principle: UTM parameters ALWAYS outrank referrer.
--
-- The audit found 392 visitors with Instagram UTMs and a Facebook
-- referrer being booked entirely to Instagram, and flagged it as a
-- design choice rather than a bug. It is the correct choice — an
-- explicit UTM tag is a stronger signal than a referrer header. The
-- actual defect was that the choice lived inside a CASE WHEN nobody
-- re-read. Here it is data: visible in the UI, reorderable, and every
-- change previews how many visitors reclassify before it is saved.
-- ---------------------------------------------------------------------

INSERT INTO channel_rules (tenant_id, priority, match_field, match_op, match_value, channel, enabled) VALUES
  -- 10-39: explicit UTM source (highest confidence)
  (0, 10, 'utm_source',   'contains', 'instagram',  'Instagram',        1),
  (0, 11, 'utm_source',   'contains', 'facebook',   'Facebook',         1),
  (0, 12, 'utm_source',   'contains', 'fb',         'Facebook',         1),
  (0, 13, 'utm_source',   'contains', 'google',     'Google',           1),
  (0, 14, 'utm_source',   'contains', 'youtube',    'YouTube',          1),
  (0, 15, 'utm_source',   'contains', 'whatsapp',   'WhatsApp',         1),
  (0, 16, 'utm_source',   'contains', 'klaviyo',    'Email',            1),
  (0, 17, 'utm_source',   'contains', 'email',      'Email',            1),
  (0, 18, 'utm_source',   'contains', 'sms',        'SMS',              1),
  (0, 19, 'utm_source',   'contains', 'influencer', 'Influencer',       1),

  -- 40-49: UTM medium, when source was not decisive
  (0, 40, 'utm_medium',   'contains', 'cpc',        'Paid Search',      1),
  (0, 41, 'utm_medium',   'contains', 'paid',       'Paid Social',      1),
  (0, 42, 'utm_medium',   'contains', 'email',      'Email',            1),
  (0, 43, 'utm_medium',   'contains', 'affiliate',  'Affiliate',        1),
  (0, 44, 'utm_medium',   'contains', 'organic',    'Organic Search',   1),

  -- 50-59: click identifiers. UTMs stripped but the click id survived —
  -- this is the "recoverable" bucket in the unattributed diagnostics.
  (0, 50, 'has_gclid',    'exists',   NULL,         'Google Ads',       1),
  -- fbclid is NOT an ads marker: Facebook adds it to organic post,
  -- Messenger and group links too. gclid above genuinely is one.
  (0, 51, 'has_fbclid',   'exists',   NULL,         'Facebook',         1),

  -- 60-89: referrer host, lowest confidence
  (0, 60, 'referrer_host','contains', 'instagram',  'Instagram',        1),
  (0, 61, 'referrer_host','contains', 'facebook',   'Facebook',         1),
  (0, 62, 'referrer_host','contains', 'l.facebook', 'Facebook',         1),
  (0, 63, 'referrer_host','contains', 'google',     'Organic Search',   1),
  (0, 64, 'referrer_host','contains', 'bing',       'Organic Search',   1),
  (0, 65, 'referrer_host','contains', 'duckduckgo', 'Organic Search',   1),
  (0, 66, 'referrer_host','contains', 'youtube',    'YouTube',          1),
  (0, 67, 'referrer_host','contains', 'pinterest',  'Pinterest',        1),
  (0, 68, 'referrer_host','contains', 'whatsapp',   'WhatsApp',         1),
  (0, 69, 'referrer_host','contains', 'linkedin',   'LinkedIn',         1),

  -- 99: fallback. Everything landing here is split by the unattributed
  -- diagnostics panel into recoverable vs genuinely unknown (§10.2).
  (0, 99, 'utm_source',   'equals',   NULL,         'Direct/Untracked', 1)
ON DUPLICATE KEY UPDATE
  match_field = VALUES(match_field),
  match_op    = VALUES(match_op),
  match_value = VALUES(match_value),
  channel     = VALUES(channel);
