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
dol_include_once('/navinvoice/class/navpartnercreation.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navpartner@navinvoice'));

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

if (empty($record->invoice_data)) {
    setEventMessages($langs->trans('FullXmlNotAvailable'), null, 'errors');
    header('Location: '.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id);
    exit;
}

try {
    $parser = new NavInvoiceParser();
    $parsed = $parser->parse((string) $record->invoice_data);
} catch (Throwable $e) {
    setEventMessages($langs->trans('NavXmlParseFailed').': '.$e->getMessage(), null, 'errors');
    header('Location: '.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id);
    exit;
}

$isInbound = strtoupper((string) $record->invoice_direction) === 'INBOUND';
$role = $isInbound ? 'supplier' : 'customer';
$party = is_array($parsed[$role] ?? null) ? $parsed[$role] : array();
$canCreatePartner = $user->hasRight('societe', 'creer');
$service = new NavPartnerCreationService($db, (int) $conf->entity);

$action = GETPOST('action', 'aZ09');
if ($action === 'create_partner') {
    if (!$canCreatePartner) {
        accessforbidden();
    }

    try {
        $created = $service->create($party, $role, $user);
        setEventMessages($langs->trans('NavPartnerCreated', (string) $created['name']), null, 'mesgs');
        header('Location: '.dol_buildpath('/navinvoice/import.php', 1).'?id='.$id);
        exit;
    } catch (Throwable $e) {
        setEventMessages($langs->trans('NavPartnerCreationFailed').': '.$e->getMessage(), null, 'errors');
    }
}

$preview = null;
$previewError = '';
try {
    $preview = $service->build($party, $role);
} catch (Throwable $e) {
    $previewError = $e->getMessage();
}

$display = static function ($value): string {
    if ($value === null || $value === '') {
        return '<span class="opacitymedium">—</span>';
    }
    return dol_escape_htmltag((string) $value);
};
$fullTaxNumber = static function (array $source) use ($display): string {
    if (!empty($source['full_tax_number'])) {
        return $display($source['full_tax_number']);
    }
    $tax = trim((string) ($source['tax_number'] ?? ''));
    if ($tax !== '' && !empty($source['vat_code']) && !empty($source['county_code'])) {
        $tax .= '-'.$source['vat_code'].'-'.$source['county_code'];
    }
    return $display($tax);
};
$address = static function (?array $source) use ($display): string {
    if (!$source) {
        return $display(null);
    }
    if (!empty($source['formatted'])) {
        return $display($source['formatted']);
    }
    return $display(trim(implode(' ', array_filter(array(
        $source['postal_code'] ?? '',
        $source['city'] ?? '',
        $source['street_name'] ?? '',
        $source['public_place_category'] ?? '',
        $source['number'] ?? '',
    )))));
};

llxHeader('', $langs->trans('NavPartnerResolution'));
print load_fiche_titre(
    $langs->trans('NavPartnerResolution').' - '.dol_escape_htmltag((string) $record->invoice_number),
    '<a href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('NavInvoiceDetails').'</a>',
    'company'
);

print '<div class="info marginbottomonly">'.img_picto('', 'info').' '.$langs->trans('NavPartnerMasterDataNotice').'</div>';

if ($previewError !== '') {
    print '<div class="error marginbottomonly">'.img_picto('', 'error').' '.$langs->trans('NavTaxpayerLookupFailed').': '.dol_escape_htmltag($previewError).'</div>';
} else {
    $master = is_array($preview['master'] ?? null) ? $preview['master'] : null;
    $invoiceAddress = is_array($party['address'] ?? null) ? $party['address'] : null;

    print '<div class="fichehalfleft">';
    print '<table class="border centpercent">';
    print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('InvoiceHistoricalPartnerData').'</td></tr>';
    print '<tr><td class="titlefield">'.$langs->trans('Name').'</td><td>'.$display($party['name'] ?? '').'</td></tr>';
    print '<tr><td>'.$langs->trans('TaxNumber').'</td><td>'.$fullTaxNumber($party).'</td></tr>';
    print '<tr><td>'.$langs->trans('Address').'</td><td>'.$address($invoiceAddress).'</td></tr>';
    print '</table>';
    print '</div>';

    print '<div class="fichehalfright">';
    print '<table class="border centpercent">';
    print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('CurrentNavTaxpayerData').'</td></tr>';
    if ($master) {
        print '<tr><td class="titlefield">'.$langs->trans('TaxpayerValidity').'</td><td>'.(!empty($master['valid']) ? img_picto('', 'tick').' '.$langs->trans('Yes') : img_picto('', 'warning').' '.$langs->trans('No')).'</td></tr>';
        print '<tr><td>'.$langs->trans('NavTaxpayerInfoDate').'</td><td>'.$display($master['info_date'] ?? '').'</td></tr>';
        print '<tr><td>'.$langs->trans('Name').'</td><td>'.$display($master['name'] ?? '').'</td></tr>';
        print '<tr><td>'.$langs->trans('ShortName').'</td><td>'.$display($master['short_name'] ?? '').'</td></tr>';
        print '<tr><td>'.$langs->trans('TaxNumber').'</td><td>'.$fullTaxNumber($master).'</td></tr>';
        print '<tr><td>'.$langs->trans('Incorporation').'</td><td>'.$display($master['incorporation'] ?? '').'</td></tr>';
        print '<tr><td>'.$langs->trans('VatGroupMembership').'</td><td>'.$display($master['vat_group_membership'] ?? '').'</td></tr>';
        print '<tr><td>'.$langs->trans('Headquarters').'</td><td>'.$address(is_array($master['primary_address'] ?? null) ? $master['primary_address'] : null).'</td></tr>';
    } else {
        print '<tr><td colspan="2" class="opacitymedium">'.$langs->trans('NoData').'</td></tr>';
    }
    print '</table>';
    print '</div>';
    print '<div class="clearboth"></div><br>';

    if (!empty($preview['differences'])) {
        print load_fiche_titre($langs->trans('InvoiceVsCurrentNavData'), '', 'list');
        print '<div class="div-table-responsive"><table class="noborder centpercent">';
        print '<tr class="liste_titre"><td>'.$langs->trans('Field').'</td><td>'.$langs->trans('InvoiceHistoricalValue').'</td><td>'.$langs->trans('CurrentNavValue').'</td><td>'.$langs->trans('ProposalStatus').'</td></tr>';
        foreach ($preview['differences'] as $item) {
            $fieldKey = 'NavPartnerField_'.(string) $item['field'];
            $fieldLabel = $langs->trans($fieldKey);
            if ($fieldLabel === $fieldKey) {
                $fieldLabel = (string) $item['field'];
            }
            print '<tr class="oddeven">';
            print '<td>'.dol_escape_htmltag($fieldLabel).'</td>';
            print '<td>'.$display($item['invoice']).'</td>';
            print '<td>'.$display($item['current']).'</td>';
            print '<td>'.(!empty($item['same']) ? img_picto('', 'tick').' '.$langs->trans('Same') : img_picto('', 'warning').' '.$langs->trans('Different')).'</td>';
            print '</tr>';
        }
        print '</table></div><br>';
    }

    $match = is_array($preview['match'] ?? null) ? $preview['match'] : array();
    $matched = is_array($match['match'] ?? null) ? $match['match'] : null;
    print load_fiche_titre($langs->trans('DolibarrPartnerResolution'), '', 'company');
    if ($matched) {
        $url = DOL_URL_ROOT.'/societe/card.php?socid='.(int) $matched['id'];
        print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('NavPartnerCandidateExists').' <a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag((string) $matched['name']).'</a></div>';
    } elseif (($match['status'] ?? '') === 'ambiguous') {
        print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('PartnerMatchAmbiguous').'</div>';
        if (!empty($match['candidates'])) {
            print '<ul>';
            foreach ($match['candidates'] as $candidate) {
                $url = DOL_URL_ROOT.'/societe/card.php?socid='.(int) $candidate['id'];
                print '<li><a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag((string) $candidate['name']).'</a> ('.(int) $candidate['score'].'%)</li>';
            }
            print '</ul>';
        }
    }

    $blockers = array_values(array_unique(array_map('strval', $preview['blockers'] ?? array())));
    if ($blockers) {
        print '<div class="warning marginbottomonly"><strong>'.$langs->trans('PartnerCreationBlocked').'</strong><ul>';
        foreach ($blockers as $blocker) {
            $key = 'PartnerCreationBlocker_'.$blocker;
            $translated = $langs->trans($key);
            print '<li>'.dol_escape_htmltag($translated !== $key ? $translated : $blocker).'</li>';
        }
        print '</ul></div>';
    } elseif (!empty($preview['can_create'])) {
        print '<div class="info marginbottomonly">'.img_picto('', 'info').' '.$langs->trans('NavPartnerCreationReady').'</div>';
        if ($canCreatePartner) {
            print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
            print '<input type="hidden" name="token" value="'.newToken().'">';
            print '<input type="hidden" name="action" value="create_partner">';
            print '<input type="hidden" name="id" value="'.$id.'">';
            print '<div class="center tabsAction">';
            print '<input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('CreatePartnerFromNav')).'" onclick="return confirm(\''.dol_escape_js($langs->trans('CreatePartnerFromNavConfirm')).'\');">';
            print '</div></form>';
        } else {
            print '<div class="warning marginbottomonly">'.$langs->trans('PartnerEnrichmentPermissionMissing').'</div>';
        }
    }
}

print '<div class="tabsAction">';
print '<a class="butAction" href="'.dol_buildpath('/navinvoice/import.php', 1).'?id='.$id.'">'.$langs->trans('ImportPreview').'</a>';
print '<a class="butAction" href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('NavInvoiceDetails').'</a>';
print '</div>';

llxFooter();
$db->close();
