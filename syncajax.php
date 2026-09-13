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

$progress = new NavSyncProgress($db, (int) $conf->entity, (int) $user->id);
try {
    $progress->start($runKey, $dateFrom, $dateTo);
    $progress->update($runKey, array('current_direction' => $direction));
} catch (Throwable $e) {
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
        $direction
    );
    $progress->update($runKey, array(
        'status' => 'done',
        'stage' => 'done',
        'seen' => (int) $stats['seen'],
        'inserted' => (int) $stats['inserted'],
        'updated' => (int) $stats['updated'],
        'unchanged' => (int) $stats['unchanged'],
        'downloaded' => (int) $stats['downloaded'],
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
}
