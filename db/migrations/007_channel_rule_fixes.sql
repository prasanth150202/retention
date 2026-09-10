-- =====================================================================
-- Channel rule correction: fbclid does not mean "ad"
--
-- The seeded rule at priority 51 read:
--     has_fbclid exists -> 'Meta Ads'
--
-- That is wrong as a matter of fact, not taste. Facebook appends fbclid
-- to outbound links from ORGANIC posts, Messenger and group shares, not
-- only from paid placements. Labelling every fbclid click 'Meta Ads'
-- books organic social traffic as ad-driven revenue, which overstates
-- ad performance in the one direction a merchant will not question.
--
-- gclid is left as 'Google Ads' because Google appends it only on paid
-- clicks, so there the inference does hold.
--
-- Applied to the tenant_id = 0 defaults AND to every tenant already
-- carrying a copy, since Tenant::upsert() copies the defaults at
-- onboarding and would otherwise leave existing stores on the old rule.
-- =====================================================================

UPDATE channel_rules
   SET channel = 'Facebook'
 WHERE match_field = 'has_fbclid'
   AND match_op    = 'exists'
   AND channel     = 'Meta Ads';

-- Any order already classified under the old name. There is no separate
-- reclassify step here because the rule and the stored label have to move
-- together — a Campaigns tab showing 'Meta Ads' for March and 'Facebook'
-- for April, with no explanation, is worse than either name alone.
--
-- Genuine rule EDITS made later go through Channel::reclassify(), which
-- recomputes from the rules rather than renaming in place.
UPDATE order_attribution
   SET channel = 'Facebook'
 WHERE channel = 'Meta Ads';
