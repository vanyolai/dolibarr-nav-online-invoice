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

dol_include_once('/navinvoice/class/navinvoicesync.class.php');
$langs->loadLangs(array('navinvoice@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');
if ($action === 'sync' && $user->hasRight('navinvoice', 'invoice', 'sync')) {
    $from = GETPOST('date_from', 'alphanohtml');
    $to = GETPOST('date_to', 'alphanohtml');
    try {
        $sync = new NavInvoiceSync($db);
        $stats = $sync->syncPeriod($from, $to, (bool) getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1));
        setEventMessages($langs->trans('SyncCompleted', $stats['seen'], $stats['inserted'], $stats['downloaded']), null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('SyncFailed').': '.$e->getMessage(), null, 'errors');
    }
}

llxHeader('', $langs->trans('NavOnlineInvoice'));
print load_fiche_titre($langs->trans('NavOnlineInvoice'), '', 'file-invoice');

if ($user->hasRight('navinvoice', 'invoice', 'sync')) {
    $today = new DateTimeImmutable('today');
    $defaultFrom = $today->modify('-6 days')->format('Y-m-d');
    print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="sync">';
    print '<div class="fichecenter">';
    print $langs->trans('DateFrom').' <input type="date" name="date_from" required value="'.dol_escape_htmltag(GETPOST('date_from', 'alphanohtml') ?: $defaultFrom).'"> ';
    print $langs->trans('DateTo').' <input type="date" name="date_to" required value="'.dol_escape_htmltag(GETPOST('date_to', 'alphanohtml') ?: $today->format('Y-m-d')).'"> ';
    print '<input class="button" type="submit" value="'.$langs->trans('RunNavSync').'">';
    print '</div></form><br>';
}

$sql = 'SELECT rowid, invoice_number, invoice_operation, invoice_issue_date, customer_name, customer_tax_number, currency, invoice_net_amount, invoice_vat_amount, data_fetched, fk_facture, last_sync';
$sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE entity = '.((int) $conf->entity);
$sql .= ' ORDER BY invoice_issue_date DESC, rowid DESC';
$sql .= ' LIMIT 200';
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('NavInvoiceNumber').'</td><td>'.$langs->trans('Date').'</td><td>'.$langs->trans('Operation').'</td><td>'.$langs->trans('Customer').'</td><td>'.$langs->trans('VATIntra').'</td><td class="right">'.$langs->trans('AmountHT').'</td><td class="right">'.$langs->trans('VAT').'</td><td>'.$langs->trans('XmlDownloaded').'</td><td>'.$langs->trans('LastSync').'</td></tr>';
if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $customerName = trim((string) $obj->customer_name);
        $customerTaxNumber = trim((string) $obj->customer_tax_number);
        // NAV Online Invoice v3 deliberately omits customer name, address and VAT data
        // for PRIVATE_PERSON customers. In the digest this is observable as both name
        // and tax number being absent; OTHER customers must still have a name.
        $privatePerson = ($customerName === '' && $customerTaxNumber === '');

        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($obj->invoice_number).'</td>';
        print '<td>'.dol_escape_htmltag($obj->invoice_issue_date).'</td>';
        print '<td>'.dol_escape_htmltag($obj->invoice_operation).'</td>';
        print '<td>'.($privatePerson ? '<span class="opacitymedium">'.$langs->trans('PrivatePerson').'</span>' : dol_escape_htmltag($customerName)).'</td>';
        print '<td>'.($privatePerson ? '<span class="opacitymedium">—</span>' : dol_escape_htmltag($customerTaxNumber)).'</td>';
        print '<td class="right">'.price($obj->invoice_net_amount).' '.dol_escape_htmltag($obj->currency).'</td>';
        print '<td class="right">'.price($obj->invoice_vat_amount).' '.dol_escape_htmltag($obj->currency).'</td>';
        print '<td>'.($obj->data_fetched ? img_picto($langs->trans('Yes'), 'tick') : img_picto($langs->trans('No'), 'warning')).'</td>';
        print '<td>'.dol_escape_htmltag($obj->last_sync).'</td>';
        print '</tr>';
    }
    $db->free($resql);
} else {
    print '<tr><td colspan="9" class="error">'.dol_escape_htmltag($db->lasterror()).'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
