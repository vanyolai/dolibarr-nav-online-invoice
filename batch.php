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

dol_include_once('/navinvoice/class/navinvoicebatch.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navinvoicebatch@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$hasModuleImportRight = !empty($user->admin) || $user->hasRight('navinvoice', 'invoice', 'import');
$hasSupplierCreateRight = $user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer');
$canImport = $hasModuleImportRight && $hasSupplierCreateRight;

$today = new DateTimeImmutable('today');
$defaultFrom = $today->modify('-30 days')->format('Y-m-d');
$dateFrom = trim((string) GETPOST('date_from', 'alphanohtml')) ?: $defaultFrom;
$dateTo = trim((string) GETPOST('date_to', 'alphanohtml')) ?: $today->format('Y-m-d');
$action = GETPOST('action', 'aZ09');
$baseCurrency = strtoupper((string) $conf->currency);
$service = new NavInvoiceBatchService($db, (int) $conf->entity, $baseCurrency);
$batchResult = null;

if ($action === 'batch_import') {
    if (!$canImport) {
        accessforbidden();
    }

    $selected = GETPOST('selected', 'array:int');
    $selected = is_array($selected) ? $selected : array();
    if (!$selected) {
        setEventMessages($langs->trans('BatchNoSelection'), null, 'warnings');
    } else {
        try {
            $batchResult = $service->importSelected($selected, $user);
            setEventMessages(
                $langs->trans(
                    'BatchImportResultSummary',
                    count($batchResult['success']),
                    count($batchResult['skipped']),
                    count($batchResult['errors'])
                ),
                null,
                empty($batchResult['errors']) ? 'mesgs' : 'warnings'
            );
        } catch (Throwable $e) {
            setEventMessages($langs->trans('BatchImportFailed').': '.$e->getMessage(), null, 'errors');
        }
    }
}

$rows = array();
$loadError = '';
try {
    $records = $service->loadInboundRecords($dateFrom, $dateTo, 300);
    $rows = $service->preflightMany($records);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$counts = array('ready' => 0, 'review' => 0, 'blocked' => 0, 'imported' => 0);
foreach ($rows as $row) {
    $state = (string) ($row['state'] ?? 'blocked');
    if (isset($counts[$state])) {
        $counts[$state]++;
    } else {
        $counts['blocked']++;
    }
}

$issueLabel = static function (string $code) use ($langs): string {
    foreach (array('ImportBlocker_', 'ImportWarning_') as $prefix) {
        $key = $prefix.$code;
        $translated = $langs->trans($key);
        if ($translated !== $key) {
            return $translated;
        }
    }
    return $code;
};

$stateHtml = static function (string $state) use ($langs): string {
    if ($state === 'ready') {
        return img_picto('', 'tick').' <span class="ok">'.$langs->trans('ImportStateReady').'</span>';
    }
    if ($state === 'review') {
        return img_picto('', 'warning').' <span class="warning">'.$langs->trans('ImportStateReview').'</span>';
    }
    if ($state === 'imported') {
        return img_picto('', 'check').' <span class="opacitymedium">'.$langs->trans('BatchStateImported').'</span>';
    }
    return img_picto('', 'error').' <span class="error">'.$langs->trans('ImportStateBlocked').'</span>';
};

$reconciliationLabel = static function (string $code) use ($langs): string {
    $keys = array(
        'default' => 'BatchReconciliationDefault',
        'mode1' => 'BatchReconciliationMode1',
        'mode2' => 'BatchReconciliationMode2',
        'nav_summary' => 'BatchReconciliationNavSummary',
        'nav_fallback' => 'BatchReconciliationNavFallback',
    );
    if (!isset($keys[$code])) {
        return '';
    }
    return $langs->trans($keys[$code]);
};

llxHeader('', $langs->trans('BatchImport'));
print load_fiche_titre(
    $langs->trans('BatchImport'),
    '<a href="'.dol_buildpath('/navinvoice/index.php', 1).'">'.$langs->trans('BackToNavInvoiceList').'</a>',
    'file-invoice'
);

print '<div class="info marginbottomonly">'.$langs->trans('BatchInboundOnlyNotice').'</div>';
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('BatchDraftOnlyNotice').'</div>';

print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<div class="fichecenter">';
print $langs->trans('DateFrom').' <input type="date" name="date_from" required value="'.dol_escape_htmltag($dateFrom).'"> ';
print $langs->trans('DateTo').' <input type="date" name="date_to" required value="'.dol_escape_htmltag($dateTo).'"> ';
print '<input class="button" type="submit" value="'.$langs->trans('BatchRunPreflight').'">';
print '</div></form><br>';

if ($loadError !== '') {
    print '<div class="error">'.img_picto('', 'error').' '.dol_escape_htmltag($loadError).'</div>';
}

print '<div class="fichecenter">';
print '<div class="fichehalfleft"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('BatchTotal').'</td><td class="right">'.count($rows).'</td></tr>';
print '<tr><td>'.$langs->trans('BatchReady').'</td><td class="right"><span class="ok">'.$counts['ready'].'</span></td></tr>';
print '<tr><td>'.$langs->trans('BatchReview').'</td><td class="right"><span class="warning">'.$counts['review'].'</span></td></tr>';
print '</table></div>';
print '<div class="fichehalfright"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('BatchBlocked').'</td><td class="right"><span class="error">'.$counts['blocked'].'</span></td></tr>';
print '<tr><td>'.$langs->trans('BatchAlreadyImported').'</td><td class="right">'.$counts['imported'].'</td></tr>';
print '</table></div>';
print '<div class="clearboth"></div></div><br>';

if (is_array($batchResult)) {
    print load_fiche_titre($langs->trans('BatchResultTitle'), '', 'list');
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre"><td>'.$langs->trans('NavInvoiceNumber').'</td><td>'.$langs->trans('ProposalStatus').'</td><td>'.$langs->trans('DolibarrInvoice').'</td><td>'.$langs->trans('BatchMessage').'</td></tr>';
    foreach ($batchResult['success'] as $item) {
        $message = $reconciliationLabel((string) ($item['reconciliation'] ?? ''));
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($item['invoice_number']).'</td><td>'.img_picto('', 'tick').' '.$langs->trans('BatchResultSuccess').'</td><td><a href="'.dol_escape_htmltag($item['url']).'">'.dol_escape_htmltag($item['ref']).'</a></td><td>'.dol_escape_htmltag($message).'</td></tr>';
    }
    foreach ($batchResult['skipped'] as $item) {
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($item['invoice_number']).'</td><td>'.img_picto('', 'warning').' '.$langs->trans('BatchResultSkipped').'</td><td></td><td>'.dol_escape_htmltag($item['message']).'</td></tr>';
    }
    foreach ($batchResult['errors'] as $item) {
        print '<tr class="oddeven"><td>'.dol_escape_htmltag($item['invoice_number']).'</td><td>'.img_picto('', 'error').' '.$langs->trans('BatchResultError').'</td><td></td><td>'.dol_escape_htmltag($item['message']).'</td></tr>';
    }
    print '</table></div><br>';
}

if ($rows) {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="batch_import">';
    print '<input type="hidden" name="date_from" value="'.dol_escape_htmltag($dateFrom).'">';
    print '<input type="hidden" name="date_to" value="'.dol_escape_htmltag($dateTo).'">';
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="center">'.$langs->trans('BatchSelect').'</td>';
    print '<td>'.$langs->trans('ProposalStatus').'</td>';
    print '<td>'.$langs->trans('NavInvoiceNumber').'</td>';
    print '<td>'.$langs->trans('Supplier').'</td>';
    print '<td>'.$langs->trans('InvoiceIssueDate').'</td>';
    print '<td>'.$langs->trans('InvoiceDeliveryDate').'</td>';
    print '<td>'.$langs->trans('PaymentDate').'</td>';
    print '<td>'.$langs->trans('InvoiceCategory').'</td>';
    print '<td class="right">'.$langs->trans('AmountTTC').'</td>';
    print '<td>'.$langs->trans('BatchIssues').'</td>';
    print '</tr>';

    foreach ($rows as $row) {
        $record = $row['record'];
        $preview = is_array($row['preview'] ?? null) ? $row['preview'] : array();
        $state = (string) ($row['state'] ?? 'blocked');
        $ready = $state === 'ready';
        $supplier = is_array($preview['partner'] ?? null)
            ? (string) $preview['partner']['name']
            : (string) ($record->supplier_name ?? '');
        $currency = (string) ($preview['header']['currency'] ?? $record->currency ?? $baseCurrency);
        $gross = $preview['totals']['gross'] ?? null;
        $rowUrl = $ready
            ? dol_buildpath('/navinvoice/import.php', 1).'?id='.(int) $record->rowid
            : dol_buildpath('/navinvoice/detail.php', 1).'?id='.(int) $record->rowid;

        $issues = array();
        foreach (($preview['blockers'] ?? array()) as $code) {
            $issues[] = img_picto('', 'error').' '.dol_escape_htmltag($issueLabel((string) $code));
        }
        foreach (($preview['warnings'] ?? array()) as $code) {
            $issues[] = img_picto('', 'warning').' '.dol_escape_htmltag($issueLabel((string) $code));
        }
        foreach (($preview['notices'] ?? array()) as $code) {
            $issues[] = img_picto('', 'info').' <span class="opacitymedium">'.dol_escape_htmltag($issueLabel((string) $code)).'</span>';
        }
        if (!empty($row['error'])) {
            $issues[] = img_picto('', 'error').' '.dol_escape_htmltag((string) $row['error']);
        }

        print '<tr class="oddeven">';
        print '<td class="center">';
        if ($ready && $canImport) {
            print '<input type="checkbox" name="selected[]" value="'.(int) $record->rowid.'" checked>';
        } else {
            print '<span class="opacitymedium">—</span>';
        }
        print '</td>';
        print '<td>'.$stateHtml($state).'</td>';
        print '<td><a href="'.dol_escape_htmltag($rowUrl).'">'.dol_escape_htmltag((string) $record->invoice_number).'</a></td>';
        print '<td>'.($supplier !== '' ? dol_escape_htmltag($supplier) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['header']['invoice_date'] ?? $record->invoice_issue_date ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['header']['delivery_date'] ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['header']['due_date'] ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['category'] ?? $record->invoice_category ?? '')).'</td>';
        print '<td class="right">'.($gross !== null && $gross !== '' ? price($gross).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.($issues ? implode('<br>', $issues) : '<span class="opacitymedium">—</span>').'</td>';
        print '</tr>';
    }
    print '</table></div>';

    if ($counts['ready'] > 0) {
        if ($canImport) {
            print '<div class="center tabsAction">';
            print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('BatchImportSelected')).'" onclick="return confirm(\''.dol_escape_js($langs->trans('BatchImportConfirm')).'\');">';
            print '</div>';
        } else {
            print '<div class="warning">'.img_picto('', 'warning').' '.$langs->trans('ImportPermissionMissing').'</div>';
        }
    }
    print '</form>';
} elseif ($loadError === '') {
    print '<div class="opacitymedium">'.$langs->trans('BatchNoInvoices').'</div>';
}

llxFooter();
$db->close();
