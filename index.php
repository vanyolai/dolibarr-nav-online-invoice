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
$langs->loadLangs(array('navinvoice@navinvoice', 'navinvoicebatch@navinvoice', 'navpartner@navinvoice', 'navrelation@navinvoice', 'navinvoiceui@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$sync = new NavInvoiceSync($db);
$linkManager = new NavInvoiceLinkManager($db, (int) $conf->entity);

$action = GETPOST('action', 'aZ09');
$syncDirection = strtoupper((string) GETPOST('sync_direction', 'alpha'));
if ($syncDirection === '' && $action === 'sync') {
    // Backward compatibility for links/forms generated before the dedicated
    // sync_direction field was introduced.
    $syncDirection = strtoupper((string) GETPOST('direction', 'alpha'));
}
if (!in_array($syncDirection, array('BOTH', 'OUTBOUND', 'INBOUND'), true)) {
    $syncDirection = 'BOTH';
}

$listDirection = strtoupper((string) GETPOST('filter_direction', 'alpha'));
if (!in_array($listDirection, array('BOTH', 'OUTBOUND', 'INBOUND'), true)) {
    $listDirection = 'BOTH';
}
$todoOnly = ((int) GETPOST('todo', 'int')) > 0;
$allowedPageSizes = array(25, 50, 100, 200);
$pageSize = (int) GETPOST('limit', 'int');
if (!in_array($pageSize, $allowedPageSizes, true)) {
    $pageSize = 50;
}
$page = max(0, (int) GETPOST('page', 'int'));

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

$dashboard = array('last_sync' => '', 'total' => 0, 'inbound' => 0, 'outbound' => 0, 'imported' => 0);
$sql = 'SELECT MAX(last_sync) AS last_sync, COUNT(*) AS total,';
$sql .= " SUM(CASE WHEN invoice_direction = 'INBOUND' THEN 1 ELSE 0 END) AS inbound,";
$sql .= " SUM(CASE WHEN invoice_direction = 'OUTBOUND' THEN 1 ELSE 0 END) AS outbound,";
$sql .= ' SUM(CASE WHEN COALESCE(fk_facture, 0) > 0 OR COALESCE(fk_facture_fourn, 0) > 0 THEN 1 ELSE 0 END) AS imported';
$sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_invoice WHERE entity = '.((int) $conf->entity);
$resDashboard = $db->query($sql);
if ($resDashboard && ($objDashboard = $db->fetch_object($resDashboard))) {
    $dashboard['last_sync'] = trim((string) $objDashboard->last_sync);
    $dashboard['total'] = (int) $objDashboard->total;
    $dashboard['inbound'] = (int) $objDashboard->inbound;
    $dashboard['outbound'] = (int) $objDashboard->outbound;
    $dashboard['imported'] = (int) $objDashboard->imported;
    $db->free($resDashboard);
}

$today = new DateTimeImmutable('today');
$lookback = max(1, min(35, getDolGlobalInt('NAVINVOICE_SYNC_LOOKBACK_DAYS', 7)));
$syncAnchor = $today;
if ($dashboard['last_sync'] !== '') {
    try {
        $candidate = new DateTimeImmutable($dashboard['last_sync']);
        $candidate = $candidate->setTime(0, 0, 0);
        if ($candidate <= $today) {
            $syncAnchor = $candidate;
        }
    } catch (Throwable $e) {
        // Keep today's safe fallback.
    }
}
$defaultFrom = $syncAnchor->modify('-'.($lookback - 1).' days')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');
$dateFrom = trim((string) GETPOST('date_from', 'alphanohtml')) ?: $defaultFrom;
$dateTo = trim((string) GETPOST('date_to', 'alphanohtml')) ?: $defaultTo;

llxHeader('', $langs->trans('NavOnlineInvoice'));
print load_fiche_titre($langs->trans('NavOnlineInvoice'), '', 'file-invoice');

$dashboardCell = static function (string $label, string $value, string $title = ''): string {
    $titleAttr = $title !== '' ? ' title="'.dol_escape_htmltag($title).'"' : '';
    return '<td style="width:33.333%;vertical-align:middle">'
        .'<div style="display:flex;align-items:center;justify-content:space-between;gap:18px;min-width:0">'
        .'<span'.$titleAttr.'>'.dol_escape_htmltag($label).'</span>'
        .'<strong style="white-space:nowrap">'.$value.'</strong>'
        .'</div></td>';
};

$lastSyncDisplay = $dashboard['last_sync'] !== ''
    ? dol_escape_htmltag($dashboard['last_sync'])
    : '<span class="opacitymedium">—</span>';
$lookbackHelp = $langs->trans('SyncSummaryLookbackHelp');
$isHungarianUi = substr(strtolower((string) $langs->defaultlang), 0, 2) === 'hu';
$fromLabel = $langs->trans('SyncFromLabel');
$toLabel = $langs->trans('SyncToLabel');

print '<div style="max-width:1180px">';
print '<table class="noborder centpercent" style="table-layout:fixed">';
print '<tr class="liste_titre"><td colspan="3">';
print '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">';
print '<span>'.img_picto('', 'refresh').' '.$langs->trans('SyncPanelTitle').'</span>';
if ($user->hasRight('navinvoice', 'invoice', 'sync')) {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" style="margin:0">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="sync">';
    print '<input type="hidden" name="filter_direction" value="'.dol_escape_htmltag($listDirection).'">';
    print '<input type="hidden" name="todo" value="'.($todoOnly ? '1' : '0').'">';
    print '<input type="hidden" name="limit" value="'.$pageSize.'">';
    print '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
    if (!$isHungarianUi) {
        print '<span>'.dol_escape_htmltag($fromLabel).'</span>';
    }
    print '<input type="date" name="date_from" required value="'.dol_escape_htmltag($dateFrom).'">';
    if ($isHungarianUi) {
        print '<span>'.dol_escape_htmltag($fromLabel).'</span>';
    }
    if (!$isHungarianUi) {
        print '<span>'.dol_escape_htmltag($toLabel).'</span>';
    }
    print '<input type="date" name="date_to" required value="'.dol_escape_htmltag($dateTo).'">';
    if ($isHungarianUi) {
        print '<span>'.dol_escape_htmltag($toLabel).'</span>';
    }
    print '<span>'.$langs->trans('InvoiceDirection').'</span>';
    print '<select name="sync_direction">';
    foreach (array('BOTH' => 'DirectionBoth', 'OUTBOUND' => 'DirectionOutbound', 'INBOUND' => 'DirectionInbound') as $value => $label) {
        print '<option value="'.$value.'"'.($syncDirection === $value ? ' selected' : '').'>'.$langs->trans($label).'</option>';
    }
    print '</select>';
    print '<input class="button" type="submit" value="'.$langs->trans('RunNavSync').'">';
    print '</div></form>';
}
print '</div></td></tr>';
print '<tr>';
print $dashboardCell($langs->trans('LastSync'), $lastSyncDisplay);
print $dashboardCell($langs->trans('DirectionInbound'), (string) $dashboard['inbound']);
print $dashboardCell($langs->trans('DirectionOutbound'), (string) $dashboard['outbound']);
print '</tr><tr>';
print $dashboardCell($langs->trans('SyncSummaryScanned'), (string) $dashboard['total']);
print $dashboardCell($langs->trans('SyncSummaryImported'), (string) $dashboard['imported']);
print $dashboardCell($langs->trans('SyncSummaryLookback'), $lookback.' '.dol_escape_htmltag($langs->trans('SyncSummaryDays')), $lookbackHelp);
print '</tr>';
print '</table>';
print '<div style="margin-top:10px">';
print '<a class="button" href="'.dol_buildpath('/navinvoice/batch.php', 1).'">'.$langs->trans('BatchImport').'</a>';
print '</div>';
print '</div><br>';

$listWhere = array('entity = '.((int) $conf->entity));
if ($listDirection !== 'BOTH') {
    $listWhere[] = "invoice_direction = '".$listDirection."'";
}
if ($todoOnly) {
    // Keep this list filter local and deterministic: "to do" means a NAV
    // record that is not yet linked to any Dolibarr invoice. More detailed
    // partner/relation checks remain available from the row actions and batch
    // preflight without triggering expensive API work for every list row.
    $listWhere[] = '(COALESCE(fk_facture, 0) <= 0 AND COALESCE(fk_facture_fourn, 0) <= 0)';
}
$whereSql = implode(' AND ', $listWhere);

$countSql = 'SELECT COUNT(*) AS nb FROM '.MAIN_DB_PREFIX.'navinvoice_invoice WHERE '.$whereSql;
$resCount = $db->query($countSql);
$totalRows = 0;
if ($resCount && ($countObj = $db->fetch_object($resCount))) {
    $totalRows = (int) $countObj->nb;
    $db->free($resCount);
}
$totalPages = max(1, (int) ceil($totalRows / $pageSize));
if ($page >= $totalPages) {
    $page = $totalPages - 1;
}
$offset = $page * $pageSize;

$listBaseParams = array(
    'filter_direction' => $listDirection,
    'todo' => $todoOnly ? 1 : 0,
    'limit' => $pageSize,
);
$buildListUrl = static function (array $overrides = array()) use ($listBaseParams): string {
    $params = array_merge($listBaseParams, $overrides);
    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($params[$key]);
        }
    }
    return dol_buildpath('/navinvoice/index.php', 1).'?'.http_build_query($params);
};

print '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:8px 0 10px 0">';
print '<form method="GET" action="'.dol_buildpath('/navinvoice/index.php', 1).'">';
print '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
print '<strong>'.$langs->trans('InvoiceListFilters').'</strong>';
print '<label>'.$langs->trans('InvoiceListDirection').' <select name="filter_direction">';
foreach (array('BOTH' => 'DirectionBoth', 'INBOUND' => 'DirectionInbound', 'OUTBOUND' => 'DirectionOutbound') as $value => $label) {
    print '<option value="'.$value.'"'.($listDirection === $value ? ' selected' : '').'>'.$langs->trans($label).'</option>';
}
print '</select></label>';
print '<label title="'.dol_escape_htmltag($langs->trans('InvoiceListTodoHelp')).'">';
print '<input type="checkbox" name="todo" value="1"'.($todoOnly ? ' checked' : '').'> '.$langs->trans('InvoiceListTodoOnly').'</label>';
print '<label>'.$langs->trans('InvoiceListPerPage').' <select name="limit">';
foreach ($allowedPageSizes as $allowedSize) {
    print '<option value="'.$allowedSize.'"'.($pageSize === $allowedSize ? ' selected' : '').'>'.$allowedSize.'</option>';
}
print '</select></label>';
print '<button class="button" type="submit">'.$langs->trans('InvoiceListApplyFilters').'</button>';
if ($listDirection !== 'BOTH' || $todoOnly || $pageSize !== 50) {
    print '<a href="'.dol_buildpath('/navinvoice/index.php', 1).'">'.$langs->trans('InvoiceListResetFilters').'</a>';
}
print '</div></form>';
print '<div class="opacitymedium">'.$langs->trans('InvoiceListResults', $totalRows).'</div>';
print '</div>';

$printPagination = static function () use ($page, $totalPages, $buildListUrl, $langs): void {
    if ($totalPages <= 1) {
        return;
    }
    print '<div style="display:flex;align-items:center;justify-content:flex-end;gap:6px;flex-wrap:wrap;margin:8px 0">';
    if ($page > 0) {
        print '<a class="button" href="'.dol_escape_htmltag($buildListUrl(array('page' => $page - 1))).'">'.$langs->trans('InvoiceListPrevious').'</a>';
    }
    $first = max(0, $page - 2);
    $last = min($totalPages - 1, $page + 2);
    if ($first > 0) {
        print '<a href="'.dol_escape_htmltag($buildListUrl(array('page' => 0))).'">1</a>';
        if ($first > 1) {
            print '<span class="opacitymedium">…</span>';
        }
    }
    for ($p = $first; $p <= $last; $p++) {
        if ($p === $page) {
            print '<strong style="padding:0 4px">'.($p + 1).'</strong>';
        } else {
            print '<a href="'.dol_escape_htmltag($buildListUrl(array('page' => $p))).'">'.($p + 1).'</a>';
        }
    }
    if ($last < $totalPages - 1) {
        if ($last < $totalPages - 2) {
            print '<span class="opacitymedium">…</span>';
        }
        print '<a href="'.dol_escape_htmltag($buildListUrl(array('page' => $totalPages - 1))).'">'.$totalPages.'</a>';
    }
    if ($page < $totalPages - 1) {
        print '<a class="button" href="'.dol_escape_htmltag($buildListUrl(array('page' => $page + 1))).'">'.$langs->trans('InvoiceListNext').'</a>';
    }
    print '</div>';
};

$printPagination();

$sql = 'SELECT rowid, invoice_direction, invoice_number, invoice_operation, invoice_issue_date,';
$sql .= ' supplier_name, supplier_tax_number, customer_name, customer_tax_number,';
$sql .= ' currency, invoice_net_amount, invoice_vat_amount, data_fetched, fk_facture, fk_facture_fourn';
$sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE '.$whereSql;
$sql .= ' ORDER BY invoice_issue_date DESC, rowid DESC';
$sql .= ' LIMIT '.$pageSize.' OFFSET '.$offset;
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
print '</tr>';

if ($resql) {
    while ($obj = $db->fetch_object($resql)) {
        $direction = strtoupper((string) $obj->invoice_direction);
        $isInbound = $direction === 'INBOUND';
        $operation = strtoupper(trim((string) $obj->invoice_operation));
        $isNonCreate = $operation !== '' && $operation !== 'CREATE';
        $partnerName = trim((string) ($isInbound ? $obj->supplier_name : $obj->customer_name));
        $partnerTaxNumber = trim((string) ($isInbound ? $obj->supplier_tax_number : $obj->customer_tax_number));
        $privatePerson = (!$isInbound && $partnerName === '' && $partnerTaxNumber === '');
        $hasNetAmount = $obj->invoice_net_amount !== null && $obj->invoice_net_amount !== '';
        $hasVatAmount = $obj->invoice_vat_amount !== null && $obj->invoice_vat_amount !== '';
        $currency = trim((string) $obj->currency);
        $detailUrl = dol_buildpath('/navinvoice/detail.php', 1).'?id='.(int) $obj->rowid;
        $importUrl = dol_buildpath('/navinvoice/import.php', 1).'?id='.(int) $obj->rowid;
        $partnerUrl = dol_buildpath('/navinvoice/partner.php', 1).'?id='.(int) $obj->rowid;
        $relationUrl = dol_buildpath('/navinvoice/relation.php', 1).'?id='.(int) $obj->rowid;

        try {
            $linkedId = $linkManager->resolve($obj, $direction);
        } catch (Throwable $e) {
            dol_syslog('NAV invoice link verification failed for mirror row '.((int) $obj->rowid).': '.$e->getMessage(), LOG_ERR);
            $linkedId = $isInbound ? (int) $obj->fk_facture_fourn : (int) $obj->fk_facture;
        }

        $operationLabel = $operation !== '' ? $langs->trans('NavOperation_'.$operation) : '';
        if ($operationLabel === 'NavOperation_'.$operation) {
            $operationLabel = $operation;
        }

        print '<tr class="oddeven">';
        print '<td>'.$langs->trans($isInbound ? 'DirectionInbound' : 'DirectionOutbound').'</td>';
        print '<td><a href="'.$detailUrl.'">'.dol_escape_htmltag($obj->invoice_number).'</a></td>';
        print '<td>'.dol_escape_htmltag($obj->invoice_issue_date).'</td>';
        print '<td>';
        if ($isNonCreate) {
            print '<a href="'.dol_escape_htmltag($relationUrl).'" title="'.dol_escape_htmltag($langs->trans('ReviewRelation')).'">'.dol_escape_htmltag($operationLabel).'</a>';
        } else {
            print dol_escape_htmltag($operationLabel);
        }
        print '</td>';
        print '<td>';
        if ($privatePerson) {
            print '<span class="opacitymedium">'.$langs->trans('PrivatePerson').'</span>';
        } elseif ($partnerName !== '') {
            print '<a href="'.dol_escape_htmltag($partnerUrl).'" title="'.dol_escape_htmltag($langs->trans('NavPartnerResolution')).'">'.dol_escape_htmltag($partnerName).'</a>';
        } else {
            print '<a href="'.dol_escape_htmltag($partnerUrl).'" title="'.dol_escape_htmltag($langs->trans('NavPartnerResolution')).'"><span class="opacitymedium">—</span></a>';
        }
        print '</td>';
        print '<td>'.($partnerTaxNumber !== '' ? '<a href="'.dol_escape_htmltag($partnerUrl).'">'.dol_escape_htmltag($partnerTaxNumber).'</a>' : '<span class="opacitymedium">—</span>').'</td>';
        print '<td class="right">'.($hasNetAmount ? price($obj->invoice_net_amount).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td class="right">'.($hasVatAmount ? price($obj->invoice_vat_amount).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.($obj->data_fetched ? img_picto($langs->trans('Yes'), 'tick') : img_picto($langs->trans('No'), 'warning')).'</td>';
        print '<td>';
        if ($linkedId > 0) {
            $invoiceUrl = $isInbound
                ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedId
                : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$linkedId;
            print img_picto('', 'tick').' <a href="'.dol_escape_htmltag($invoiceUrl).'">'.$langs->trans('OpenDolibarrInvoice').'</a>';
        } elseif ($isNonCreate) {
            print '<a href="'.dol_escape_htmltag($relationUrl).'">'.img_picto('', 'link').' '.$langs->trans('ReviewRelation').'</a>';
        } else {
            print '<a href="'.dol_escape_htmltag($importUrl).'">'.img_picto('', 'file-invoice').' '.$langs->trans('ImportPreview').'</a>';
        }
        print '</td>';
        print '</tr>';
    }
    $db->free($resql);
} else {
    print '<tr><td colspan="10" class="error">'.dol_escape_htmltag($db->lasterror()).'</td></tr>';
}
print '</table></div>';

$printPagination();

llxFooter();
$db->close();