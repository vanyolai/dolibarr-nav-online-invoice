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

dol_include_once('/navinvoice/class/navinvoicemetadata.class.php');
$langs->loadLangs(array('navinvoiceui@navinvoice'));

header('Content-Type: application/json; charset=UTF-8');

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    http_response_code(403);
    print json_encode(array('error' => 'forbidden'));
    exit;
}

$id = (int) GETPOST('id', 'int');
if ($id <= 0) {
    http_response_code(400);
    print json_encode(array('error' => 'invalid_id'));
    exit;
}

$sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
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

// This endpoint returns JSON, not HTML. Use Dolibarr's entity-free
// translation API so the browser receives real UTF-8 text and can keep
// rendering it safely through textContent without HTML decoding.
$tr = static function (string $key) use ($langs): string {
    return $langs->transnoentities($key);
};

$labels = array(
    'title' => $tr('NavProcessingMetadata'),
    'help' => $tr('NavProcessingMetadataHelp'),
    'none' => $tr('NavProcessingMetadataNone'),
    'invoice' => $tr('NavInvoiceLevelMetadata'),
    'line' => $tr('NavLineMetadata'),
    'product_codes' => $tr('NavProductCodes'),
    'conventional' => $tr('NavConventionalData'),
    'additional' => $tr('NavAdditionalData'),
    'barcode_candidates' => $tr('NavBarcodeCandidates'),
    'barcode_help' => $tr('NavBarcodeCandidatesHelp'),
    'source' => $tr('NavMetadataSource'),
    'name' => $tr('NavMetadataName'),
    'description' => $tr('NavMetadataDescription'),
    'value' => $tr('NavMetadataValue'),
    'conventional_labels' => array(),
);

foreach (array(
    'order_numbers',
    'delivery_notes',
    'shipping_dates',
    'contract_numbers',
    'supplier_company_codes',
    'customer_company_codes',
    'dealer_codes',
    'cost_centers',
    'project_numbers',
    'general_ledger_account_numbers',
    'gln_supplier',
    'gln_customer',
    'material_numbers',
    'item_numbers',
    'ekaer_ids',
) as $key) {
    $labels['conventional_labels'][$key] = $tr('NavMeta_'.$key);
}

try {
    $metadata = array('invoice' => array('conventional' => array(), 'additional_data' => array()), 'lines' => array());
    if (!empty($record->invoice_data)) {
        $extractor = new NavInvoiceMetadataExtractor();
        $metadata = $extractor->extract((string) $record->invoice_data);
    }

    print json_encode(
        array('labels' => $labels, 'metadata' => $metadata),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    dol_syslog('NAV metadata extraction failed for mirror row '.$id.': '.$e->getMessage(), LOG_ERR);
    http_response_code(422);
    print json_encode(array('error' => 'metadata_parse_failed'));
}

$db->close();
