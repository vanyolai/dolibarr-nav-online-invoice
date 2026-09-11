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
$currency = (string) $record->currency;
if ($parsed && !empty($parsed['detail']['currency'])) {
    $currency = (string) $parsed['detail']['currency'];
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

print '<div class="fichehalfleft">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('NavInvoiceNumber').'</td><td>'.$display($record->invoice_number).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceDirection').'</td><td>'.$langs->trans($isInbound ? 'DirectionInbound' : 'DirectionOutbound').'</td></tr>';
print '<tr><td>'.$langs->trans('Operation').'</td><td>'.$display($record->invoice_operation).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceCategory').'</td><td>'.$display($record->invoice_category).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceIssueDate').'</td><td>'.$display($record->invoice_issue_date).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceDeliveryDate').'</td><td>'.$display($parsed ? $parsed['detail']['delivery_date'] : $record->invoice_delivery_date).'</td></tr>';
if ($parsed && ($parsed['detail']['delivery_period_start'] !== '' || $parsed['detail']['delivery_period_end'] !== '')) {
    print '<tr><td>'.$langs->trans('DeliveryPeriod').'</td><td>'.$display($parsed['detail']['delivery_period_start']).' – '.$display($parsed['detail']['delivery_period_end']).'</td></tr>';
}
print '<tr><td>'.$langs->trans('PaymentDate').'</td><td>'.$display($parsed ? $parsed['detail']['payment_date'] : $record->payment_date).'</td></tr>';
print '<tr><td>'.$langs->trans('PaymentMode').'</td><td>'.$display($parsed ? $parsed['detail']['payment_method'] : $record->payment_method).'</td></tr>';
print '<tr><td>'.$langs->trans('Currency').'</td><td>'.$display($currency).'</td></tr>';
print '<tr><td>'.$langs->trans('ExchangeRate').'</td><td>'.$display($parsed ? $parsed['detail']['exchange_rate'] : null).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceAppearance').'</td><td>'.$display($parsed ? $parsed['detail']['invoice_appearance'] : $record->invoice_appearance).'</td></tr>';
if ($parsed) {
    print '<tr><td>'.$langs->trans('CashAccounting').'</td><td>'.$yesNo($parsed['detail']['cash_accounting']).'</td></tr>';
}
if (!empty($record->original_invoice_number) || ($parsed && !empty($parsed['reference']['original_invoice_number']))) {
    print '<tr><td>'.$langs->trans('OriginalInvoiceNumber').'</td><td>'.$display($parsed && $parsed['reference']['original_invoice_number'] !== '' ? $parsed['reference']['original_invoice_number'] : $record->original_invoice_number).'</td></tr>';
    print '<tr><td>'.$langs->trans('ModificationIndex').'</td><td>'.$display($parsed && $parsed['reference']['modification_index'] !== '' ? $parsed['reference']['modification_index'] : $record->modification_index).'</td></tr>';
}
print '</table>';
print '</div>';

print '<div class="fichehalfright">';
print '<table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('AmountHT').'</td><td class="right">'.$money($parsed ? $parsed['totals']['net'] : $record->invoice_net_amount, $currency).'</td></tr>';
print '<tr><td>'.$langs->trans('VAT').'</td><td class="right">'.$money($parsed ? $parsed['totals']['vat'] : $record->invoice_vat_amount, $currency).'</td></tr>';
print '<tr><td>'.$langs->trans('AmountTTC').'</td><td class="right">'.$money($parsed ? $parsed['totals']['gross'] : null, $currency).'</td></tr>';
if ($currency !== 'HUF' && $parsed) {
    print '<tr><td>'.$langs->trans('AmountHT').' (HUF)</td><td class="right">'.$money($parsed['totals']['net_huf'], 'HUF').'</td></tr>';
    print '<tr><td>'.$langs->trans('VAT').' (HUF)</td><td class="right">'.$money($parsed['totals']['vat_huf'], 'HUF').'</td></tr>';
    print '<tr><td>'.$langs->trans('AmountTTC').' (HUF)</td><td class="right">'.$money($parsed['totals']['gross_huf'], 'HUF').'</td></tr>';
}
print '<tr><td>'.$langs->trans('XmlDownloaded').'</td><td>'.($record->data_fetched ? img_picto($langs->trans('Yes'), 'tick').' '.$langs->trans('Yes') : img_picto($langs->trans('No'), 'warning').' '.$langs->trans('No')).'</td></tr>';
print '<tr><td>'.$langs->trans('LastSync').'</td><td>'.$display($record->last_sync).'</td></tr>';
print '</table>';
print '</div>';
print '<div class="clearboth"></div><br>';

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
            print '<tr><td>'.$langs->trans('VatStatus').'</td><td>'.$display($party['vat_status']).'</td></tr>';
        }
        if (!empty($party['group_member_tax_number'])) {
            print '<tr><td>'.$langs->trans('GroupMemberTaxNumber').'</td><td>'.$display($party['group_member_tax_number']).'</td></tr>';
        }
        print '<tr><td>'.$langs->trans('Address').'</td><td>'.$display($party['address']['formatted']).'</td></tr>';
        if (!empty($party['bank_account'])) {
            print '<tr><td>'.$langs->trans('BankAccount').'</td><td>'.$display($party['bank_account']).'</td></tr>';
        }
        print '</table>';
        print '</div>';
    }
    print '<div class="clearboth"></div><br>';

    print load_fiche_titre($langs->trans('InvoiceLines'), '', 'list');
    print '<div class="div-table-responsive">';
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td class="right">#</td>';
    print '<td>'.$langs->trans('Description').'</td>';
    print '<td>'.$langs->trans('LineNature').'</td>';
    print '<td class="right">'.$langs->trans('Qty').'</td>';
    print '<td>'.$langs->trans('Unit').'</td>';
    print '<td class="right">'.$langs->trans('UnitPriceHT').'</td>';
    print '<td>'.$langs->trans('VAT').'</td>';
    print '<td class="right">'.$langs->trans('AmountHT').'</td>';
    print '<td class="right">'.$langs->trans('VAT').'</td>';
    print '<td class="right">'.$langs->trans('AmountTTC').'</td>';
    print '</tr>';

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

        $unit = $line['unit_own'] !== '' ? $line['unit_own'] : $line['unit'];
        $vatLabel = $line['vat']['label'];
        if ($line['vat']['kind'] === 'content') {
            $vatLabel = $langs->trans('VatContent').' '.rtrim(rtrim(number_format(((float) $line['vat']['value']) * 100, 4, '.', ''), '0'), '.').'%';
        }

        print '<tr class="oddeven">';
        print '<td class="right">'.$display($line['number']).'</td>';
        print '<td>'.$description.'</td>';
        print '<td>'.$display($line['nature']).'</td>';
        print '<td class="right">'.$display($line['quantity']).'</td>';
        print '<td>'.$display($unit).'</td>';
        print '<td class="right">'.$money($line['unit_price'], $currency).'</td>';
        print '<td>'.$display($vatLabel).'</td>';
        print '<td class="right">'.$money($line['amounts']['net'], $currency).'</td>';
        print '<td class="right">'.$money($line['amounts']['vat'], $currency).'</td>';
        print '<td class="right">'.$money($line['amounts']['gross'], $currency).'</td>';
        print '</tr>';
    }

    if (empty($parsed['lines'])) {
        print '<tr><td colspan="10" class="opacitymedium">'.$langs->trans('NoInvoiceLinesInNavXml').'</td></tr>';
    }
    print '</table></div>';
}

print '</div>';
llxFooter();
$db->close();
