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

    $status = (string) $state['status'];
    $stage = (string) $state['stage'];
    $direction = (string) $state['current_direction'];
    $directionLabel = '';
    if ($direction === 'INBOUND') {
        $directionLabel = $langs->transnoentities('DirectionInbound');
    } elseif ($direction === 'OUTBOUND') {
        $directionLabel = $langs->transnoentities('DirectionOutbound');
    } elseif ($direction === 'BOTH') {
        $directionLabel = $langs->transnoentities('DirectionBoth');
    }

    $chunkIndex = (int) ($state['chunk_index'] ?? 0);
    $chunkTotal = (int) ($state['chunk_total'] ?? 0);
    $page = (int) ($state['page'] ?? 0);
    $availablePage = (int) ($state['available_page'] ?? 0);
    $recordIndex = (int) ($state['record_index'] ?? 0);
    $recordTotal = (int) ($state['record_total'] ?? 0);

    $progressFraction = null;
    if ($status === 'done') {
        $progressFraction = 1.0;
    } elseif ($chunkIndex > 0 && $chunkTotal > 0) {
        $withinChunk = 0.0;
        if ($stage === 'chunk_done') {
            $withinChunk = 1.0;
        } elseif ($page > 0 && $availablePage > 0) {
            $withinPage = 0.0;
            if ($recordTotal > 0) {
                $withinPage = max(0.0, min(1.0, $recordIndex / $recordTotal));
            } elseif ($stage === 'digest_page_done') {
                $withinPage = 1.0;
            }
            $withinChunk = (($page - 1) + $withinPage) / $availablePage;
        }
        $progressFraction = (($chunkIndex - 1) + max(0.0, min(1.0, $withinChunk))) / $chunkTotal;
        if ($status === 'running') {
            $progressFraction = min(0.999, $progressFraction);
        }
        $progressFraction = max(0.0, min(1.0, $progressFraction));
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
        $parts = array($langs->transnoentities('SyncProgressRunningShort'));
        if ($chunkIndex > 0 && $chunkTotal > 0) {
            $parts[] = $langs->transnoentities('SyncProgressChunk', $chunkIndex, $chunkTotal);
        }
        if ((string) $state['chunk_from'] !== '' && (string) $state['chunk_to'] !== '') {
            $parts[] = $langs->transnoentities('SyncProgressPeriod', (string) $state['chunk_from'], (string) $state['chunk_to']);
        }
        if ($directionLabel !== '') {
            $parts[] = $directionLabel;
        }
        if ($page > 0 && $availablePage > 0 && !in_array($stage, array('chunk', 'chunk_done'), true)) {
            $parts[] = $langs->transnoentities('SyncProgressDigestPage', $page, $availablePage);
        }
        if ($recordTotal > 0 && in_array($stage, array('digest_page', 'record', 'xml_request', 'digest_page_done'), true)) {
            $parts[] = $langs->transnoentities('SyncProgressRecord', $recordIndex, $recordTotal);
        }
        $parts[] = $langs->transnoentities('SyncProgressApiRequests', (int) $state['api_requests']);
        $parts[] = $langs->transnoentities('SyncProgressXmlDownloaded', (int) $state['downloaded']);
        if ((string) $state['current_invoice'] !== '' && $stage === 'xml_request') {
            $parts[] = $langs->transnoentities('SyncProgressCurrentInvoice').': '.(string) $state['current_invoice'];
        }
        $message = implode(' · ', $parts);
    }

    echo json_encode(array(
        'ok' => true,
        'found' => true,
        'status' => $status,
        'stage' => $stage,
        'message' => $message,
        'progress' => $progressFraction,
        'progress_percent' => $progressFraction !== null ? (int) round($progressFraction * 100) : null,
        'current_direction' => $direction,
        'chunk' => array(
            'index' => $chunkIndex,
            'total' => $chunkTotal,
            'from' => (string) $state['chunk_from'],
            'to' => (string) $state['chunk_to'],
        ),
        'digest_page' => array(
            'index' => $page,
            'total' => $availablePage,
        ),
        'record' => array(
            'index' => $recordIndex,
            'total' => $recordTotal,
        ),
        'current_invoice' => (string) $state['current_invoice'],
        'api_requests' => (int) $state['api_requests'],
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
