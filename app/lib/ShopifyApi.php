<?php
/**
 * Shopify Admin GraphQL client.
 *
 * Two modes, for two very different jobs:
 *
 *   query()  — ordinary paginated reads, used by the hourly incremental sync.
 *
 *   bulk*()  — bulk operations, used for the historical backfill. Shopify runs
 *              the query server-side and produces a JSONL file we stream down.
 *              This is the only sane way to pull years of orders on a host
 *              with no shell and a PHP execution limit: the expensive part
 *              happens on Shopify's side, and our job is just a download.
 *
 * RATE LIMITING
 * Shopify's GraphQL limit is cost-based and varies by plan, so there is no
 * fixed request-per-second figure to code against. Every response reports the
 * remaining budget in extensions.cost.throttleStatus, and this client waits on
 * that rather than assuming a number that would be wrong on half of them.
 */

declare(strict_types=1);

final class ShopifyApi
{
    private const MAX_RETRIES = 5;

    public function __construct(
        private string $shopDomain,
        private string $accessToken,
        private ?string $apiVersion = null,
    ) {
        $this->apiVersion ??= (string) Config::get('shopify.api_version', '2025-07');
    }

    /** Build a client for a tenant, decrypting its stored token. */
    public static function forTenant(int $tenantId): self
    {
        $stmt = Db::core()->prepare(
            'SELECT shop_domain, admin_token_enc FROM tenants WHERE tenant_id = ?'
        );
        $stmt->execute([$tenantId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException("No such tenant: {$tenantId}");
        }
        if (empty($row['admin_token_enc'])) {
            throw new RuntimeException(
                "Store {$row['shop_domain']} has no Shopify API token. Connect it first."
            );
        }

        return new self((string) $row['shop_domain'], Crypto::decrypt((string) $row['admin_token_enc']));
    }

    /**
     * Run a GraphQL query.
     *
     * @param array<string,mixed> $variables
     * @return array<string,mixed> the `data` payload
     */
    public function query(string $query, array $variables = []): array
    {
        $attempt = 0;

        while (true) {
            $attempt++;
            $res = $this->post(['query' => $query, 'variables' => $variables]);

            // Throttled. Shopify tells us the budget; wait for enough of it to
            // come back rather than guessing at a sleep.
            if ($res['http'] === 429 || $this->isThrottled($res['body'])) {
                if ($attempt > self::MAX_RETRIES) {
                    throw new RuntimeException('Rate limited by Shopify after ' . self::MAX_RETRIES . ' retries.');
                }
                $this->waitForBudget($res['body'], $attempt);
                continue;
            }

            if ($res['http'] >= 500) {
                if ($attempt > self::MAX_RETRIES) {
                    throw new RuntimeException("Shopify returned HTTP {$res['http']} repeatedly.");
                }
                sleep(min(30, 2 ** $attempt));
                continue;
            }

            if ($res['http'] === 401 || $res['http'] === 403) {
                throw new RuntimeException(
                    "Shopify rejected the access token (HTTP {$res['http']}). The app may have "
                    . 'been uninstalled, or the token revoked. Reconnect the store.'
                );
            }

            if ($res['http'] !== 200) {
                throw new RuntimeException("Shopify returned HTTP {$res['http']}.");
            }

            $body = $res['body'];

            if (isset($body['errors']) && $body['errors'] !== []) {
                $messages = array_map(
                    static fn($e) => is_array($e) ? ($e['message'] ?? json_encode($e)) : (string) $e,
                    (array) $body['errors']
                );

                // A missing-scope error names the scope, which is far more
                // useful than a generic failure.
                throw new RuntimeException('GraphQL error: ' . implode('; ', $messages));
            }

            // Stay ahead of the limit rather than hitting it: if the budget is
            // nearly spent, pause before returning so the caller's next call
            // does not have to be retried.
            $this->throttleIfLow($body);

            return $body['data'] ?? [];
        }
    }

    // -----------------------------------------------------------------
    // Bulk operations
    // -----------------------------------------------------------------

    /**
     * Start a bulk export. Only one may run per shop at a time.
     *
     * @return string the operation id
     */
    public function bulkStart(string $query): string
    {
        $mutation = <<<'GQL'
        mutation bulkOperationRunQuery($query: String!) {
          bulkOperationRunQuery(query: $query) {
            bulkOperation { id status }
            userErrors { field message }
          }
        }
        GQL;

        $data = $this->query($mutation, ['query' => $query]);
        $node = $data['bulkOperationRunQuery'] ?? [];

        if (!empty($node['userErrors'])) {
            $messages = array_column($node['userErrors'], 'message');

            // The common one, and worth naming: a previous backfill is still
            // running, which is a reason to wait rather than an error.
            throw new RuntimeException('Bulk operation refused: ' . implode('; ', $messages));
        }

        if (empty($node['bulkOperation']['id'])) {
            throw new RuntimeException('Shopify did not return a bulk operation id.');
        }

        return (string) $node['bulkOperation']['id'];
    }

    /**
     * Current bulk operation state.
     *
     * @return array{id:?string,status:?string,url:?string,objectCount:int,errorCode:?string}
     */
    public function bulkStatus(): array
    {
        $data = $this->query(<<<'GQL'
        {
          currentBulkOperation {
            id status errorCode objectCount url partialDataUrl
          }
        }
        GQL);

        $op = $data['currentBulkOperation'] ?? null;

        return [
            'id'          => $op['id'] ?? null,
            'status'      => $op['status'] ?? null,
            'url'         => $op['url'] ?? null,
            'objectCount' => (int) ($op['objectCount'] ?? 0),
            'errorCode'   => $op['errorCode'] ?? null,
        ];
    }

    /**
     * Stream a completed bulk result, yielding one decoded object per line.
     *
     * A generator rather than an array: the file can be hundreds of megabytes
     * for a store with real history, and holding it in memory would exceed the
     * limit on any shared plan.
     *
     * Nested connections arrive as separate lines carrying __parentId, so the
     * caller reassembles them rather than expecting nested objects.
     *
     * @return Generator<int,array<string,mixed>>
     */
    public function bulkStream(string $url): Generator
    {
        $fh = @fopen($url, 'rb');

        if ($fh === false) {
            throw new RuntimeException('Could not open the bulk result URL. It expires after a week.');
        }

        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $obj = json_decode($line, true);
                if (is_array($obj)) {
                    yield $obj;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /** @return array{http:int,body:array<string,mixed>} */
    private function post(array $payload): array
    {
        $url = "https://{$this->shopDomain}/admin/api/{$this->apiVersion}/graphql.json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'X-Shopify-Access-Token: ' . $this->accessToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);

        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("Request to {$this->shopDomain} failed: {$err}");
        }

        $body = json_decode((string) $raw, true);

        return ['http' => $http, 'body' => is_array($body) ? $body : []];
    }

    private function isThrottled(array $body): bool
    {
        foreach ((array) ($body['errors'] ?? []) as $e) {
            if (is_array($e) && ($e['extensions']['code'] ?? '') === 'THROTTLED') {
                return true;
            }
        }
        return false;
    }

    /** @return array{currentlyAvailable:int,maximumAvailable:int,restoreRate:float}|null */
    private function throttleStatus(array $body): ?array
    {
        $t = $body['extensions']['cost']['throttleStatus'] ?? null;

        if (!is_array($t)) {
            return null;
        }

        return [
            'currentlyAvailable' => (int) ($t['currentlyAvailable'] ?? 0),
            'maximumAvailable'   => (int) ($t['maximumAvailable'] ?? 1000),
            'restoreRate'        => (float) ($t['restoreRate'] ?? 50),
        ];
    }

    /** Sleep until roughly half the budget is back. */
    private function waitForBudget(array $body, int $attempt): void
    {
        $t = $this->throttleStatus($body);

        if ($t === null || $t['restoreRate'] <= 0) {
            sleep(min(30, 2 ** $attempt));
            return;
        }

        $needed  = max(0, ($t['maximumAvailable'] / 2) - $t['currentlyAvailable']);
        $seconds = (int) ceil($needed / $t['restoreRate']);

        sleep(max(1, min(30, $seconds)));
    }

    /** Pre-emptive pause when the remaining budget is under 20%. */
    private function throttleIfLow(array $body): void
    {
        $t = $this->throttleStatus($body);

        if ($t === null || $t['maximumAvailable'] <= 0) {
            return;
        }

        if ($t['currentlyAvailable'] / $t['maximumAvailable'] < 0.2 && $t['restoreRate'] > 0) {
            $needed = ($t['maximumAvailable'] * 0.5) - $t['currentlyAvailable'];
            usleep((int) (max(0.0, $needed / $t['restoreRate']) * 1_000_000));
        }
    }

    /**
     * Shopify global ids are "gid://shopify/Order/12345"; we store the number.
     */
    public static function gidToId(?string $gid): ?int
    {
        if ($gid === null || $gid === '') {
            return null;
        }
        if (ctype_digit($gid)) {
            return (int) $gid;
        }

        $tail = substr($gid, (int) strrpos($gid, '/') + 1);

        return ctype_digit($tail) ? (int) $tail : null;
    }

    /** Decimal money string to integer minor units. */
    public static function minor(mixed $amount): ?int
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }
}
