-- =====================================================================
-- One Google Ads campaign, one row
--
-- A Google Ads click arrived under two different channel names depending
-- on a setting the merchant may not know is on:
--
--   auto-tagging ON   gclid, usually no UTM parameters  -> 'Google Ads'
--   auto-tagging OFF  utm_source=google&utm_medium=cpc  -> 'Google'
--
-- Same campaign, same spend, two rows in the Campaigns tab and neither
-- showing the real total. A merchant reconciling against Google Ads would
-- find both numbers too low and no clue why.
--
-- Resolved by naming both 'Google Ads'. The reasoning: Google's own
-- organic results never append UTM parameters, so utm_source=google is
-- something an advertiser typed. It is a tagged campaign either way.
--
-- KNOWN COST OF THIS CHOICE: a store that tags its newsletter with
-- utm_source=google (wrong, but people do) now sees it filed as Google
-- Ads. The rules are editable per tenant, so that store can fix it
-- without a code change.
--
-- Organic Google traffic is untouched: it carries no UTM parameters and
-- is matched by the referrer_host rule at priority 63 -> 'Organic Search'.
-- =====================================================================

UPDATE channel_rules
   SET channel = 'Google Ads'
 WHERE match_field = 'utm_source'
   AND match_op    = 'contains'
   AND match_value = 'google'
   AND channel     = 'Google';

-- Orders already classified under the old name. The rule and the stored
-- label move together, or the Campaigns tab shows one name before this
-- migration and another after, with nothing to explain the split.
--
-- Scoped to campaigns that actually carry a google UTM source: a channel
-- literally named 'Google' could only have come from that rule, but being
-- explicit costs nothing and makes the statement re-readable.
UPDATE order_attribution a
   JOIN dim_campaign c
     ON c.tenant_id = a.tenant_id AND c.campaign_id = a.campaign_id
    SET a.channel = 'Google Ads'
  WHERE a.channel = 'Google'
    AND c.utm_source LIKE '%google%';

-- Any daily rollup already written under the old name. These are summed
-- per (date, model, channel), so two names mean two rows that have to be
-- folded into one rather than renamed.
-- The source is a derived table with every column renamed. Two reasons:
-- materialising the rows first means the DELETE below cannot race the read,
-- and MySQL resolves an unqualified name in ON DUPLICATE KEY UPDATE against
-- both the target and the source. Selecting from the same table leaves
-- `visitors` ambiguous unless exactly one of them has a column by that name.
INSERT INTO rollup_daily_channel
    (tenant_id, stat_date, model, channel, visitors, orders, revenue_minor,
     is_provisional, computed_at)
SELECT s_tenant, s_date, s_model, 'Google Ads',
       s_visitors, s_orders, s_revenue, s_prov, UTC_TIMESTAMP()
  FROM (
        SELECT tenant_id     AS s_tenant,
               stat_date     AS s_date,
               model         AS s_model,
               visitors      AS s_visitors,
               orders        AS s_orders,
               revenue_minor AS s_revenue,
               is_provisional AS s_prov
          FROM rollup_daily_channel
         WHERE channel = 'Google'
       ) AS src
ON DUPLICATE KEY UPDATE
    visitors      = visitors + VALUES(visitors),
    orders        = orders + VALUES(orders),
    revenue_minor = revenue_minor + VALUES(revenue_minor),
    computed_at   = UTC_TIMESTAMP();

DELETE FROM rollup_daily_channel WHERE channel = 'Google';
