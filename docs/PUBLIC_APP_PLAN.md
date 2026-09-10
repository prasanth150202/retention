# Pivot: public Shopify App Store app

**Status:** plan, superseding parts of [`TECHNICAL_PLAN.md`](../TECHNICAL_PLAN.md)
**Date:** 2026-09-10

---

## 1. What changed

The original plan served 3–5 known Digifyce clients through per-store custom
apps. The new goal is a single public app, listed on the Shopify App Store,
installable by any merchant, billed through Shopify.

### Why this is the right call

The stated motivation was avoiding repetitive setup. The stronger argument is
one that was not made: **`read_all_orders` is requested per app and reviewed
each time.** Five clients under custom distribution means five separate review
requests, five protected-data declarations, five chances of rejection. One
public app is reviewed once and installs anywhere.

The pixel convenience is real but secondary.

### What it costs

| | Custom distribution | Public / App Store |
|---|---|---|
| App review | none | full, iterative |
| `read_all_orders` | per app | once |
| Protected customer data | automatic | reviewed against functionality |
| Compliance webhooks | not required | three, mandatory |
| Billing | invoice however you like | Shopify Billing API only |
| Support | your own clients | strangers, ongoing |
| Merchants | you onboard each | self-serve, unknown number |

Unlisted distribution does **not** avoid review — Shopify's requirements are
identical for listed and unlisted apps; visibility only controls App Store
search. There is no "public but unreviewed" route to live stores.

---

## 2. What survives

Nothing built so far is wasted. The server side never cared how a token
arrived.

### Untouched

- **Schema** — all four migrations. `tenants` already models "a connected
  store" with no assumption about how it was connected.
- **Ingest** — `c.php`, the spool, `import.php`. A pixel is a pixel.
- **`Db`, `Shard`, `Dim`, `Hash`, `Crypto`, `GeoIp`, `Job`, `EventType`**
- **`ShopifyApi`** — GraphQL, bulk operations, cost-based rate limiting
- **`sync.php`, `health_check.php`**
- **Migrations, setup page, self-test** (45 checks)

### Changes shape, keeps its logic

- **`ShopifyOAuth`** — the HMAC verification, state handling and token
  exchange are all correct and stay. What inverts is who starts the install:
  today our console generates a link, and for a public app Shopify sends the
  merchant to us with `?shop=`. That is a new entry point, not new logic.

- **`Snippet::pixel()`** — the event mapping (which Shopify events, how each
  maps to our compact schema, money as integer minor units, purchases flushed
  immediately) moves into the web pixel extension bundle essentially verbatim.
  Only the delivery mechanism changes: an extension Shopify installs rather
  than code a merchant pastes.

- **Console** — staff auth stays. Merchant sessions are added alongside.

### Assumption that breaks

**Capacity.** Every number in `TECHNICAL_PLAN.md` §14 assumed 3–5 stores. See
§4.

---

## 3. What is new

| Item | Why | Optional? |
|---|---|---|
| `/install?shop=…` | Shopify-initiated install | No |
| Scopes `write_pixels`, `read_customer_events` | Required to create a web pixel | No |
| Web pixel extension | The one-click pixel | Only if you accept manual pasting |
| `webPixelCreate` on install | Activates it per store | With the above |
| **Expiring token handling + refresh** | Mandatory for public apps — see §3.2 | **No** |
| `app/uninstalled` webhook | Stop syncing, mark tenant dead | No |
| `customers/data_request`, `customers/redact`, `shop/redact` | Mandatory for App Store | No |
| Merchant session from admin-link HMAC | Non-embedded apps get `shop`+`hmac` on the app URL | No |
| Shopify Billing API | Cannot charge outside it | Only if free |
| Privacy policy page | Listing requirement | No |
| **Merchant-facing dashboard** | The actual product | No — see §6 |

### 3.1 The install flow, concretely

Checked against Shopify's documentation rather than assumed. Two things are
simpler than the first draft of this plan supposed, and one is harder.

**Simpler: Shopify managed installation.** Scopes are declared in
`shopify.app.toml` and Shopify requests them itself when the merchant
installs. We do not build an authorize URL, and `ShopifyOAuth::beginInstall()`
— which existed to construct one — is no longer needed. Managed installation
is the default; the legacy flow requires opting in with
`use_legacy_install_flow = true`, and is discouraged precisely because it lets
an app end up with different scopes on different stores.

**Simpler: compliance webhooks are declared, not registered.** They go in the
TOML under `compliance_topics` and Shopify wires them up on deploy:

```toml
[[webhooks.subscriptions]]
uri = "/webhooks/compliance.php"
compliance_topics = [ "customers/redact", "customers/data_request", "shop/redact" ]
```

So the work is writing the handler, not the registration.

**Merchant does nothing beyond clicking Install:**

```
Install button
  → Shopify grants the TOML scopes           (managed installation)
  → redirect to application_url
  → we exchange the code for an access token (authorization code grant)
  → webPixelCreate                            (pixel live, no theme editing)
  → done — data starts flowing
```

Note that the **web pixel needs no theme embed at all.** A theme app extension
is only required for the Liquid identity layer — reading `customer.id` while a
known customer browses — and that one does need the merchant to toggle an app
embed in the theme editor. The funnel is complete without it.

**Non-embedded apps use the authorization code grant**, which is what is
already built and tested. Token exchange is the embedded-app path: it needs an
App Bridge session token, and there is no App Bridge here.

> **To verify in P1:** exactly which query parameters Shopify sends when a
> merchant opens a *non-embedded* app from the admin. `shop`, `hmac` and
> `timestamp` are expected, and `ShopifyOAuth::verifyHmac()` already handles
> that signature scheme — but merchant login depends on it, so it gets
> confirmed empirically against a development store rather than assumed.

### 3.2 Expiring access tokens — the one that is harder

**New public apps cannot use non-expiring offline access tokens for the
GraphQL Admin API.** Existing public apps lose them on 1 January 2027. Custom
apps are unaffected, which is why this never came up until now.

The token request must include `expiring=1`, and Shopify returns:

| Field | Meaning |
|---|---|
| `access_token` | Valid ~60 minutes |
| `refresh_token` | Used to get a new access token |
| `expires_in` | Seconds until the access token dies |
| `refresh_token_expires_in` | 90 days |

This is real new machinery, and it touches things already built:

- **Schema.** `tenants` currently stores one encrypted token and nothing else.
  It needs the refresh token (also encrypted) and both expiry timestamps.
- **`ShopifyApi`.** It assumes a token that always works. It needs to refresh
  on expiry, and to treat a 401 as "refresh and retry once" rather than "the
  app was uninstalled".
- **`sync.php`.** An hourly job comfortably refreshes inside the 90-day
  refresh window. A store whose sync has been broken for 90 days needs
  reinstalling, and should be surfaced as an alert rather than discovered.

None of this is difficult, but it is not optional and it is easy to discover
late — the failure mode is every API call breaking exactly one hour after a
successful install.

### On the toolchain

Web pixel extensions are defined by `shopify.extension.toml` and deployed with
Shopify CLI, which requires Node. This is a **local** dependency — the server
stays PHP with no build step. The extension lives in its own directory with
its own tooling so it never touches the PHP deployment.

### On not embedding

Shopify prefers embedded apps but does not mandate them; an app may open an
external site. When a merchant clicks a non-embedded app, Shopify opens the
app URL with `shop`, `hmac` and `timestamp` — the same signature scheme
`ShopifyOAuth::verifyHmac()` already implements and tests. Merchant login is
therefore nearly free.

Expect review to ask why it is not embedded. "The analytics surface is a full
dashboard that does not fit an admin panel" is a reasonable answer.

---

## 4. The capacity problem

A 3 GB shard holds roughly **21 million events**, about **65 store-months** at
the measured rate of ~323,000 events per store per month.

| Stores | A shard lasts |
|---|---|
| 5 | 13 months |
| 20 | 3 months |
| 50 | 5 weeks |
| 100 | 3 weeks |

Hostinger allows 100 databases, so you do not hit a hard wall — you hit an
operational one first, since each shard needs its own credential in `.env`.

**Realistic ceiling on Hostinger shared: 10–20 stores.**

### Deferred, deliberately

The decision on where events live at scale is **deferred**. That is
defensible because the seam already exists: `Shard::connectionForDate()` and
`Db::shardTarget()` are the only places that know where an event row goes.

Three futures, all reachable from here:

- **Hostinger VPS** — one large database, no 3 GB cap, real cron. The shard
  router degrades to a single shard by configuration.
- **Managed columnar store** (ClickHouse, BigQuery) — a new implementation
  behind the same seam.
- **Stay and cap installs** — contradicts an App Store listing.

**When this must be answered:** before roughly the fifteenth install, or
before submitting for review if you expect volume from day one. Not before.

**What Phase P0 does about it now:** keeps the seam clean and adds a test that
fails if anything starts querying events outside it.

---

## 5. Uninstall and retention

Also **deferred** — but only the policy, not the mechanism.

`shop/redact` is mandatory and must be honoured within 30 days, so the
deletion machinery gets built regardless. What is deferred is how long to wait
before purging: immediately, 90 days, or keep anonymous rollups and purge raw.

That becomes a config value, changeable at any time.

Worth noting that App Store distribution changes the shape of this. "Keep
everything forever" was set for clients under contract. With self-serve
installs, merchants will trial for days and uninstall — retaining their raw
events indefinitely is storage you pay for and personal data you have no
ongoing basis to hold.

---

## 6. What does not exist yet

Worth stating plainly, because the pipeline being solid can disguise it:

**There is no analytics layer at all.** What exists is ingest, storage,
sync, and a staff console. Not built:

- Rollup jobs — every `rollup_*` table is empty
- Identity resolution — `person_id`, `order_sequence`
- Attribution resolution — the four models
- The dashboard itself — funnel, campaigns, products, retention, abandonment

For an internal tool that was the last milestone. For an App Store product,
**the dashboard is the product** — the thing a merchant evaluates in thirty
seconds and decides whether to pay for. It is now the largest remaining piece
of work and the one that determines whether the listing succeeds.

---

## 7. Phases

Ordered so that everything reviewable is built before review is requested, and
so nothing blocks on a Shopify decision that could have been requested sooner.

### P0 — Schema and scaffolding *(small)*
- Migration 005: expiring-token columns on `tenants` (`refresh_token_enc`,
  `token_expires_at`, `refresh_expires_at`) plus `plan`, `trial_ends_at`,
  `billing_status` so billing is not a migration under pressure
- Retention-policy config value, no enforcement yet
- A test that fails if events are queried outside `Shard`, keeping the
  storage decision (§4) cheap to defer
- Strip the CLI scaffold to `shopify.app.toml` + `extensions/` — see §10

### P1 — Public install flow *(no review needed; testable on a dev store)*
- `shopify.app.toml`: scopes, `embedded = false`, `application_url`,
  webhook and compliance-topic declarations
- Install entry point and the authorization code grant with `expiring=1`
- Token refresh in `ShopifyApi`, and 401 handled as refresh-and-retry
- `app/uninstalled`
- Merchant session from the admin-link HMAC — confirm the parameters first
- Merchant dashboard shell, store-scoped

### P2 — One-click pixel
- Shopify CLI scaffold, kept isolated from the PHP tree
- Web pixel extension carrying the event mapping from `Snippet::pixel()`
- `webPixelCreate` on install, with the write key as a validated setting
- Theme app extension for the identity snippet *(optional; the pixel alone is
  a complete funnel)*

### P3 — Compliance
- Three mandatory webhooks, HMAC-verified
- Retention enforcement
- Privacy policy page

### P4 — Analytics *(the product)*
- Identity resolution and `order_sequence`
- Attribution, four models
- Rollup jobs and the 3-day reclose
- The dashboard tabs

### P5 — Billing
- Shopify Billing, subscription and trial
- Plan gating

### P6 — Listing and submission
- Screenshots, copy, pricing, support contact
- `read_all_orders` request **for the new app** — it does not transfer
- Protected customer data declaration, now reviewed
- Submit, expect iteration

### Start now, in parallel
The `read_all_orders` request for the new app should be filed as soon as the
app exists. It is reviewed, it does not carry over from the existing app, and
it gates nothing else — so there is no reason for it to be on the critical
path.

---

## 8. Risks

| Risk | Mitigation |
|---|---|
| Review rejects non-embedded | Answer prepared; embedding is a UI change, not architecture |
| Review rejects protected customer data scope | Only email and phone requested, both stored as irreversible hashes — a strong position |
| Capacity forces a move mid-growth | Seam kept clean; decision has a named trigger (§4) |
| Dashboard underwhelms | Largest piece; deserves the most design attention of anything here |
| Shopify CLI toolchain rot | Isolated to one directory, used only to deploy the extension |
| Billing edge cases | Shopify handles dunning; app only reads subscription state |

---

## 9. Open

- Event storage at scale — §4, answer before ~15 installs
- Retention policy after uninstall — §5, answer before review submission
- App name
- Pricing

---

## 10. The Shopify CLI project

`shopify app init` produces the React Router + Prisma template: a complete
Node application, embedded, with its own database. **We keep none of it.**

That is not a criticism of the template — it is a good starting point for
someone building a Node app. It is simply the wrong shape here. It is
embedded, we are not; it is Node, our server runs PHP with no build step; it
brings Prisma, we already have a schema and a migration runner.

**The CLI is a deployment tool, not a runtime.** Nothing requires an app to
run Shopify's template, or any Node at all in production. The project reduces
to two things:

```
shopify/
├── shopify.app.toml     app config: scopes, URLs, webhooks, compliance topics
└── extensions/
    └── web-pixel/       the pixel, deployed with `shopify app deploy`
```

No `web/`, no `shopify.web.toml`, no Prisma, no React Router. Node lives on a
developer's laptop to run `shopify app deploy` and nowhere else.

### Why it lives in this repository

The extension's event mapping and `import.php`'s expectations are two halves
of one contract: if the extension starts sending a field the importer does not
read, or renames one it does, data is silently lost. Keeping them in one
repository means a single commit changes both. Separate repositories are how
those two halves drift.

`node_modules/` is gitignored, and the root `.htaccess` already denies the
directory, so nothing about it reaches the web server.

### Configuration

Notable values, against the scaffold's defaults:

| Setting | Scaffold | Ours |
|---|---|---|
| `embedded` | `true` | **`false`** |
| `application_url` | `https://example.com` | `https://retention.digifyce.com` |
| `scopes` | `write_products,write_metaobjects,…` | the eight we need, plus `write_pixels` and `read_customer_events` |
| Metafield / metaobject blocks | present | removed — template demo material |
| `[build] automatically_update_urls_on_dev` | `true` | **`false`** — it would rewrite the production URL during local development |

That last one matters more than it looks: left on, running `shopify app dev`
points the live app at a developer's tunnel.
