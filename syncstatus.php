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

dol_include_once('/navinvoice/class/navsyncprogress.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navsyncprogress@navinvoice'));

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'Forbidden'));
    exit;
}

$runKey = trim((string) GETPOST('run_key', 'alphanohtml'));
$progress = new NavSyncProgress($db, (int) $conf->entity, (int) $user->id);

try {
    $state = $progress->get($runKey);
    if ($state === null) {
        echo json_encode(array('ok' => true, 'found' => false));
        exit;
    }

    $processed = (int) $state['seen'];
    $latestInvoice = '';
    $latestDirection = '';

    if ((string) $state['status'] === 'running') {
        // During a live run the core sync updates last_sync on every processed
        // mirror row. Count those rows so the browser sees real progress without
        // coupling the synchronization engine to the UI transport.
        $where = array(
            'entity = '.((int) $conf->entity),
            "last_sync >= '".$db->escape((string) $state['datec'])."'",
            "invoice_issue_date >= '".$db->escape((string) $state['date_from'])."'",
            "invoice_issue_date <= '".$db->escape((string) $state['date_to'])."'",
        );
        $requestedDirection = strtoupper((string) $state['current_direction']);
        if (in_array($requestedDirection, array('INBOUND', 'OUTBOUND'), true)) {
            $where[] = "invoice_direction = '".$db->escape($requestedDirection)."'";
        }
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'navinvoice_invoice WHERE '.$whereSql;
        $resql = $db->query($sql);
        if ($resql && ($obj = $db->fetch_object($resql))) {
            $processed = (int) $obj->nb;
            $db->free($resql);
        }

        $sql = 'SELECT invoice_number, invoice_direction FROM '.MAIN_DB_PREFIX.'navinvoice_invoice'
            .' WHERE '.$whereSql.' ORDER BY last_sync DESC, rowid DESC LIMIT 1';
        $resql = $db->query($sql);
        if ($resql && ($obj = $db->fetch_object($resql))) {
            $latestInvoice = (string) $obj->invoice_number;
            $latestDirection = (string) $obj->invoice_direction;
            $db->free($resql);
        }
    }

    $status = (string) $state['status'];
    $directionLabel = '';
    $effectiveDirection = $latestDirection !== '' ? $latestDirection : (string) $state['current_direction'];
    if ($effectiveDirection === 'INBOUND') {
        $directionLabel = $langs->transnoentities('DirectionInbound');
    } elseif ($effectiveDirection === 'OUTBOUND') {
        $directionLabel = $langs->transnoentities('DirectionOutbound');
    } elseif ($effectiveDirection === 'BOTH') {
        $directionLabel = $langs->transnoentities('DirectionBoth');
    }

    if ($status === 'done') {
        $message = $langs->transnoentities(
            'SyncProgressDone',
            (int) $state['seen'],
            (int) $state['inserted'],
            (int) $state['updated'],
            (int) $state['downloaded']
        );
    } elseif ($status === 'error') {
        $message = $langs->transnoentities('SyncProgressError').': '.(string) $state['message'];
    } else {
        $message = $langs->transnoentities('SyncProgressRunning', $processed);
        if ($directionLabel !== '') {
            $message .= ' · '.$directionLabel;
        }
        if ($latestInvoice !== '') {
            $message .= ' · '.$langs->transnoentities('SyncProgressLatestInvoice').': '.$latestInvoice;
        }
    }

    echo json_encode(array(
        'ok' => true,
        'found' => true,
        'status' => $status,
        'message' => $message,
        'processed' => $processed,
        'latest_invoice' => $latestInvoice,
        'latest_direction' => $latestDirection,
        'stats' => array(
            'seen' => (int) $state['seen'],
            'inserted' => (int) $state['inserted'],
            'updated' => (int) $state['updated'],
            'unchanged' => (int) $state['unchanged'],
            'downloaded' => (int) $state['downloaded'],
        ),
        'updated_at' => (string) $state['updated_at'],
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
