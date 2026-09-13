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

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navpurchaseworkbench.class.php');

$langs->loadLangs(array('navinvoice@navinvoice', 'navpurchase@navinvoice', 'products', 'suppliers', 'orders'));

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    accessforbidden();
}
if (!getDolGlobalInt('NAVINVOICE_PURCHASE_WORKBENCH_ENABLED')) {
    accessforbidden($langs->trans('PurchaseWorkbenchDisabled'));
}

$id = GETPOSTINT('id');
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

$parser = new NavInvoiceParser();
try {
    if (empty($record->invoice_data)) {
        throw new Exception($langs->trans('FullXmlNotAvailable'));
    }
    $parsed = $parser->parse((string) $record->invoice_data);
    $partnerMatcher = new NavPartnerMatcher($db, (int) $conf->entity);
    $partnerMatch = $partnerMatcher->match($parsed['supplier'], 'supplier');
    $workbenchService = new NavPurchaseWorkbench($db, (int) $conf->entity, strtoupper((string) $conf->currency));
    $workbench = $workbenchService->build($parsed, $record, $partnerMatch);
} catch (Throwable $e) {
    llxHeader('', $langs->trans('PurchaseWorkbench'));
    setEventMessages($langs->trans('PurchaseWorkbenchBuildFailed').': '.$e->getMessage(), null, 'errors');
    print load_fiche_titre($langs->trans('PurchaseWorkbench'), '<a href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('BackToNavInvoiceDetails').'</a>', 'supplier_order');
    llxFooter();
    $db->close();
    exit;
}

$findLine = static function (array $workbench, int $index): array {
    foreach (($workbench['lines'] ?? array()) as $line) {
        if (is_array($line) && (int) ($line['workbench_index'] ?? -1) === $index) {
            return $line;
        }
    }
    throw new Exception('NAV purchase-workbench line no longer exists.');
};

$canUseWorkbench = $user->hasRight('navinvoice', 'invoice', 'import');
$canCreateSupplierOrder = $canUseWorkbench && $user->hasRight('fournisseur', 'commande', 'creer');
$canLinkSupplierOrder = $canUseWorkbench && $user->hasRight('fournisseur', 'commande', 'lire');
$canEditProductType = static function (int $type) use ($user, $canUseWorkbench): bool {
    if (!$canUseWorkbench) {
        return false;
    }
    return $type === 1 ? $user->hasRight('service', 'creer') : $user->hasRight('produit', 'creer');
};

$action = GETPOST('action', 'aZ09');
if (in_array($action, array('create_product', 'link_product', 'update_supplier_price', 'create_order', 'link_order'), true)) {
    if (!$canUseWorkbench) {
        accessforbidden();
    }
    try {
        if (empty($workbench['available'])) {
            throw new Exception($langs->trans('PurchaseWorkbenchUnavailable'));
        }

        if ($action === 'create_product') {
            $lineIndex = GETPOSTINT('line_index');
            $line = $findLine($workbench, $lineIndex);
            $type = (int) ($line['product_type'] ?? 0);
            if (!$canEditProductType($type)) {
                accessforbidden();
            }
            $created = $workbenchService->createProduct(
                $line,
                (int) $workbench['partner_id'],
                array(
                    'ref' => trim(GETPOST('candidate_ref', 'alphanohtml')),
                    'label' => trim(GETPOST('candidate_label', 'alphanohtml')),
                    'description' => trim(GETPOST('candidate_description', 'restricthtml')),
                    'product_type' => $type,
                    'unit_id' => GETPOSTINT('candidate_unit_id'),
                    'stockable' => GETPOSTINT('candidate_stockable'),
                    'tosell' => GETPOSTINT('candidate_tosell'),
                    'supplier_ref' => trim(GETPOST('candidate_supplier_ref', 'alphanohtml')),
                    'supplier_quantity' => price2num(GETPOST('candidate_supplier_quantity', 'alphanohtml'), 'MS'),
                    'supplier_packaging' => price2num(GETPOST('candidate_supplier_packaging', 'alphanohtml'), 'MS'),
                    'supplier_price_total' => price2num(GETPOST('candidate_supplier_price_total', 'alphanohtml'), 'MU'),
                    'vat_rate' => price2num(GETPOST('candidate_vat_rate', 'alphanohtml'), 'MU'),
                ),
                $user
            );
            setEventMessages($langs->trans('PurchaseProductCreated', $created['ref']), null, 'mesgs');
        } elseif ($action === 'link_product') {
            $lineIndex = GETPOSTINT('line_index');
            $line = $findLine($workbench, $lineIndex);
            $type = (int) ($line['product_type'] ?? 0);
            if (!$canEditProductType($type)) {
                accessforbidden();
            }
            $productId = GETPOSTINT('product_id');
            $workbenchService->linkExistingProduct($line, (int) $workbench['partner_id'], $productId, $user);
            setEventMessages($langs->trans('PurchaseProductLinked'), null, 'mesgs');
        } elseif ($action === 'update_supplier_price') {
            $lineIndex = GETPOSTINT('line_index');
            $line = $findLine($workbench, $lineIndex);
            $type = (int) ($line['product_type'] ?? 0);
            if (!$canEditProductType($type)) {
                accessforbidden();
            }
            $workbenchService->updateSupplierPrice($line, (int) $workbench['partner_id'], $user);
            setEventMessages($langs->trans('PurchaseSupplierPriceUpdated'), null, 'mesgs');
        } elseif ($action === 'link_order') {
            if (!$canLinkSupplierOrder) {
                accessforbidden();
            }
            $linked = $workbenchService->linkExistingOrder($workbench, $record, GETPOSTINT('supplier_order_id'), $user);
            setEventMessages($langs->trans('PurchaseExistingOrderLinked', $linked['ref']), null, 'mesgs');
        } elseif ($action === 'create_order') {
            if (!$canCreateSupplierOrder) {
                accessforbidden();
            }
            $freeTextIndexes = isset($_POST['free_text_indexes']) && is_array($_POST['free_text_indexes'])
                ? array_map('intval', $_POST['free_text_indexes'])
                : array();
            $result = $workbenchService->createDraftOrder(
                $workbench,
                $record,
                trim(GETPOST('order_date', 'alphanohtml')),
                $freeTextIndexes,
                $user
            );
            setEventMessages($langs->trans('PurchaseDraftOrderCreated', $result['ref']), null, 'mesgs');
        }

        header('Location: '.$_SERVER['PHP_SELF'].'?id='.$id);
        exit;
    } catch (Throwable $e) {
        setEventMessages($langs->trans('PurchaseWorkbenchActionFailed').': '.$e->getMessage(), null, 'errors');
        try {
            $partnerMatch = $partnerMatcher->match($parsed['supplier'], 'supplier');
            $workbench = $workbenchService->build($parsed, $record, $partnerMatch);
        } catch (Throwable $refreshError) {
            // Keep prior preview; primary action error is more useful.
        }
    }
}

$form = new Form($db);
$units = array();
if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
    $sql = 'SELECT rowid, code, label, short_label FROM '.MAIN_DB_PREFIX.'c_units WHERE active = 1 ORDER BY sortorder, rowid';
    $resql = $db->query($sql);
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $units[] = array(
                'id' => (int) $obj->rowid,
                'code' => (string) $obj->code,
                'label' => (string) $obj->label,
                'short_label' => (string) $obj->short_label,
            );
        }
        $db->free($resql);
    }
}

$money = static function ($value, string $currency): string {
    if ($value === null || $value === '') {
        return '<span class="opacitymedium">—</span>';
    }
    return price($value).' '.dol_escape_htmltag($currency);
};
$reasonLabel = static function (string $code) use ($langs): string {
    $key = 'PurchaseWorkbenchReason_'.$code;
    $translated = $langs->trans($key);
    return $translated === $key ? $code : $translated;
};
$orderStatusLabel = static function (int $status) use ($langs): string {
    $key = 'PurchaseOrderStatus_'.$status;
    $translated = $langs->trans($key);
    return $translated === $key ? (string) $status : $translated;
};

$currency = (string) ($workbench['preview']['header']['currency'] ?? $record->currency ?? $conf->currency);
$invoiceNumber = (string) ($workbench['preview']['invoice_number'] ?? $record->invoice_number);
$supplier = is_array($workbench['partner'] ?? null) ? $workbench['partner'] : array();
$linkedOrders = is_array($workbench['orders'] ?? null) ? $workbench['orders'] : array();
$candidateOrders = is_array($workbench['candidate_orders'] ?? null) ? $workbench['candidate_orders'] : array();

llxHeader('', $langs->trans('PurchaseWorkbench'));
print load_fiche_titre(
    $langs->trans('PurchaseWorkbench').' - '.dol_escape_htmltag($invoiceNumber),
    '<a href="'.dol_buildpath('/navinvoice/detail.php', 1).'?id='.$id.'">'.$langs->trans('BackToNavInvoiceDetails').'</a>',
    'supplier_order'
);

if (empty($workbench['available'])) {
    print '<div class="warning marginbottomonly">'.img_picto('', 'warning').' '.$langs->trans('PurchaseWorkbenchUnavailable').'</div><ul>';
    foreach (($workbench['reasons'] ?? array()) as $reason) {
        print '<li>'.dol_escape_htmltag($reasonLabel((string) $reason)).'</li>';
    }
    print '</ul>';
    llxFooter();
    $db->close();
    exit;
}

print '<div class="info marginbottomonly">'.img_picto('', 'info').' '.$langs->trans('PurchaseWorkbenchNotice').'</div>';
print '<div class="fichecenter"><div class="fichehalfleft"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('Supplier').'</td><td>';
if (!empty($supplier['id'])) {
    print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.(int) $supplier['id'].'">'.dol_escape_htmltag((string) ($supplier['name'] ?? '')).'</a>';
} else {
    print '<span class="warning">'.$langs->trans('PartnerMatchNone').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('NavInvoiceNumber').'</td><td>'.dol_escape_htmltag($invoiceNumber).'</td></tr>';
print '<tr><td>'.$langs->trans('InvoiceIssueDate').'</td><td>'.dol_escape_htmltag((string) ($workbench['preview']['header']['invoice_date'] ?? '')).'</td></tr>';
print '</table></div>';
print '<div class="fichehalfright"><table class="border centpercent">';
print '<tr><td class="titlefield">'.$langs->trans('PurchaseLinkedSupplierOrders').'</td><td>';
if ($linkedOrders) {
    foreach ($linkedOrders as $i => $order) {
        if ($i > 0) {
            print '<br>';
        }
        print img_picto('', 'tick').' <a href="'.dol_escape_htmltag((string) $order['url']).'">'.dol_escape_htmltag((string) $order['ref']).'</a>';
        print ' <span class="opacitymedium">('.dol_escape_htmltag($orderStatusLabel((int) $order['status'])).')</span>';
    }
} else {
    print '<span class="opacitymedium">'.$langs->trans('None').'</span>';
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('PurchaseLinkedSupplierInvoice').'</td><td>';
if (!empty($workbench['linked_invoice_id'])) {
    print '<a href="'.DOL_URL_ROOT.'/fourn/facture/card.php?facid='.(int) $workbench['linked_invoice_id'].'">#'.(int) $workbench['linked_invoice_id'].'</a>';
} else {
    print '<span class="opacitymedium">'.$langs->trans('PurchaseInvoiceNotImportedYet').'</span>';
}
print '</td></tr></table></div><div class="clearboth"></div></div><br>';

if ($canLinkSupplierOrder && $candidateOrders) {
    print load_fiche_titre($langs->trans('PurchaseLinkExistingOrder'), '', 'supplier_order');
    print '<div class="info marginbottomonly">'.img_picto('', 'info').' '.$langs->trans('PurchaseLinkExistingOrderHelp').'</div>';
    print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="link_order">';
    print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('SupplierOrder').'</td><td><select class="minwidth400" name="supplier_order_id" required><option value="">—</option>';
    foreach ($candidateOrders as $order) {
        $label = (string) $order['ref'].' · '.$orderStatusLabel((int) $order['status']);
        if (!empty($order['date'])) {
            $label .= ' · '.substr((string) $order['date'], 0, 10);
        }
        $label .= ' · '.price((float) $order['total_ht']).' '.$currency;
        print '<option value="'.(int) $order['id'].'">'.dol_escape_htmltag($label).'</option>';
    }
    print '</select> <button class="button" type="submit" onclick="return confirm(\''.dol_escape_js($langs->trans('PurchaseLinkExistingOrderConfirm')).'\');">'.$langs->trans('PurchaseLinkOrder').'</button></td></tr></table></form><br>';
}

print load_fiche_titre($langs->trans('PurchaseLineResolution'), '', 'product');
print '<div class="div-table-responsive"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>#</td><td>'.$langs->trans('Description').'</td><td>'.$langs->trans('SupplierRef').'</td><td>'.$langs->trans('PurchaseProductResolution').'</td><td class="right">'.$langs->trans('NavUnitPrice').'</td><td class="right">'.$langs->trans('PurchaseCurrentSupplierPrice').'</td><td>'.$langs->trans('Actions').'</td></tr>';

$unresolvedIndexes = array();
foreach (($workbench['lines'] ?? array()) as $line) {
    if (!is_array($line)) {
        continue;
    }
    $index = (int) ($line['workbench_index'] ?? 0);
    $match = is_array($line['product_match'] ?? null) ? $line['product_match'] : array();
    $product = is_array($match['product'] ?? null) ? $match['product'] : null;
    $matched = (string) ($match['status'] ?? '') === 'matched' && !empty($match['auto_link']) && $product !== null;
    $skip = !empty($line['skip_for_order']);
    $norm = is_array($line['purchase_normalization'] ?? null) ? $line['purchase_normalization'] : array();
    if (!$matched && !$skip) {
        $unresolvedIndexes[] = $index;
    }

    print '<tr class="oddeven"><td class="nowrap">'.dol_escape_htmltag((string) ($line['number'] ?? ($index + 1))).'</td>';
    print '<td>'.dol_escape_htmltag((string) ($line['description'] ?? '')).'</td>';
    print '<td>'.($line['supplier_ref'] !== '' ? dol_escape_htmltag((string) $line['supplier_ref']) : '<span class="opacitymedium">—</span>').'</td>';
    print '<td>';
    if ($skip) {
        print '<span class="opacitymedium">'.$langs->trans('PurchaseInformationalLineSkipped').'</span>';
    } elseif ($matched) {
        print img_picto('', 'tick').' <a href="'.DOL_URL_ROOT.'/product/card.php?id='.(int) $product['id'].'">'.dol_escape_htmltag((string) $product['ref']).' - '.dol_escape_htmltag((string) $product['label']).'</a>';
        if (in_array((string) ($norm['mode'] ?? ''), array('price_quantity', 'packaging'), true) && (float) ($norm['factor'] ?? 1) != 1.0) {
            print '<br><span class="opacitymedium">'.img_picto('', 'info').' ';
            print $langs->trans(
                'PurchaseQuantityNormalized',
                price((float) ($norm['nav_quantity'] ?? 0)),
                price((float) ($norm['factor'] ?? 1)),
                price((float) ($norm['normalized_quantity'] ?? 0)),
                price((float) ($norm['normalized_unit_price'] ?? 0)),
                $currency
            );
            print '</span>';
        }
    } else {
        $statusKey = 'PurchaseProductMatch_'.(string) ($match['status'] ?? 'none');
        $statusText = $langs->trans($statusKey);
        print img_picto('', 'warning').' <span class="warning">'.dol_escape_htmltag($statusText === $statusKey ? (string) ($match['status'] ?? 'none') : $statusText).'</span>';
    }
    print '</td>';
    print '<td class="right">'.$money($line['unit_price_ht'] ?? null, $currency).'</td>';
    print '<td class="right">';
    if (($norm['supplier_unit_price'] ?? null) !== null) {
        print $money($norm['supplier_unit_price'], $currency);
        if ((float) ($norm['supplier_quantity'] ?? 0) > 1) {
            print '<br><span class="opacitymedium">'.price((float) $norm['supplier_quantity']).' × '.price((float) $norm['supplier_unit_price']).' = '.$money($norm['supplier_price_total'], $currency).'</span>';
        }
        if (!empty($norm['price_differs'])) {
            print '<br><span class="warning">'.img_picto('', 'warning').' '.$langs->trans('PurchasePriceNeedsReview').'</span>';
        } elseif (in_array((string) ($norm['mode'] ?? ''), array('price_quantity', 'packaging'), true)) {
            print '<br><span class="ok">'.img_picto('', 'tick').' '.$langs->trans('PurchasePackagePriceMatched').'</span>';
        }
    } else {
        print '<span class="opacitymedium">—</span>';
    }
    print '</td><td>';

    if (!$skip && $matched && !empty($line['supplier_price_differs']) && !empty($norm['can_update_price']) && $canEditProductType((int) ($line['product_type'] ?? 0))) {
        print '<form method="POST" class="inline-block" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="update_supplier_price"><input type="hidden" name="line_index" value="'.$index.'">';
        print '<button class="button smallpaddingimp" type="submit" onclick="return confirm(\''.dol_escape_js($langs->trans('PurchaseUpdatePriceConfirm')).'\');">'.$langs->trans('PurchaseUpdateSupplierPrice').'</button></form>';
    } elseif (!$skip && $matched && !empty($line['supplier_price_differs']) && empty($norm['can_update_price'])) {
        print '<span class="warning">'.$langs->trans('PurchasePriceConversionNeedsReview').'</span>';
    } elseif (!$skip && !$matched && $canEditProductType((int) ($line['product_type'] ?? 0))) {
        print '<details><summary style="cursor:pointer">'.$langs->trans('PurchaseResolveLine').'</summary>';
        print '<div class="marginbottomonly margintoponly"><strong>'.$langs->trans('PurchaseLinkExistingProduct').'</strong></div>';
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="link_product"><input type="hidden" name="line_index" value="'.$index.'">';
        $selector = $form->select_produits(0, 'product_id', (string) ((int) ($line['product_type'] ?? 0)), 20, 0, -1, 2, '', 0, array(), 0, '1', 0, 'minwidth300', 1, '', null, 1, -1, 0);
        print $selector.' <button class="button" type="submit">'.$langs->trans('PurchaseLinkProduct').'</button></form>';

        $candidate = is_array($line['candidate'] ?? null) ? $line['candidate'] : array();
        $candidateType = (int) ($candidate['product_type'] ?? 0);
        print '<div class="marginbottomonly margintoponly"><strong>'.$langs->trans('PurchaseCreateNewProduct').'</strong></div>';
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="create_product"><input type="hidden" name="line_index" value="'.$index.'">';
        print '<table class="noborder centpercent">';
        print '<tr><td class="titlefield">'.$langs->trans('Ref').'</td><td><input class="minwidth200" type="text" name="candidate_ref" value="'.dol_escape_htmltag((string) ($candidate['ref'] ?? '')).'"> <span class="opacitymedium">'.$langs->trans('PurchaseTemporaryRefHelp').'</span></td></tr>';
        print '<tr><td>'.$langs->trans('Label').'</td><td><input class="minwidth300" type="text" name="candidate_label" value="'.dol_escape_htmltag((string) ($candidate['label'] ?? '')).'"></td></tr>';
        print '<tr><td>'.$langs->trans('Description').'</td><td><textarea class="quatrevingtpercent" rows="2" name="candidate_description">'.dol_escape_htmltag((string) ($candidate['description'] ?? '')).'</textarea></td></tr>';
        print '<tr><td>'.$langs->trans('Type').'</td><td>'.dol_escape_htmltag($langs->trans($candidateType === 1 ? 'Service' : 'Product')).' <span class="opacitymedium">'.$langs->trans('PurchaseTypeFromNavHelp').'</span></td></tr>';
        if (getDolGlobalInt('PRODUCT_USE_UNITS')) {
            print '<tr><td>'.$langs->trans('Unit').'</td><td><select name="candidate_unit_id"><option value="0">—</option>';
            foreach ($units as $unit) {
                $unitLabel = trim($unit['short_label'].' '.$unit['label']);
                print '<option value="'.$unit['id'].'"'.((int) ($candidate['unit_id'] ?? 0) === $unit['id'] ? ' selected' : '').'>'.dol_escape_htmltag($unitLabel).'</option>';
            }
            print '</select></td></tr>';
        } else {
            print '<input type="hidden" name="candidate_unit_id" value="0">';
        }
        print '<tr><td>'.$langs->trans('PurchaseStockable').'</td><td><input type="checkbox" name="candidate_stockable" value="1"'.(!empty($candidate['stockable']) ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('PurchaseStockableHelp').'</span></td></tr>';
        print '<tr><td>'.$langs->trans('PurchaseSellable').'</td><td><input type="checkbox" name="candidate_tosell" value="1"'.(!empty($candidate['tosell']) ? ' checked' : '').'></td></tr>';
        print '<tr><td>'.$langs->trans('SupplierRef').'</td><td><input class="minwidth200" type="text" name="candidate_supplier_ref" value="'.dol_escape_htmltag((string) ($candidate['supplier_ref'] ?? '')).'"></td></tr>';
        print '<tr><td>'.$langs->trans('PurchaseSupplierMinQty').'</td><td><input class="width100" type="text" name="candidate_supplier_quantity" value="'.dol_escape_htmltag((string) ($candidate['supplier_quantity'] ?? 1)).'"></td></tr>';
        print '<tr><td>'.$langs->trans('PurchaseSupplierPackaging').'</td><td><input class="width100" type="text" name="candidate_supplier_packaging" value="'.dol_escape_htmltag((string) ($candidate['supplier_packaging'] ?? 1)).'"> <span class="opacitymedium">'.$langs->trans('PurchaseSupplierPackagingHelp').'</span></td></tr>';
        print '<tr><td>'.$langs->trans('PurchaseSupplierPriceForQty').'</td><td><input class="width100" type="text" name="candidate_supplier_price_total" value="'.dol_escape_htmltag((string) ($candidate['supplier_price_total'] ?? '')).'"> '.dol_escape_htmltag($currency).' <span class="opacitymedium">'.$langs->trans('PurchaseSupplierPriceModelHelp').'</span></td></tr>';
        print '<tr><td>'.$langs->trans('VAT').'</td><td><input class="width75" type="text" name="candidate_vat_rate" value="'.dol_escape_htmltag((string) ($candidate['vat_rate'] ?? '')).'"> %</td></tr>';
        print '</table><div class="center"><button class="button button-save" type="submit" onclick="return confirm(\''.dol_escape_js($langs->trans('PurchaseCreateProductConfirm')).'\');">'.$langs->trans('PurchaseCreateProduct').'</button></div></form></details>';
    }
    print '</td></tr>';
}
print '</table></div><br>';

if (!$linkedOrders) {
    print load_fiche_titre($langs->trans('PurchaseCreateDraftOrder'), '', 'supplier_order');
    print '<div class="info marginbottomonly">'.img_picto('', 'info').' '.$langs->trans('PurchaseDraftOrderNotice').'</div>';
    if ($canCreateSupplierOrder) {
        print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="id" value="'.$id.'"><input type="hidden" name="action" value="create_order">';
        $defaultOrderDate = (string) ($workbench['preview']['header']['invoice_date'] ?? date('Y-m-d'));
        print '<table class="border centpercent"><tr><td class="titlefield">'.$langs->trans('OrderDate').'</td><td><input type="date" name="order_date" value="'.dol_escape_htmltag($defaultOrderDate).'"></td></tr>';
        if ($unresolvedIndexes) {
            print '<tr><td>'.$langs->trans('PurchaseUnresolvedLines').'</td><td><div class="warning marginbottomonly">'.$langs->trans('PurchaseFreeTextExplicitHelp').'</div>';
            foreach (($workbench['lines'] ?? array()) as $line) {
                $idx = (int) ($line['workbench_index'] ?? -1);
                if (!in_array($idx, $unresolvedIndexes, true)) {
                    continue;
                }
                print '<label class="block"><input type="checkbox" name="free_text_indexes[]" value="'.$idx.'"> '.dol_escape_htmltag((string) ($line['number'] ?? ($idx + 1)).' — '.(string) ($line['description'] ?? '')).' <span class="opacitymedium">('.$langs->trans('PurchaseKeepAsFreeText').')</span></label>';
            }
            print '</td></tr>';
        }
        print '</table><div class="center tabsAction"><button class="button button-save" type="submit" onclick="return confirm(\''.dol_escape_js($langs->trans('PurchaseCreateDraftOrderConfirm')).'\');">'.$langs->trans('PurchaseCreateDraftOrder').'</button></div></form>';
    } else {
        print '<div class="warning">'.$langs->trans('PurchaseOrderPermissionMissing').'</div>';
    }
} else {
    print '<div class="info">'.$langs->trans('PurchaseOrderAlreadyLinkedNotice').'</div>';
}

llxFooter();
$db->close();
