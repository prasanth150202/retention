/**
 * retention-dashboard — web pixel.
 *
 * Runs in Shopify's sandbox: no DOM, no Liquid, no access to the storefront
 * page. That isolation is why Shopify lets an app switch it on without the
 * merchant touching anything, and why it keeps working on checkout, where the
 * theme does not exist.
 *
 * The event mapping here is the counterpart of app/cron/import.php. The two
 * are halves of one contract — if this starts sending a field the importer
 * does not read, or renames one it does, data is silently lost. They live in
 * the same repository so a single commit changes both.
 *
 * WHAT IT DOES NOT SUBSCRIBE TO
 * input_changed, input_blurred, input_focused and alert_displayed. Those carry
 * raw element.value: real email addresses and phone numbers as they are typed.
 * An earlier raw export in this project captured 2,626 emails and roughly
 * 30,000 phone numbers that way. Their absence here is the reason that cannot
 * happen again.
 */

import { register } from '@shopify/web-pixels-extension';

register(({ analytics, browser, settings, init }) => {
  const ENDPOINT = 'https://retention.digifyce.com/c.php';
  const KEY = settings.writeKey;

  if (!KEY) {
    // No key means events could not be attributed to a store anyway. Fail
    // silent rather than noisy: this runs on a merchant's storefront.
    return;
  }

  // The complete commercial funnel. Everything from a first page view to the
  // purchase, including the five checkout steps that only a web pixel can see.
  const EVENTS = [
    'page_viewed',
    'collection_viewed',
    'product_viewed',
    'search_submitted',
    'cart_viewed',
    'product_added_to_cart',
    'product_removed_from_cart',
    'checkout_started',
    'checkout_contact_info_submitted',
    'checkout_address_info_submitted',
    'checkout_shipping_info_submitted',
    'payment_info_submitted',
    'checkout_completed',
  ];

  let queue = [];
  let timer = null;

  /**
   * Money as integer minor units.
   *
   * Shopify sends decimal strings. Converting here means nothing downstream
   * ever does floating-point arithmetic on revenue.
   */
  function minor(amount) {
    const n = parseFloat(amount);
    return Number.isFinite(n) ? Math.round(n * 100) : null;
  }

  /**
   * A logged-in customer's id, when Shopify provides one.
   *
   * init.data.customer is gated behind protected customer data access. If it
   * is populated, a returning customer is identifiable while browsing rather
   * than only at checkout — which is the one capability a theme extension
   * would otherwise have been needed for.
   */
  const customerId =
    (init && init.data && init.data.customer && init.data.customer.id) || null;

  function map(event) {
    const d = event.data || {};
    const ctx = (event.context && event.context.document) || {};

    const out = {
      n: event.name,
      s: 1,
      id: event.id,
      ts: Date.parse(event.timestamp) || Date.now(),
      cid: event.clientId,
      u: ctx.location && ctx.location.href,
      r: ctx.referrer || null,
    };

    if (customerId) {
      out.cu = customerId;
    }

    const variant =
      d.productVariant || (d.cartLine && d.cartLine.merchandise) || null;

    if (variant) {
      out.v = variant.id;
      out.p = variant.product && variant.product.id;
      if (variant.price) {
        out.a = minor(variant.price.amount);
        out.c = variant.price.currencyCode;
      }
    }

    if (d.cartLine) {
      out.q = d.cartLine.quantity;
      if (d.cartLine.cost && d.cartLine.cost.totalAmount) {
        out.a = minor(d.cartLine.cost.totalAmount.amount);
        out.c = d.cartLine.cost.totalAmount.currencyCode;
      }
    }

    // checkout.order.id is the join between behaviour and revenue: it is what
    // connects "this visitor browsed these products" to the order the Admin
    // API reports. Without it the two are separate piles of data.
    if (d.checkout) {
      out.ct = d.checkout.token || null;
      if (d.checkout.order) {
        out.o = d.checkout.order.id;
      }
      if (d.checkout.totalPrice) {
        out.a = minor(d.checkout.totalPrice.amount);
        out.c = d.checkout.totalPrice.currencyCode || d.checkout.currencyCode;
      }
    }

    if (d.searchResult && d.searchResult.query) {
      out.st = d.searchResult.query;
    }
    if (d.collection) {
      out.p = d.collection.id;
    }

    return out;
  }

  function flush() {
    timer = null;
    if (queue.length === 0) {
      return;
    }

    const body = JSON.stringify({ k: KEY, e: queue.splice(0, 50) });

    try {
      // text/plain keeps this a simple request, so there is no CORS preflight
      // on every page view. keepalive lets it survive the page being closed,
      // which is exactly when checkout events fire.
      fetch(ENDPOINT, {
        method: 'POST',
        body,
        keepalive: true,
        mode: 'no-cors',
        headers: { 'Content-Type': 'text/plain' },
      }).catch(() => {});
    } catch (e) {
      // Analytics must never break a storefront.
    }
  }

  function schedule(immediate) {
    if (immediate) {
      flush();
      return;
    }
    if (timer) {
      return;
    }
    timer = setTimeout(flush, 2000);
  }

  EVENTS.forEach((name) => {
    analytics.subscribe(name, (event) => {
      try {
        queue.push(map(event));

        // Purchases go immediately. Batching them risks losing the single most
        // important event to a closing tab.
        schedule(name === 'checkout_completed' || queue.length >= 20);
      } catch (e) {
        // One malformed event must not stop the rest.
      }
    });
  });
});
