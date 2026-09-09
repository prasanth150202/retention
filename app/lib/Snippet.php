<?php
/**
 * Generates the two snippets a store owner installs.
 *
 * They are deliberately different shapes because they see different things:
 *
 *   The Custom Pixel runs in Shopify's sandbox — no DOM, no Liquid, a fixed
 *   list of events — but it is the ONLY thing that can see checkout. It owns
 *   the funnel.
 *
 *   The Liquid snippet runs in the page — full DOM, Liquid variables, a
 *   logged-in customer id — but cannot reach checkout at all. It fills the
 *   gaps the pixel structurally cannot: who this visitor is before they buy,
 *   what they clicked, what they searched for.
 *
 * The critical rule is that the Liquid snippet NEVER emits page_viewed or
 * product_viewed. Both feeds would report them and every funnel step would
 * double. Its event names live in a separate range for exactly this reason
 * (EventType, 64+).
 *
 * See TECHNICAL_PLAN.md sections 5.1 and 5.2.
 */

declare(strict_types=1);

final class Snippet
{
    /**
     * Shopify Custom Pixel.
     *
     * Settings → Customer events → Add custom pixel.
     *
     * @param array{write_key:string,shop_domain:string} $tenant
     */
    public static function pixel(array $tenant): string
    {
        $endpoint = json_encode(Config::get('hosts.collect'), JSON_UNESCAPED_SLASHES);
        $key      = json_encode($tenant['write_key']);

        return <<<JS
        // Project Odysseus — Shopify Custom Pixel
        // Store: {$tenant['shop_domain']}
        //
        // Install: Settings -> Customer events -> Add custom pixel -> paste -> Save -> Connect
        //
        // Subscribes only to the events below. input_changed, input_blurred and
        // input_focused are deliberately absent: they carry raw element.value,
        // which means real emails and phone numbers. We do not collect them.

        (function () {
          var ENDPOINT = {$endpoint};
          var KEY      = {$key};

          var EVENTS = [
            'page_viewed', 'collection_viewed', 'product_viewed', 'search_submitted',
            'cart_viewed', 'product_added_to_cart', 'product_removed_from_cart',
            'checkout_started', 'checkout_contact_info_submitted',
            'checkout_address_info_submitted', 'checkout_shipping_info_submitted',
            'payment_info_submitted', 'checkout_completed'
          ];

          var queue = [];
          var timer = null;

          // Money arrives as a decimal string; we store integer minor units so
          // nothing downstream ever does floating-point arithmetic on revenue.
          function minor(amount) {
            var n = parseFloat(amount);
            return isFinite(n) ? Math.round(n * 100) : null;
          }

          function map(e) {
            var d   = e.data || {};
            var ctx = (e.context && e.context.document) || {};
            var out = {
              n:   e.name,
              s:   1,
              id:  e.id,
              ts:  Date.parse(e.timestamp) || Date.now(),
              cid: e.clientId,
              u:   ctx.location && ctx.location.href,
              r:   ctx.referrer || null
            };

            var variant = d.productVariant
              || (d.cartLine && d.cartLine.merchandise)
              || null;

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

            // checkout.token is the join key between behaviour and the order
            // that the Admin API later reports. Without it a purchase cannot be
            // traced back to the visit that produced it.
            if (d.checkout) {
              out.ct = d.checkout.token || null;
              if (d.checkout.order) { out.o = d.checkout.order.id; }
              if (d.checkout.totalPrice) {
                out.a = minor(d.checkout.totalPrice.amount);
                out.c = d.checkout.totalPrice.currencyCode || d.checkout.currencyCode;
              }
            }

            if (d.searchResult && d.searchResult.query) { out.st = d.searchResult.query; }
            if (d.collection) { out.p = d.collection.id; }

            return out;
          }

          function flush() {
            if (!queue.length) { return; }

            var body = JSON.stringify({ k: KEY, e: queue.splice(0, 50) });
            timer = null;

            try {
              // text/plain keeps this a "simple" request, so there is no CORS
              // preflight on every page view. keepalive lets it survive the
              // page being closed, which is exactly when checkout events fire.
              if (typeof fetch === 'function') {
                fetch(ENDPOINT, {
                  method: 'POST',
                  body: body,
                  keepalive: true,
                  headers: { 'Content-Type': 'text/plain' },
                  mode: 'no-cors'
                }).catch(function () {});
              } else if (navigator.sendBeacon) {
                navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }));
              }
            } catch (err) {
              // Analytics must never break a storefront.
            }
          }

          function schedule(immediate) {
            if (immediate) { flush(); return; }
            if (timer) { return; }
            timer = setTimeout(flush, 2000);
          }

          EVENTS.forEach(function (name) {
            analytics.subscribe(name, function (event) {
              try {
                queue.push(map(event));
                // Send purchases at once. Batching them risks losing the single
                // most important event to a closing tab.
                schedule(name === 'checkout_completed' || queue.length >= 20);
              } catch (err) {}
            });
          });
        })();
        JS;
    }

    /**
     * Liquid theme snippet.
     *
     * theme.liquid, immediately before </body>.
     *
     * @param array{write_key:string,shop_domain:string} $tenant
     */
    public static function liquid(array $tenant): string
    {
        $endpoint = json_encode(Config::get('hosts.collect'), JSON_UNESCAPED_SLASHES);
        $key      = json_encode($tenant['write_key']);

        return <<<LIQUID
        {%- comment -%}
          Project Odysseus — storefront snippet
          Store: {$tenant['shop_domain']}

          Install: Online Store -> Themes -> Edit code -> theme.liquid
                   Paste immediately before </body>

          This does NOT track page views or product views. The Custom Pixel
          already reports those, and sending them twice would double every
          funnel step. It only reports what the pixel structurally cannot see:
          who a logged-in visitor is, what they clicked, and what they searched.
        {%- endcomment -%}
        <script>
        (function () {
          var ENDPOINT = {$endpoint};
          var KEY      = {$key};

          // The pixel identifies visitors by _shopify_y. Reading the same cookie
          // here is what lets one visitor's behaviour join across both feeds.
          function shopifyClientId() {
            try {
              var m = document.cookie.match(/(?:^|;\\s*)_shopify_y=([^;]+)/);
              return m ? decodeURIComponent(m[1]) : null;
            } catch (e) { return null; }
          }

          var CID = shopifyClientId();
          if (!CID) { return; }   // nothing to join to; stay silent

          var queue = [];
          var timer = null;

          function send(name, extra) {
            var e = {
              n: name, s: 2,
              id: name + ':' + CID + ':' + Date.now() + ':' + Math.random().toString(36).slice(2, 8),
              ts: Date.now(),
              cid: CID,
              u: location.href,
              r: document.referrer || null
            };
            for (var k in extra) { if (extra.hasOwnProperty(k)) { e[k] = extra[k]; } }
            queue.push(e);
            if (!timer) { timer = setTimeout(flush, 2000); }
          }

          function flush() {
            timer = null;
            if (!queue.length) { return; }
            var body = JSON.stringify({ k: KEY, e: queue.splice(0, 50) });
            try {
              if (navigator.sendBeacon) {
                navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain' }));
              } else {
                fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true,
                                  headers: { 'Content-Type': 'text/plain' }, mode: 'no-cors' })
                  .catch(function () {});
              }
            } catch (err) {}
          }

          // --- 1. Identity -------------------------------------------------
          // The reason this snippet exists. The sandboxed pixel cannot read
          // Liquid, so a logged-in repeat customer is anonymous to it until
          // they reach checkout. Here they are known while still browsing.
          {%- if customer %}
          send('identify', {
            cu: {{ customer.id | json }},
            d: {
              orders: {{ customer.orders_count | default: 0 }},
              spent:  {{ customer.total_spent | default: 0 }}
            }
          });
          {%- endif %}

          // --- 2. Clicks ---------------------------------------------------
          // The pixel's own 'clicked' event exposes no element text, so it
          // cannot tell you WHICH button was pressed in human terms.
          document.addEventListener('click', function (ev) {
            try {
              var el = ev.target && ev.target.closest && ev.target.closest('a, button, [role="button"]');
              if (!el) { return; }

              var text = (el.innerText || el.textContent || '').trim().slice(0, 120);
              var sel  = el.tagName.toLowerCase()
                       + (el.id ? '#' + el.id : '')
                       + (el.className && typeof el.className === 'string'
                           ? '.' + el.className.trim().split(/\\s+/).slice(0, 2).join('.') : '');

              send('link_click', {
                cl: { t: text, s: sel.slice(0, 255), h: el.getAttribute('href') || null }
              });
            } catch (e) {}
          }, true);

          // --- 3. Internal search ------------------------------------------
          {%- if template contains 'search' %}
          send('internal_search', {
            st: {{ search.terms | default: '' | json }},
            q:  {{ search.results_count | default: 0 }}
          });
          {%- endif %}

          // Do not lose a batch to a closing tab.
          addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') { flush(); }
          });
        })();
        </script>
        LIQUID;
    }

    /** A write key: public, in every visitor's page source, not a credential. */
    public static function generateWriteKey(): string
    {
        return 'wk_' . bin2hex(random_bytes(14));   // 31 chars, fits CHAR(32)
    }
}
