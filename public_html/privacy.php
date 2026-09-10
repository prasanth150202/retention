<?php
/**
 * Privacy policy.
 *
 * A listing requirement, and a real obligation. Written to describe what the
 * app actually does rather than to cover every eventuality, because a policy
 * that describes a conservative design is far stronger than boilerplate that
 * claims broad rights nobody exercises.
 *
 * ITEMS MARKED [TO CONFIRM] NEED A HUMAN.
 * Legal entity name and registered address are not things to invent.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/app/lib/bootstrap.php';

try {
    odysseus_boot();
    $contact = (string) (Config::get('alerts.email_to') ?: 'privacy@digifyce.com');
} catch (Throwable) {
    $contact = 'privacy@digifyce.com';
}

$updated = '10 September 2026';
?>
<!doctype html>
<html lang="en">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Privacy Policy — Retention Dashboard</title>
<style>
  :root { color-scheme: light dark; }
  body { font: 16px/1.65 system-ui, -apple-system, "Segoe UI", sans-serif;
         max-width: 760px; margin: 0 auto; padding: 48px 24px 96px; }
  h1 { font-size: 26px; letter-spacing: -.01em; margin-bottom: 4px; }
  h2 { font-size: 17px; margin-top: 38px; }
  .sub { opacity: .65; margin-top: 0; }
  table { border-collapse: collapse; width: 100%; margin: 14px 0; }
  th { text-align: left; font-size: 12px; text-transform: uppercase;
       letter-spacing: .04em; opacity: .6; padding: 0 10px 8px;
       border-bottom: 1px solid #8883; }
  td { padding: 10px; border-bottom: 1px solid #8882; vertical-align: top; }
  code { background: #8881; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
  .note { border-left: 3px solid #8a6100; padding: 2px 0 2px 14px; margin: 18px 0; }
</style>

<h1>Privacy Policy</h1>
<p class="sub">Retention Dashboard, a Shopify app by Digifyce · Last updated <?= $updated ?></p>

<h2>Who this covers</h2>
<p>This policy explains how the Retention Dashboard app handles data when a
merchant installs it on their Shopify store.</p>
<p>Under data protection law the <strong>merchant is the data controller</strong>
— it is their store, their customers and their relationship. Digifyce acts as a
<strong>data processor</strong>, handling data on the merchant's instructions
and only for the purpose of providing analytics back to them.</p>

<h2>What the app collects</h2>
<p>Two kinds of data, both obtained through Shopify.</p>

<p><strong>Behavioural events</strong>, through a Shopify web pixel: page views,
product views, cart activity, checkout steps and completed purchases. Each event
carries the page URL, the referring URL, any campaign tags in the address, the
browser and device type, and an approximate location.</p>

<p><strong>Commercial records</strong>, through Shopify's Admin API: orders,
order totals, line items, refunds, abandoned checkouts, products and customer
records.</p>

<h2>What the app does not collect</h2>
<p>This is deliberate design, not omission.</p>

<table>
  <tr><th>Data</th><th>How it is handled</th></tr>
  <tr>
    <td><strong>Email addresses</strong></td>
    <td>Stored only as an <strong>irreversible cryptographic hash</strong>, salted
    per store. Used to recognise that two orders came from the same person. The
    original address cannot be recovered from what is stored.</td>
  </tr>
  <tr>
    <td><strong>Phone numbers</strong></td>
    <td>As above — irreversible hash only.</td>
  </tr>
  <tr>
    <td><strong>IP addresses</strong></td>
    <td><strong>Never stored.</strong> A visitor's IP is used once, in memory, to
    derive an approximate city, and then discarded. There is no IP address field
    anywhere in the system.</td>
  </tr>
  <tr>
    <td><strong>Names and addresses</strong></td>
    <td>Not requested and not stored.</td>
  </tr>
  <tr>
    <td><strong>Payment details</strong></td>
    <td>Never accessible to this app. Shopify does not expose them and the app
    does not request them.</td>
  </tr>
  <tr>
    <td><strong>Form input</strong></td>
    <td>The app deliberately does <strong>not</strong> subscribe to Shopify's
    input events, which would expose the contents of form fields as a shopper
    types them.</td>
  </tr>
</table>

<h2>Consent</h2>
<p>Tracking runs as a Shopify web pixel, on a surface Shopify manages. Shopify
applies each store's customer privacy and consent settings to it, so where a
visitor's consent is required and has not been given, the app does not collect.
The app declares that it requires <strong>analytics</strong> consent only: it does
not perform advertising, retargeting, audience building or personalisation.</p>

<h2>How the data is used</h2>
<p>Solely to produce analytics for the merchant whose store it came from:
conversion funnels, repeat purchase and retention rates, campaign attribution,
product performance and checkout abandonment.</p>
<p>Data from one store is never combined with, compared against, or shown to
another. Hashes are salted per store, so the same person shopping at two stores
produces two unrelated values that cannot be matched.</p>
<p>Data is <strong>never</strong> sold, shared for cross-context behavioural
advertising, or used to train models.</p>

<h2>Retention</h2>
<ul>
  <li>While the app is installed, data is retained to provide the service.</li>
  <li>When a merchant <strong>uninstalls</strong>, raw event data is deleted
      according to the configured retention period, and the store's hashing salt
      is destroyed — after which nothing remaining can be linked to a person.</li>
  <li>On a <code>shop/redact</code> request from Shopify, everything for that
      store is deleted immediately.</li>
  <li>On a <code>customers/redact</code> request, that customer's identifying
      records are removed and their orders are detached from any person.</li>
  <li>Anonymous aggregate counts, which identify nobody, may be retained.</li>
</ul>

<h2>Individual rights</h2>
<p>Requests should be made to the merchant, who is the controller. Shopify
forwards them to us automatically and we act within 30 days.</p>
<div class="note">
  <p style="margin:0">Because email addresses and phone numbers exist only as
  irreversible hashes and IP addresses are never stored, the app <em>cannot</em>
  produce a readable record of an individual in response to an access request.
  This is stated plainly rather than presented as a limitation to work around:
  it is the intended consequence of not holding the data.</p>
</div>

<h2>Security</h2>
<ul>
  <li>All data in transit is encrypted with TLS.</li>
  <li>Shopify API credentials are encrypted at rest with AES-256-GCM, using a
      key held outside the database.</li>
  <li>Personal identifiers are stored only as salted, irreversible hashes.</li>
  <li>Access is restricted to Digifyce personnel who need it to operate the
      service.</li>
</ul>

<h2>Sub-processors</h2>
<table>
  <tr><th>Provider</th><th>Purpose</th></tr>
  <tr><td>Hostinger</td><td>Application hosting and database storage <em>[TO CONFIRM: hosting region]</em></td></tr>
  <tr><td>Shopify</td><td>Source of all data; event delivery</td></tr>
  <tr><td>DB-IP</td><td>Offline IP-to-city database. Consulted locally — no visitor data is sent to DB-IP.</td></tr>
</table>

<h2>Changes</h2>
<p>Material changes will be notified to installed merchants before taking
effect.</p>

<h2>Contact</h2>
<p>
  Digifyce<br>
  <em>[TO CONFIRM: registered legal entity name and address]</em><br>
  <a href="mailto:<?= htmlspecialchars($contact) ?>"><?= htmlspecialchars($contact) ?></a>
</p>

<p style="margin-top:44px;opacity:.6;font-size:13px">
  IP Geolocation by <a href="https://db-ip.com">DB-IP</a>
</p>
