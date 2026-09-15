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
$langs->loadLangs(array('navinvoice@navinvoice', 'navinvoicebatch@navinvoice', 'navrelation@navinvoice', 'navinvoiceui@navinvoice'));

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
$pageSizeOptions = array(50, 100, 200);
$pageSize = (int) GETPOST('page_size', 'int');
if (!in_array($pageSize, $pageSizeOptions, true)) {
    $pageSize = 100;
}
$page = max(1, (int) GETPOST('page', 'int'));
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
                $langs->trans('BatchImportResultSummary', count($batchResult['success']), count($batchResult['skipped']), count($batchResult['errors'])),
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
$totalRecords = 0;
$pageCount = 1;
try {
    $totalRecords = $service->countInboundRecords($dateFrom, $dateTo);
    $pageCount = max(1, (int) ceil($totalRecords / $pageSize));
    $page = min($page, $pageCount);
    $offset = ($page - 1) * $pageSize;
    $dependencyLimit = min(100, max(0, 300 - $pageSize));
    $records = $service->loadInboundRecords($dateFrom, $dateTo, $pageSize, $offset, $dependencyLimit);
    $rows = $service->preflightMany($records);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}

$chainPendingCodes = array(
    'operation_relation_original_not_imported',
    'operation_relation_prior_modification_not_imported',
    'operation_source_invoice_missing',
);
$isChainPending = static function (array $preview, string $state) use ($chainPendingCodes): bool {
    if ($state !== 'blocked') {
        return false;
    }
    $blockers = array_values(array_unique(array_map('strval', $preview['blockers'] ?? array())));
    return $blockers && !array_diff($blockers, $chainPendingCodes);
};

$counts = array('ready' => 0, 'review' => 0, 'partner_required' => 0, 'blocked' => 0, 'imported' => 0, 'dependency' => 0, 'chain_pending' => 0);
foreach ($rows as $row) {
    $state = (string) ($row['state'] ?? 'blocked');
    $preview = is_array($row['preview'] ?? null) ? $row['preview'] : array();
    if (isset($counts[$state])) {
        $counts[$state]++;
    } else {
        $counts['blocked']++;
    }
    if (!empty($row['record']->_nav_batch_dependency)) {
        $counts['dependency']++;
    }
    if ($isChainPending($preview, $state)) {
        $counts['chain_pending']++;
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
    if ($state === 'partner_required') {
        return img_picto('', 'company').' <span class="warning">'.$langs->trans('BatchStatePartnerRequired').'</span>';
    }
    if ($state === 'imported') {
        return img_picto('', 'check').' <span class="opacitymedium">'.$langs->trans('BatchStateImported').'</span>';
    }
    return img_picto('', 'error').' <span class="error">'.$langs->trans('ImportStateBlocked').'</span>';
};

$reconciliationLabel = static function (string $code) use ($langs): string {
    $keys = array('default' => 'BatchReconciliationDefault', 'mode1' => 'BatchReconciliationMode1', 'mode2' => 'BatchReconciliationMode2', 'nav_summary' => 'BatchReconciliationNavSummary', 'nav_fallback' => 'BatchReconciliationNavFallback');
    return isset($keys[$code]) ? $langs->trans($keys[$code]) : '';
};

$pagerUrl = static function (int $targetPage) use ($dateFrom, $dateTo, $pageSize): string {
    $query = http_build_query(array(
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page_size' => $pageSize,
        'page' => $targetPage,
    ));
    return $_SERVER['PHP_SELF'].'?'.$query;
};
$renderPager = static function () use ($page, $pageCount, $pagerUrl, $langs): string {
    if ($pageCount <= 1) {
        return '';
    }
    $html = '<div style="display:flex;align-items:center;justify-content:center;gap:10px;margin:12px 0">';
    if ($page > 1) {
        $html .= '<a class="button" href="'.dol_escape_htmltag($pagerUrl($page - 1)).'">‹ '.$langs->trans('BatchPreviousPage').'</a>';
    }
    $html .= '<strong>'.$langs->trans('BatchPageStatus', $page, $pageCount).'</strong>';
    if ($page < $pageCount) {
        $html .= '<a class="button" href="'.dol_escape_htmltag($pagerUrl($page + 1)).'">'.$langs->trans('BatchNextPage').' ›</a>';
    }
    $html .= '</div>';
    return $html;
};

llxHeader('', $langs->trans('BatchImport'));
print load_fiche_titre($langs->trans('BatchImport'), '<a href="'.dol_buildpath('/navinvoice/index.php', 1).'">'.$langs->trans('BackToNavInvoiceList').'</a>', 'file-invoice');
print '<div style="max-width:1200px">';
print '<div class="info marginbottomonly">'.$langs->trans('BatchInboundOnlyNotice').'</div>';
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('BatchDraftOnlyNotice').'</div>';
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('BatchRangeAndDependenciesNotice').'</div>';

$isHungarianUi = substr(strtolower((string) $langs->defaultlang), 0, 2) === 'hu';
$fromLabel = $langs->trans('SyncFromLabel');
$toLabel = $langs->trans('SyncToLabel');
print '<form id="navinvoice-batch-preflight-form" method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" style="margin:12px 0">';
print '<input type="hidden" name="page" value="1">';
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
print '<span>'.$langs->trans('BatchPerPage').'</span><select name="page_size">';
foreach ($pageSizeOptions as $option) {
    print '<option value="'.$option.'"'.($option === $pageSize ? ' selected' : '').'>'.$option.'</option>';
}
print '</select>';
print '<input id="navinvoice-batch-preflight-submit" class="button" type="submit" value="'.$langs->trans('BatchRunPreflight').'">';
print '</div></form>';
print '<script>';
print 'document.addEventListener("DOMContentLoaded",function(){';
print 'var form=document.getElementById("navinvoice-batch-preflight-form");';
print 'var button=document.getElementById("navinvoice-batch-preflight-submit");';
print 'if(!form||!button){return;}';
print 'form.addEventListener("submit",function(){';
print 'if(button.disabled){return false;}';
print 'button.disabled=true;button.setAttribute("aria-busy","true");button.value=button.value+"…";';
print '});';
print '});';
print '</script>';

if ($loadError !== '') {
    print '<div class="error">'.img_picto('', 'error').' '.dol_escape_htmltag($loadError).'</div>';
}

$summaryCell = static function (string $label, string $value, string $class = ''): string {
    $valueClass = $class !== '' ? ' class="'.$class.'"' : '';
    return '<td style="width:33.333%;vertical-align:middle">'
        .'<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:18px;min-width:0">'
        .'<span style="min-width:0">'.dol_escape_htmltag($label).'</span>'
        .'<strong'.$valueClass.' style="white-space:nowrap">'.$value.'</strong>'
        .'</div></td>';
};

print '<table class="noborder centpercent" style="max-width:1100px;table-layout:fixed">';
print '<tr>';
print $summaryCell($langs->trans('BatchRangeTotal'), (string) $totalRecords);
print $summaryCell($langs->trans('BatchPageChecked'), (string) count($rows));
print $summaryCell($langs->trans('BatchReady'), (string) $counts['ready'], 'ok');
print '</tr><tr>';
print $summaryCell($langs->trans('BatchAlreadyImported'), (string) $counts['imported']);
print $summaryCell($langs->trans('BatchDependencies'), (string) $counts['dependency']);
print $summaryCell($langs->trans('BatchChainPending'), (string) $counts['chain_pending'], 'warning');
print '</tr><tr>';
print $summaryCell($langs->trans('BatchBlocked'), (string) $counts['blocked'], 'error');
print $summaryCell($langs->trans('BatchPartnerRequired'), (string) $counts['partner_required'], 'warning');
print $summaryCell($langs->trans('BatchReview'), (string) $counts['review'], 'warning');
print '</tr>';
print '</table>';
print '<div class="opacitymedium small" style="margin-top:6px">'.$langs->trans('BatchPageCountsNotice').'</div><br>';
print $renderPager();
print '</div>';

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
    print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="batch_import">';
    print '<input type="hidden" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"><input type="hidden" name="date_to" value="'.dol_escape_htmltag($dateTo).'">';
    print '<input type="hidden" name="page_size" value="'.$pageSize.'"><input type="hidden" name="page" value="'.$page.'">';
    print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
    print '<td class="center">'.$langs->trans('BatchSelect').'</td><td>'.$langs->trans('ProposalStatus').'</td><td>'.$langs->trans('NavInvoiceNumber').'</td><td>'.$langs->trans('Supplier').'</td>';
    print '<td>'.$langs->trans('InvoiceIssueDate').'</td><td>'.$langs->trans('InvoiceDeliveryDate').'</td><td>'.$langs->trans('PaymentDate').'</td><td>'.$langs->trans('InvoiceCategory').'</td><td class="right">'.$langs->trans('AmountTTC').'</td><td>'.$langs->trans('BatchIssues').'</td></tr>';

    $selectableCount = 0;
    foreach ($rows as $row) {
        $record = $row['record'];
        $preview = is_array($row['preview'] ?? null) ? $row['preview'] : array();
        $state = (string) ($row['state'] ?? 'blocked');
        $ready = $state === 'ready';
        $chainPending = $isChainPending($preview, $state);
        $selectable = $ready || $chainPending;
        if ($selectable) {
            $selectableCount++;
        }
        $dependency = !empty($record->_nav_batch_dependency);
        $operation = strtoupper(trim((string) ($preview['operation'] ?? $record->invoice_operation ?? 'CREATE')));
        $isNonCreate = $operation !== '' && $operation !== 'CREATE';
        $supplier = is_array($preview['partner'] ?? null) ? (string) $preview['partner']['name'] : (string) ($record->supplier_name ?? '');
        $currency = (string) ($preview['header']['currency'] ?? $record->currency ?? $baseCurrency);
        $gross = $preview['totals']['gross'] ?? null;
        $relationUrl = dol_buildpath('/navinvoice/relation.php', 1).'?id='.(int) $record->rowid;
        if ($state === 'partner_required') {
            $rowUrl = dol_buildpath('/navinvoice/partner.php', 1).'?id='.(int) $record->rowid;
        } elseif ($isNonCreate && !$ready) {
            $rowUrl = $relationUrl;
        } elseif ($ready) {
            $rowUrl = dol_buildpath('/navinvoice/import.php', 1).'?id='.(int) $record->rowid;
        } else {
            $rowUrl = dol_buildpath('/navinvoice/detail.php', 1).'?id='.(int) $record->rowid;
        }

        $issues = array();
        if ($dependency) {
            $issues[] = img_picto('', 'history').' <span class="opacitymedium">'.$langs->trans('BatchDependencyIncluded').'</span>';
        }
        if ($chainPending) {
            $issues[] = img_picto('', 'warning').' <span class="warning">'.$langs->trans('BatchChainPending').'</span>';
        }
        if ($isNonCreate) {
            $issues[] = img_picto('', 'link').' <a href="'.dol_escape_htmltag($relationUrl).'">'.dol_escape_htmltag($langs->trans('ReviewRelation')).'</a>';
        }
        if ($state === 'partner_required') {
            $partnerUrl = dol_buildpath('/navinvoice/partner.php', 1).'?id='.(int) $record->rowid;
            $issues[] = img_picto('', 'company').' <a href="'.dol_escape_htmltag($partnerUrl).'">'.dol_escape_htmltag($langs->trans('BatchResolvePartner')).'</a>';
        }
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

        print '<tr class="oddeven"><td class="center">';
        if ($selectable && $canImport) {
            print '<input type="checkbox" name="selected[]" value="'.(int) $record->rowid.'" checked>';
        } else {
            print '<span class="opacitymedium">—</span>';
        }
        print '</td>';
        print '<td>'.($chainPending ? img_picto('', 'warning').' <span class="warning">'.$langs->trans('BatchChainPending').'</span>' : $stateHtml($state)).'</td>';
        print '<td><a href="'.dol_escape_htmltag($rowUrl).'">'.dol_escape_htmltag((string) $record->invoice_number).'</a></td>';
        print '<td>'.($supplier !== '' ? dol_escape_htmltag($supplier) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['header']['invoice_date'] ?? $record->invoice_issue_date ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['header']['delivery_date'] ?? '')).'</td><td>'.dol_escape_htmltag((string) ($preview['header']['due_date'] ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($preview['category'] ?? $record->invoice_category ?? '')).'</td>';
        print '<td class="right">'.($gross !== null && $gross !== '' ? price($gross).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.($issues ? implode('<br>', $issues) : '<span class="opacitymedium">—</span>').'</td></tr>';
    }
    print '</table></div>';

    if ($selectableCount > 0) {
        if ($canImport) {
            print '<div class="center tabsAction"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('BatchImportSelected')).'" onclick="return confirm(\''.dol_escape_js($langs->trans('BatchImportConfirm')).'\');"></div>';
        } else {
            print '<div class="warning">'.img_picto('', 'warning').' '.$langs->trans('ImportPermissionMissing').'</div>';
        }
    }
    print '</form>';
    print $renderPager();
} elseif ($loadError === '') {
    print '<div class="opacitymedium">'.$langs->trans('BatchNoInvoices').'</div>';
}

llxFooter();
$db->close();
