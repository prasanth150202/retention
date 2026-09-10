<?php
/**
 * Loads the application libraries.
 *
 * Explicit requires rather than an autoloader: there is no composer install
 * on the server (TECHNICAL_PLAN.md 11.1), the library is small, and a fixed
 * list makes the load order obvious.
 *
 * NOT used by public_html/c.php. The ingest endpoint deliberately loads
 * nothing but its own config, because it runs on every pixel event and its
 * latency budget is under 10ms.
 *
 * Usage:
 *     require __DIR__ . '/../app/lib/bootstrap.php';
 *     odysseus_boot();                       // default .env
 *     odysseus_boot('.env.production');      // explicit environment
 */

declare(strict_types=1);

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Crypto.php';
require_once __DIR__ . '/Hash.php';
require_once __DIR__ . '/EventType.php';
require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/Shard.php';
require_once __DIR__ . '/Job.php';
require_once __DIR__ . '/Tenant.php';
require_once __DIR__ . '/Webhook.php';
require_once __DIR__ . '/Merchant.php';
require_once __DIR__ . '/Purge.php';
require_once __DIR__ . '/Identity.php';
require_once __DIR__ . '/Channel.php';
require_once __DIR__ . '/Attribution.php';
require_once __DIR__ . '/ShopifyOAuth.php';
require_once __DIR__ . '/ShopifyApi.php';
require_once __DIR__ . '/Dim.php';
require_once __DIR__ . '/GeoIp.php';
require_once __DIR__ . '/Migrator.php';

function odysseus_boot(?string $envFile = null): void
{
    static $booted = false;
    if ($booted) {
        return;
    }

    Config::init($envFile);

    date_default_timezone_set(Config::get('app.timezone', 'UTC'));

    if (Config::get('app.debug', false)) {
        error_reporting(E_ALL);
        ini_set('display_errors', '1');
    } else {
        ini_set('display_errors', '0');
    }

    $booted = true;
}

/**
 * Resolve a CLI --env=<path> argument.
 *
 * Lets one checkout target several environments without swapping files.
 */
function odysseus_env_arg(): ?string
{
    $opts = getopt('', ['env::']);
    return isset($opts['env']) && $opts['env'] !== false ? (string) $opts['env'] : null;
}
