# What tracks what, and what it means legally

Written to settle two things: which mechanism can see which events, and
whether showing that data creates a legal problem.

---

## 1. The correction

> "previously we discussed using a liquid pixel to track but it does track
> checkout as it is in the theme"

**It does not, and never did.** This was established at the start of the
project and has not changed:

> *Liquid cannot touch checkout. `theme.liquid` is storefront-only, and
> `checkout.liquid` was Plus-only and was deprecated for the thank-you /
> order-status page in August 2025. So every checkout funnel step is
> pixel-only.*

Checkout is not part of the theme. It is a separate surface Shopify hosts, and
since the deprecation there is no Liquid in it at all — not for Plus stores,
not for anyone. A script in `theme.liquid` stops existing the moment a shopper
clicks "Checkout".

This matters because it means the theme route was never the complete one. The
original design used **both** feeds precisely because neither is sufficient
alone, and the reason has not changed under the new plan.

---

## 2. What each mechanism can see

Three ways to get data, and they see different things.

| Event | Web Pixel | Theme extension | Admin API |
|---|:---:|:---:|:---:|
| `page_viewed` | ✅ | ✅ | ❌ |
| `product_viewed` | ✅ | ✅ | ❌ |
| `product_added_to_cart` | ✅ | ✅ | ❌ |
| `cart_viewed` | ✅ | ✅ | ❌ |
| `checkout_started` | ✅ | **❌** | ❌ |
| `contact_info_submitted` | ✅ | **❌** | ❌ |
| `shipping_info_submitted` | ✅ | **❌** | ❌ |
| `payment_info_submitted` | ✅ | **❌** | ❌ |
| `checkout_completed` | ✅ | **❌** | ❌ |
| Order totals, refunds | ❌ | ❌ | ✅ |
| Customer order history | ❌ | ❌ | ✅ |
| Abandoned checkouts | ❌ | ❌ | ✅ |
| Logged-in `customer.id` while browsing | maybe¹ | ✅ | ❌ |
| Click text, scroll depth, time on page | ❌ | ✅ | ❌ |

¹ The web pixel exposes `init.data.customer`, gated behind protected customer
data access. Unverified — see §6.

### The two things only the pixel can do

**See checkout.** Every step from `checkout_started` to `checkout_completed`.
Without these there is no conversion rate, no abandonment analysis, and no
checkout funnel.

**Link a visitor to their order.** `checkout_completed` carries
`data.checkout.order.id`. That single field is what connects "this person
browsed these products" to "this person spent ₹2,400". Without it, behaviour
and revenue sit in two separate piles with nothing joining them — which for a
*retention* product is fatal, since retention is entirely about attaching
purchases to people over time.

### The one thing only the theme can do

Read Liquid: `{{ customer.id }}`, `{{ customer.orders_count }}`. That makes a
logged-in returning customer identifiable *while browsing*, rather than only
at checkout. Genuinely useful, not load-bearing.

Also full DOM access — click text, scroll depth. Useful for a heatmap product.
Not what this one is.

---

## 3. Why the answer is pixel-first

| | Funnel | Visitor ↔ order | Merchant action | Survives theme change |
|---|---|---|---|---|
| **Pixel only** | complete | ✅ | **none** | ✅ |
| Theme only | stops at cart | ❌ | toggle + save | ❌ |
| Both | complete + identity | ✅ | toggle + save | pixel does |

Pixel-only is a shippable product. Theme-only is not, because it cannot see a
purchase happen.

---

## 4. The legal question

The concern was: *"I'm afraid showing that data we might hit a legal barrier."*

It is the right thing to worry about, and the answer points the same way as
everything above.

### Who is responsible for what

The merchant is the **data controller** — it is their store, their customers,
their relationship. The app is a **data processor**, handling data on their
instructions. This is the standard arrangement for every Shopify app and it
puts the primary obligation on the merchant, not on us.

That does not make us free of duties. It means our duties are the processor's
ones: process only what we were asked to, secure it, delete it when told, and
be transparent about what we do.

### What this app actually stores

Conservative by design, and most of the exposure was engineered out before
this question was asked:

| Data | How it is stored |
|---|---|
| Email address | **Irreversible hash**, salted per store. Cannot be read back. |
| Phone number | **Irreversible hash**, salted per store. Cannot be read back. |
| IP address | **Not stored at all.** Used to derive a city, then discarded. There is no `ip` column. |
| Visitor id | Hashed, salted per store |
| Order totals, products | Stored plainly — commercial data, not personal |
| City / region | Stored — identifies a place, not a person |

The hashes exist so that two orders can be recognised as the same person. They
cannot be turned back into an email. A stolen copy of the database yields no
contact details for anyone.

**We also deliberately do not subscribe** to the pixel's `input_changed`,
`input_blurred` and `input_focused` events. Those carry raw `element.value` —
real emails and phone numbers as they are typed. An earlier raw export in this
project captured 2,626 email addresses and roughly 30,000 phone numbers that
way. Not subscribing is why that cannot happen here.

### What the dashboard shows

Aggregates. Counts, rates, totals, cohorts. No screen displays an individual
customer's email or phone, because the app does not hold either in readable
form. There is no contact-export feature.

That is the direct answer to the fear: **you cannot leak what you never
stored**, and a dashboard of counts is not a disclosure of personal data.

### Consent — and this is the part that favours the pixel

A **web pixel** runs on a surface Shopify manages, and Shopify applies the
store's consent decisions to it. Where consent is required and not given, the
pixel does not collect.

**Theme code gets none of that.** A script in the theme runs regardless, and
the app is responsible for checking consent itself through the Customer
Privacy API — `analyticsProcessingAllowed()`, the `visitorConsentCollected`
event, and so on. Get it wrong and you are collecting without consent in a
regulated region, on a merchant's storefront, under their controller
liability.

So the mechanism that is easier to install is also the one that is harder to
get legally wrong. Those usually pull in opposite directions. Here they do not.

### What is still required

Not optional, regardless of the above:

- **Privacy policy** — a listing requirement and a real obligation
- **Three compliance webhooks** — `customers/data_request`, `customers/redact`,
  `shop/redact`, answered within 30 days
- **Protected customer data declaration** — we request email and phone only,
  and can say truthfully that both are stored irreversibly hashed
- **Retention policy** — how long data survives an uninstall (open, §5 of the
  public app plan)
- **DPA** — merchants may ask for one; a template is standard

None of these is a barrier. They are paperwork and two small endpoints.

---

## 5. Answering "as easy as possible"

Easiest for the **merchant**, which is what determines installs:

```
Click Install
  → Shopify grants the scopes            (managed installation)
  → we exchange for an access token
  → webPixelCreate                        (tracking live)
  → done
```

No theme editor. No copy-paste. No API keys. No consent code to get wrong.

Easiest for **us**: one extension instead of two, one set of event mapping,
and no consent implementation of our own.

Adding the theme extension later costs a merchant toggle-and-save, which is
the step most likely to be skipped. It should be optional and clearly worth
it, or not built at all.

---

## 6. Open question worth five minutes

Does the web pixel's `init.data.customer` return a customer id for a logged-in
shopper once protected customer data access is approved?

If yes, we get identity-while-browsing with **zero** merchant action, and the
theme extension becomes unnecessary entirely — removing the only remaining
reason to build it.

Checkable as soon as the app is installed on a development store. Worth
answering before committing to a second extension.

---

## 7. Conclusion

Build the **web pixel only**.

It is the only mechanism that sees a purchase happen, the only one that links
behaviour to revenue, the only one that installs without merchant action, and
the only one where consent is handled for us. It is simultaneously the
easiest, the most complete, and the most conservative legally.

Revisit the theme extension only if §6 comes back negative *and* identity
while browsing proves worth a merchant toggle.
