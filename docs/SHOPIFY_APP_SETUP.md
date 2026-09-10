# Creating the Shopify app

What to build in the Shopify Partner Dashboard before any store can be
connected, and what to do about the one scope that needs approval.

Start this early. Two steps involve a Shopify review, and neither is instant.

---

## Why a Partner Dashboard app, not a custom app in the admin

Shopify offers two routes, and only one works here.

A **custom app created inside a store's admin** (Settings → Apps → Develop
apps) is quicker: you pick scopes and it hands you a token, no OAuth. But it
**cannot obtain `read_all_orders`** — the option does not appear. That caps
you at 60 days of order history, permanently, which makes every retention
figure in this platform impossible to calculate.

A **Partner Dashboard app** with custom distribution can request the scope, so
that is the route. It costs an OAuth flow, which is already built.

---

## 1. Create the app

Partner Dashboard → **Apps → Create app → Create app manually**. Name it
something a merchant will recognise, e.g. `Digifyce Retention`.

---

## 2. Set the redirect URL

**App setup → URLs → Allowed redirection URL(s):**

```
https://retention.digifyce.com/oauth/callback.php
```

This must match **character for character**. Shopify rejects the install if it
differs by a scheme, a trailing slash or a capital letter, and the error it
shows does not say which part is wrong.

---

## 3. The scopes

Eight. The merchant sees this list on the consent screen, so each one should
be defensible — an app asking for gift cards and bank accounts when it claims
to do analytics is a fair reason to refuse the install.

| Scope | Why it is needed |
|---|---|
| `read_all_orders` | **Order history beyond 60 days.** Every retention, cohort and lifetime-value figure depends on it. See §4. |
| `read_orders` | Orders, totals, line items — the revenue figures |
| `read_customers` | Customer records, for first / second / third purchase logic. Needs §5. |
| `read_products` | Product titles and types for the products report |
| `read_checkouts` | Abandoned checkouts, and the value sitting in them |
| `read_inventory` | Stock state — separates "not selling" from "was out of stock" |
| `read_price_rules` | Which discount codes drove which orders |
| `read_locales` | Store currency and timezone, so figures are reported correctly |

As a single string:

```
read_all_orders,read_orders,read_customers,read_products,read_checkouts,read_inventory,read_price_rules,read_locales
```

This list lives in `app/lib/ShopifyOAuth.php` and is sent automatically during
the install. You do not need to type it anywhere unless the Partner Dashboard
asks for it.

---

## 4. Requesting `read_all_orders`

**This is the step with a wait on it, and the one worth starting today.**

By default Shopify gives an app only the **last 60 days** of orders. There is
no error and no warning: the install succeeds, the API returns data, and the
data is simply truncated. Cohort charts come out empty months later with
nothing pointing back to this decision.

### How to request it

1. Partner Dashboard → **Apps** → your app
2. Click **API access** in the sidebar
3. Find the **Access requests** section
4. On the **Read all orders** card, click **Request access**
5. Describe the app and why it needs full order history
6. Submit

### What to write

Shopify wants to know the data is genuinely needed rather than collected
because it was available. Something like:

> This app produces customer retention analytics for merchants: repeat
> purchase rate, time between first and second order, and cohort retention
> curves. All of these are calculated by comparing a customer's first order
> against their later ones. With only 60 days of history a customer's first
> order is usually outside the window, so the calculations cannot be
> performed at all. We read order dates, totals and line items; we do not
> modify orders.

Adjust to be accurate — do not claim anything the app does not do.

### While you wait

Everything else works. You can create the app, connect stores, install the
pixel and collect behavioural data with the other seven scopes. Only order
history beyond 60 days is blocked.

When the app is connected without this scope, the system detects it and says
so on the store page rather than letting it be discovered later. Once granted,
reconnect the store and the backfill picks up the full history.

---

## 5. Protected customer data

`read_customers` reads personal data, so Shopify requires a declaration.

1. Partner Dashboard → **Apps** → your app
2. **API access requests** → **Protected customer data access** → **Request access**
3. Select the data used and explain why
4. Select the individual fields needed, with a justification each
5. Complete **Data protection details** and save

### Which fields to request

This platform hashes email addresses and phone numbers rather than storing
them readably — they are used to recognise that two orders came from the same
person, and cannot be read back out. Request:

| Field | Justification |
|---|---|
| Email | Matching repeat purchases to one customer. Stored only as an irreversible hash. |
| Phone | As above. In Indian ecommerce phone is the more reliable identifier, since guest checkout is common. |

Name and address are **not** needed. Do not request them.

### Does this need review?

For **custom distribution** apps — which is what this is — protected customer
data access is *always available* and does not go through review. You still
have to complete the declaration, but there is no waiting.

Public apps do require review. Keep the distribution set to custom unless
there is a reason to change it.

---

## 6. What to hand over

Once the app exists, the values needed to connect a store are:

- **Client ID** — Partner Dashboard → your app → *Client credentials*
- **Client secret** — same page

They are entered in the console at **Stores → (a store) → Connect the Shopify
API**, which produces a single-use install link valid for 15 minutes.

The client secret is stored encrypted for those 15 minutes, because it is
needed to verify Shopify's signature on the callback, and is not retained
afterwards. The resulting access token is encrypted with a key held outside
the database.

---

## 7. Order of operations

1. Create the app *(minutes)*
2. Set the redirect URL *(minutes)*
3. Request `read_all_orders` *(submit now — this is the wait)*
4. Complete the protected customer data declaration *(no review for custom distribution)*
5. Connect a store and install the pixel — **do not wait for step 3**
6. Once `read_all_orders` is granted, reconnect the store to pull full history

Behavioural tracking works from step 5. Revenue works as soon as the app is
connected. Only retention needs step 3, and it can be backfilled afterwards.

---

## Sources

- [Shopify API access scopes](https://shopify.dev/docs/api/usage/access-scopes)
- [Protected customer data](https://shopify.dev/docs/apps/launch/protected-customer-data)
- [Manage access scopes](https://shopify.dev/docs/apps/build/authentication-authorization/app-installation/manage-access-scopes)
