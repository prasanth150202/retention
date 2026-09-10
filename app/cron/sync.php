<?php
/**
 * Project Odysseus — Shopify Admin API sync.
 *
 * Pulls orders, line items, customers and Shopify's own attribution into the
 * core database. Runs hourly.
 *
 * THE JOIN KEY IS order_id, NOT checkout_token
 * TECHNICAL_PLAN.md section 5.4 describes checkout_token as the glue between
 * behaviour and revenue. That is right for REST and wrong for GraphQL, which
 * does not expose checkout_token on Order at all. It does not matter, because
 * the pixel's checkout_completed event carries data.checkout.order.id — so
 * events.order_ref joins straight to orders.order_id with no token in the
 * middle. checkout_token remains the join for abandoned checkouts, which have
 * no order to point at.
 *
 * Everything is chunked and resumable through sync_cursors. A run killed by a
 * PHP time limit resumes from its watermark rather than starting again, which
 * matters on a host where execution limits are not ours to set.
 *
 * Usage:
 *   php app/cron/sync.php [--env=.env.production] [--tenant=1] [--verbose]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';
require_once $root . '/app/lib/ShopifyApi.php';

$opts     = getopt('', ['env::', 'tenant::', 'verbose']);
$verbose  = array_key_exists('verbose', $opts);
$onlyOne  = isset($opts['tenant']) ? (int) $opts['tenant'] : null;

odysseus_boot(odysseus_env_arg());

$log = static function (string $m) use ($verbose): void {
    if ($verbose) {
        echo $m, "\n";
    }
};

if (!Job::lock('sync')) {
    $log('Another sync is already running; exiting.');
    exit(0);
}

$jobId  = Job::start('sync');
$totals = ['orders' => 0, 'customers' => 0, 'tenants' => 0, 'failed' => 0];

try {
    $sql = "SELECT tenant_id, shop_domain, display_name
              FROM tenants
             WHERE status = 'active' AND admin_token_enc IS NOT NULL";
    if ($onlyOne !== null) {
        $sql .= ' AND tenant_id = ' . $onlyOne;
    }

    $tenants = Db::core()->query($sql)->fetchAll();

    if ($tenants === []) {
        $log('No stores have a Shopify token yet. Nothing to sync.');
    }

    foreach ($tenants as $t) {
        $tid = (int) $t['tenant_id'];
        $log("--- {$t['display_name']} ({$t['shop_domain']})");

        try {
            $api = ShopifyApi::forTenant($tid);

            $n = syncOrders($api, $tid, $log);
            $totals['orders'] += $n;
            $totals['tenants']++;

            $log("    {$n} order(s) synced");
        } catch (Throwable $e) {
            $totals['failed']++;
            $log('    FAILED: ' . $e->getMessage());
            noteFailure($tid, 'orders', $e->getMessage());
        }
    }

    Job::finish($jobId, 'ok', $totals['orders'], $totals['orders'], json_encode($totals) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, 'SYNC FAILED: ' . $e->getMessage() . "\n");
    Job::unlock('sync');
    exit(1);
}

Job::unlock('sync');

printf(
    "sync: %d store(s), %d order(s), %d failed\n",
    $totals['tenants'], $totals['orders'], $totals['failed']
);

exit($totals['failed'] > 0 ? 1 : 0);


// =====================================================================

/**
 * Incremental order sync.
 *
 * Filtered on updated_at rather than created_at, so edits, refunds,
 * cancellations and fulfilment changes to old orders are picked up. An order
 * from last year that gets refunded today must not stay recorded as revenue.
 */
function syncOrders(ShopifyApi $api, int $tenantId, callable $log): int
{
    $cursorRow = cursorFor($tenantId, 'orders');
    $watermark = $cursorRow['watermark_at'];

    // First run: no watermark. Ask for everything, which read_all_orders
    // permits and its absence silently truncates to 60 days.
    $filter = $watermark !== null
        ? 'updated_at:>=' . gmdate('c', strtotime($watermark . ' UTC') - 300)
        : '';

    $query = <<<'GQL'
    query syncOrders($cursor: String, $q: String) {
      orders(first: 25, after: $cursor, query: $q, sortKey: UPDATED_AT, reverse: false) {
        pageInfo { hasNextPage endCursor }
        edges {
          node {
            id name createdAt processedAt cancelledAt updatedAt
            displayFinancialStatus displayFulfillmentStatus
            currencyCode
            currentSubtotalPriceSet { shopMoney { amount } }
            currentTotalPriceSet    { shopMoney { amount } }
            totalDiscountsSet       { shopMoney { amount } }
            totalRefundedSet        { shopMoney { amount } }
            discountCodes
            landingPageUrl referrerUrl sourceName
            email
            phone
            customer { id }
            customerJourneySummary {
              ready
              daysToConversion
              momentsCount
              firstVisit { occurredAt landingPage referrerUrl source
                           utmParameters { source medium campaign content term } }
              lastVisit  { occurredAt landingPage referrerUrl source
                           utmParameters { source medium campaign content term } }
            }
            lineItems(first: 50) {
              edges { node {
                id title sku quantity
                variant { id }
                product { id }
                originalUnitPriceSet { shopMoney { amount } }
                totalDiscountSet     { shopMoney { amount } }
              } }
            }
          }
        }
      }
    }
    GQL;

    $cursor  = $cursorRow['cursor_token'];
    $count   = 0;
    $newest  = $watermark;
    $pages   = 0;

    do {
        $data = $api->query($query, ['cursor' => $cursor, 'q' => $filter !== '' ? $filter : null]);
        $conn = $data['orders'] ?? [];
        $edges = $conn['edges'] ?? [];

        foreach ($edges as $edge) {
            $node = $edge['node'] ?? null;
            if (!is_array($node)) {
                continue;
            }

            upsertOrder($tenantId, $node);
            $count++;

            $updated = $node['updatedAt'] ?? null;
            if ($updated !== null) {
                $ts = gmdate('Y-m-d H:i:s', strtotime($updated));
                if ($newest === null || $ts > $newest) {
                    $newest = $ts;
                }
            }
        }

        $cursor   = $conn['pageInfo']['endCursor'] ?? null;
        $hasNext  = (bool) ($conn['pageInfo']['hasNextPage'] ?? false);
        $pages++;

        // Save progress every page. A run killed mid-way resumes here rather
        // than re-fetching everything it already stored.
        saveCursor($tenantId, 'orders', $newest, $hasNext ? $cursor : null);

        $log("    page {$pages}: {$count} order(s) so far");

        // Bound a single run so cron never overlaps itself indefinitely on a
        // store with years of history; the next run picks up the cursor.
        if ($pages >= 40) {
            $log('    stopping at 40 pages; the next run resumes from the cursor');
            return $count;
        }
    } while ($hasNext);

    saveCursor($tenantId, 'orders', $newest, null);
    markSynced($tenantId, 'orders');

    return $count;
}

/** @param array<string,mixed> $o */
function upsertOrder(int $tenantId, array $o): void
{
    $orderId = ShopifyApi::gidToId($o['id'] ?? null);
    if ($orderId === null) {
        return;
    }

    $customerId = ShopifyApi::gidToId($o['customer']['id'] ?? null);
    $journey    = $o['customerJourneySummary'] ?? [];

    // Contact details are hashed HERE, on arrival, and the plaintext is never
    // written anywhere. Deterministic so two orders from the same person
    // match; irreversible so a database dump yields no contact details.
    //
    // These live on the order rather than only on the customer record because
    // guest checkout produces orders with no customer at all — and in Indian
    // D2C that is the common case, not the exception. Resolving identity from
    // customer_id alone would undercount repeat buyers badly.
    $emailNorm = Hash::normaliseEmail($o['email'] ?? null);
    $phoneNorm = Hash::normalisePhone($o['phone'] ?? null);

    $emailHash = $emailNorm !== null ? Hash::pii($tenantId, $emailNorm) : null;
    $phoneHash = $phoneNorm !== null ? Hash::pii($tenantId, $phoneNorm) : null;

    $pdo = Db::core();

    $pdo->prepare(
        'INSERT INTO orders
            (tenant_id, order_id, order_number, shopify_customer_id, email_hash, phone_hash, created_at, processed_at,
             cancelled_at, financial_status, fulfillment_status, currency,
             subtotal_minor, total_minor, discount_minor, refunded_minor, discount_codes,
             landing_site, referring_site, source_name,
             journey_ready, days_to_conversion, moments_count, synced_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            order_number       = VALUES(order_number),
            shopify_customer_id= VALUES(shopify_customer_id),
            email_hash         = COALESCE(VALUES(email_hash), email_hash),
            phone_hash         = COALESCE(VALUES(phone_hash), phone_hash),
            processed_at       = VALUES(processed_at),
            cancelled_at       = VALUES(cancelled_at),
            financial_status   = VALUES(financial_status),
            fulfillment_status = VALUES(fulfillment_status),
            subtotal_minor     = VALUES(subtotal_minor),
            total_minor        = VALUES(total_minor),
            discount_minor     = VALUES(discount_minor),
            refunded_minor     = VALUES(refunded_minor),
            discount_codes     = VALUES(discount_codes),
            journey_ready      = VALUES(journey_ready),
            days_to_conversion = VALUES(days_to_conversion),
            moments_count      = VALUES(moments_count),
            synced_at          = UTC_TIMESTAMP()'
    )->execute([
        $tenantId,
        $orderId,
        $o['name'] ?? null,
        $customerId,
        $emailHash,
        $phoneHash,
        isoToSql($o['createdAt'] ?? null) ?? gmdate('Y-m-d H:i:s'),
        isoToSql($o['processedAt'] ?? null),
        isoToSql($o['cancelledAt'] ?? null),
        $o['displayFinancialStatus'] ?? null,
        $o['displayFulfillmentStatus'] ?? null,
        $o['currencyCode'] ?? 'INR',
        ShopifyApi::minor($o['currentSubtotalPriceSet']['shopMoney']['amount'] ?? null),
        ShopifyApi::minor($o['currentTotalPriceSet']['shopMoney']['amount'] ?? null),
        ShopifyApi::minor($o['totalDiscountsSet']['shopMoney']['amount'] ?? null),
        ShopifyApi::minor($o['totalRefundedSet']['shopMoney']['amount'] ?? null) ?? 0,
        isset($o['discountCodes']) && is_array($o['discountCodes'])
            ? substr(implode(',', $o['discountCodes']), 0, 255) : null,
        substr((string) ($o['landingPageUrl'] ?? ''), 0, 512) ?: null,
        substr((string) ($o['referrerUrl'] ?? ''), 0, 512) ?: null,
        substr((string) ($o['sourceName'] ?? ''), 0, 64) ?: null,
        !empty($journey['ready']) ? 1 : 0,
        isset($journey['daysToConversion']) ? (int) $journey['daysToConversion'] : null,
        isset($journey['momentsCount']) ? (int) $journey['momentsCount'] : null,
    ]);

    upsertLineItems($tenantId, $orderId, $o['lineItems']['edges'] ?? []);
    upsertShopifyAttribution($tenantId, $orderId, $journey);
    linkVisitor($tenantId, $orderId);
}

/** @param array<int,array<string,mixed>> $edges */
function upsertLineItems(int $tenantId, int $orderId, array $edges): void
{
    if ($edges === []) {
        return;
    }

    $stmt = Db::core()->prepare(
        'INSERT INTO order_line_items
            (tenant_id, order_id, line_id, product_id, variant_id, title, sku,
             quantity, price_minor, discount_minor)
         VALUES (?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            quantity = VALUES(quantity), price_minor = VALUES(price_minor),
            discount_minor = VALUES(discount_minor), title = VALUES(title)'
    );

    foreach ($edges as $e) {
        $n = $e['node'] ?? null;
        if (!is_array($n)) {
            continue;
        }

        $lineId = ShopifyApi::gidToId($n['id'] ?? null);
        if ($lineId === null) {
            continue;
        }

        $stmt->execute([
            $tenantId,
            $orderId,
            $lineId,
            ShopifyApi::gidToId($n['product']['id'] ?? null),
            ShopifyApi::gidToId($n['variant']['id'] ?? null),
            substr((string) ($n['title'] ?? ''), 0, 255) ?: null,
            substr((string) ($n['sku'] ?? ''), 0, 96) ?: null,
            (int) ($n['quantity'] ?? 1),
            ShopifyApi::minor($n['originalUnitPriceSet']['shopMoney']['amount'] ?? null),
            ShopifyApi::minor($n['totalDiscountSet']['shopMoney']['amount'] ?? null) ?? 0,
        ]);
    }
}

/**
 * Store Shopify's own first- and last-touch attribution.
 *
 * The value of this is that it survives what our pixel cannot: an ad blocker,
 * a refused consent banner, a cleared cookie between sessions. Where the two
 * disagree, the disagreement is itself the finding — which is why all four
 * models are stored side by side rather than reconciled into one number.
 *
 * @param array<string,mixed> $journey
 */
function upsertShopifyAttribution(int $tenantId, int $orderId, array $journey): void
{
    foreach (['firstVisit' => 'shopify_first', 'lastVisit' => 'shopify_last'] as $key => $model) {
        $v = $journey[$key] ?? null;
        if (!is_array($v)) {
            continue;
        }

        $utm = $v['utmParameters'] ?? [];

        // Reuse the same dimension the pixel feed writes into, so both models
        // group by the same campaign_id and are directly comparable.
        $campaignId = null;
        if (array_filter((array) $utm) !== []) {
            $qs = http_build_query(array_filter([
                'utm_source'   => $utm['source']   ?? null,
                'utm_medium'   => $utm['medium']   ?? null,
                'utm_campaign' => $utm['campaign'] ?? null,
                'utm_content'  => $utm['content']  ?? null,
                'utm_term'     => $utm['term']     ?? null,
            ]));
            $campaignId = Dim::campaign($tenantId, 'https://x/?' . $qs);
        }

        Db::core()->prepare(
            'INSERT INTO order_attribution
                (tenant_id, order_id, model, campaign_id, landing_path_id, referrer_id, touch_at)
             VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE
                campaign_id = VALUES(campaign_id),
                landing_path_id = VALUES(landing_path_id),
                referrer_id = VALUES(referrer_id),
                touch_at = VALUES(touch_at)'
        )->execute([
            $tenantId,
            $orderId,
            $model,
            $campaignId,
            Dim::path($tenantId, $v['landingPage'] ?? null),
            Dim::referrer($tenantId, $v['referrerUrl'] ?? null),
            isoToSql($v['occurredAt'] ?? null),
        ]);
    }
}

/**
 * Attach the visitor who placed this order.
 *
 * The pixel's checkout_completed carries the order id, so events.order_ref
 * points straight at orders.order_id. Queried on the shard covering the
 * order's date, and written back to core — no cross-database join, which is
 * what lets each database keep its own credential.
 */
function linkVisitor(int $tenantId, int $orderId): void
{
    $stmt = Db::core()->prepare(
        'SELECT created_at, visitor_key FROM orders WHERE tenant_id = ? AND order_id = ?'
    );
    $stmt->execute([$tenantId, $orderId]);
    $row = $stmt->fetch();

    if (!$row || $row['visitor_key'] !== null) {
        return;   // already linked
    }

    try {
        $pdo = Shard::connectionForDate((string) $row['created_at']);
    } catch (Throwable) {
        return;   // no shard for that date; nothing to link against
    }

    $find = Db::tenantQuery(
        $pdo,
        'SELECT visitor_key FROM events
          WHERE tenant_id = :tenant_id AND order_ref = :order_ref
          ORDER BY id LIMIT 1',
        $tenantId,
        [':order_ref' => $orderId]
    );

    $visitorKey = $find->fetchColumn();

    if ($visitorKey !== false && $visitorKey !== null) {
        Db::core()->prepare('UPDATE orders SET visitor_key = ? WHERE tenant_id = ? AND order_id = ?')
            ->execute([(int) $visitorKey, $tenantId, $orderId]);
    }
}


// ---------------------------------------------------------------------
// Cursor bookkeeping
// ---------------------------------------------------------------------

/** @return array{watermark_at:?string,cursor_token:?string} */
function cursorFor(int $tenantId, string $resource): array
{
    $stmt = Db::core()->prepare(
        'SELECT watermark_at, cursor_token FROM sync_cursors WHERE tenant_id = ? AND resource = ?'
    );
    $stmt->execute([$tenantId, $resource]);
    $row = $stmt->fetch();

    return [
        'watermark_at' => $row['watermark_at'] ?? null,
        'cursor_token' => $row['cursor_token'] ?? null,
    ];
}

function saveCursor(int $tenantId, string $resource, ?string $watermark, ?string $cursor): void
{
    Db::core()->prepare(
        'INSERT INTO sync_cursors (tenant_id, resource, watermark_at, cursor_token, last_run_at, last_ok_at)
         VALUES (?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE
            watermark_at = VALUES(watermark_at),
            cursor_token = VALUES(cursor_token),
            last_run_at  = UTC_TIMESTAMP(),
            last_ok_at   = UTC_TIMESTAMP(),
            last_error   = NULL'
    )->execute([$tenantId, $resource, $watermark, $cursor]);
}

function markSynced(int $tenantId, string $resource): void
{
    Db::core()->prepare(
        "UPDATE tenants SET backfill_state = 'done' WHERE tenant_id = ? AND backfill_state <> 'done'"
    )->execute([$tenantId]);
}

function noteFailure(int $tenantId, string $resource, string $message): void
{
    Db::core()->prepare(
        'INSERT INTO sync_cursors (tenant_id, resource, last_run_at, last_error)
         VALUES (?,?,UTC_TIMESTAMP(),?)
         ON DUPLICATE KEY UPDATE last_run_at = UTC_TIMESTAMP(), last_error = VALUES(last_error)'
    )->execute([$tenantId, $resource, substr($message, 0, 2000)]);
}

function isoToSql(?string $iso): ?string
{
    if ($iso === null || $iso === '') {
        return null;
    }
    $t = strtotime($iso);

    return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
}
