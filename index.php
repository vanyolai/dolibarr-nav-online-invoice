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
dol_include_once('/navinvoice/class/navinvoicelinkmanager.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navinvoicebatch@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$sync = new NavInvoiceSync($db);
$linkManager = new NavInvoiceLinkManager($db, (int) $conf->entity);
try {
    $sync->ensureSchema();
} catch (Throwable $e) {
    setEventMessages($langs->trans('SchemaMigrationFailed').': '.$e->getMessage(), null, 'errors');
}

$action = GETPOST('action', 'aZ09');
$syncDirection = strtoupper(GETPOST('direction', 'alpha'));
if (!in_array($syncDirection, array('BOTH', 'OUTBOUND', 'INBOUND'), true)) {
    $syncDirection = 'BOTH';
}

if ($action === 'sync' && $user->hasRight('navinvoice', 'invoice', 'sync')) {
    $from = GETPOST('date_from', 'alphanohtml');
    $to = GETPOST('date_to', 'alphanohtml');
    try {
        $stats = $sync->syncPeriod($from, $to, (bool) getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1), $syncDirection);
        $message = $langs->trans(
            'SyncCompletedDetailed',
            $stats['seen'],
            $stats['outbound'],
            $stats['inbound'],
            $stats['inserted']
        );
        $message .= ' '.$langs->trans(
            'SyncCompletedDetailedExtra',
            $stats['updated'],
            $stats['downloaded']
        );
        setEventMessages($message, null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('SyncFailed').': '.$e->getMessage(), null, 'errors');
    }
}

llxHeader('', $langs->trans('NavOnlineInvoice'));
print load_fiche_titre($langs->trans('NavOnlineInvoice'), '', 'file-invoice');
print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/navinvoice/batch.php', 1).'">'.$langs->trans('BatchImport').'</a>';
print '</div>';

if ($user->hasRight('navinvoice', 'invoice', 'sync')) {
    $today = new DateTimeImmutable('today');
    $defaultFrom = $today->modify('-6 days')->format('Y-m-d');
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="sync">';
    print '<div class="fichecenter">';
    print $langs->trans('DateFrom').' <input type="date" name="date_from" required value="'.dol_escape_htmltag(GETPOST('date_from', 'alphanohtml') ?: $defaultFrom).'"> ';
    print $langs->trans('DateTo').' <input type="date" name="date_to" required value="'.dol_escape_htmltag(GETPOST('date_to', 'alphanohtml') ?: $today->format('Y-m-d')).'"> ';
    print $langs->trans('InvoiceDirection').' <select name="direction">';
    foreach (array('BOTH' => 'DirectionBoth', 'OUTBOUND' => 'DirectionOutbound', 'INBOUND' => 'DirectionInbound') as $value => $label) {
        print '<option value="'.$value.'"'.($syncDirection === $value ? ' selected' : '').'>'.$langs->trans($label).'</option>';
    }
    print '</select> ';
    print '<input class="button" type="submit" value="'.$langs->trans('RunNavSync').'">';
    print '</div></form><br>';
}

$sql = 'SELECT rowid, invoice_direction, invoice_number, invoice_operation, invoice_issue_date,';
$sql .= ' supplier_name, supplier_tax_number, customer_name, customer_tax_number,';
$sql .= ' currency, invoice_net_amount, invoice_vat_amount, data_fetched, fk_facture, fk_facture_fourn, last_sync';
$sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE entity = '.((int) $conf->entity);
$sql .= ' ORDER BY invoice_issue_date DESC, rowid DESC';
$sql .= ' LIMIT 300';
$resql = $db->query($sql);

print '<div class="div-table-responsive">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre">';
print '<td>'.$langs->trans('InvoiceDirection').'</td>';
print '<td>'.$langs->trans('NavInvoiceNumber').'</td>';
print '<td>'.$langs->trans('Date').'</td>';
print '<td>'.$langs->trans('Operation').'</td>';
print '<td>'.$langs->trans('Partner').'</td>';
print '<td>'.$langs->trans('TaxNumber').'</td>';
print '<td class="right">'.$langs->trans('AmountHT').'</td>';
print '<td class="right">'.$langs->trans('VAT').'</td>';
print '<td>'.$langs->trans('XmlDownloaded').'</td>';
print '<td>'.$langs->trans('DolibarrInvoice').'</td>';
print '<td>'.$langs->trans('LastSync').'</td>';
print '</tr>';

if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $direction = strtoupper((string) $obj->invoice_direction);
        $isInbound = $direction === 'INBOUND';
        $partnerName = trim((string) ($isInbound ? $obj->supplier_name : $obj->customer_name));
        $partnerTaxNumber = trim((string) ($isInbound ? $obj->supplier_tax_number : $obj->customer_tax_number));
        $privatePerson = (!$isInbound && $partnerName === '' && $partnerTaxNumber === '');
        $hasNetAmount = $obj->invoice_net_amount !== null && $obj->invoice_net_amount !== '';
        $hasVatAmount = $obj->invoice_vat_amount !== null && $obj->invoice_vat_amount !== '';
        $currency = trim((string) $obj->currency);
        $detailUrl = dol_buildpath('/navinvoice/detail.php', 1).'?id='.(int) $obj->rowid;
        $importUrl = dol_buildpath('/navinvoice/import.php', 1).'?id='.(int) $obj->rowid;

        try {
            $linkedId = $linkManager->resolve($obj, $direction);
        } catch (Throwable $e) {
            dol_syslog('NAV invoice link verification failed for mirror row '.((int) $obj->rowid).': '.$e->getMessage(), LOG_ERR);
            $linkedId = $isInbound ? (int) $obj->fk_facture_fourn : (int) $obj->fk_facture;
        }

        print '<tr class="oddeven">';
        print '<td>'.$langs->trans($isInbound ? 'DirectionInbound' : 'DirectionOutbound').'</td>';
        print '<td><a href="'.$detailUrl.'">'.dol_escape_htmltag($obj->invoice_number).'</a></td>';
        print '<td>'.dol_escape_htmltag($obj->invoice_issue_date).'</td>';
        print '<td>'.dol_escape_htmltag($obj->invoice_operation).'</td>';
        print '<td>'.($privatePerson ? '<span class="opacitymedium">'.$langs->trans('PrivatePerson').'</span>' : ($partnerName !== '' ? dol_escape_htmltag($partnerName) : '<span class="opacitymedium">—</span>')).'</td>';
        print '<td>'.($partnerTaxNumber !== '' ? dol_escape_htmltag($partnerTaxNumber) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td class="right">'.($hasNetAmount ? price($obj->invoice_net_amount).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td class="right">'.($hasVatAmount ? price($obj->invoice_vat_amount).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.($obj->data_fetched ? img_picto($langs->trans('Yes'), 'tick') : img_picto($langs->trans('No'), 'warning')).'</td>';
        print '<td>';
        if ($linkedId > 0) {
            $invoiceUrl = $isInbound
                ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedId
                : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$linkedId;
            print img_picto('', 'tick').' <a href="'.dol_escape_htmltag($invoiceUrl).'">'.$langs->trans('OpenDolibarrInvoice').'</a>';
        } else {
            print '<a href="'.dol_escape_htmltag($importUrl).'">'.img_picto('', 'file-invoice').' '.$langs->trans('ImportPreview').'</a>';
        }
        print '</td>';
        print '<td>'.dol_escape_htmltag($obj->last_sync).'</td>';
        print '</tr>';
    }
    $db->free($resql);
} else {
    print '<tr><td colspan="11" class="error">'.dol_escape_htmltag($db->lasterror()).'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
