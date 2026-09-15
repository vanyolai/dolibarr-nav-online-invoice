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
dol_include_once('/navinvoice/class/navpurchaseeligibility.class.php');
$langs->load('navpurchase@navinvoice');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    http_response_code(403);
    print json_encode(array('show' => false));
    exit;
}

$id = GETPOSTINT('id');
$enabled = (bool) getDolGlobalInt('NAVINVOICE_PURCHASE_WORKBENCH_ENABLED');
if (!$enabled || $id <= 0) {
    print json_encode(array('show' => false, 'enabled' => $enabled));
    exit;
}

$sql = 'SELECT invoice_direction, invoice_operation, invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE rowid = '.$id.' AND entity = '.((int) $conf->entity).' LIMIT 1';
$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    print json_encode(array('show' => false));
    exit;
}
$obj = $db->fetch_object($resql);
$db->free($resql);

$parsed = null;
if ($obj && !empty($obj->invoice_data)) {
    try {
        $parsed = (new NavInvoiceParser())->parse((string) $obj->invoice_data);
    } catch (Throwable $e) {
        // Do not turn a display helper into a hard failure. The detail/import
        // preview will report malformed XML through its normal validation path.
    }
}

$show = $obj
    && NavPurchaseEligibility::isEligible(
        (string) $obj->invoice_direction,
        (string) $obj->invoice_operation,
        is_array($parsed) ? $parsed : null
    );

print json_encode(array(
    'show' => (bool) $show,
    'enabled' => true,
    'label' => $langs->transnoentities('PurchaseWorkbench'),
    'url' => $show ? dol_buildpath('/navinvoice/purchase.php', 1).'?id='.$id : '',
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$db->close();
