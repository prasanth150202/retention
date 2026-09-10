<?php
/**
 * Shopify webhook verification and bookkeeping.
 *
 * Webhooks are signed DIFFERENTLY from OAuth callbacks: a base64 HMAC of the
 * raw request body in the X-Shopify-Hmac-Sha256 header, rather than a hex HMAC
 * over sorted query parameters. Reusing the OAuth verifier here would reject
 * every genuine webhook — they are two schemes that happen to share an
 * algorithm.
 *
 * Verification is not optional. Webhook endpoints are public URLs, and an
 * unverified handler means anyone who learns the URL can tell us a store
 * uninstalled, or ask us to delete a merchant's data.
 */

declare(strict_types=1);

final class Webhook
{
    /**
     * Verify the signature over the raw body.
     *
     * MUST be given the raw bytes — json_decode'ing and re-encoding changes
     * whitespace and key order, and the digest then never matches.
     */
    public static function verify(string $rawBody, ?string $header = null, ?string $secret = null): bool
    {
        $header ??= (string) ($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '');

        if ($header === '' || $rawBody === '') {
            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha256', $rawBody, $secret ?? ShopifyOAuth::clientSecret(), true)
        );

        return hash_equals($expected, $header);
    }

    /**
     * Read and verify the current request, or exit.
     *
     * Shopify treats any non-2xx as a failure and retries with backoff for up
     * to 48 hours, so the response code is a real signal rather than
     * decoration: 401 on a bad signature stops the retries, and a 5xx on our
     * own failure asks Shopify to try again later.
     *
     * @return array{topic:string,shop:string,payload:array<string,mixed>,raw:string}
     */
    public static function receive(): array
    {
        $raw   = (string) file_get_contents('php://input');
        $topic = (string) ($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? '');
        $shop  = strtolower((string) ($_SERVER['HTTP_X_SHOPIFY_SHOP_DOMAIN'] ?? ''));

        if (!self::verify($raw)) {
            // Not from Shopify. Do not retry, do not explain.
            http_response_code(401);
            exit;
        }

        if ($topic === '' || $shop === '') {
            http_response_code(400);
            exit;
        }

        $payload = json_decode($raw, true);

        return [
            'topic'   => $topic,
            'shop'    => $shop,
            'payload' => is_array($payload) ? $payload : [],
            'raw'     => $raw,
        ];
    }

    /**
     * Has this exact delivery been handled already?
     *
     * Shopify retries on any non-2xx and can deliver more than once even on
     * success, so handlers must be idempotent. Deduplicating on the body
     * digest is what makes that true for handlers that are otherwise not.
     */
    public static function seenBefore(string $topic, string $rawBody): bool
    {
        $hash = substr(hash('sha256', $rawBody, true), 0, 16);

        $stmt = Db::core()->prepare(
            'SELECT request_id FROM compliance_requests WHERE topic = ? AND payload_hash = ?'
        );
        $stmt->execute([$topic, $hash]);

        return $stmt->fetchColumn() !== false;
    }

    /** Record a compliance request so the obligation can be proven, not just asserted. */
    public static function logCompliance(
        string $topic,
        string $shop,
        string $rawBody,
        ?int $tenantId,
        ?string $subjectRef
    ): int {
        $pdo  = Db::core();
        $hash = substr(hash('sha256', $rawBody, true), 0, 16);

        $pdo->prepare(
            'INSERT INTO compliance_requests
                (tenant_id, shop_domain, topic, payload_hash, subject_ref, received_at)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE request_id = LAST_INSERT_ID(request_id)'
        )->execute([$tenantId, $shop, $topic, $hash, $subjectRef]);

        return (int) $pdo->lastInsertId();
    }

    public static function completeCompliance(int $requestId, int $rowsDeleted, ?string $notes = null): void
    {
        Db::core()->prepare(
            'UPDATE compliance_requests
                SET completed_at = UTC_TIMESTAMP(), rows_deleted = ?, notes = ?
              WHERE request_id = ?'
        )->execute([$rowsDeleted, $notes, $requestId]);
    }

    /**
     * Answer Shopify and stop.
     *
     * Always 200 once a webhook is verified and recorded, even when the work
     * happens later. Shopify only asks "did you receive this?" — holding the
     * connection open while deleting a year of events would time out and be
     * retried, which is the opposite of helpful.
     */
    public static function ok(string $note = ''): never
    {
        http_response_code(200);
        header('Content-Type: text/plain');
        echo $note !== '' ? $note : 'ok';
        exit;
    }
}
