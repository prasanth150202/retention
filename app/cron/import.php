<?php
/**
 * Project Odysseus — spool importer.
 *
 * Reads the NDJSON files c.php writes, resolves dimensions, and inserts rows
 * into the event shard for each event's date. Runs on cron every few minutes.
 *
 * THE RULE THAT MATTERS: a spool file is only moved out of spool/ once its
 * rows are committed. Anything that fails is left where it is and retried on
 * the next run. Nothing is ever deleted on a failure path.
 *
 * That is not caution for its own sake. Orders can be re-fetched from Shopify
 * whenever we like; pixel events exist in exactly one place until they are in
 * the database, and a lost spool file is behaviour that never happened as far
 * as the platform is concerned.
 *
 * Usage:
 *   php app/cron/import.php [--env=.env.production] [--limit=50] [--verbose]
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
require_once $root . '/app/lib/bootstrap.php';

$opts    = getopt('', ['env::', 'limit::', 'verbose']);
$verbose = array_key_exists('verbose', $opts);
$limit   = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 200;

odysseus_boot(odysseus_env_arg());

$log = static function (string $msg) use ($verbose): void {
    if ($verbose) {
        echo $msg, "\n";
    }
};

// ---------------------------------------------------------------------
// Only one importer at a time.
//
// Cron will happily start a second copy while the first is still going, and
// two processes reading the same spool file would double-insert everything
// that is not caught by the dedup key.
// ---------------------------------------------------------------------
if (!Job::lock('import')) {
    $log('Another import is already running; exiting.');
    exit(0);
}

$jobId  = Job::start('import');
$totals = ['files' => 0, 'accepted' => 0, 'duplicate' => 0, 'malformed' => 0, 'skipped' => 0, 'failed' => 0];

try {
    // Keep c.php's tenant lookup off the database by refreshing its cache here,
    // where we already hold a connection.
    refreshTenantCache();

    $files = closedSpoolFiles(Config::get('paths.spool'), $limit);
    $log(count($files) . ' spool file(s) ready');

    foreach ($files as $file) {
        $totals['files']++;
        $r = importFile($file, $log);

        $totals['accepted']  += $r['accepted'];
        $totals['duplicate'] += $r['duplicate'];
        $totals['malformed'] += $r['malformed'];
        $totals['skipped']   += $r['skipped'];
        if (!$r['ok']) {
            $totals['failed']++;
        }
    }

    sweepProcessed();

    Job::finish($jobId, 'ok', $totals['accepted'], $totals['accepted'], json_encode($totals) ?: null);
} catch (Throwable $e) {
    Job::finish($jobId, 'failed', 0, 0, $e->getMessage());
    fwrite(STDERR, "IMPORT FAILED: " . $e->getMessage() . "\n");
    Job::unlock('import');
    exit(1);
}

Job::unlock('import');

printf(
    "import: %d file(s), %d accepted, %d duplicate, %d skipped, %d malformed, %d failed\n",
    $totals['files'], $totals['accepted'], $totals['duplicate'],
    $totals['skipped'], $totals['malformed'], $totals['failed']
);

exit($totals['failed'] > 0 ? 1 : 0);


// =====================================================================

/**
 * Spool files whose hour has closed.
 *
 * The file for the current hour is still being appended to by c.php, so it is
 * deliberately skipped — reading it would import a prefix and then move it,
 * losing whatever arrived in between.
 *
 * @return string[]
 */
function closedSpoolFiles(string $spoolRoot, int $limit): array
{
    if (!is_dir($spoolRoot)) {
        return [];
    }

    $currentHour = gmdate('YmdH');
    $found       = [];

    foreach (glob($spoolRoot . '/*/*.ndjson') ?: [] as $path) {
        if (basename($path, '.ndjson') === $currentHour) {
            continue;
        }
        $found[] = $path;
        if (count($found) >= $limit) {
            break;
        }
    }

    sort($found);   // oldest first
    return $found;
}

/**
 * @return array{ok:bool,accepted:int,duplicate:int,malformed:int,skipped:int}
 */
function importFile(string $path, callable $log): array
{
    $tenantId = (int) basename(dirname($path));
    $name     = basename(dirname($path)) . '/' . basename($path);
    $result   = ['ok' => false, 'accepted' => 0, 'duplicate' => 0, 'malformed' => 0, 'skipped' => 0];

    $core = Db::core();

    // One row per spool file, so a stuck or repeatedly failing file is visible
    // rather than just quietly retried forever.
    $core->prepare(
        'INSERT INTO import_log (tenant_id, spool_file, started_at, status)
         VALUES (?, ?, UTC_TIMESTAMP(), \'running\')
         ON DUPLICATE KEY UPDATE started_at = UTC_TIMESTAMP(), status = \'running\''
    )->execute([$tenantId, $name]);

    $fh = fopen($path, 'rb');
    if ($fh === false) {
        finishImportLog($tenantId, $name, 'failed', $result, 'Cannot open file');
        return $result;
    }

    // Group by year: a file covers one hour, but occurred_at comes from the
    // client's clock, so a single file can still straddle a shard boundary.
    $byYear     = [];
    $shardNames = [];
    $lineNo     = 0;

    while (($line = fgets($fh)) !== false) {
        $lineNo++;
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        $raw = json_decode($line, true);
        if (!is_array($raw)) {
            $result['malformed']++;
            continue;
        }

        try {
            $row = mapEvent($raw, $tenantId);
        } catch (Throwable $e) {
            $result['malformed']++;
            $log("  line {$lineNo}: " . $e->getMessage());
            continue;
        }

        if ($row === null) {
            // Not an error. An event type we deliberately do not subscribe to,
            // such as the input_* events that carry raw element.value. Counted
            // apart from malformed so a misconfigured pixel does not raise a
            // data-corruption alarm every few minutes.
            $result['skipped']++;
            continue;
        }

        $day = substr($row['occurred_at'], 0, 10);

        try {
            $shard = Shard::forDate($day);
        } catch (Throwable $e) {
            // No shard for that date. Stop the whole file rather than drop the
            // row: the fix is to create the database, and the events should
            // still be here when it exists.
            fclose($fh);
            finishImportLog($tenantId, $name, 'failed', $result, $e->getMessage());
            $log("  {$name}: " . $e->getMessage());
            return $result;
        }

        $year = (int) substr($day, 0, 4);
        $byYear[$year][]     = $row;
        $shardNames[$year] ??= $shard['physical_name'];
    }

    fclose($fh);

    try {
        foreach ($byYear as $year => $rows) {
            [, $user, $pass] = Db::shardTarget($year);
            $pdo = Db::connect($shardNames[$year], $user, $pass);

            $counts = insertEvents($pdo, $rows);
            $result['accepted']  += $counts['accepted'];
            $result['duplicate'] += $counts['duplicate'];
        }
    } catch (Throwable $e) {
        finishImportLog($tenantId, $name, 'failed', $result, $e->getMessage());
        $log("  {$name}: insert failed — " . $e->getMessage());
        return $result;
    }

    // Only now is it safe to move the file.
    $result['ok'] = true;
    finishImportLog($tenantId, $name, 'ok', $result, null);
    moveToProcessed($path, $tenantId);

    $log(sprintf('  %s: %d accepted, %d duplicate, %d skipped, %d malformed',
        $name, $result['accepted'], $result['duplicate'], $result['skipped'], $result['malformed']));

    return $result;
}

/**
 * Turn one spool line into an events row.
 *
 * Returns null for events we do not store — an unsubscribed event name, or a
 * missing identifier. Throws only on something genuinely malformed.
 *
 * @param array<string,mixed> $e
 * @return array<string,mixed>|null
 */
function mapEvent(array $e, int $tenantId): ?array
{
    $source = (int) ($e['s'] ?? EventType::SOURCE_PIXEL);
    $name   = (string) ($e['n'] ?? '');

    $type = EventType::fromName($name, $source);
    if ($type === null) {
        return null;   // not subscribed; includes the PII-bearing input events
    }

    $eventId = (string) ($e['id'] ?? '');
    if ($eventId === '') {
        // Without a stable id there is no dedup key, so synthesise one from
        // the event's own content. A genuine duplicate produces the same
        // digest and is still caught.
        $eventId = 'syn:' . hash('sha256', json_encode([
            $e['cid'] ?? '', $name, $e['ts'] ?? '', $e['u'] ?? '', $e['p'] ?? '',
        ]));
    }

    $tsMs = (int) ($e['ts'] ?? 0);
    $rxMs = (int) ($e['_rx'] ?? 0);

    // A client clock can be wrong by years. Anything implausible is pinned to
    // arrival time so it lands in a shard that exists, rather than being
    // routed to 1970 or 2049.
    $occurred = ($tsMs > 946684800000 && $tsMs < (time() + 86400) * 1000)
        ? $tsMs
        : ($rxMs ?: (int) (microtime(true) * 1000));

    $url = isset($e['u']) ? (string) $e['u'] : null;
    $ip  = isset($e['_ip']) ? (string) $e['_ip'] : '';

    $click = is_array($e['cl'] ?? null) ? $e['cl'] : [];

    return [
        'tenant_id'       => $tenantId,
        'event_uid'       => Hash::uid64($tenantId . ':' . $eventId),
        'occurred_at'     => gmdate('Y-m-d H:i:s', intdiv($occurred, 1000)),
        'received_at'     => gmdate('Y-m-d H:i:s', $rxMs ? intdiv($rxMs, 1000) : time()),
        'event_type'      => $type,
        'source'          => $source,
        'visitor_key'     => Dim::visitor($tenantId, (string) ($e['cid'] ?? '')) ?? 0,
        'person_id'       => null,
        'customer_ref'    => isset($e['cu']) && $e['cu'] !== '' ? (int) $e['cu'] : null,
        'path_id'         => Dim::path($tenantId, $url),
        'referrer_id'     => Dim::referrer($tenantId, isset($e['r']) ? (string) $e['r'] : null),
        'campaign_id'     => Dim::campaign($tenantId, $url),
        'ua_id'           => Dim::userAgent($tenantId, isset($e['_ua']) ? (string) $e['_ua'] : null),
        'geo_id'          => Dim::geo(GeoIp::lookup($ip)),
        'product_id'      => isset($e['p']) && $e['p'] !== '' ? (int) $e['p'] : null,
        'variant_id'      => isset($e['v']) && $e['v'] !== '' ? (int) $e['v'] : null,
        'qty'             => isset($e['q']) ? max(0, min(65535, (int) $e['q'])) : null,
        'amount_minor'    => isset($e['a']) ? max(0, (int) round((float) $e['a'])) : null,
        'currency_id'     => currencyId(isset($e['c']) ? (string) $e['c'] : null),
        'checkout_token'  => isset($e['ct']) && $e['ct'] !== ''
            ? Hash::uid64((string) $e['ct']) : null,
        'order_ref'       => isset($e['o']) && $e['o'] !== '' ? (int) $e['o'] : null,
        'search_term_id'  => Dim::searchTerm($tenantId, isset($e['st']) ? (string) $e['st'] : null),
        'click_target_id' => Dim::clickTarget(
            $tenantId,
            isset($click['t']) ? (string) $click['t'] : null,
            isset($click['s']) ? (string) $click['s'] : null,
            isset($click['h']) ? (string) $click['h'] : null,
        ),
    ];
}

/**
 * Batch insert with INSERT IGNORE on the dedup key.
 *
 * This is what permanently closes the defect the prior audit found: 443
 * duplicate rows, of which two duplicated checkout_completed pairs overstated
 * revenue by INR 1,378. Duplicates are counted and reported, never stored.
 *
 * @param array<int,array<string,mixed>> $rows
 * @return array{accepted:int,duplicate:int}
 */
function insertEvents(PDO $pdo, array $rows): array
{
    if ($rows === []) {
        return ['accepted' => 0, 'duplicate' => 0];
    }

    $cols = array_keys($rows[0]);
    $list = implode(', ', $cols);
    $one  = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';

    $accepted = 0;

    foreach (array_chunk($rows, 500) as $chunk) {
        $sql = "INSERT IGNORE INTO events ({$list}) VALUES "
             . implode(', ', array_fill(0, count($chunk), $one));

        $params = [];
        foreach ($chunk as $row) {
            foreach ($cols as $c) {
                $params[] = $row[$c];
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $accepted += $stmt->rowCount();
    }

    return ['accepted' => $accepted, 'duplicate' => count($rows) - $accepted];
}

function currencyId(?string $code): ?int
{
    static $map = null;

    if ($code === null || $code === '') {
        return null;
    }

    if ($map === null) {
        $map = [];
        foreach (Db::core()->query('SELECT currency_id, code FROM dim_currency')->fetchAll() as $r) {
            $map[strtoupper((string) $r['code'])] = (int) $r['currency_id'];
        }
    }

    return $map[strtoupper($code)] ?? null;
}

/** @param array{accepted:int,duplicate:int,malformed:int,skipped:int} $r */
function finishImportLog(int $tenantId, string $name, string $status, array $r, ?string $message): void
{
    Db::core()->prepare(
        'UPDATE import_log
            SET accepted = ?, duplicates = ?, malformed = ?, skipped = ?,
                finished_at = UTC_TIMESTAMP(), status = ?, message = ?
          WHERE tenant_id = ? AND spool_file = ?'
    )->execute([
        $r['accepted'], $r['duplicate'], $r['malformed'], $r['skipped'], $status,
        $message !== null ? substr($message, 0, 2000) : null,
        $tenantId, $name,
    ]);
}

/**
 * Move a committed file into processed/.
 *
 * Kept for a retention window rather than deleted, so a bug in the mapping
 * above can be corrected and the raw feed replayed. This is what makes it
 * acceptable to shred events into typed columns at import and never store the
 * original payload.
 */
function moveToProcessed(string $path, int $tenantId): void
{
    $dir = Config::get('paths.processed') . '/' . $tenantId;

    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
        return;   // leave it in spool rather than lose it
    }

    @rename($path, $dir . '/' . basename($path));
}

function sweepProcessed(): void
{
    $days   = (int) Config::get('storage.processed_retain_days', 30);
    $cutoff = time() - ($days * 86400);

    foreach (glob(Config::get('paths.processed') . '/*/*.ndjson') ?: [] as $f) {
        if (@filemtime($f) < $cutoff) {
            @unlink($f);
        }
    }
}

/** Regenerate the write_key map c.php reads, so it never queries the database. */
function refreshTenantCache(): void
{
    // One implementation, shared with the OAuth callback so a store is in
    // the map the instant it installs rather than at the next import.
    Tenant::refreshWriteKeyCache();

}

