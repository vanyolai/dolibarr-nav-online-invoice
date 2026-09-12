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
dol_include_once('/navinvoice/class/navinvoicerelation.class.php');
dol_include_once('/navinvoice/class/navinvoicechain.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navrelation@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$id = (int) GETPOST('id', 'int');
if ($id <= 0) {
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
$parseError = '';
if (!empty($record->invoice_data)) {
    try {
        $parser = new NavInvoiceParser();
        $parsed = $parser->parse((string) $record->invoice_data);
    } catch (Throwable $e) {
        $parseError = $e->getMessage();
    }
}

$relation = null;
$relationError = '';
try {
    $resolver = new NavInvoiceRelationResolver($db, (int) $conf->entity);
    $relation = $resolver->resolve($record, $parsed);
} catch (Throwable $e) {
    $relationError = $e->getMessage();
}

$navChain = null;
$chainComparison = null;
$navChainError = '';
if (is_array($relation)) {
    try {
        $chainService = new NavInvoiceChainService();
        $chainInvoiceNumber = trim((string) ($relation['original_invoice_number'] ?? ''));
        if ($chainInvoiceNumber === '') {
            throw new Exception('Original invoice number is required for NAV invoice-chain lookup.');
        }
        $chainTaxNumber = null;
        if (strtoupper((string) $record->invoice_direction) === 'INBOUND' && is_array($parsed)) {
            $supplierTaxNumber = trim((string) ($parsed['supplier']['tax_number'] ?? ''));
            if ($supplierTaxNumber !== '') {
                $chainTaxNumber = $supplierTaxNumber;
            }
        }
        $navChain = $chainService->fetch(
            $chainInvoiceNumber,
            (string) $record->invoice_direction,
            $chainTaxNumber
        );
        $chainComparison = $chainService->compareToLocal(
            is_array($navChain['elements'] ?? null) ? $navChain['elements'] : array(),
            is_array($relation['chain'] ?? null) ? $relation['chain'] : array()
        );
    } catch (Throwable $e) {
        // The local mirror view remains useful if NAV is temporarily unavailable.
        // Import stays conservative; a failed authoritative query never upgrades
        // a relation into an importable state.
        $navChainError = $e->getMessage();
    }
}

$display = static function ($value): string {
    if ($value === null || $value === '') {
        return '<span class="opacitymedium">—</span>';
    }
    return dol_escape_htmltag((string) $value);
};
$issueLabel = static function (string $prefix, string $code) use ($langs): string {
    $key = $prefix.$code;
    $translated = $langs->trans($key);
    return $translated !== $key ? $translated : $code;
};
$identity = static function (array $item): string {
    $invoiceNumber = trim((string) ($item['invoice_number'] ?? ''));
    $batchIndex = $item['batch_index'] ?? null;
    return $invoiceNumber.'#'.($batchIndex === null || $batchIndex === '' ? '0' : (string) ((int) $batchIndex));
};

llxHeader('', $langs->trans('NavInvoiceRelation'));
print load_fiche_titre(
    $langs->trans('NavInvoiceRelation').' - '.dol_escape_htmltag((string) $record->invoice_number),
    '<a href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('NavInvoiceDetails').'</a>',
    'link'
);

if ($parseError !== '') {
    print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('NavXmlParseFailed').': '.dol_escape_htmltag($parseError).'</div>';
}
if ($relationError !== '') {
    print '<div class="error marginbottomonly">'.img_picto('', 'error').' '.$langs->trans('RelationResolutionFailed').': '.dol_escape_htmltag($relationError).'</div>';
} elseif (is_array($relation)) {
    $operation = strtoupper((string) ($relation['operation'] ?? ''));
    print '<div class="fichecenter">';
    print '<div class="fichehalfleft"><table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('NavInvoiceNumber').'</td><td>'.$display($relation['invoice_number'] ?? '').'</td></tr>';
    print '<tr><td>'.$langs->trans('InvoiceDirection').'</td><td>'.$langs->trans(($relation['direction'] ?? '') === 'INBOUND' ? 'DirectionInbound' : 'DirectionOutbound').'</td></tr>';
    print '<tr><td>'.$langs->trans('Operation').'</td><td>'.$langs->trans('NavOperation_'.$operation).'</td></tr>';
    print '<tr><td>'.$langs->trans('RelationKind').'</td><td>'.$langs->trans('RelationKind_'.(string) ($relation['relationship_kind'] ?? 'unknown')).'</td></tr>';
    print '</table></div>';

    print '<div class="fichehalfright"><table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('OriginalInvoiceNumber').'</td><td>'.$display($relation['original_invoice_number'] ?? '').'</td></tr>';
    print '<tr><td>'.$langs->trans('ModificationIndex').'</td><td>'.$display($relation['modification_index'] ?? null).'</td></tr>';
    print '<tr><td>'.$langs->trans('ModifyWithoutMaster').'</td><td>'.(($relation['modify_without_master'] ?? null) === null ? $display(null) : $langs->trans(!empty($relation['modify_without_master']) ? 'Yes' : 'No')).'</td></tr>';
    print '<tr><td>'.$langs->trans('OriginalDolibarrInvoice').'</td><td>';
    if (!empty($relation['original_dolibarr_invoice_id']) && !empty($relation['original_dolibarr_url'])) {
        print '<a href="'.dol_escape_htmltag((string) $relation['original_dolibarr_url']).'">'.img_picto('', 'file-invoice').' #'.(int) $relation['original_dolibarr_invoice_id'].'</a>';
    } else {
        print $display(null);
    }
    print '</td></tr>';
    print '</table></div>';
    print '<div class="clearboth"></div></div><br>';

    $blockers = array_values(array_unique(array_map('strval', $relation['blockers'] ?? array())));
    $warnings = array_values(array_unique(array_map('strval', $relation['warnings'] ?? array())));
    if ($blockers) {
        print '<div class="error marginbottomonly"><strong>'.$langs->trans('RelationBlockers').'</strong><ul>';
        foreach ($blockers as $code) {
            print '<li>'.dol_escape_htmltag($issueLabel('RelationBlocker_', $code)).'</li>';
        }
        print '</ul></div>';
    }
    if ($warnings) {
        print '<div class="warning marginbottomonly"><strong>'.$langs->trans('RelationWarnings').'</strong><ul>';
        foreach ($warnings as $code) {
            print '<li>'.dol_escape_htmltag($issueLabel('RelationWarning_', $code)).'</li>';
        }
        print '</ul></div>';
    }
    if (!$blockers && !empty($relation['requires_relation'])) {
        print '<div class="info marginbottomonly">'.img_picto('', 'tick').' '.$langs->trans('RelationResolvedNotice').'</div>';
    }

    print load_fiche_titre($langs->trans('AuthoritativeNavChain'), '', 'cloud');
    if ($navChainError !== '') {
        print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('AuthoritativeNavChainFailed').': '.dol_escape_htmltag($navChainError).'</div>';
        print '<div class="opacitymedium marginbottomonly">'.$langs->trans('AuthoritativeNavChainRequiredForImport').'</div>';
    } elseif (is_array($navChain)) {
        $navElements = is_array($navChain['elements'] ?? null) ? $navChain['elements'] : array();
        $localMap = array();
        foreach (($relation['chain'] ?? array()) as $localItem) {
            $localMap[$identity($localItem)] = $localItem;
        }

        if (is_array($chainComparison) && !empty($chainComparison['complete'])) {
            print '<div class="info marginbottomonly">'.img_picto('', 'tick').' '.$langs->trans('AuthoritativeNavChainMatchesLocal', (string) count($navElements)).'</div>';
        } else {
            $missingCount = is_array($chainComparison['missing_local'] ?? null) ? count($chainComparison['missing_local']) : 0;
            $localOnlyCount = is_array($chainComparison['local_only'] ?? null) ? count($chainComparison['local_only']) : 0;
            $mismatchCount = is_array($chainComparison['mismatches'] ?? null) ? count($chainComparison['mismatches']) : 0;
            print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('AuthoritativeNavChainDiffersLocal', $missingCount, $localOnlyCount, $mismatchCount).'</div>';
        }

        print '<div class="div-table-responsive"><table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans('ModificationIndex').'</td>';
        print '<td>'.$langs->trans('Operation').'</td>';
        print '<td>'.$langs->trans('NavInvoiceNumber').'</td>';
        print '<td>'.$langs->trans('InvoiceIssueDate').'</td>';
        print '<td>'.$langs->trans('BatchIndex').'</td>';
        print '<td>'.$langs->trans('LocalMirrorStatus').'</td>';
        print '</tr>';
        foreach ($navElements as $navItem) {
            $key = $identity($navItem);
            $navOperation = strtoupper((string) ($navItem['invoice_operation'] ?? ''));
            print '<tr class="oddeven">';
            print '<td>'.$display($navItem['modification_index'] ?? null).'</td>';
            print '<td>'.$langs->trans('NavOperation_'.$navOperation).'</td>';
            print '<td>'.dol_escape_htmltag((string) ($navItem['invoice_number'] ?? '')).'</td>';
            print '<td>'.$display($navItem['invoice_issue_date'] ?? '').'</td>';
            print '<td>'.$display($navItem['batch_index'] ?? null).'</td>';
            if (isset($localMap[$key])) {
                $localUrl = dol_buildpath('/navinvoice/detail.php', 1).'?id='.(int) $localMap[$key]['id'];
                print '<td>'.img_picto('', 'tick').' <a href="'.dol_escape_htmltag($localUrl).'">'.$langs->trans('PresentInLocalMirror').'</a></td>';
            } else {
                print '<td>'.img_picto('', 'warning').' <span class="warning">'.$langs->trans('MissingFromLocalMirror').'</span></td>';
            }
            print '</tr>';
        }
        if (!$navElements) {
            print '<tr><td colspan="6" class="opacitymedium">'.$langs->trans('RelationChainEmpty').'</td></tr>';
        }
        print '</table></div><br>';
    }

    print load_fiche_titre($langs->trans('LocalMirrorRelationChain'), '', 'database');
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans('ModificationIndex').'</td>';
    print '<td>'.$langs->trans('Operation').'</td>';
    print '<td>'.$langs->trans('NavInvoiceNumber').'</td>';
    print '<td>'.$langs->trans('InvoiceIssueDate').'</td>';
    print '<td>'.$langs->trans('TransactionIndex').'</td>';
    print '<td>'.$langs->trans('DolibarrInvoice').'</td>';
    print '</tr>';

    foreach (($relation['chain'] ?? array()) as $item) {
        $rowOperation = strtoupper((string) ($item['operation'] ?? ''));
        $rowUrl = dol_buildpath('/navinvoice/detail.php', 1).'?id='.(int) $item['id'];
        print '<tr class="oddeven">';
        print '<td>'.$display($item['modification_index'] ?? null).'</td>';
        print '<td>'.$langs->trans('NavOperation_'.$rowOperation).'</td>';
        print '<td><a href="'.dol_escape_htmltag($rowUrl).'">'.dol_escape_htmltag((string) $item['invoice_number']).'</a></td>';
        print '<td>'.$display($item['invoice_issue_date'] ?? '').'</td>';
        print '<td>'.$display($item['transaction_index'] ?? null).'</td>';
        print '<td>';
        if (!empty($item['dolibarr_invoice_id']) && !empty($item['dolibarr_url'])) {
            print '<a href="'.dol_escape_htmltag((string) $item['dolibarr_url']).'">'.img_picto('', 'file-invoice').' #'.(int) $item['dolibarr_invoice_id'].'</a>';
        } else {
            print '<span class="opacitymedium">—</span>';
        }
        print '</td>';
        print '</tr>';
    }
    if (empty($relation['chain'])) {
        print '<tr><td colspan="6" class="opacitymedium">'.$langs->trans('RelationChainEmpty').'</td></tr>';
    }
    print '</table></div>';

    if (!empty($relation['requires_relation'])) {
        print '<div class="opacitymedium small margin-top">'.$langs->trans('RelationImportSafetyNotice').'</div>';
    }
}

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/navinvoice/import.php', 1).'?id='.$id.'">'.$langs->trans('ImportPreview').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('NavInvoiceDetails').'</a>';
print '</div>';

llxFooter();
$db->close();
