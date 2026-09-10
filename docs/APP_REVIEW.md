# Submitting retention-dashboard to the Shopify App Store

What is finished, what needs a decision, and what has to be done in the
Partner Dashboard by a person. Written so the submission is a checklist rather
than a research exercise.

Shopify's own requirements are the authority; this maps them onto what has
actually been built. Sources: **App Store requirements**, **Pass app review**,
**Best practices for apps in the Shopify App Store** on shopify.dev.

---

## 1. Blocked on you

Nothing below can be done from the code side.

| # | What | Why it is blocking |
|---|---|---|
| 1 | **Partner Dashboard `client_id` and `client_secret`** for the `retention-dashboard` app | Without them `shopify app config link` and `shopify app deploy` cannot run, so the web pixel extension and webhook subscriptions are never registered. Nothing installs. |
| 2 | **Request `read_all_orders`** for this app | It does **not** transfer from the earlier custom app. Without it the Admin API silently returns 60 days of orders and every cohort and repeat-purchase figure comes out wrong rather than empty — which is worse, because it looks plausible. Partner Dashboard → the app → API access → request, with a written justification. |
| 3 | **The price** | Currency decided: **USD**. `BILLING_PRICE` is still a placeholder (19.00) and must match the listing exactly — a mismatch is a rejection. |
| 4 | **Rotate the credentials pasted into chat** | The database passwords and the old app's `shpss_…` secret. Assume they are compromised. |
| 5 | **Listing copy, icon and screenshots** | Section 5 below lists what is needed. |

---

## 2. Built and verified

Each row is covered by `bin/selftest.php` unless noted.

| Requirement | State |
|---|---|
| OAuth install works and redirects correctly | Managed installation, scopes declared in `shopify.app.toml`. HMAC verification tested both ways round (accept and reject). |
| Mandatory compliance webhooks | `customers/data_request`, `customers/redact`, `shop/redact` — declared in the TOML, handled in `public_html/webhooks/compliance.php`, and answered through the same deletion code the retention cron uses, so the two cannot drift. |
| Webhook signature verification | Base64 HMAC over the raw body. Tested against forged signatures, tampered bodies and missing signatures, and asserted to be a *different* scheme from OAuth's. |
| Billing through Shopify's Billing API | `app/lib/Billing.php`. Nothing charges outside it. Access decided in one pure function; every Shopify subscription status mapped and storable. |
| Expiring access tokens | Mandatory for new public apps. Refresh happens transparently at a 300-second margin. |
| Privacy policy | `public_html/privacy.php`. Must be linked from the listing. |
| No customer PII stored in the clear | Email and phone are HMAC-SHA256 with a per-store salt, truncated to 16 bytes — deterministic so matching works, irreversible so the plaintext is gone. Cross-tenant hash collision is asserted impossible. |
| Data deleted on request | `Purge` clears every event shard and every core table, and is the single implementation behind both `shop/redact` and the retention cron. |
| Supported API version | `2025-07`. Shopify will not accept an app on an API version due for deprecation within 90 days — check this again at submission time. |

---

## 3. Decisions already made, worth being able to defend

A reviewer may ask about any of these. They are choices, not accidents.

**The app is not embedded.** Clicking it in the admin opens
`retention.digifyce.com` in a new tab. This is a full analytics surface, not an
admin panel. The consequence is that authentication uses the authorization code
grant rather than token exchange — token exchange needs an App Bridge session
token, which does not exist outside an iframe.

**Tracking continues when billing lapses; only the dashboard is gated.** Events
cost roughly 130 bytes each and a gap in history is permanent. A merchant who
subscribes two weeks after their trial ends finds those two weeks waiting for
them. Data for stores that *uninstall* is a separate matter, governed by
`UNINSTALL_PURGE_DAYS`.

**Ten scopes, each with a stated reason** in `shopify.app.toml`. An earlier
iteration of this project asked for seventy-five. Every one here is used.

**No `input_changed`, `input_blurred`, `input_focused` or `alert_displayed`
pixel events.** They carry raw `element.value`. In an audit of the previous
system those four leaked 2,626 email addresses and roughly 30,000 phone
numbers into an export. Not subscribing to them is why this app does not have
that problem to explain.

**Consent is Shopify's to enforce, and it does.** The web pixel declares
`analytics = true`, `marketing = false`, `sale_of_data = "disabled"` and runs in
the strict sandbox. Shopify withholds events from a visitor who declined.

---

## 4. Before submitting: test on a development store

Shopify requires the app to be tested on a development store first, and asks
for a screencast of setup and use.

```
# from shopify/
shopify app config link      # once the client_id exists
shopify app deploy           # registers the pixel extension and webhooks
```

Then, on a development store:

1. Install. Confirm the consent screen lists the ten scopes and nothing else.
2. Confirm the app opens `retention.digifyce.com` in a new tab, signed in, with
   no further setup asked of the merchant.
3. Browse the storefront and place a test order. Within one hour of crons, the
   Overview should show a visitor and an order.
4. **Billing**: set `BILLING_TEST=true` and `BILLING_ENABLED=true`. Subscribe,
   approve the charge, confirm the dashboard unlocks immediately (the
   `app_subscriptions/update` webhook), and that the plan page shows the trial
   end date.
5. Cancel the subscription from the Shopify admin. Confirm the dashboard locks
   and the plan page explains why.
6. Uninstall. Confirm `app/uninstalled` records it and that reinstalling keeps
   the store's history rather than creating a new one.
7. Set `BILLING_TEST=false` before going live, or nobody is ever charged.

Record the screencast during steps 1–5. Shopify asks for English or English
subtitles, and wants the expected outcome shown for each case.

---

## 5. Listing content still to write

- **App icon** — 1200×1200, no text.
- **Screenshots** — the Overview, Funnel, Campaigns and Retention tabs are the
  four worth showing. Use a store with plausible data, not an empty one.
- **App name and tagline.**
- **Description**, including what the app does *not* do.
- **Pricing**, matching `BILLING_PRICE` exactly. A mismatch between the listing
  and what the Billing API charges is a rejection.
- **Privacy policy URL** — `https://retention.digifyce.com/privacy.php`.
- **Support email and contact.**
- **Install eligibility** — Online Store sales channel. Consider whether to
  restrict by geography; the reports assume a single-currency store.
- **Test credentials and instructions** for the reviewer.

---

## 6. Known gaps, stated rather than discovered

**Single currency per store.** Revenue is summed in the store's own currency
with no conversion. A store selling in several currencies would see figures
added together that should not be.

**Cross-device attribution only works for repeat buyers.** A touch belongs to a
browser, not a person. Where the buyer is known, all their browsers are
combined — which is never possible for a first purchase. This is a floor on how
good pixel attribution can be, and is why Shopify's own attribution is kept
alongside rather than replaced.

**Event storage at scale is unresolved.** Yearly shards give roughly 65
store-months each on a 3 GB cap. The answer is needed before about fifteen
installs, not before launch. `bin/selftest.php` fails if events are ever queried
outside `Shard`, so the storage layer can still be changed.
