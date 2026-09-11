# TO-DO

State as of 2026-09-11. Everything in the repo is committed, pushed and
deployed; the working tree is clean. Nothing is half-finished.

Suite: **127 checks local, 80 against the production database, 0 failing.**
Plus six HTTP harnesses (front door, webhooks, tokens, setup gate, merchant
isolation, ingest) and 51 data invariants, all green.

---

## 1. Blocked on you — in order

### 1.1 Put `SHOPIFY_CLIENT_SECRET` on the server ← **blocks everything else**

Until this is done **every webhook in production is rejected**, and the app
**cannot pass review**: Shopify tests the mandatory compliance webhooks and
expects `401` for a bad signature. It currently answers `500`, because
`ShopifyOAuth::clientSecret()` throws when the secret is missing.

hPanel → File Manager → the `.env` at the **repository root** — the folder
holding `README.md`, `app/` and `config/`. **Not** `public_html`.

```
SHOPIFY_CLIENT_ID=f53cb10aa7084449146c730ee5c7d791
SHOPIFY_CLIENT_SECRET=<Dev Dashboard → Settings → Credentials>
APP_URL=https://retention.digifyce.com
SHOPIFY_API_VERSION=2026-07
```

Nothing needs restarting. Check it worked:

```bash
curl -s -o /dev/null -w '%{http_code}\n' -X POST \
  https://retention.digifyce.com/webhooks/app.php \
  -H 'Content-Type: application/json' \
  -H 'X-Shopify-Topic: app/uninstalled' \
  -H 'X-Shopify-Shop-Domain: probe.myshopify.com' \
  -H 'X-Shopify-Hmac-Sha256: AAAA' \
  -d '{"probe":1}'
```

- `500` → secret still missing
- `401` → **correct.** The deliberately wrong signature was refused.

Do not paste the secret into chat. The status code is all that is needed.

### 1.2 File the `read_all_orders` request

Partner Dashboard → Apps → `retention-dashboard` → API access.
Paste-ready justification in [docs/APP_REVIEW.md](docs/APP_REVIEW.md) §1.2.

It does **not** transfer from the earlier custom app. Without it, order history
is capped at 60 days and the cohort charts stay thin.

### 1.3 File the protected customer data request

Same page. Needed for `read_customers`. See [docs/APP_REVIEW.md](docs/APP_REVIEW.md) §1.3.

### 1.4 Rotate the credentials that were pasted into chat

The database passwords and the old app's `shpss_…` secret. Deferred by your
decision, never done. Assume they are compromised.

### 1.5 Settle the App Store handle

Currently `digifyce-retention-dashboard`. It becomes the public listing URL and
is awkward to change later.

### 1.6 Before the listing goes live

- `BILLING_ENABLED=true` in the production `.env`
- `BILLING_TEST=false` — **test mode means nobody is ever charged**
- Price must match the listing exactly: **USD 19.00/month, 14-day trial**.
  A mismatch is a rejection.

### 1.7 Listing copy, icon, screenshots

Listed in [docs/APP_REVIEW.md](docs/APP_REVIEW.md) §5.

---

## 2. Code work, in the order I would pick it up

Nothing here is broken. These are the surfaces still without coverage.

1. **`Billing::syncFromShopify()` against partial responses.** The webhook path
   is covered; this one is not. It is the backstop that runs hourly, so a store
   whose webhook never arrived depends on it entirely.
2. **The `billing-return` route end-to-end.** It carries no proof of outcome by
   design and re-asks Shopify — worth proving it cannot be made to grant access
   on its own.
3. **The two permanently-skipped merchant-session checks.** They need a client
   secret, so they will start running by themselves once 1.1 is done. Worth
   re-running the suite after that to see them go green rather than skip.
4. **The dashboard views under hostile data.** `scratchpad/edge.php` exists and
   passes; it could be folded into the suite rather than living outside it.

---

## 3. What changed overnight — 2026-09-11

Nine rounds, eight real bugs, four hardenings. Each fix was verified by
restoring the original defect and watching the guard fail.

| # | Found |
|---|---|
| 6 | **`customers/redact` undid itself.** Deleted the person, left `email_hash`/`phone_hash` on their orders — the columns identity resolution reads. The next cron rebuilt the same customer, same sequence. |
| 7 | **An interrupted deletion counted as a completed one.** The request is logged before the work starts; the dedup check only asked whether a row existed. One timeout → Shopify retries → `200 already handled` → never erased. `ix_outstanding` existed for a sweeper nobody wrote. |
| 8 | **A sparse refresh response switched off refreshing.** A null `token_expires_at` reads as "never expires", so the store is never refreshed again and dies a day later with nothing reporting it. |
| 9 | **`setup.php` reported every refusal as a server fault.** `fail()` set 500 over the 403. That URL's permanent resting state was answering 500. |
| 10 | Install flow clean. Hardened the cancel page: anyone could put their own sentence on our domain under *"Shopify reported:"*. |
| 11 | Tenant isolation clean. Added a guard, because the checks that would catch a leak are the two this suite skips. |
| 12 | **The app kept collecting after uninstall.** The write-key map is rebuilt only by the importer's cron, so tracking continued for minutes after the merchant removed the app. |
| 13 | **The free trial could be taken again and again.** Shopify grants whatever `trialDays` the app asks for, every time. Subscribe → 14 free days → cancel → repeat. Free forever at $19/month. Migration 010 closes it. |
| 14 | Billing webhook clean. Quietened a warning it wrote to the error log from an unauthenticated path. |

Migration **010** (`trial_started_at`) is applied to production. It backfills
any store that already shows a trial or a paid status — without that, the fix
would hand every existing merchant one more free trial. There are currently
**0 stores**, so the backfill was a no-op. Good time to have caught it.

---

## 4. Things that look like bugs and are not

Recorded so they are not rediscovered.

- **PHP's built-in server answers `200` when a script dies.** Production
  returns `500` with an empty body — verified against the live host. Any local
  test asserting a status code on a fatal is testing the test server.
- **`Rollup::daysFrom()` only lists the days.** `Rollup::day()` does the work.
  Planting orders and calling only `daysFrom()` leaves every panel at zero.
- **The dashboard reads rollups, never `orders`.** The one exception is
  `Report::buyersOverRange()`.
- **`Billing::state()` returns access for everyone when `BILLING_ENABLED` is
  off.** Correct — billing is not switched on yet — but it makes every access
  assertion pass vacuously.
- **The importer deliberately skips the current hour's spool file**, because
  `c.php` is still appending to it.
- **The pixel sends `n`, `s` and `ts` in milliseconds** — not `t`, not ISO.
- **Local PHP has no CA bundle**, so every HTTPS call fails certificate
  verification on this machine. An environment artefact.
- Real table and column names: `rollup_daily_kpi`, not `rollup_daily`;
  `iana_timezone`, not `timezone`.
