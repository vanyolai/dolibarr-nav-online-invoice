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

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navpartnerenrichmentpreview.class.php');
dol_include_once('/navinvoice/class/navpartnerselectionenricher.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navpartnerselection@navinvoice'));

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    http_response_code(403);
    echo json_encode(array('ok' => false, 'error' => 'forbidden'));
    exit;
}

$id = GETPOSTINT('id');
if ($id <= 0) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'error' => 'invalid_id'));
    exit;
}

$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE rowid = '.$id;
$sql .= ' AND entity = '.((int) $conf->entity);
$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    echo json_encode(array('ok' => false, 'error' => 'database_error'));
    exit;
}
$record = $db->fetch_object($resql);
$db->free($resql);
if (!$record) {
    http_response_code(404);
    echo json_encode(array('ok' => false, 'error' => 'not_found'));
    exit;
}

$tr = static function (string $key) use ($langs): string {
    return $langs->transnoentities($key);
};

try {
    if (empty($record->invoice_data)) {
        throw new Exception($tr('FullXmlNotAvailable'));
    }

    $parser = new NavInvoiceParser();
    $parsed = $parser->parse((string) $record->invoice_data);
    $direction = strtoupper(trim((string) $record->invoice_direction));
    $partyKey = $direction === 'INBOUND' ? 'supplier' : 'customer';

    $matcher = new NavPartnerMatcher($db, (int) $conf->entity);
    $partnerMatch = $matcher->match($parsed[$partyKey], $partyKey);

    $previewBuilder = new NavPartnerEnrichmentPreview($db, (int) $conf->entity);
    $enrichment = $previewBuilder->build($parsed[$partyKey], $partnerMatch, $partyKey);

    $selector = new NavPartnerSelectionEnricher($db, (int) $conf->entity);
    $items = $selector->selectableItems($enrichment);
    $canUpdate = $user->hasRight('societe', 'creer');

    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
        if (!$canUpdate) {
            http_response_code(403);
            echo json_encode(array('ok' => false, 'error' => $tr('PartnerEnrichmentPermissionMissing')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $selected = array();
        if (isset($_POST['fields']) && is_array($_POST['fields'])) {
            foreach ($_POST['fields'] as $field) {
                $field = preg_replace('/[^a-z0-9_]/i', '', (string) $field);
                if ($field !== '') {
                    $selected[] = $field;
                }
            }
        }
        $selected = array_values(array_unique($selected));
        if (!$selected) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => $tr('PartnerSelectionNothingSelected')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        $applied = $selector->apply($enrichment, $selected, $user);
        if (!$applied) {
            http_response_code(400);
            echo json_encode(array('ok' => false, 'error' => $tr('PartnerSelectionNothingSelected')), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            exit;
        }

        echo json_encode(array(
            'ok' => true,
            'applied' => $applied,
            'message' => $langs->transnoentities('PartnerSelectionApplied', count($applied)),
        ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $responseItems = array();
    foreach ($items as $item) {
        $selectable = !empty($item['selectable']);
        $defaultSelected = !empty($item['default_selected']);
        $statusMessage = $defaultSelected
            ? $tr('PartnerSelectionDefault')
            : ($selectable ? $tr('PartnerSelectionManual') : $tr('PartnerSelectionLocked'));

        $responseItems[] = array(
            'field' => (string) ($item['field'] ?? ''),
            'label' => $tr((string) ($item['label'] ?? '')),
            'status' => (string) ($item['status'] ?? ''),
            'safe' => !empty($item['safe']),
            'selectable' => $selectable,
            'default_selected' => $defaultSelected,
            'selection_status' => $statusMessage,
        );
    }

    echo json_encode(array(
        'ok' => true,
        'available' => !empty($enrichment['available']),
        'strong_match' => !empty($enrichment['strong_match']),
        'can_update' => $canUpdate,
        'token' => $canUpdate ? newToken() : '',
        'items' => $responseItems,
        'labels' => array(
            'section_title' => $tr('PartnerEnrichmentPreview'),
            'field' => $tr('Field'),
            'current' => $tr('CurrentDolibarrValue'),
            'nav' => $tr('NavValue'),
            'status' => $tr('ProposalStatus'),
            'selection' => $tr('PartnerSelectionColumn'),
            'notice' => $tr('PartnerSelectionNotice'),
            'apply' => $tr('PartnerSelectionApply'),
            'confirm' => $tr('PartnerSelectionConfirm'),
            'nothing_selected' => $tr('PartnerSelectionNothingSelected'),
            'failed' => $tr('PartnerSelectionFailed'),
        ),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    dol_syslog('NAV selective partner enrichment failed for mirror row '.$id.': '.$e->getMessage(), LOG_ERR);
    http_response_code(422);
    echo json_encode(array('ok' => false, 'error' => $e->getMessage()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$db->close();
