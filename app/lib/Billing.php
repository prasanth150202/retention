<?php
/**
 * Billing — subscription state, read from Shopify.
 *
 * SHOPIFY OWNS THE SUBSCRIPTION. It runs the trial, takes the money, retries
 * failed payments, and cancels on uninstall. This class creates a
 * subscription, sends the merchant to Shopify to approve it, and reads back
 * what Shopify says the state is. It never decides that state itself.
 *
 * That is not modesty about scope, it is the only correct design: a public
 * app may not charge outside the Billing API, so Shopify's answer is the only
 * answer, and a second record of "who is paying" would eventually disagree
 * with it. The columns on `tenants` are a cache of Shopify's state, refreshed
 * by webhook and re-read periodically in case a webhook never arrived.
 *
 * TRACKING DOES NOT STOP WHEN BILLING DOES.
 * A lapsed store keeps collecting events and orders; only the dashboard is
 * gated. Data is cheap — roughly 130 bytes an event — and a gap in history is
 * permanent. A merchant who pays two weeks after their trial ends should find
 * those two weeks waiting for them, not missing. Stores that uninstall are
 * handled by the retention policy in Purge, which is a separate decision.
 */

declare(strict_types=1);

final class Billing
{
    /** Re-read from Shopify if the cached state is older than this. */
    private const STALE_HOURS = 6;

    /** Shopify's AppSubscriptionStatus -> our billing_status. */
    private const STATUS_MAP = [
        'ACTIVE'    => 'active',
        'PENDING'   => 'pending',
        'FROZEN'    => 'frozen',
        'CANCELLED' => 'cancelled',
        'DECLINED'  => 'declined',
        'EXPIRED'   => 'expired',
    ];

    public static function enabled(): bool
    {
        return (bool) Config::get('billing.enabled', false);
    }

    /** @return array<string,array<string,mixed>> */
    public static function plans(): array
    {
        return (array) Config::get('billing.plans', []);
    }

    /** @return array<string,mixed>|null */
    public static function plan(string $handle): ?array
    {
        return self::plans()[$handle] ?? null;
    }

    /**
     * What this store's billing looks like right now.
     *
     * @return array{
     *   status:string, plan:?string, access:bool, reason:string,
     *   trial_ends_at:?string, current_period_end:?string,
     *   confirm_url:?string, test:bool, enabled:bool
     * }
     */
    public static function state(int $tenantId, bool $refreshIfStale = true): array
    {
        $tenant = Tenant::find($tenantId);

        if ($tenant === null) {
            return self::denied('unknown', 'This store is not installed.');
        }

        if (!self::enabled()) {
            // Before the listing exists there is nothing to charge for, and an
            // app that gates itself in that state locks out its own test
            // stores with no explanation.
            return [
                'status' => 'active', 'plan' => null, 'access' => true,
                'reason' => 'Billing is not enabled on this deployment.',
                'trial_ends_at' => null, 'current_period_end' => null,
                'confirm_url' => null, 'test' => false, 'enabled' => false,
            ];
        }

        if ($refreshIfStale && self::isStale($tenant)) {
            // A missed webhook must not leave a paying merchant locked out, so
            // the cache is re-read on its own every few hours. Failure here is
            // not fatal: the cached answer is still the best available.
            try {
                self::syncFromShopify($tenantId);
                $tenant = Tenant::find($tenantId) ?? $tenant;
            } catch (Throwable) {
            }
        }

        $trial    = $tenant['trial_ends_at'] ?? null;
        $resolved = self::resolve((string) ($tenant['billing_status'] ?? 'none'), $trial);

        $status = $resolved['status'];
        $access = $resolved['access'];

        return [
            'status'             => $status,
            'plan'               => $tenant['plan'] ?? null,
            'access'             => $access,
            'reason'             => self::reason($status),
            'trial_ends_at'      => $trial,
            'current_period_end' => $tenant['current_period_end'] ?? null,
            'confirm_url'        => $tenant['billing_confirm_url'] ?? null,
            'test'               => (bool) ($tenant['subscription_test'] ?? false),
            'enabled'            => true,
        ];
    }

    /**
     * The access decision, given only what Shopify told us.
     *
     * Pure: no database, no config, no clock beyond the one passed in. Every
     * rule about who can see the dashboard lives here and nowhere else, so
     * "why is this merchant locked out" has exactly one answer to read.
     *
     * ACCESS IS DECIDED BY STATUS, NEVER BY PLAN NAME. A subscription created
     * under a plan that has since been renamed or withdrawn is still a paid
     * subscription, and cutting it off would be taking money for nothing.
     *
     * @param string  $stored      billing_status as last read from Shopify
     * @param ?string $trialEndsAt UTC datetime, or null
     * @return array{status:string,access:bool}
     */
    public static function resolve(string $stored, ?string $trialEndsAt, ?int $now = null): array
    {
        $now ??= time();

        // Shopify reports a trialling subscription as ACTIVE with trialDays
        // set — there is no TRIAL status — so this one is ours to derive.
        if ($stored === 'active'
            && $trialEndsAt !== null
            && strtotime((string) $trialEndsAt . ' UTC') > $now
        ) {
            $stored = 'trial';
        }

        return [
            'status' => $stored,
            // FROZEN means a payment failed and Shopify is retrying. It is not
            // access: the merchant is not currently paying, and Shopify
            // restores the subscription itself the moment one goes through.
            'access' => in_array($stored, ['active', 'trial'], true),
        ];
    }

    /** Convenience for the places that only need yes or no. */
    public static function hasAccess(int $tenantId): bool
    {
        return self::state($tenantId)['access'];
    }

    /**
     * Start a subscription and return where to send the merchant.
     *
     * The merchant must approve the charge on Shopify's own confirmation page
     * — the app cannot approve on their behalf, and should not try to make the
     * redirect look like anything other than what it is.
     *
     * @return array{confirm_url:string,subscription_gid:string}
     */
    public static function subscribe(int $tenantId, string $planHandle, string $returnUrl): array
    {
        $plan = self::plan($planHandle);

        if ($plan === null) {
            throw new InvalidArgumentException("Unknown plan '{$planHandle}'.");
        }

        $trialDays = (int) Config::get('billing.trial_days', 0);
        $test      = (bool) Config::get('billing.test', false);

        $result = ShopifyApi::forTenant($tenantId)->query(
            'mutation Subscribe($name: String!, $lineItems: [AppSubscriptionLineItemInput!]!,
                                $returnUrl: URL!, $trialDays: Int, $test: Boolean) {
               appSubscriptionCreate(name: $name, lineItems: $lineItems, returnUrl: $returnUrl,
                                     trialDays: $trialDays, test: $test) {
                 appSubscription { id status trialDays test }
                 confirmationUrl
                 userErrors { field message }
               }
             }',
            [
                'name'      => (string) $plan['name'],
                'returnUrl' => $returnUrl,
                'trialDays' => $trialDays,
                'test'      => $test,
                'lineItems' => [[
                    'plan' => [
                        'appRecurringPricingDetails' => [
                            'price'    => [
                                'amount'       => (string) $plan['price'],
                                'currencyCode' => (string) $plan['currency'],
                            ],
                            'interval' => (string) $plan['interval'],
                        ],
                    ],
                ]],
            ]
        );

        $payload = $result['appSubscriptionCreate'] ?? [];
        $errors  = $payload['userErrors'] ?? [];

        if ($errors !== []) {
            throw new RuntimeException(
                'Shopify refused the subscription: ' . implode('; ', array_map(
                    static fn($e) => (string) ($e['message'] ?? 'unknown'),
                    $errors
                ))
            );
        }

        $confirmUrl = (string) ($payload['confirmationUrl'] ?? '');
        $gid        = (string) ($payload['appSubscription']['id'] ?? '');

        if ($confirmUrl === '' || $gid === '') {
            throw new RuntimeException('Shopify returned no confirmation URL.');
        }

        $before = (string) (Tenant::find($tenantId)['billing_status'] ?? 'none');

        Db::core()->prepare(
            "UPDATE tenants
                SET plan = ?, billing_status = 'pending', subscription_gid = ?,
                    billing_confirm_url = ?, subscription_test = ?,
                    trial_ends_at = CASE WHEN ? > 0
                                         THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? DAY)
                                         ELSE NULL END,
                    billing_checked_at = UTC_TIMESTAMP()
              WHERE tenant_id = ?"
        )->execute([
            $planHandle, $gid, substr($confirmUrl, 0, 512),
            $test ? 1 : 0, $trialDays, $trialDays, $tenantId,
        ]);

        Tenant::forgetCache();
        self::log($tenantId, 'app', $before, 'pending', $planHandle, $gid, 'Subscription created');

        return ['confirm_url' => $confirmUrl, 'subscription_gid' => $gid];
    }

    /**
     * Re-read subscription state from Shopify and store it.
     *
     * The authority for everything this class reports.
     *
     * @return array<string,mixed> the state as Shopify gave it
     */
    public static function syncFromShopify(int $tenantId): array
    {
        $result = ShopifyApi::forTenant($tenantId)->query(
            'query CurrentSubscription {
               currentAppInstallation {
                 activeSubscriptions {
                   id name status test trialDays currentPeriodEnd createdAt
                   lineItems {
                     plan {
                       pricingDetails {
                         ... on AppRecurringPricing {
                           interval
                           price { amount currencyCode }
                         }
                       }
                     }
                   }
                 }
               }
             }'
        );

        $subs = $result['currentAppInstallation']['activeSubscriptions'] ?? [];
        $sub  = is_array($subs) && $subs !== [] ? $subs[0] : null;

        $tenant = Tenant::find($tenantId);
        $before = (string) ($tenant['billing_status'] ?? 'none');

        if ($sub === null) {
            // No active subscription. A tenant that had one has ended it —
            // Shopify does not report cancelled subscriptions here — but one
            // still awaiting approval is PENDING and also absent, so a pending
            // state must not be overwritten with 'none' or the merchant loses
            // the confirmation link they were sent.
            if ($before !== 'pending') {
                self::store($tenantId, 'none', null, null, null, null, false);
                if ($before !== 'none') {
                    self::log($tenantId, 'sync', $before, 'none', null, null, 'No active subscription');
                }
            } else {
                self::touch($tenantId);
            }

            return [];
        }

        $status = self::STATUS_MAP[(string) ($sub['status'] ?? '')] ?? 'none';

        // trialDays counts from creation, and Shopify does not give a trial
        // end date directly.
        $trialEnds = null;
        $trialDays = (int) ($sub['trialDays'] ?? 0);
        if ($trialDays > 0 && !empty($sub['createdAt'])) {
            $trialEnds = gmdate(
                'Y-m-d H:i:s',
                strtotime((string) $sub['createdAt']) + $trialDays * 86400
            );
        }

        self::store(
            $tenantId,
            $status,
            self::matchPlan($sub),
            (string) ($sub['id'] ?? ''),
            $trialEnds,
            !empty($sub['currentPeriodEnd'])
                ? gmdate('Y-m-d H:i:s', strtotime((string) $sub['currentPeriodEnd']))
                : null,
            (bool) ($sub['test'] ?? false)
        );

        if ($status !== $before) {
            self::log($tenantId, 'sync', $before, $status, self::matchPlan($sub),
                (string) ($sub['id'] ?? ''), 'Read from Shopify');
        }

        return $sub;
    }

    /**
     * Apply an app_subscriptions/update webhook.
     *
     * The fast path: Shopify tells us the moment a merchant approves,
     * declines, or a payment fails, so the dashboard unlocks immediately
     * rather than at the next scheduled read.
     *
     * @param array<string,mixed> $payload the webhook body, already verified
     */
    public static function applyWebhook(string $shopDomain, array $payload): ?int
    {
        $sub = $payload['app_subscription'] ?? null;

        if (!is_array($sub)) {
            return null;
        }

        $tenant = Tenant::findByShop($shopDomain);

        if ($tenant === null) {
            return null;
        }

        $tenantId = (int) $tenant['tenant_id'];
        $before   = (string) ($tenant['billing_status'] ?? 'none');
        $status   = self::STATUS_MAP[strtoupper((string) ($sub['status'] ?? ''))] ?? null;

        if ($status === null) {
            return $tenantId;
        }

        $gid = (string) ($sub['admin_graphql_api_id'] ?? ($tenant['subscription_gid'] ?? ''));

        Db::core()->prepare(
            'UPDATE tenants
                SET billing_status = ?, subscription_gid = ?, billing_checked_at = UTC_TIMESTAMP()
              WHERE tenant_id = ?'
        )->execute([$status, $gid !== '' ? $gid : null, $tenantId]);

        Tenant::forgetCache();

        if ($status !== $before) {
            self::log($tenantId, 'webhook', $before, $status, $tenant['plan'] ?? null, $gid,
                'app_subscriptions/update');
        }

        // The webhook carries status but not the trial or period dates, and a
        // newly approved subscription is exactly when those matter. One read
        // to fill them in; a failure here leaves the status correct, which is
        // the part that gates access.
        if ($status === 'active') {
            try {
                self::syncFromShopify($tenantId);
            } catch (Throwable) {
            }
        }

        return $tenantId;
    }

    /**
     * Stores whose billing state has not been read recently.
     *
     * Used by the hourly sync as the backstop for a webhook that never
     * arrived. Nobody should discover they have been locked out of a product
     * they are paying for.
     *
     * @return array<int,int>
     */
    public static function needingRefresh(int $limit = 100): array
    {
        if (!self::enabled()) {
            return [];
        }

        $stmt = Db::core()->prepare(
            "SELECT tenant_id FROM tenants
              WHERE status = 'active'
                AND (billing_checked_at IS NULL
                     OR billing_checked_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL ? HOUR))
              ORDER BY billing_checked_at IS NOT NULL, billing_checked_at
              LIMIT " . (int) $limit
        );
        $stmt->execute([self::STALE_HOURS]);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,array<string,mixed>> */
    public static function history(int $tenantId, int $limit = 50): array
    {
        $stmt = Db::core()->prepare(
            'SELECT occurred_at, source, status_from, status_to, plan, note
               FROM billing_events WHERE tenant_id = ?
              ORDER BY occurred_at DESC, event_id DESC
              LIMIT ' . (int) $limit
        );
        $stmt->execute([$tenantId]);

        return $stmt->fetchAll();
    }

    // -----------------------------------------------------------------

    /** @param array<string,mixed> $tenant */
    private static function isStale(array $tenant): bool
    {
        $at = $tenant['billing_checked_at'] ?? null;

        return $at === null
            || strtotime((string) $at . ' UTC') < time() - self::STALE_HOURS * 3600;
    }

    private static function store(
        int $tenantId,
        string $status,
        ?string $plan,
        ?string $gid,
        ?string $trialEnds,
        ?string $periodEnd,
        bool $test
    ): void {
        Db::core()->prepare(
            'UPDATE tenants
                SET billing_status = ?, plan = COALESCE(?, plan), subscription_gid = ?,
                    trial_ends_at = ?, current_period_end = ?, subscription_test = ?,
                    billing_confirm_url = CASE WHEN ? = 1 THEN billing_confirm_url ELSE NULL END,
                    billing_checked_at = UTC_TIMESTAMP()
              WHERE tenant_id = ?'
        )->execute([
            $status, $plan, $gid, $trialEnds, $periodEnd, $test ? 1 : 0,
            // The confirmation link only means anything while approval is
            // still outstanding.
            $status === 'pending' ? 1 : 0,
            $tenantId,
        ]);

        Tenant::forgetCache();
    }

    private static function touch(int $tenantId): void
    {
        Db::core()->prepare(
            'UPDATE tenants SET billing_checked_at = UTC_TIMESTAMP() WHERE tenant_id = ?'
        )->execute([$tenantId]);

        Tenant::forgetCache();
    }

    /** @param array<string,mixed> $sub */
    private static function matchPlan(array $sub): ?string
    {
        $name = (string) ($sub['name'] ?? '');

        foreach (self::plans() as $handle => $plan) {
            if (strcasecmp((string) $plan['name'], $name) === 0) {
                return $handle;
            }
        }

        // A subscription created under a plan name that no longer exists is
        // still a real, paid subscription. Access is decided by status, never
        // by whether the name still matches a row in config.
        return null;
    }

    private static function log(
        int $tenantId,
        string $source,
        ?string $from,
        string $to,
        ?string $plan,
        ?string $gid,
        string $note
    ): void {
        Db::core()->prepare(
            'INSERT INTO billing_events
                (tenant_id, occurred_at, source, status_from, status_to, plan, subscription_gid, note)
             VALUES (?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?, ?)'
        )->execute([$tenantId, $source, $from, $to, $plan, $gid, substr($note, 0, 255)]);
    }

    private static function reason(string $status): string
    {
        return match ($status) {
            'trial'     => 'Free trial in progress.',
            'active'    => 'Subscription active.',
            'pending'   => 'Waiting for the charge to be approved in Shopify.',
            'frozen'    => 'The last payment did not go through. Access returns as soon as it does.',
            'declined'  => 'The charge was declined.',
            'expired'   => 'The charge was not approved in time and has expired.',
            'cancelled' => 'The subscription was cancelled.',
            default     => 'No subscription yet.',
        };
    }

    /** @return array<string,mixed> */
    private static function denied(string $status, string $reason): array
    {
        return [
            'status' => $status, 'plan' => null, 'access' => false, 'reason' => $reason,
            'trial_ends_at' => null, 'current_period_end' => null,
            'confirm_url' => null, 'test' => false, 'enabled' => self::enabled(),
        ];
    }
}
