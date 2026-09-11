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
dol_include_once('/navinvoice/class/navpartnerenrichmentpreview.class.php');
dol_include_once('/navinvoice/class/navpartnerenricher.class.php');
$langs->loadLangs(array('navinvoice@navinvoice'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}

$id = (int) GETPOST('id', 'int');
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

$direction = strtoupper((string) $record->invoice_direction);
$isInbound = $direction === 'INBOUND';
$externalPartyKey = $isInbound ? 'supplier' : 'customer';
$partnerMatch = null;
$partnerMatchError = '';
$partnerEnrichment = null;
$partnerEnrichmentError = '';
if ($parsed) {
    try {
        $matcher = new NavPartnerMatcher($db, (int) $conf->entity);
        $partnerMatch = $matcher->match($parsed[$externalPartyKey], $externalPartyKey);
    } catch (Throwable $e) {
        $partnerMatchError = $e->getMessage();
    }

    if ($partnerMatchError === '' && $partnerMatch !== null) {
        try {
            $enrichment = new NavPartnerEnrichmentPreview($db, (int) $conf->entity);
            $partnerEnrichment = $enrichment->build($parsed[$externalPartyKey], $partnerMatch, $externalPartyKey);
        } catch (Throwable $e) {
            $partnerEnrichmentError = $e->getMessage();
        }
    }
}

$action = GETPOST('action', 'aZ09');
$canApplyPartnerEnrichment = $user->hasRight('societe', 'creer');
$autoEnrichmentFields = array('tva_intra', 'address', 'zip', 'town', 'fk_pays');
if ($action === 'apply_partner_enrichment') {
    if (!$canApplyPartnerEnrichment) {
        accessforbidden();
    }
    if (!$parsed || !is_array($partnerEnrichment)) {
        setEventMessages($langs->trans('PartnerEnrichmentApplyFailed'), null, 'errors');
    } else {
        try {
            // The proposal is rebuilt from the current NAV XML and current
            // Dolibarr third party on every request. No values from the browser
            // are trusted for the actual master-data update.
            $enricher = new NavPartnerEnricher($db, (int) $conf->entity);
            $applied = $enricher->apply($partnerEnrichment, $user);
            if ($applied) {
                setEventMessages($langs->trans('PartnerEnrichmentApplied', count($applied)), null, 'mesgs');
            } else {
                setEventMessages($langs->trans('PartnerEnrichmentNothingToApply'), null, 'warnings');
            }
            header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
            exit;
        } catch (Throwable $e) {
            setEventMessages($langs->trans('PartnerEnrichmentApplyFailed').': '.$e->getMessage(), null, 'errors');
        }
    }
}

$currency = (string) $record->currency;
if ($parsed && !empty($parsed['detail']['currency'])) {
    $currency = (string) $parsed['detail']['currency'];
}
$isSimplified = $parsed && strtoupper((string) ($parsed['detail']['category'] ?? '')) === 'SIMPLIFIED';

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
$yesNo = static function ($value) use ($langs, $display): string {
    if ($value === null) {
        return $display(null);
    }
    return $langs->trans($value ? 'Yes' : 'No');
};
$taxNumber = static function (array $party) use ($display): string {
    if (!empty($party['tax_number'])) {
        $full = $party['tax_number'];
        if (!empty($party['vat_code']) && !empty($party['county_code'])) {
            $full .= '-'.$party['vat_code'].'-'.$party['county_code'];
        }
        return dol_escape_htmltag($full);
    }
    if (!empty($party['community_vat_number'])) {
        return dol_escape_htmltag($party['community_vat_number']);
    }
    if (!empty($party['third_state_tax_id'])) {
        return dol_escape_htmltag($party['third_state_tax_id']);
    }
    return $display(null);
};
$navEnum = static function (string $group, $value) use ($langs, $display): string {
    if ($value === null || $value === '') {
        return $display(null);
    }
    $raw = strtoupper((string) $value);
    $key = 'Nav'.$group.'_'.$raw;
    $translated = $langs->trans($key);
    return $translated !== $key ? dol_escape_htmltag($translated) : $display($value);
};
$vatRateFromContent = static function ($value): ?float {
    $content = (float) $value;
    if ($content < 0 || $content >= 1) {
        return null;
    }
    $known = array(
        array(0.0476, 5.0),
        array(0.1525, 18.0),
        array(0.2126, 27.0),
    );
    foreach ($known as $entry) {
        if (abs($content - $entry[0]) <= 0.00005) {
            return $entry[1];
        }
    }
    $derived = 100 * $content / (1 - $content);
    $integerRate = round($derived);
    return abs($derived - $integerRate) <= 0.05 ? (float) $integerRate : null;
};
$addressDisplay = static function (string $partyKey, array $party, string $xml) use ($langs, $display): string {
    if (!empty($party['address']['formatted'])) {
        return $display($party['address']['formatted']);
    }

    $tag = $partyKey === 'supplier' ? 'supplierAddress' : 'customerAddress';
    $pattern = '/<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?'.preg_quote($tag, '/').'\b/i';
    if ($xml !== '' && preg_match($pattern, $xml)) {
        return '<span class="warning">'.$langs->trans('AddressPresentButNotParsed').'</span>';
    }

    return '<span class="opacitymedium">'.$langs->trans('AddressNotInNavXml').'</span>';
};
$partnerMatchDisplay = static function (?array $result, string $error = '') use ($langs, $display): string {
    if ($error !== '') {
        return img_picto('', 'warning').' <span class="warning">'.$langs->trans('PartnerMatchFailed').': '.dol_escape_htmltag($error).'</span>';
    }
    if ($result === null) {
        return $display(null);
    }

    $status = (string) ($result['status'] ?? 'none');
    $match = $result['match'] ?? null;

    if (is_array($match)) {
        $url = DOL_URL_ROOT.'/societe/card.php?socid='.(int) $match['id'];
        $link = '<a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag((string) $match['name']).'</a>';
        if ($status === 'tax') {
            $text = img_picto('', 'tick').' '.$link.' <span class="opacitymedium">('.$langs->trans('PartnerMatchByTax').')</span>';
        } elseif ($status === 'name_address') {
            $text = img_picto('', 'tick').' '.$link.' <span class="opacitymedium">('.$langs->trans('PartnerMatchByNameAddress').')</span>';
        } elseif ($status === 'name') {
            $text = img_picto('', 'warning').' '.$link.' <span class="opacitymedium">('.$langs->trans('PartnerMatchByName').')</span>';
        } else {
            $similarity = isset($match['name_similarity']) && $match['name_similarity'] !== null ? ' '.round((float) $match['name_similarity']).'%' : '';
            $text = img_picto('', 'warning').' '.$link.' <span class="opacitymedium">('.$langs->trans('PartnerMatchCandidate').$similarity.')</span>';
        }

        if (!empty($result['can_fill_tax_number'])) {
            $text .= '<br><span class="small warning">'.$langs->trans('PartnerTaxCanBeFilledFromNav').'</span>';
        }
        return $text;
    }

    if ($status === 'ambiguous') {
        $parts = array();
        foreach (array_slice($result['candidates'] ?? array(), 0, 3) as $candidate) {
            $url = DOL_URL_ROOT.'/societe/card.php?socid='.(int) $candidate['id'];
            $parts[] = '<a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag((string) $candidate['name']).'</a> ('.(int) $candidate['score'].'%)';
        }
        $suffix = $parts ? '<br><span class="small opacitymedium">'.implode(' · ', $parts).'</span>' : '';
        return img_picto('', 'warning').' <span class="warning">'.$langs->trans('PartnerMatchAmbiguous').'</span>'.$suffix;
    }
    if ($status === 'unavailable') {
        return '<span class="opacitymedium">'.$langs->trans('PartnerMatchUnavailable').'</span>';
    }

    return '<span class="opacitymedium">'.$langs->trans('PartnerMatchNone').'</span>';
};
$enrichmentValue = static function (string $field, $value) use ($langs, $display): string {
    if ($field === 'fournisseur') {
        return $langs->trans(((int) $value) === 1 ? 'Yes' : 'No');
    }
    if ($field === 'client') {
        $map = array(
            0 => $langs->trans('No'),
            1 => $langs->trans('Customer'),
            2 => $langs->trans('Prospect'),
            3 => $langs->trans('Customer').' + '.$langs->trans('Prospect'),
        );
        return $map[(int) $value] ?? $display($value);
    }
    return $display($value);
};

llxHeader('', $langs->trans('NavInvoiceDetails'));

print '<div class="fichecenter">';
print '<div class="underbanner clearboth"></div>';
print load_fiche_titre(
    $langs->trans('NavInvoiceDetails').' - '.dol_escape_htmltag((string) $record->invoice_number),
    '<a href="'.dol_buildpath('/navinvoice/index.php', 1).'">'.$langs->trans('BackToNavInvoiceList').'</a>',
    'file-invoice'
);

if ($parseError !== '') {
    setEventMessages($langs->trans('NavXmlParseFailed').': '.$parseError, null, 'errors');
}
if (empty($record->invoice_data)) {
    setEventMessages($langs->trans('FullXmlNotAvailable'), null, 'warnings');
}
if ($partnerEnrichmentError !== '') {
    setEventMessages($langs->trans('PartnerEnrichmentFailed').': '.$partnerEnrichmentError, null, 'warnings');
}

print '<div class="fichehalfleft">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('NavInvoiceNumber').'</td><td>'.$display($record->invoice_number).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceDirection').'</td><td>'.$langs->trans($isInbound ? 'DirectionInbound' : 'DirectionOutbound').'</td></tr>';
print '<tr><td>'.$langs->trans('Operation').'</td><td>'.$navEnum('Operation', $record->invoice_operation).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceCategory').'</td><td>'.$navEnum('InvoiceCategory', $parsed ? $parsed['detail']['category'] : $record->invoice_category).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceIssueDate').'</td><td>'.$display($record->invoice_issue_date).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceDeliveryDate').'</td><td>'.$display($parsed ? $parsed['detail']['delivery_date'] : $record->invoice_delivery_date).'</td></tr>';
if ($parsed && ($parsed['detail']['delivery_period_start'] !== '' || $parsed['detail']['delivery_period_end'] !== '')) {
    print '<tr><td>'.$langs->trans('DeliveryPeriod').'</td><td>'.$display($parsed['detail']['delivery_period_start']).' – '.$display($parsed['detail']['delivery_period_end']).'</td></tr>';
}
print '<tr><td>'.$langs->trans('PaymentDate').'</td><td>'.$display($parsed ? $parsed['detail']['payment_date'] : $record->payment_date).'</td></tr>';
print '<tr><td>'.$langs->trans('PaymentMode').'</td><td>'.$navEnum('PaymentMethod', $parsed ? $parsed['detail']['payment_method'] : $record->payment_method).'</td></tr>';
print '<tr><td>'.$langs->trans('Currency').'</td><td>'.$display($currency).'</td></tr>';
print '<tr><td>'.$langs->trans('ExchangeRate').'</td><td>'.$display($parsed ? $parsed['detail']['exchange_rate'] : null).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceAppearance').'</td><td>'.$navEnum('InvoiceAppearance', $parsed ? $parsed['detail']['invoice_appearance'] : $record->invoice_appearance).'</td></tr>';
if ($parsed) {
    print '<tr><td>'.$langs->trans('CashAccounting').'</td><td>'.$yesNo($parsed['detail']['cash_accounting']).'</td></tr>';
}
if (!empty($record->original_invoice_number) || ($parsed && !empty($parsed['reference']['original_invoice_number']))) {
    print '<tr><td>'.$langs->trans('OriginalInvoiceNumber').'</td><td>'.$display($parsed && $parsed['reference']['original_invoice_number'] !== '' ? $parsed['reference']['original_invoice_number'] : $record->original_invoice_number).'</td></tr>';
    print '<tr><td>'.$langs->trans('ModificationIndex').'</td><td>'.$display($parsed && $parsed['reference']['modification_index'] !== '' ? $parsed['reference']['modification_index'] : $record->modification_index).'</td></tr>';
}
print '</table>';
print '</div>';

$derivedMarker = $isSimplified ? ' <span class="opacitymedium">*</span>' : '';
print '<div class="fichehalfright">';
print '<table class="border centpercent">';
$grossDerived = $parsed && !empty($parsed['totals']['gross_derived']);
print '<tr><td class="titlefield">'.$langs->trans('AmountHT').$derivedMarker.'</td><td class="right">'.$money($parsed ? $parsed['totals']['net'] : $record->invoice_net_amount, $currency).'</td></tr>';
print '<tr><td>'.$langs->trans('VAT').$derivedMarker.'</td><td class="right">'.$money($parsed ? $parsed['totals']['vat'] : $record->invoice_vat_amount, $currency).'</td></tr>';
print '<tr><td>'.$langs->trans('AmountTTC').($grossDerived ? ' <span class="opacitymedium">*</span>' : '').'</td><td class="right">'.$money($parsed ? $parsed['totals']['gross'] : null, $currency).'</td></tr>';
if ($currency !== 'HUF' && $parsed) {
    print '<tr><td>'.$langs->trans('AmountHT').' (HUF)'.$derivedMarker.'</td><td class="right">'.$money($parsed['totals']['net_huf'], 'HUF').'</td></tr>';
    print '<tr><td>'.$langs->trans('VAT').' (HUF)'.$derivedMarker.'</td><td class="right">'.$money($parsed['totals']['vat_huf'], 'HUF').'</td></tr>';
    print '<tr><td>'.$langs->trans('AmountTTC').' (HUF)'.(!empty($parsed['totals']['gross_huf_derived']) ? ' <span class="opacitymedium">*</span>' : '').'</td><td class="right">'.$money($parsed['totals']['gross_huf'], 'HUF').'</td></tr>';
}
print '<tr><td>'.$langs->trans('XmlDownloaded').'</td><td>'.($record->data_fetched ? img_picto($langs->trans('Yes'), 'tick').' '.$langs->trans('Yes') : img_picto($langs->trans('No'), 'warning').' '.$langs->trans('No')).'</td></tr>';
print '<tr><td>'.$langs->trans('LastSync').'</td><td>'.$display($record->last_sync).'</td></tr>';
print '</table>';
print '</div>';
print '<div class="clearboth"></div>';
if ($isSimplified) {
    print '<div class="opacitymedium small marginbottomonly">* '.$langs->trans('SimplifiedDerivedAmountsHelp').'</div>';
} elseif ($grossDerived) {
    print '<div class="opacitymedium small marginbottomonly">* '.$langs->trans('DerivedInvoiceGrossHelp').'</div>';
}
print '<br>';

if ($parsed) {
    foreach (array('supplier' => 'Supplier', 'customer' => 'Customer') as $partyKey => $labelKey) {
        $party = $parsed[$partyKey];
        $privatePerson = ($partyKey === 'customer' && ($party['vat_status'] ?? '') === 'PRIVATE_PERSON');
        print '<div class="fichehalf'.($partyKey === 'supplier' ? 'left' : 'right').'">';
        print '<table class="border centpercent">';
        print '<tr class="liste_titre"><td colspan="2">'.$langs->trans($labelKey).'</td></tr>';
        print '<tr><td class="titlefield">'.$langs->trans('Name').'</td><td>'.($privatePerson ? '<span class="opacitymedium">'.$langs->trans('PrivatePerson').'</span>' : $display($party['name'])).'</td></tr>';
        print '<tr><td>'.$langs->trans('TaxNumber').'</td><td>'.$taxNumber($party).'</td></tr>';
        if (!empty($party['vat_status'])) {
            print '<tr><td>'.$langs->trans('VatStatus').'</td><td>'.$navEnum('VatStatus', $party['vat_status']).'</td></tr>';
        }
        if (!empty($party['group_member_tax_number'])) {
            print '<tr><td>'.$langs->trans('GroupMemberTaxNumber').'</td><td>'.$display($party['group_member_tax_number']).'</td></tr>';
        }
        print '<tr><td>'.$langs->trans('Address').'</td><td>'.$addressDisplay($partyKey, $party, (string) $record->invoice_data).'</td></tr>';
        if (!empty($party['bank_account'])) {
            print '<tr><td>'.$langs->trans('BankAccount').'</td><td>'.$display($party['bank_account']).'</td></tr>';
        }
        if ($partyKey === $externalPartyKey) {
            print '<tr><td>'.$langs->trans('DolibarrPartner').'</td><td>'.$partnerMatchDisplay($partnerMatch, $partnerMatchError).'</td></tr>';
        }
        print '</table>';
        print '</div>';
    }
    print '<div class="clearboth"></div><br>';

    if (is_array($partnerEnrichment) && !empty($partnerEnrichment['available']) && !empty($partnerEnrichment['items'])) {
        print load_fiche_titre($langs->trans('PartnerEnrichmentPreview'), '', 'company');
        $hasAutoApplicable = false;
        foreach ($partnerEnrichment['items'] as $item) {
            if (!empty($item['safe']) && (string) ($item['status'] ?? '') === 'missing' && in_array((string) ($item['field'] ?? ''), $autoEnrichmentFields, true)) {
                $hasAutoApplicable = true;
                break;
            }
        }

        if (empty($partnerEnrichment['strong_match'])) {
            print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('PartnerEnrichmentWeakMatchNotice').'</div>';
        } elseif ($hasAutoApplicable) {
            print '<div class="info marginbottomonly">'.img_picto('', 'info').' '.$langs->trans('PartnerEnrichmentSafeWriteNotice').'</div>';
        } else {
            print '<div class="opacitymedium marginbottomonly">'.$langs->trans('PartnerEnrichmentNothingToApply').'</div>';
        }
        print '<div class="div-table-responsive">';
        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre"><td>'.$langs->trans('Field').'</td><td>'.$langs->trans('CurrentDolibarrValue').'</td><td>'.$langs->trans('NavValue').'</td><td>'.$langs->trans('ProposalStatus').'</td></tr>';
        foreach ($partnerEnrichment['items'] as $item) {
            $status = (string) $item['status'];
            $field = (string) $item['field'];
            $autoWritable = in_array($field, $autoEnrichmentFields, true);
            if ($status === 'different') {
                $statusDisplay = img_picto('', 'warning').' <span class="warning">'.$langs->trans('EnrichmentDifferentNoOverwrite').'</span>';
            } elseif ($status === 'separate_review' || !$autoWritable) {
                $statusDisplay = img_picto('', 'warning').' '.$langs->trans('EnrichmentSeparateReview');
            } elseif (!empty($item['safe'])) {
                $statusDisplay = img_picto('', 'tick').' '.$langs->trans('EnrichmentCanFill');
            } else {
                $statusDisplay = img_picto('', 'warning').' '.$langs->trans('EnrichmentNeedsConfirmedMatch');
            }
            print '<tr class="oddeven">';
            print '<td>'.$langs->trans((string) $item['label']).'</td>';
            print '<td>'.$enrichmentValue($field, $item['current']).'</td>';
            print '<td>'.$enrichmentValue($field, $item['proposed']).'</td>';
            print '<td>'.$statusDisplay.'</td>';
            print '</tr>';
        }
        print '</table></div>';

        if (!empty($partnerEnrichment['strong_match']) && $hasAutoApplicable) {
            if ($canApplyPartnerEnrichment) {
                print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
                print '<input type="hidden" name="token" value="'.newToken().'">';
                print '<input type="hidden" name="action" value="apply_partner_enrichment">';
                print '<input type="hidden" name="id" value="'.$id.'">';
                print '<div class="center tabsAction">';
                print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('ApplySafePartnerEnrichment')).'" onclick="return confirm(\''.dol_escape_js($langs->trans('ApplySafePartnerEnrichmentConfirm')).'\');">';
                print '</div>';
                print '</form>';
            } else {
                print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('PartnerEnrichmentPermissionMissing').'</div>';
            }
        }
        print '<br>';
    }

    $showLineNature = false;
    foreach ($parsed['lines'] as $line) {
        if (!empty($line['nature'])) {
            $showLineNature = true;
            break;
        }
    }

    print load_fiche_titre($langs->trans('InvoiceLines'), '', 'list');
    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="right">#</td>';
    print '<td>'.$langs->trans('Description').'</td>';
    if ($showLineNature) {
        print '<td>'.$langs->trans('LineNature').'</td>';
    }
    print '<td class="right">'.$langs->trans('Qty').'</td>';
    print '<td>'.$langs->trans('Unit').'</td>';
    print '<td class="right">'.$langs->trans($isSimplified ? 'UnitPriceGross' : 'UnitPriceHT').'</td>';
    print '<td>'.$langs->trans('VAT').'</td>';
    print '<td class="right">'.$langs->trans('AmountHT').($isSimplified ? ' *' : '').'</td>';
    print '<td class="right">'.$langs->trans('VAT').($isSimplified ? ' *' : '').'</td>';
    print '<td class="right">'.$langs->trans('AmountTTC').'</td>';
    print '</tr>';

    $hasDerivedLineAmounts = false;
    $hasNonExpressionNormalization = false;
    foreach ($parsed['lines'] as $line) {
        $description = $display($line['description']);
        $extras = array();
        foreach ($line['product_codes'] as $code) {
            $extras[] = trim($code['category'].': '.$code['value']);
        }
        if ($line['modification']['operation'] !== '' || $line['modification']['reference'] !== '') {
            $extras[] = $langs->trans('LineModification').': '.trim($line['modification']['operation'].' #'.$line['modification']['reference']);
        }
        if ($extras) {
            $description .= '<br><span class="opacitymedium small">'.dol_escape_htmltag(implode(' · ', $extras)).'</span>';
        }

        $unitDisplay = $line['unit_own'] !== '' ? $display($line['unit_own']) : $navEnum('Unit', $line['unit']);
        $vatLabel = $line['vat']['label'];
        if ($line['vat']['kind'] === 'content') {
            $effectiveRate = $vatRateFromContent($line['vat']['value']);
            $vatLabel = $effectiveRate !== null
                ? rtrim(rtrim(number_format($effectiveRate, 2, '.', ''), '0'), '.').'%'
                : $langs->trans('VatContent');
        }

        $amounts = $line['amounts'];
        $lineVatDerived = !empty($amounts['vat_derived']);
        $lineGrossDerived = !empty($amounts['gross_derived']);
        $hasDerivedLineAmounts = $hasDerivedLineAmounts || $lineVatDerived || $lineGrossDerived;

        $quantityDisplay = $line['quantity'];
        $unitPriceDisplay = $line['unit_price'];
        $nonExpressionNormalized = false;
        if (($line['expression'] ?? null) === false && ($quantityDisplay === null || $quantityDisplay === '')) {
            $quantityDisplay = 1;
            if ($unitPriceDisplay === null || $unitPriceDisplay === '') {
                $unitPriceDisplay = $isSimplified ? ($amounts['gross'] ?? null) : ($amounts['net'] ?? null);
            }
            $nonExpressionNormalized = true;
            $hasNonExpressionNormalization = true;
        }

        print '<tr class="oddeven">';
        print '<td class="right">'.$display($line['number']).'</td>';
        print '<td>'.$description.'</td>';
        if ($showLineNature) {
            print '<td>'.$navEnum('LineNature', $line['nature']).'</td>';
        }
        print '<td class="right">'.$display($quantityDisplay).($nonExpressionNormalized ? ' <span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('NonExpressionLineDerivedHelp')).'">*</span>' : '').'</td>';
        print '<td>'.$unitDisplay.'</td>';
        print '<td class="right">'.$money($unitPriceDisplay, $currency).($nonExpressionNormalized ? ' <span class="opacitymedium" title="'.dol_escape_htmltag($langs->trans('NonExpressionLineDerivedHelp')).'">*</span>' : '').'</td>';
        print '<td>'.$display($vatLabel).'</td>';
        print '<td class="right">'.$money($amounts['net'], $currency).'</td>';
        print '<td class="right">'.$money($amounts['vat'], $currency).($lineVatDerived ? ' <span class="opacitymedium">*</span>' : '').'</td>';
        print '<td class="right">'.$money($amounts['gross'], $currency).($lineGrossDerived ? ' <span class="opacitymedium">*</span>' : '').'</td>';
        print '</tr>';
    }

    if (empty($parsed['lines'])) {
        print '<tr><td colspan="'.($showLineNature ? '10' : '9').'" class="opacitymedium">'.$langs->trans('NoInvoiceLinesInNavXml').'</td></tr>';
    }
    print '</table></div>';
    if ($hasNonExpressionNormalization) {
        print '<div class="opacitymedium small">* '.$langs->trans('NonExpressionLineDerivedHelp').'</div>';
    }
    if ($hasDerivedLineAmounts) {
        print '<div class="opacitymedium small">* '.$langs->trans('DerivedLineAmountsHelp').'</div>';
    }
    if ($isSimplified) {
        print '<div class="opacitymedium small">* '.$langs->trans('SimplifiedDerivedAmountsHelp').'</div>';
    }
}

if (!empty($record->invoice_data)) {
    print '<br><details>';
    print '<summary style="cursor:pointer;font-weight:600">'.$langs->trans('ShowNavXml').'</summary>';
    print '<div class="opacitymedium small">'.$langs->trans('NavXmlOriginalNotice').'</div>';
    print '<pre style="max-height:600px;overflow:auto;white-space:pre-wrap;word-break:break-word;border:1px solid var(--colortextlink);padding:10px">'.dol_escape_htmltag((string) $record->invoice_data).'</pre>';
    print '</details>';
}

print '</div>';
llxFooter();
$db->close();
