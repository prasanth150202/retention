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
| 1 | **Put `SHOPIFY_CLIENT_SECRET` in the server's `.env`** | The app itself is created and its `client_id` is in `shopify.app.toml`. The secret is the last piece and it is not there: every webhook on production is being rejected right now. Verified, not assumed — see below. |
| 2 | **Request `read_all_orders`** | It does **not** transfer from the earlier custom app. Step-by-step and a ready-to-paste justification in 1.2 below. |
| 3 | **Request protected customer data access** | Needed for `read_customers`. Same page as 1.2. See 1.3. |
| 4 | **Rotate the credentials pasted into chat** | The database passwords and the old app's `shpss_…` secret. Assume they are compromised. Deferred by decision, not done. |
| 5 | **Listing copy, icon and screenshots** | Section 5 below lists what is needed. |

**How to see #1 for yourself.** A webhook sent with a body reaches the
signature check, which needs the secret:

```bash
curl -i -X POST https://retention.digifyce.com/webhooks/app.php \
  -H 'Content-Type: application/json' \
  -H 'X-Shopify-Topic: app/uninstalled' \
  -H 'X-Shopify-Shop-Domain: probe.myshopify.com' \
  -H 'X-Shopify-Hmac-Sha256: AAAA' \
  -d '{"probe":1}'
```

`500` means the secret is still missing and no webhook can be verified.
`401` means it is set and the deliberately wrong signature was refused —
which is the answer you want. The app cannot pass review while it is 500:
Shopify tests the mandatory compliance webhooks and expects a `401` for a
bad signature.

The file is the `.env` at the **repository root** — the folder holding
`README.md`, `app/` and `config/`, *not* `public_html`:

```
SHOPIFY_CLIENT_ID=f53cb10aa7084449146c730ee5c7d791
SHOPIFY_CLIENT_SECRET=<Dev Dashboard → Settings → Credentials>
APP_URL=https://retention.digifyce.com
SHOPIFY_API_VERSION=2026-07
```

Nothing needs restarting; the next request picks it up.

Price is settled: **USD 19.00/month**, 14-day trial. Already the default in
`config/config.php`; no `.env` entry is needed unless it changes. It must match
the App Store listing exactly — a mismatch is a rejection.

### 1.1 Create the app and get `client_id` / `client_secret`

Shopify's own guidance is that App Store apps are created **through the CLI**,
not by hand in the dashboard — the CLI creates the app, writes the config, and
registers the extensions in one place. This project is already a CLI project,
so it is three commands.

```bash
cd shopify
npx shopify auth login          # opens a browser
npx shopify app config link     # creates or links the app
npx shopify app deploy          # pushes config + the web pixel extension
```

At `config link`:

- choose your organisation
- choose **Create a new app** (not one of the existing custom apps — the old
  one's scopes and `read_all_orders` grant do not carry over)
- name it `retention-dashboard`
- **if it offers to write a differently named config file** (for example
  `shopify.app.retention-dashboard.toml`), let it, and say so — the repo
  currently expects `shopify.app.toml` and the two need reconciling.

`config link` fills in the `client_id`, which is currently blank on line 23.
A client id is not a secret and is committed to git deliberately.

Then the secret:

- Dev Dashboard → **Apps** → `retention-dashboard` → **Settings** → **Credentials**
- copy the **Client ID** and **Client secret**

Both go in the server's `.env` — never in git:

```
SHOPIFY_CLIENT_ID=<client id>
SHOPIFY_CLIENT_SECRET=<client secret>
```

The client secret is also what Shopify signs webhooks with, so the app cannot
verify a single webhook until it is set.

### 1.2 Request `read_all_orders`

By default the Admin API returns only the last 60 days of orders. This app
cannot work on that: every cohort, repeat-rate and lifetime-value figure is a
statement about a customer's **first** order, and a customer whose first order
predates the window is silently counted as a brand new one. The result is not
missing data, it is wrong data that looks plausible.

- Partner Dashboard → **Apps** → `retention-dashboard` → **API access**
- under **Access requests**, find the **Read all orders** card → **Request access**
- describe the app and why, then submit

Something like the following, which is accurate to what this app actually does:

> retention-dashboard is a retention analytics app for Shopify merchants. Its
> core function is repeat-purchase and cohort analysis: for every customer it
> determines their first, second and third order, the time between them, and
> the share of each monthly acquisition cohort that orders again within 30, 60,
> 90 and 180 days.
>
> All of those figures depend on knowing a customer's first order. Restricted to
> the default 60-day window, any customer whose first purchase predates that
> window is indistinguishable from a new customer, so the repeat-purchase rate
> is understated and returning customers are miscounted as new. The app cannot
> report a correct retention figure for any store without full order history.
>
> Data handling: order history is read once at install and then kept current by
> webhook and an hourly incremental sync. Customer email and phone are hashed
> with HMAC-SHA256 under a per-store salt at the moment they arrive and the
> plaintext is never written to storage. All data for a store is deleted on the
> shop/redact webhook and after a configurable retention period following
> uninstall.

File this early. It is reviewed by a person and it blocks nothing else, so
there is no reason for it to sit on the critical path.

### 1.3 Request protected customer data access

`read_customers` is protected. It is requested from the same **API access**
page, and it is what lets the app read the email and phone it hashes for
identity matching — the thing that makes a guest checkout and a later account
count as one person rather than two.

Worth knowing: since December 2025 Shopify also gates *web pixel* access to
customer PII behind this same approval. **That does not break this app.** The
pixel deliberately reads no email or phone at all; the only gated field it
touches is `init.data.customer.id`, and it already handles that being null.
Without the approval the app loses some cross-device matching for logged-in
browsing and nothing else.

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
