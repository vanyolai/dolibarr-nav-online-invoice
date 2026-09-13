<?php

$res = 0;
foreach (array(__DIR__.'/../main.inc.php', __DIR__.'/../../main.inc.php') as $main) {
    if (!$res && file_exists($main)) {
        $res = @include $main;
    }
}
if (!$res) {
    http_response_code(500);
    die('Failed to include Dolibarr main.inc.php');
}

dol_include_once('/navinvoice/class/navinvoicesync.class.php');
dol_include_once('/navinvoice/class/navsyncprogress.class.php');

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!$user->hasRight('navinvoice', 'invoice', 'sync')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Forbidden'));
    exit;
}

$runKey = trim((string) GETPOST('run_key', 'alphanohtml'));
$dateFrom = trim((string) GETPOST('date_from', 'alphanohtml'));
$dateTo = trim((string) GETPOST('date_to', 'alphanohtml'));
$direction = strtoupper(trim((string) GETPOST('sync_direction', 'alpha')));
if (!in_array($direction, array('BOTH', 'OUTBOUND', 'INBOUND'), true)) {
    $direction = 'BOTH';
}

$startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
$endDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $dateFrom || $endDate->format('Y-m-d') !== $dateTo || $endDate < $startDate) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'Invalid synchronization period.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$days = (int) $startDate->diff($endDate)->format('%a') + 1;
$chunksPerDirection = max(1, (int) ceil($days / 35));
$totalChunks = $chunksPerDirection * ($direction === 'BOTH' ? 2 : 1);

// Manual synchronization is intentionally serialized per Dolibarr entity.
// The AJAX worker survives navigation (ignore_user_abort), so without this lock
// a user could accidentally start a second multi-minute synchronization while
// the first PHP process was still running in the background.
$lockDirectory = defined('DOL_DATA_ROOT') ? rtrim((string) DOL_DATA_ROOT, '/').'/navinvoice' : sys_get_temp_dir();
if (!is_dir($lockDirectory) && !@mkdir($lockDirectory, 0770, true) && !is_dir($lockDirectory)) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Unable to create NAV synchronization lock directory.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
$lockPath = rtrim($lockDirectory, '/').'/manual-sync-'.((int) $conf->entity).'.lock';
$syncLock = @fopen($lockPath, 'c+');
if ($syncLock === false) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'Unable to open NAV synchronization lock.'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (!flock($syncLock, LOCK_EX | LOCK_NB)) {
    fclose($syncLock);
    http_response_code(409);
    echo json_encode(array(
        'ok' => false,
        'error' => 'Már fut egy NAV szinkronizálás. Várd meg, amíg befejeződik, és csak utána indíts újabbat.',
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$progress = new NavSyncProgress($db, (int) $conf->entity, (int) $user->id);
try {
    $progress->start($runKey, $dateFrom, $dateTo, $direction);
    $progress->update($runKey, array(
        'stage' => 'preparing',
        'chunk_index' => 0,
        'chunk_total' => $totalChunks,
        'api_requests' => 0,
        'downloaded' => 0,
    ));
} catch (Throwable $e) {
    flock($syncLock, LOCK_UN);
    fclose($syncLock);
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Release the PHP session lock before the long operation so syncstatus.php can
// be polled concurrently by the same logged-in browser.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}
ignore_user_abort(true);
@set_time_limit(0);

try {
    $sync = new NavInvoiceSync($db);
    $stats = $sync->syncPeriod(
        $dateFrom,
        $dateTo,
        (bool) getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1),
        $direction,
        static function (array $state) use ($progress, $runKey): void {
            $progress->update($runKey, $state);
        }
    );
    $progress->update($runKey, array(
        'status' => 'done',
        'stage' => 'done',
        'seen' => (int) $stats['seen'],
        'inserted' => (int) $stats['inserted'],
        'updated' => (int) $stats['updated'],
        'unchanged' => (int) $stats['unchanged'],
        'downloaded' => (int) $stats['downloaded'],
        'api_requests' => (int) ($stats['api_requests'] ?? 0),
        'message' => '',
    ));
    echo json_encode(array('ok' => true, 'stats' => $stats), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    try {
        $progress->update($runKey, array('status' => 'error', 'stage' => 'error', 'message' => $e->getMessage()));
    } catch (Throwable $ignored) {
        // Keep the original synchronization error.
    }
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} finally {
    flock($syncLock, LOCK_UN);
    fclose($syncLock);
}
