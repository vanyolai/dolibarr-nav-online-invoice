<?php

$res = 0;
foreach (array(__DIR__.'/../main.inc.php', __DIR__.'/../../main.inc.php') as $main) {
    if (!$res && file_exists($main)) {
        $res = @include $main;
    }
}
if (!$res) {
    http_response_code(500);
    exit;
}

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpreview.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navoperation@navinvoice'));

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    http_response_code(403);
    print json_encode(array('error' => 'forbidden'));
    exit;
}

$id = GETPOSTINT('id');
if ($id <= 0) {
    http_response_code(400);
    print json_encode(array('error' => 'invalid_id'));
    exit;
}

$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE rowid = '.$id;
$sql .= ' AND entity = '.((int) $conf->entity);
$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    print json_encode(array('error' => 'database_error'));
    exit;
}
$record = $db->fetch_object($resql);
$db->free($resql);
if (!$record) {
    http_response_code(404);
    print json_encode(array('error' => 'not_found'));
    exit;
}

$tr = static function (string $key) use ($langs): string {
    return $langs->transnoentities($key);
};

try {
    $direction = strtoupper(trim((string) $record->invoice_direction));
    $isInbound = $direction === 'INBOUND';
    $linkedId = $isInbound ? (int) $record->fk_facture_fourn : (int) $record->fk_facture;
    $linkedUrl = '';
    if ($linkedId > 0) {
        $linkedUrl = $isInbound
            ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedId
            : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$linkedId;
    }

    $state = 'blocked';
    $blockers = array();
    $warnings = array();

    if ($linkedId > 0) {
        $state = 'imported';
    } elseif (empty($record->invoice_data)) {
        $blockers[] = 'full_xml_missing';
    } else {
        $parser = new NavInvoiceParser();
        $parsed = $parser->parse((string) $record->invoice_data);
        $partyKey = $isInbound ? 'supplier' : 'customer';

        $partnerMatcher = new NavPartnerMatcher($db, (int) $conf->entity);
        $partnerMatch = $partnerMatcher->match($parsed[$partyKey], $partyKey);

        $previewBuilder = new NavInvoiceOperationPreview($db, (int) $conf->entity, strtoupper((string) $conf->currency));
        $preview = $previewBuilder->build($parsed, $record, $partnerMatch);
        $state = (string) ($preview['state'] ?? 'blocked');
        $blockers = array_values(array_map('strval', $preview['blockers'] ?? array()));
        $warnings = array_values(array_map('strval', $preview['warnings'] ?? array()));
    }

    $stateLabels = array(
        'imported' => $tr('AlreadyImported'),
        'ready' => $tr('ImportStateReady'),
        'review' => $tr('ImportStateReview'),
        'blocked' => $tr('ImportStateBlocked'),
    );

    print json_encode(array(
        'state' => $state,
        'label' => $stateLabels[$state] ?? $state,
        'blockers' => $blockers,
        'warnings' => $warnings,
        'linked_id' => $linkedId,
        'linked_url' => $linkedUrl,
        'labels' => array(
            'proposal_status' => $tr('ProposalStatus'),
            'open_invoice' => $tr('OpenDolibarrInvoice'),
        ),
    ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    dol_syslog('NAV import-status preview failed for mirror row '.$id.': '.$e->getMessage(), LOG_ERR);
    http_response_code(422);
    print json_encode(array('error' => 'import_status_failed'));
}

$db->close();
