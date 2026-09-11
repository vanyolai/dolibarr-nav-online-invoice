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
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceimporter.class.php');
$langs->loadLangs(array('navinvoice@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$id = GETPOSTINT('id');
if ($id <= 0) {
    accessforbidden();
}

$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE rowid = '.$id;
$sql .= ' AND entity = '.((int) $conf->entity);
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

$direction = strtoupper((string) $record->invoice_direction);
$isInbound = $direction === 'INBOUND';
$externalPartyKey = $isInbound ? 'supplier' : 'customer';
$baseCurrency = strtoupper((string) $conf->currency);

$parsed = null;
$parseError = '';
$partnerMatch = null;
$partnerMatchError = '';
$preview = null;
$previewError = '';

if (!empty($record->invoice_data)) {
    try {
        $parser = new NavInvoiceParser();
        $parsed = $parser->parse((string) $record->invoice_data);
    } catch (Throwable $e) {
        $parseError = $e->getMessage();
    }
}

if ($parsed !== null) {
    try {
        $matcher = new NavPartnerMatcher($db, (int) $conf->entity);
        $partnerMatch = $matcher->match($parsed[$externalPartyKey], $externalPartyKey);
    } catch (Throwable $e) {
        $partnerMatchError = $e->getMessage();
    }

    if ($partnerMatchError === '') {
        try {
            $previewBuilder = new NavInvoiceImportPreview($db, (int) $conf->entity, $baseCurrency);
            $preview = $previewBuilder->build($parsed, $record, $partnerMatch);
        } catch (Throwable $e) {
            $previewError = $e->getMessage();
        }
    }
}

$hasModuleImportRight = !empty($user->admin) || $user->hasRight('navinvoice', 'invoice', 'import');
$hasDolibarrCreateRight = $isInbound
    ? ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer'))
    : $user->hasRight('facture', 'creer');
$canImport = $hasModuleImportRight && $hasDolibarrCreateRight;

$action = GETPOST('action', 'aZ09');
if ($action === 'import_draft') {
    if (!$canImport) {
        accessforbidden();
    }
    if ($preview === null || !empty($preview['blockers'])) {
        setEventMessages($langs->trans('ImportBlocked'), null, 'errors');
    } else {
        try {
            $importer = new NavInvoiceImporter($db, (int) $conf->entity, $baseCurrency);
            $result = $importer->importDraft($preview, $record, $user);
            setEventMessages($langs->trans('ImportSucceeded', $preview['invoice_number']), null, 'mesgs');
            header('Location: '.$result['url']);
            exit;
        } catch (Throwable $e) {
            setEventMessages($langs->trans('ImportFailed').': '.$e->getMessage(), null, 'errors');
        }
    }
}

$display = static function ($value): string {
    if ($value === null || $value === '') {
        return '<span class="opacitymedium">—</span>';
    }
    return dol_escape_htmltag((string) $value);
};
$money = static function ($value, string $currency) use ($display): string {
    if ($value === null || $value === '') {
        return $display(null);
    }
    return price($value).' '.dol_escape_htmltag($currency);
};
$issueLabel = static function (string $prefix, string $code) use ($langs): string {
    $key = $prefix.$code;
    $translated = $langs->trans($key);
    return $translated === $key ? $code : $translated;
};

$linkedId = $isInbound ? (int) $record->fk_facture_fourn : (int) $record->fk_facture;
$linkedUrl = '';
if ($linkedId > 0) {
    $linkedUrl = $isInbound
        ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedId
        : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$linkedId;
}

llxHeader('', $langs->trans('ImportPreview'));
print '<div class="fichecenter">';
print load_fiche_titre(
    $langs->trans('ImportPreview').' - '.dol_escape_htmltag((string) $record->invoice_number),
    '<a href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('NavInvoiceDetails').'</a>',
    'file-invoice'
);

if ($parseError !== '') {
    print '<div class="error">'.img_picto('', 'error').' '.$langs->trans('NavXmlParseFailed').': '.dol_escape_htmltag($parseError).'</div>';
} elseif (empty($record->invoice_data)) {
    print '<div class="warning">'.img_picto('', 'warning').' '.$langs->trans('FullXmlNotAvailable').'</div>';
}
if ($partnerMatchError !== '') {
    print '<div class="error">'.img_picto('', 'error').' '.$langs->trans('PartnerMatchFailed').': '.dol_escape_htmltag($partnerMatchError).'</div>';
}
if ($previewError !== '') {
    print '<div class="error">'.img_picto('', 'error').' '.$langs->trans('ImportPreviewFailed').': '.dol_escape_htmltag($previewError).'</div>';
}

if ($linkedId > 0) {
    print '<div class="info marginbottomonly">'.img_picto('', 'tick').' '.$langs->trans('AlreadyImported').' <a href="'.dol_escape_htmltag($linkedUrl).'">'.$langs->trans('OpenDolibarrInvoice').'</a></div>';
}

if (is_array($preview)) {
    $state = (string) $preview['state'];
    $isSimplified = strtoupper((string) ($preview['category'] ?? '')) === 'SIMPLIFIED';
    if ($state === 'ready') {
        $stateDisplay = img_picto('', 'tick').' <span class="ok">'.$langs->trans('ImportStateReady').'</span>';
    } elseif ($state === 'review') {
        $stateDisplay = img_picto('', 'warning').' <span class="warning">'.$langs->trans('ImportStateReview').'</span>';
    } else {
        $stateDisplay = img_picto('', 'error').' <span class="error">'.$langs->trans('ImportStateBlocked').'</span>';
    }

    print '<div class="fichehalfleft">';
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">'.$langs->trans('ProposalStatus').'</td><td>'.$stateDisplay.'</td></tr>';
    print '<tr><td>'.$langs->trans('ImportTarget').'</td><td>'.$langs->trans($isInbound ? 'ImportAsSupplierInvoice' : 'ImportAsCustomerInvoice').'</td></tr>';
    print '<tr><td>'.$langs->trans('NavInvoiceNumber').'</td><td>'.$display($preview['invoice_number']).'</td></tr>';
    print '<tr><td>'.$langs->trans('InvoiceCategory').'</td><td>'.$display($preview['category']).'</td></tr>';
    print '<tr><td>'.$langs->trans('InvoiceIssueDate').'</td><td>'.$display($preview['header']['invoice_date']).'</td></tr>';
    print '<tr><td>'.$langs->trans('InvoiceDeliveryDate').'</td><td>'.$display($preview['header']['delivery_date']).'</td></tr>';
    print '<tr><td>'.$langs->trans('PaymentDate').'</td><td>'.$display($preview['header']['due_date']).'</td></tr>';
    print '<tr><td>'.$langs->trans('PaymentMode').'</td><td>'.$display($preview['header']['payment_method']).'</td></tr>';
    print '<tr><td>'.$langs->trans('Currency').'</td><td>'.$display($preview['header']['currency']).'</td></tr>';
    print '</table></div>';

    $derivedMarker = $isSimplified ? ' <span class="opacitymedium">*</span>' : '';
    print '<div class="fichehalfright">';
    print '<table class="border centpercent">';
    $partner = $preview['partner'];
    print '<tr><td class="titlefield">'.$langs->trans('DolibarrPartner').'</td><td>';
    if (is_array($partner)) {
        print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $partner['id'].'">'.dol_escape_htmltag((string) $partner['name']).'</a>';
        print ' <span class="opacitymedium">('.dol_escape_htmltag((string) $preview['partner_status']).')</span>';
    } else {
        print $display(null);
    }
    print '</td></tr>';
    print '<tr><td>'.$langs->trans('AmountHT').$derivedMarker.'</td><td class="right">'.$money($preview['totals']['net'] ?? null, $preview['header']['currency']).'</td></tr>';
    print '<tr><td>'.$langs->trans('VAT').$derivedMarker.'</td><td class="right">'.$money($preview['totals']['vat'] ?? null, $preview['header']['currency']).'</td></tr>';
    print '<tr><td>'.$langs->trans('AmountTTC').'</td><td class="right">'.$money($preview['totals']['gross'] ?? null, $preview['header']['currency']).'</td></tr>';
    print '<tr><td>'.$langs->trans('ExternalReference').'</td><td>'.$display($preview['external_key']).'</td></tr>';
    print '</table></div>';
    print '<div class="clearboth"></div>';
    if ($isSimplified) {
        print '<div class="opacitymedium small marginbottomonly">* '.$langs->trans('SimplifiedDerivedAmountsHelp').'</div>';
    }
    print '<br>';

    if (!empty($preview['blockers'])) {
        print '<div class="error marginbottomonly"><strong>'.$langs->trans('ImportBlockers').'</strong><br>';
        foreach ($preview['blockers'] as $code) {
            print img_picto('', 'error').' '.dol_escape_htmltag($issueLabel('ImportBlocker_', (string) $code)).'<br>';
        }
        print '</div>';
    }
    if (!empty($preview['warnings'])) {
        print '<div class="warning marginbottomonly"><strong>'.$langs->trans('ImportWarnings').'</strong><br>';
        foreach ($preview['warnings'] as $code) {
            print img_picto('', 'warning').' '.dol_escape_htmltag($issueLabel('ImportWarning_', (string) $code)).'<br>';
        }
        print '</div>';
    }

    $showUnits = !empty($preview['units_enabled']);
    print load_fiche_titre($langs->trans('InvoiceLines'), '', 'list');
    print '<div class="div-table-responsive"><table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="right">#</td><td>'.$langs->trans('Description').'</td>';
    print '<td class="right">'.$langs->trans('Qty').'</td>';
    if ($showUnits) {
        print '<td>'.$langs->trans('NavUnit').'</td>';
        print '<td>'.$langs->trans('DolibarrUnit').'</td>';
    }
    print '<td class="right">'.$langs->trans($isSimplified ? 'NavGrossUnitPrice' : 'NavUnitPrice').'</td>';
    print '<td class="right">'.$langs->trans('DolibarrNetUnitPrice').'</td>';
    print '<td class="right">'.$langs->trans('VAT').'</td>';
    print '<td class="right">'.$langs->trans('AmountHT').($isSimplified ? ' *' : '').'</td>';
    print '<td class="right">'.$langs->trans('AmountTTC').'</td>';
    print '</tr>';
    $hasAdjustedPrice = false;
    $hasNonExpressionNormalization = false;
    foreach ($preview['lines'] as $line) {
        $gross = $line['gross'];
        if (($gross === null || $gross === '') && $line['net'] !== null && $line['vat'] !== null) {
            $gross = (float) $line['net'] + (float) $line['vat'];
        }
        print '<tr class="oddeven">';
        print '<td class="right">'.$display($line['number']).'</td>';
        print '<td>'.$display($line['description']).'</td>';
        print '<td class="right">'.$display($line['quantity']);
        if (!empty($line['quantity_derived'])) {
            $hasNonExpressionNormalization = true;
            print ' <span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('NonExpressionLineDerivedHelp')).'">*</span>';
        }
        print '</td>';
        if ($showUnits) {
            print '<td>'.$display($line['unit']).'</td>';
            print '<td>';
            if (!empty($line['unit_id'])) {
                $unitText = (string) $line['unit_code'];
                if (!empty($line['unit_short_label']) && strcasecmp((string) $line['unit_short_label'], $unitText) !== 0) {
                    $unitText .= ' / '.(string) $line['unit_short_label'];
                }
                print img_picto('', 'tick').' '.dol_escape_htmltag($unitText);
            } elseif (($line['unit_status'] ?? '') === 'ambiguous') {
                print img_picto('', 'warning').' <span class="warning">'.$langs->trans('UnitMatchAmbiguous').'</span>';
            } else {
                print '<span class="opacitymedium">—</span>';
            }
            print '</td>';
        }
        $navUnitPrice = array_key_exists('nav_unit_price', $line) ? $line['nav_unit_price'] : ($line['nav_unit_price_ht'] ?? null);
        print '<td class="right">'.$money($navUnitPrice, $preview['header']['currency']).'</td>';
        print '<td class="right">'.$money($line['unit_price_ht'], $preview['header']['currency']);
        if (!empty($line['unit_price_adjusted'])) {
            $hasAdjustedPrice = true;
            print ' <span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('ImportUnitPriceAdjustedHelp')).'">*</span>';
        } elseif (!empty($line['unit_price_derived'])) {
            $hasNonExpressionNormalization = true;
            print ' <span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('NonExpressionLineDerivedHelp')).'">*</span>';
        }
        print '</td>';
        $vatDisplay = $line['vat_rate'] !== null ? price($line['vat_rate']).'%' : $display(null);
        print '<td class="right">'.$vatDisplay.'</td>';
        print '<td class="right">'.$money($line['net'], $preview['header']['currency']).'</td>';
        print '<td class="right">'.$money($gross, $preview['header']['currency']).'</td>';
        print '</tr>';
    }
    print '</table></div>';
    if ($hasNonExpressionNormalization) {
        print '<div class="opacitymedium small">* '.$langs->trans('NonExpressionLineDerivedHelp').'</div>';
    }
    if ($hasAdjustedPrice) {
        print '<div class="opacitymedium small">* '.$langs->trans('ImportUnitPriceAdjustedHelp').'</div>';
    }
    if ($isSimplified) {
        print '<div class="opacitymedium small">* '.$langs->trans('SimplifiedDerivedAmountsHelp').'</div>';
    }
    print '<br>';

    if (!$isInbound) {
        print '<div class="info marginbottomonly">'.$langs->trans('ImportOutboundDraftNumberNotice').'</div>';
    } else {
        print '<div class="info marginbottomonly">'.$langs->trans('ImportSupplierReferenceNotice').'</div>';
    }
    print '<div class="opacitymedium marginbottomonly">'.$langs->trans('ImportCreatesDraftNotice').'</div>';

    if ($linkedId <= 0 && empty($preview['blockers'])) {
        if ($canImport) {
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="import_draft">';
            print '<input type="hidden" name="id" value="'.$id.'">';
            print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('ImportToDolibarrDraft')).'"></div>';
            print '</form>';
        } else {
            print '<div class="warning">'.img_picto('', 'warning').' '.$langs->trans('ImportPermissionMissing').'</div>';
        }
    }
}

print '</div>';
llxFooter();
$db->close();
