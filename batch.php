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
$allowedStateFilters = array('ALL', 'READY', 'IMPORTED', 'BLOCKED', 'REVIEW', 'PARTNER_REQUIRED', 'CHAIN_PENDING');
$stateFilter = strtoupper(trim((string) GETPOST('state_filter', 'alpha')));
if (!in_array($stateFilter, $allowedStateFilters, true)) {
    $stateFilter = 'ALL';
}

$baseCurrency = strtoupper((string) $conf->currency);
$service = new NavInvoiceBatchService($db, (int) $conf->entity, $baseCurrency);
$batchResult = null;
$loadError = '';

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

$compactRow = static function (array $row) use ($isChainPending, $baseCurrency): array {
    $record = $row['record'];
    $preview = is_array($row['preview'] ?? null) ? $row['preview'] : array();
    $state = (string) ($row['state'] ?? 'blocked');
    $chainPending = $isChainPending($preview, $state);
    $partner = is_array($preview['partner'] ?? null) ? $preview['partner'] : array();
    $header = is_array($preview['header'] ?? null) ? $preview['header'] : array();
    $totals = is_array($preview['totals'] ?? null) ? $preview['totals'] : array();

    return array(
        'id' => (int) ($record->rowid ?? 0),
        'invoice_number' => (string) ($record->invoice_number ?? ''),
        'supplier_name' => (string) ($record->supplier_name ?? ''),
        'record_invoice_date' => (string) ($record->invoice_issue_date ?? ''),
        'record_category' => (string) ($record->invoice_category ?? ''),
        'record_operation' => strtoupper(trim((string) ($record->invoice_operation ?? 'CREATE'))),
        'dependency' => !empty($record->_nav_batch_dependency),
        'state' => $state,
        'display_state' => $chainPending ? 'CHAIN_PENDING' : strtoupper($state),
        'chain_pending' => $chainPending,
        'partner_name' => (string) ($partner['name'] ?? ''),
        'invoice_date' => (string) ($header['invoice_date'] ?? $record->invoice_issue_date ?? ''),
        'delivery_date' => (string) ($header['delivery_date'] ?? ''),
        'due_date' => (string) ($header['due_date'] ?? ''),
        'category' => (string) ($preview['category'] ?? $record->invoice_category ?? ''),
        'operation' => strtoupper(trim((string) ($preview['operation'] ?? $record->invoice_operation ?? 'CREATE'))),
        'currency' => (string) ($header['currency'] ?? $record->currency ?? $baseCurrency),
        'gross' => $totals['gross'] ?? null,
        'blockers' => array_values(array_unique(array_map('strval', $preview['blockers'] ?? array()))),
        'warnings' => array_values(array_unique(array_map('strval', $preview['warnings'] ?? array()))),
        'notices' => array_values(array_unique(array_map('strval', $preview['notices'] ?? array()))),
        'error' => (string) ($row['error'] ?? ''),
    );
};

$sessionKey = 'navinvoice_batch_preflight_v3_'.((int) $conf->entity).'_'.((int) $user->id);
$snapshot = isset($_SESSION[$sessionKey]) && is_array($_SESSION[$sessionKey]) ? $_SESSION[$sessionKey] : null;
$snapshotMatchesRange = static function ($snapshot, string $from, string $to) use ($conf, $user): bool {
    return is_array($snapshot)
        && (int) ($snapshot['entity'] ?? 0) === (int) $conf->entity
        && (int) ($snapshot['user_id'] ?? 0) === (int) $user->id
        && (string) ($snapshot['date_from'] ?? '') === $from
        && (string) ($snapshot['date_to'] ?? '') === $to
        && !empty($snapshot['snapshot_id'])
        && is_array($snapshot['rows'] ?? null);
};

$buildSnapshot = static function () use ($service, $dateFrom, $dateTo, $compactRow, $conf, $user): array {
    @set_time_limit(0);
    $totalRecords = $service->countInboundRecords($dateFrom, $dateTo);
    $chunkSize = 100;
    $byId = array();

    for ($offset = 0; $offset < $totalRecords; $offset += $chunkSize) {
        $records = $service->loadInboundRecords($dateFrom, $dateTo, $chunkSize, $offset, 200);
        foreach ($records as $record) {
            $id = (int) ($record->rowid ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (isset($byId[$id])) {
                if (empty($record->_nav_batch_dependency)) {
                    $byId[$id]['dependency'] = false;
                }
                continue;
            }
            $byId[$id] = $compactRow($service->preflightRecord($record));
        }
    }

    $rows = array_values($byId);
    usort($rows, static function (array $a, array $b): int {
        $dateCompare = strcmp((string) ($b['invoice_date'] ?? ''), (string) ($a['invoice_date'] ?? ''));
        if ($dateCompare !== 0) {
            return $dateCompare;
        }
        return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
    });

    return array(
        'snapshot_id' => bin2hex(random_bytes(12)),
        'entity' => (int) $conf->entity,
        'user_id' => (int) $user->id,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'created_at' => time(),
        'range_total' => $totalRecords,
        'rows' => $rows,
    );
};

$updateSnapshotAfterImport = static function (array &$snapshot, array $batchResult): void {
    $successIds = array();
    foreach (($batchResult['success'] ?? array()) as $item) {
        $id = (int) ($item['id'] ?? 0);
        if ($id > 0) {
            $successIds[$id] = true;
        }
    }
    if (!$successIds) {
        return;
    }
    foreach ($snapshot['rows'] as &$row) {
        if (isset($successIds[(int) ($row['id'] ?? 0)])) {
            $row['state'] = 'imported';
            $row['display_state'] = 'IMPORTED';
            $row['chain_pending'] = false;
            $row['blockers'] = array();
            $row['warnings'] = array();
            $row['notices'] = array();
        }
    }
    unset($row);
    $snapshot['updated_after_import_at'] = time();
};

if ($action === 'preflight') {
    try {
        $snapshot = $buildSnapshot();
        $_SESSION[$sessionKey] = $snapshot;
        $page = 1;
    } catch (Throwable $e) {
        $loadError = $e->getMessage();
        $snapshot = null;
    }
}

if ($action === 'batch_import' || $action === 'batch_import_all') {
    if (!$canImport) {
        accessforbidden();
    }
    if (!$snapshotMatchesRange($snapshot, $dateFrom, $dateTo)) {
        setEventMessages($langs->trans('BatchSnapshotMissing'), null, 'warnings');
    } else {
        $postedSnapshotId = trim((string) GETPOST('snapshot_id', 'alphanohtml'));
        if ($postedSnapshotId === '' || !hash_equals((string) $snapshot['snapshot_id'], $postedSnapshotId)) {
            setEventMessages($langs->trans('BatchSnapshotMissing'), null, 'warnings');
        } else {
            if ($action === 'batch_import_all') {
                $selected = array();
                foreach ($snapshot['rows'] as $row) {
                    if (($row['display_state'] ?? '') === 'READY') {
                        $selected[] = (int) ($row['id'] ?? 0);
                    }
                }
            } else {
                $selected = GETPOST('selected', 'array:int');
                $selected = is_array($selected) ? $selected : array();
            }

            $selected = array_values(array_unique(array_filter(array_map('intval', $selected), static function (int $id): bool {
                return $id > 0;
            })));

            if (!$selected) {
                setEventMessages($langs->trans('BatchNoSelection'), null, 'warnings');
            } else {
                try {
                    @set_time_limit(0);
                    $batchResult = array('success' => array(), 'skipped' => array(), 'errors' => array());
                    foreach (array_chunk($selected, 300) as $chunk) {
                        $part = $service->importSelected($chunk, $user);
                        foreach (array('success', 'skipped', 'errors') as $key) {
                            $batchResult[$key] = array_merge($batchResult[$key], $part[$key] ?? array());
                        }
                    }
                    $updateSnapshotAfterImport($snapshot, $batchResult);
                    $_SESSION[$sessionKey] = $snapshot;
                    setEventMessages(
                        $langs->trans('BatchImportResultSummary', count($batchResult['success']), count($batchResult['skipped']), count($batchResult['errors'])),
                        null,
                        empty($batchResult['errors']) ? 'mesgs' : 'warnings'
                    );
                    if (!empty($batchResult['success'])) {
                        setEventMessages($langs->trans('BatchSnapshotRefreshAfterImport'), null, 'mesgs');
                    }
                } catch (Throwable $e) {
                    setEventMessages($langs->trans('BatchImportFailed').': '.$e->getMessage(), null, 'errors');
                }
            }
        }
    }
}

$snapshotValid = $snapshotMatchesRange($snapshot, $dateFrom, $dateTo);
$snapshotRows = $snapshotValid ? array_values($snapshot['rows']) : array();
$rangeTotal = $snapshotValid ? (int) ($snapshot['range_total'] ?? 0) : 0;

$counts = array(
    'ready' => 0,
    'review' => 0,
    'partner_required' => 0,
    'blocked' => 0,
    'imported' => 0,
    'dependency' => 0,
    'chain_pending' => 0,
);
foreach ($snapshotRows as $row) {
    $displayState = strtoupper((string) ($row['display_state'] ?? 'BLOCKED'));
    if ($displayState === 'READY') {
        $counts['ready']++;
    } elseif ($displayState === 'IMPORTED') {
        $counts['imported']++;
    } elseif ($displayState === 'REVIEW') {
        $counts['review']++;
    } elseif ($displayState === 'PARTNER_REQUIRED') {
        $counts['partner_required']++;
    } elseif ($displayState === 'CHAIN_PENDING') {
        $counts['chain_pending']++;
    } else {
        $counts['blocked']++;
    }
    if (!empty($row['dependency'])) {
        $counts['dependency']++;
    }
}

$filteredRows = $snapshotRows;
if ($stateFilter !== 'ALL') {
    $filteredRows = array_values(array_filter($snapshotRows, static function (array $row) use ($stateFilter): bool {
        return strtoupper((string) ($row['display_state'] ?? 'BLOCKED')) === $stateFilter;
    }));
}
$filteredTotal = count($filteredRows);
$pageCount = max(1, (int) ceil($filteredTotal / $pageSize));
$page = min($page, $pageCount);
$displayRows = $filteredTotal > 0 ? array_slice($filteredRows, ($page - 1) * $pageSize, $pageSize) : array();

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

$stateHtml = static function (string $displayState) use ($langs): string {
    $displayState = strtoupper($displayState);
    if ($displayState === 'READY') {
        return img_picto('', 'tick').' <span class="ok">'.$langs->trans('ImportStateReady').'</span>';
    }
    if ($displayState === 'REVIEW') {
        return img_picto('', 'warning').' <span class="warning">'.$langs->trans('ImportStateReview').'</span>';
    }
    if ($displayState === 'PARTNER_REQUIRED') {
        return img_picto('', 'company').' <span class="warning">'.$langs->trans('BatchStatePartnerRequired').'</span>';
    }
    if ($displayState === 'IMPORTED') {
        return img_picto('', 'check').' <span class="opacitymedium">'.$langs->trans('BatchStateImported').'</span>';
    }
    if ($displayState === 'CHAIN_PENDING') {
        return img_picto('', 'warning').' <span class="warning">'.$langs->trans('BatchChainPending').'</span>';
    }
    return img_picto('', 'error').' <span class="error">'.$langs->trans('ImportStateBlocked').'</span>';
};

$reconciliationLabel = static function (string $code) use ($langs): string {
    $keys = array('default' => 'BatchReconciliationDefault', 'mode1' => 'BatchReconciliationMode1', 'mode2' => 'BatchReconciliationMode2', 'nav_summary' => 'BatchReconciliationNavSummary', 'nav_fallback' => 'BatchReconciliationNavFallback');
    return isset($keys[$code]) ? $langs->trans($keys[$code]) : '';
};

$pagerUrl = static function (int $targetPage) use ($dateFrom, $dateTo, $pageSize, $stateFilter): string {
    return $_SERVER['PHP_SELF'].'?'.http_build_query(array(
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'page_size' => $pageSize,
        'state_filter' => $stateFilter,
        'page' => $targetPage,
    ));
};
$renderPager = static function () use ($page, $pageCount, $pagerUrl, $langs): string {
    if ($pageCount <= 1) {
        return '';
    }
    $html = '<div style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;margin:12px 0">';
    if ($page > 1) {
        $html .= '<a class="button" href="'.dol_escape_htmltag($pagerUrl($page - 1)).'">‹ '.$langs->trans('BatchPreviousPage').'</a>';
    }
    $first = max(1, $page - 2);
    $last = min($pageCount, $page + 2);
    if ($first > 1) {
        $html .= '<a href="'.dol_escape_htmltag($pagerUrl(1)).'">1</a>';
        if ($first > 2) {
            $html .= '<span class="opacitymedium">…</span>';
        }
    }
    for ($p = $first; $p <= $last; $p++) {
        if ($p === $page) {
            $html .= '<strong style="padding:0 4px">'.$p.'</strong>';
        } else {
            $html .= '<a href="'.dol_escape_htmltag($pagerUrl($p)).'">'.$p.'</a>';
        }
    }
    if ($last < $pageCount) {
        if ($last < $pageCount - 1) {
            $html .= '<span class="opacitymedium">…</span>';
        }
        $html .= '<a href="'.dol_escape_htmltag($pagerUrl($pageCount)).'">'.$pageCount.'</a>';
    }
    $html .= '<span class="opacitymedium">'.$langs->trans('BatchPageStatus', $page, $pageCount).'</span>';
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
print '<input type="hidden" name="action" value="preflight"><input type="hidden" name="page" value="1">';
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

if ($snapshotValid) {
    $snapshotTime = !empty($snapshot['created_at']) ? dol_print_date((int) $snapshot['created_at'], 'dayhourtext') : '';
    print '<div class="info marginbottomonly">'.$langs->trans('BatchSnapshotReady', $rangeTotal, count($snapshotRows), $snapshotTime).'</div>';
    print '<table class="noborder centpercent" style="max-width:1100px;table-layout:fixed">';
    print '<tr>';
    print $summaryCell($langs->trans('BatchRangeTotal'), (string) $rangeTotal);
    print $summaryCell($langs->trans('BatchPreflightTotal'), (string) count($snapshotRows));
    print $summaryCell($langs->trans('BatchDependencies'), (string) $counts['dependency']);
    print '</tr><tr>';
    print $summaryCell($langs->trans('BatchReady'), (string) $counts['ready'], 'ok');
    print $summaryCell($langs->trans('BatchAlreadyImported'), (string) $counts['imported']);
    print $summaryCell($langs->trans('BatchBlocked'), (string) $counts['blocked'], 'error');
    print '</tr><tr>';
    print $summaryCell($langs->trans('BatchReview'), (string) $counts['review'], 'warning');
    print $summaryCell($langs->trans('BatchPartnerRequired'), (string) $counts['partner_required'], 'warning');
    print $summaryCell($langs->trans('BatchChainPending'), (string) $counts['chain_pending'], 'warning');
    print '</tr>';
    print '</table><br>';

    print '<div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:10px 0">';
    print '<form method="GET" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"><input type="hidden" name="date_to" value="'.dol_escape_htmltag($dateTo).'">';
    print '<input type="hidden" name="page" value="1">';
    print '<div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">';
    print '<strong>'.$langs->trans('BatchResultFilters').'</strong>';
    print '<label>'.$langs->trans('BatchStateFilter').' <select name="state_filter">';
    $stateFilterLabels = array(
        'ALL' => 'BatchStateFilterAll',
        'READY' => 'BatchReady',
        'IMPORTED' => 'BatchAlreadyImported',
        'BLOCKED' => 'BatchBlocked',
        'REVIEW' => 'BatchReview',
        'PARTNER_REQUIRED' => 'BatchPartnerRequired',
        'CHAIN_PENDING' => 'BatchChainPending',
    );
    foreach ($stateFilterLabels as $value => $labelKey) {
        print '<option value="'.$value.'"'.($stateFilter === $value ? ' selected' : '').'>'.dol_escape_htmltag($langs->trans($labelKey)).'</option>';
    }
    print '</select></label>';
    print '<label>'.$langs->trans('BatchPerPage').' <select name="page_size">';
    foreach ($pageSizeOptions as $option) {
        print '<option value="'.$option.'"'.($option === $pageSize ? ' selected' : '').'>'.$option.'</option>';
    }
    print '</select></label>';
    print '<button class="button" type="submit">'.$langs->trans('BatchApplyFilters').'</button>';
    print '</div></form>';
    print '<div><strong>'.$langs->trans('BatchFilteredTotal').': '.$filteredTotal.'</strong></div>';
    print '</div>';

    if ($counts['ready'] > 0 && $canImport) {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'" style="margin:8px 0 14px 0">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="batch_import_all">';
        print '<input type="hidden" name="snapshot_id" value="'.dol_escape_htmltag((string) $snapshot['snapshot_id']).'">';
        print '<input type="hidden" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"><input type="hidden" name="date_to" value="'.dol_escape_htmltag($dateTo).'">';
        print '<input type="hidden" name="page_size" value="'.$pageSize.'"><input type="hidden" name="page" value="'.$page.'"><input type="hidden" name="state_filter" value="'.dol_escape_htmltag($stateFilter).'">';
        print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('BatchImportAllReady', $counts['ready'])).'" onclick="return confirm(\''.dol_escape_js($langs->trans('BatchImportAllReadyConfirm', $counts['ready'])).'\');">';
        print '</form>';
    }

    print $renderPager();
} else {
    print '<div class="opacitymedium" style="margin:10px 0 18px 0">'.$langs->trans('BatchNoSnapshot').'</div>';
}
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

if ($snapshotValid && $displayRows) {
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="batch_import"><input type="hidden" name="snapshot_id" value="'.dol_escape_htmltag((string) $snapshot['snapshot_id']).'">';
    print '<input type="hidden" name="date_from" value="'.dol_escape_htmltag($dateFrom).'"><input type="hidden" name="date_to" value="'.dol_escape_htmltag($dateTo).'">';
    print '<input type="hidden" name="page_size" value="'.$pageSize.'"><input type="hidden" name="page" value="'.$page.'"><input type="hidden" name="state_filter" value="'.dol_escape_htmltag($stateFilter).'">';
    print '<div class="div-table-responsive"><table class="noborder centpercent"><tr class="liste_titre">';
    print '<td class="center">'.$langs->trans('BatchSelect').'</td><td>'.$langs->trans('ProposalStatus').'</td><td>'.$langs->trans('NavInvoiceNumber').'</td><td>'.$langs->trans('Supplier').'</td>';
    print '<td>'.$langs->trans('InvoiceIssueDate').'</td><td>'.$langs->trans('InvoiceDeliveryDate').'</td><td>'.$langs->trans('PaymentDate').'</td><td>'.$langs->trans('InvoiceCategory').'</td><td class="right">'.$langs->trans('AmountTTC').'</td><td>'.$langs->trans('BatchIssues').'</td></tr>';

    $selectableCount = 0;
    foreach ($displayRows as $row) {
        $displayState = strtoupper((string) ($row['display_state'] ?? 'BLOCKED'));
        $selectable = $displayState === 'READY';
        if ($selectable) {
            $selectableCount++;
        }
        $operation = strtoupper(trim((string) ($row['operation'] ?? $row['record_operation'] ?? 'CREATE')));
        $isNonCreate = $operation !== '' && $operation !== 'CREATE';
        $supplier = trim((string) ($row['partner_name'] ?? ''));
        if ($supplier === '') {
            $supplier = trim((string) ($row['supplier_name'] ?? ''));
        }
        $gross = $row['gross'] ?? null;
        $currency = (string) ($row['currency'] ?? $baseCurrency);
        $id = (int) ($row['id'] ?? 0);
        $relationUrl = dol_buildpath('/navinvoice/relation.php', 1).'?id='.$id;
        if ($displayState === 'PARTNER_REQUIRED') {
            $rowUrl = dol_buildpath('/navinvoice/partner.php', 1).'?id='.$id;
        } elseif ($isNonCreate && $displayState !== 'READY') {
            $rowUrl = $relationUrl;
        } elseif ($displayState === 'READY') {
            $rowUrl = dol_buildpath('/navinvoice/import.php', 1).'?id='.$id;
        } else {
            $rowUrl = dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id;
        }

        $issues = array();
        if (!empty($row['dependency'])) {
            $issues[] = img_picto('', 'history').' <span class="opacitymedium">'.$langs->trans('BatchDependencyIncluded').'</span>';
        }
        if ($displayState === 'CHAIN_PENDING') {
            $issues[] = img_picto('', 'warning').' <span class="warning">'.$langs->trans('BatchChainPending').'</span>';
        }
        if ($isNonCreate) {
            $issues[] = img_picto('', 'link').' <a href="'.dol_escape_htmltag($relationUrl).'">'.dol_escape_htmltag($langs->trans('ReviewRelation')).'</a>';
        }
        if ($displayState === 'PARTNER_REQUIRED') {
            $partnerUrl = dol_buildpath('/navinvoice/partner.php', 1).'?id='.$id;
            $issues[] = img_picto('', 'company').' <a href="'.dol_escape_htmltag($partnerUrl).'">'.dol_escape_htmltag($langs->trans('BatchResolvePartner')).'</a>';
        }
        foreach (($row['blockers'] ?? array()) as $code) {
            $issues[] = img_picto('', 'error').' '.dol_escape_htmltag($issueLabel((string) $code));
        }
        foreach (($row['warnings'] ?? array()) as $code) {
            $issues[] = img_picto('', 'warning').' '.dol_escape_htmltag($issueLabel((string) $code));
        }
        foreach (($row['notices'] ?? array()) as $code) {
            $issues[] = img_picto('', 'info').' <span class="opacitymedium">'.dol_escape_htmltag($issueLabel((string) $code)).'</span>';
        }
        if (!empty($row['error'])) {
            $issues[] = img_picto('', 'error').' '.dol_escape_htmltag((string) $row['error']);
        }

        print '<tr class="oddeven"><td class="center">';
        if ($selectable && $canImport) {
            print '<input type="checkbox" name="selected[]" value="'.$id.'" checked>';
        } else {
            print '<span class="opacitymedium">—</span>';
        }
        print '</td>';
        print '<td>'.$stateHtml($displayState).'</td>';
        print '<td><a href="'.dol_escape_htmltag($rowUrl).'">'.dol_escape_htmltag((string) ($row['invoice_number'] ?? '')).'</a></td>';
        print '<td>'.($supplier !== '' ? dol_escape_htmltag($supplier) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.dol_escape_htmltag((string) ($row['invoice_date'] ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($row['delivery_date'] ?? '')).'</td><td>'.dol_escape_htmltag((string) ($row['due_date'] ?? '')).'</td>';
        print '<td>'.dol_escape_htmltag((string) ($row['category'] ?? '')).'</td>';
        print '<td class="right">'.($gross !== null && $gross !== '' ? price($gross).' '.dol_escape_htmltag($currency) : '<span class="opacitymedium">—</span>').'</td>';
        print '<td>'.($issues ? implode('<br>', $issues) : '<span class="opacitymedium">—</span>').'</td></tr>';
    }
    print '</table></div>';

    if ($selectableCount > 0) {
        if ($canImport) {
            print '<div class="center tabsAction"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('BatchImportSelectedPage')).'" onclick="return confirm(\''.dol_escape_js($langs->trans('BatchImportConfirm')).'\');"></div>';
        } else {
            print '<div class="warning">'.img_picto('', 'warning').' '.$langs->trans('ImportPermissionMissing').'</div>';
        }
    }
    print '</form>';
    print $renderPager();
} elseif ($snapshotValid && $filteredTotal === 0) {
    print '<div class="opacitymedium">'.$langs->trans('BatchNoStateMatchesGlobal').'</div>';
}

llxFooter();
$db->close();