<?php

$res = 0;
foreach (array(__DIR__.'/../main.inc.php', __DIR__.'/../../main.inc.php') as $main) {
    if (!$res && file_exists($main)) {
        $res = @include $main;
    }
}
if (!$res) {
    die('Failed to include Dolibarr main.inc.php');
}

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navinvoicechainsync.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navrelation@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'sync')) {
    accessforbidden();
}

$id = (int) GETPOST('id', 'int');
if ($id <= 0) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
if ($action !== 'sync_chain') {
    accessforbidden();
}

$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE rowid = '.$id.' AND entity = '.((int) $conf->entity);
$resql = $db->query($sql);
if (!$resql) {
    dol_print_error($db);
    exit;
}
$record = $db->fetch_object($resql);
$db->free($resql);
if (!$record) {
    accessforbidden();
}

$parsed = null;
if (!empty($record->invoice_data)) {
    try {
        $parser = new NavInvoiceParser();
        $parsed = $parser->parse((string) $record->invoice_data);
    } catch (Throwable $e) {
        setEventMessages($langs->trans('NavXmlParseFailed').': '.$e->getMessage(), null, 'errors');
        header('Location: '.dol_buildpath('/navinvoice/relation.php', 1).'?id='.$id);
        exit;
    }
}

try {
    $service = new NavInvoiceChainSyncService($db, (int) $conf->entity);
    $stats = $service->syncFromRecord($record, $parsed);
    setEventMessages(
        $langs->trans(
            'NavChainSyncSuccess',
            (string) ($stats['chain_count'] ?? 0),
            (string) ($stats['inserted'] ?? 0),
            (string) ($stats['updated'] ?? 0)
        ),
        null,
        'mesgs'
    );
} catch (Throwable $e) {
    setEventMessages($langs->trans('NavChainSyncFailed').': '.$e->getMessage(), null, 'errors');
}

header('Location: '.dol_buildpath('/navinvoice/relation.php', 1).'?id='.$id);
exit;
