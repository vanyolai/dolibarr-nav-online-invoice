<?php

dol_include_once('/navinvoice/class/navinvoiceoperationpreview.class.php');

/**
 * Purchase workbench for inbound NAV invoices.
 *
 * The workbench deliberately separates master-data preparation from accounting
 * import. It can resolve invoice rows to existing products, create an editable
 * product candidate only after explicit confirmation, maintain the supplier
 * price/reference, and finally reconstruct a DRAFT supplier order. It never
 * validates, approves, orders or receives the supplier order.
 */
class NavPurchaseWorkbench
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var string */
    private $baseCurrency;

    public function __construct($db, int $entity, string $baseCurrency = 'HUF')
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
    }

    /** @return array<string,mixed> */
    public function build(array $parsed, $record, ?array $partnerMatch): array
    {
        $this->ensureSchema();

        $builder = new NavInvoiceOperationPreview($this->db, $this->entity, $this->baseCurrency);
        $preview = $builder->build($parsed, $record, $partnerMatch);

        $direction = strtoupper(trim((string) ($preview['direction'] ?? '')));
        $operation = strtoupper(trim((string) ($preview['operation'] ?? '')));
        $partnerId = is_array($preview['partner'] ?? null) ? (int) ($preview['partner']['id'] ?? 0) : 0;
        $reasons = array();

        if ($direction !== 'INBOUND') {
            $reasons[] = 'inbound_only';
        }
        if ($operation !== 'CREATE') {
            $reasons[] = 'create_only';
        }
        if (!empty($preview['is_advance_invoice'])) {
            $reasons[] = 'advance_invoice';
        }
        if ($partnerId <= 0 || !in_array((string) ($preview['partner_status'] ?? ''), array('tax', 'name_address'), true)) {
            $reasons[] = 'partner_required';
        }
        if (strtoupper((string) ($preview['header']['currency'] ?? '')) !== $this->baseCurrency) {
            $reasons[] = 'currency_unsupported';
        }

        $lines = array();
        foreach (($preview['lines'] ?? array()) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $match = is_array($line['product_match'] ?? null) ? $line['product_match'] : array();
            $product = is_array($match['product'] ?? null) ? $match['product'] : null;
            $priceDetails = null;
            if ($product !== null && !empty($product['supplier_price_id'])) {
                $priceDetails = $this->supplierPriceDetails((int) $product['supplier_price_id']);
            }

            $navUnitPrice = $line['unit_price_ht'] ?? null;
            $currentUnitPrice = $priceDetails !== null ? $this->supplierPriceUnitPrice($priceDetails) : null;
            $priceDiffers = $navUnitPrice !== null && $navUnitPrice !== '' && $currentUnitPrice !== null
                && abs((float) $navUnitPrice - $currentUnitPrice) > 0.00001;

            $line['workbench_index'] = (int) $index;
            $line['supplier_price'] = $priceDetails;
            $line['supplier_price_unit_price'] = $currentUnitPrice;
            $line['supplier_price_differs'] = $priceDiffers;
            $line['skip_for_order'] = !empty($line['informational_zero_line']);
            $line['candidate'] = array(
                'ref' => $this->temporaryProductRef((int) $record->rowid, (string) ($line['number'] ?? $index + 1)),
                'label' => trim((string) ($line['description'] ?? '')),
                'description' => trim((string) ($line['description'] ?? '')),
                'product_type' => (int) ($line['product_type'] ?? 0),
                'unit_id' => (int) ($line['unit_id'] ?? 0),
                'stockable' => (int) ($line['product_type'] ?? 0) === 0 ? 1 : 0,
                'tosell' => 0,
                'supplier_ref' => trim((string) ($line['supplier_ref'] ?? '')),
                'unit_price_ht' => $navUnitPrice,
                'vat_rate' => $line['vat_rate'] ?? 0,
            );
            $lines[] = $line;
        }

        $order = $this->findLinkedOrder((int) $record->rowid);

        return array(
            'available' => !$reasons,
            'reasons' => array_values(array_unique($reasons)),
            'preview' => $preview,
            'partner_id' => $partnerId,
            'partner' => $preview['partner'] ?? null,
            'linked_invoice_id' => (int) ($record->fk_facture_fourn ?? 0),
            'order' => $order,
            'lines' => $lines,
        );
    }

    /**
     * Create a real Dolibarr product/service plus its supplier reference/price.
     * The caller is expected to show/edit every supplied master-data field first.
     *
     * @param array<string,mixed> $line Workbench line rebuilt server-side.
     * @param array<string,mixed> $input User-confirmed candidate values.
     * @return array{id:int,ref:string,label:string}
     */
    public function createProduct(array $line, int $supplierId, array $input, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

        $ref = trim((string) ($input['ref'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $type = (int) ($input['product_type'] ?? ($line['product_type'] ?? 0));
        $unitId = (int) ($input['unit_id'] ?? ($line['unit_id'] ?? 0));
        $stockable = !empty($input['stockable']) && $type === 0 ? 1 : 0;
        $tosell = !empty($input['tosell']) ? 1 : 0;
        $supplierRef = trim((string) ($input['supplier_ref'] ?? ($line['supplier_ref'] ?? '')));
        $unitPrice = (float) ($input['unit_price_ht'] ?? ($line['unit_price_ht'] ?? 0));
        $vatRate = (float) ($input['vat_rate'] ?? ($line['vat_rate'] ?? 0));

        if ($supplierId <= 0) {
            throw new Exception('Supplier is required before creating a product from NAV.');
        }
        if ($ref === '' || $label === '') {
            throw new Exception('Product reference and label are required.');
        }
        if (!in_array($type, array(0, 1), true)) {
            throw new Exception('Unsupported Dolibarr product type.');
        }
        if ($supplierRef === '') {
            throw new Exception('Supplier reference is required for workbench product creation.');
        }

        $supplier = new Societe($this->db);
        if ($supplier->fetch($supplierId) <= 0 || (int) $supplier->entity !== $this->entity) {
            throw new Exception('Supplier could not be loaded for product creation.');
        }

        $product = new ProductFournisseur($this->db);
        $product->entity = $this->entity;
        $product->ref = $ref;
        $product->label = $label;
        $product->description = $description;
        $product->type = $type;
        $product->status = $tosell;
        $product->status_buy = 1;
        $product->stockable_product = $stockable;
        $product->fk_unit = $unitId > 0 ? $unitId : null;
        $product->price_base_type = 'HT';
        $product->price = 0;
        $product->price_ttc = 0;
        $product->tva_tx = $vatRate;

        $productId = $product->create($user);
        if ($productId <= 0) {
            throw new Exception('Dolibarr product creation failed: '.$this->objectError($product));
        }

        try {
            $product->id = (int) $productId;
            $result = $product->update_buyprice(
                qty: 1,
                buyprice: $unitPrice,
                user: $user,
                price_base_type: 'HT',
                fourn: $supplier,
                availability: 0,
                ref_fourn: $supplierRef,
                tva_tx: $vatRate
            );
            if ($result < 0) {
                throw new Exception('Supplier price creation failed: '.$this->objectError($product));
            }
        } catch (Throwable $e) {
            try {
                $product->delete($user);
            } catch (Throwable $cleanupError) {
                dol_syslog('NAV purchase workbench could not roll back product '.$productId.': '.$cleanupError->getMessage(), LOG_ERR);
            }
            throw $e;
        }

        return array('id' => (int) $productId, 'ref' => (string) $product->ref, 'label' => (string) $product->label);
    }

    /**
     * Associate an existing product/variant with this supplier reference.
     * Existing supplier price data is not overwritten here; price differences
     * are handled by the explicit updateSupplierPrice() action.
     */
    public function linkExistingProduct(array $line, int $supplierId, int $productId, User $user): void
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

        if ($supplierId <= 0 || $productId <= 0) {
            throw new Exception('Supplier and product are required.');
        }
        $supplierRef = trim((string) ($line['supplier_ref'] ?? ''));
        if ($supplierRef === '') {
            throw new Exception('NAV line has no deterministic supplier reference.');
        }

        $product = new ProductFournisseur($this->db);
        if ($product->fetch($productId) <= 0 || !in_array((int) $product->entity, getEntity('product'), true)) {
            throw new Exception('Selected Dolibarr product could not be loaded.');
        }
        if ((int) $product->type !== (int) ($line['product_type'] ?? 0)) {
            throw new Exception('Selected Dolibarr product/service type differs from the NAV line type.');
        }

        $supplier = new Societe($this->db);
        if ($supplier->fetch($supplierId) <= 0 || (int) $supplier->entity !== $this->entity) {
            throw new Exception('Supplier could not be loaded.');
        }

        $existing = $this->findSupplierPrice($supplierId, $productId, $supplierRef);
        if ($existing === null) {
            $unitPrice = (float) ($line['unit_price_ht'] ?? 0);
            $vatRate = (float) ($line['vat_rate'] ?? 0);
            $product->id = $productId;
            $result = $product->update_buyprice(
                qty: 1,
                buyprice: $unitPrice,
                user: $user,
                price_base_type: 'HT',
                fourn: $supplier,
                availability: 0,
                ref_fourn: $supplierRef,
                tva_tx: $vatRate
            );
            if ($result < 0) {
                throw new Exception('Supplier reference/price link failed: '.$this->objectError($product));
            }
        }

        if (empty($product->status_buy)) {
            $product->status_buy = 1;
            if ($product->update($user) <= 0) {
                throw new Exception('Product could not be enabled for purchase: '.$this->objectError($product));
            }
        }
    }

    /** Update an already matched supplier-price row after explicit confirmation. */
    public function updateSupplierPrice(array $line, int $supplierId, User $user): void
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';
        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

        $match = is_array($line['product_match'] ?? null) ? $line['product_match'] : array();
        $product = is_array($match['product'] ?? null) ? $match['product'] : null;
        $productId = $product !== null ? (int) ($product['id'] ?? 0) : 0;
        $supplierPriceId = $product !== null ? (int) ($product['supplier_price_id'] ?? 0) : 0;
        if ((string) ($match['status'] ?? '') !== 'matched' || $productId <= 0 || $supplierPriceId <= 0) {
            throw new Exception('NAV line is not linked to an updatable supplier price.');
        }

        $details = $this->supplierPriceDetails($supplierPriceId);
        if ($details === null || (int) $details['fk_soc'] !== $supplierId || (int) $details['fk_product'] !== $productId) {
            throw new Exception('Supplier price relationship changed since the workbench preview.');
        }

        $unitPrice = (float) ($line['unit_price_ht'] ?? 0);
        $quantity = (float) ($details['quantity'] ?? 1);
        if ($quantity <= 0) {
            $quantity = 1;
        }

        $supplier = new Societe($this->db);
        if ($supplier->fetch($supplierId) <= 0) {
            throw new Exception('Supplier could not be loaded.');
        }

        $pf = new ProductFournisseur($this->db);
        if ($pf->fetch($productId) <= 0) {
            throw new Exception('Product could not be loaded.');
        }
        $pf->product_fourn_price_id = $supplierPriceId;
        $pf->product_fourn_packaging = (float) ($details['packaging'] ?? $quantity);

        $result = $pf->update_buyprice(
            qty: $quantity,
            buyprice: $unitPrice * $quantity,
            user: $user,
            price_base_type: 'HT',
            fourn: $supplier,
            availability: (int) ($details['fk_availability'] ?? 0),
            ref_fourn: (string) ($details['ref_fourn'] ?? ($line['supplier_ref'] ?? '')),
            tva_tx: (float) ($line['vat_rate'] ?? ($details['tva_tx'] ?? 0)),
            charges: (float) ($details['charges'] ?? 0),
            remise_percent: (float) ($details['remise_percent'] ?? 0),
            remise: (float) ($details['remise'] ?? 0),
            newnpr: !empty($details['info_bits']) ? 1 : 0,
            delivery_time_days: $details['delivery_time_days'] ?? 0,
            supplier_reputation: (string) ($details['supplier_reputation'] ?? ''),
            localtaxes_array: array(
                (string) ($details['localtax1_type'] ?? '0'),
                (float) ($details['localtax1_tx'] ?? 0),
                (string) ($details['localtax2_type'] ?? '0'),
                (float) ($details['localtax2_tx'] ?? 0),
            ),
            newdefaultvatcode: (string) ($details['default_vat_code'] ?? ''),
            multicurrency_buyprice: (float) ($details['multicurrency_price'] ?? 0),
            multicurrency_price_base_type: 'HT',
            multicurrency_tx: (float) ($details['multicurrency_tx'] ?? 1),
            multicurrency_code: (string) ($details['multicurrency_code'] ?? ''),
            desc_fourn: (string) ($details['desc_fourn'] ?? ''),
            barcode: (string) ($details['barcode'] ?? ''),
            fk_barcode_type: (int) ($details['fk_barcode_type'] ?? 0)
        );
        if ($result < 0) {
            throw new Exception('Supplier price update failed: '.$this->objectError($pf));
        }
    }

    /**
     * Create a supplier order in DRAFT status only.
     *
     * @param array<string,mixed> $workbench Result of build(), rebuilt server-side.
     * @param array<int,int> $freeTextIndexes Explicitly accepted unresolved rows.
     * @return array{id:int,ref:string,url:string}
     */
    public function createDraftOrder(array $workbench, $record, string $orderDate, array $freeTextIndexes, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';

        if (empty($workbench['available'])) {
            throw new Exception('Purchase workbench is not available for this NAV invoice.');
        }
        if (!empty($workbench['order']['id'])) {
            throw new Exception('A supplier order is already linked to this NAV invoice.');
        }

        $supplierId = (int) ($workbench['partner_id'] ?? 0);
        if ($supplierId <= 0) {
            throw new Exception('Supplier is required before order creation.');
        }

        $timestamp = $this->dateToTimestamp($orderDate);
        if ($timestamp <= 0) {
            throw new Exception('Invalid supplier order date.');
        }

        $freeTextIndexes = array_values(array_unique(array_map('intval', $freeTextIndexes)));
        $resolvedLines = array();
        foreach (($workbench['lines'] ?? array()) as $line) {
            if (!is_array($line) || !empty($line['skip_for_order'])) {
                continue;
            }
            $index = (int) ($line['workbench_index'] ?? -1);
            $match = is_array($line['product_match'] ?? null) ? $line['product_match'] : array();
            $product = is_array($match['product'] ?? null) ? $match['product'] : null;
            $matched = (string) ($match['status'] ?? '') === 'matched' && !empty($match['auto_link']) && $product !== null;
            if (!$matched && !in_array($index, $freeTextIndexes, true)) {
                throw new Exception('Every unresolved invoice line must be explicitly accepted as a free-text order line or linked to a product.');
            }
            $line['_order_product_id'] = $matched ? (int) ($product['id'] ?? 0) : 0;
            $line['_order_supplier_price_id'] = $matched ? (int) ($product['supplier_price_id'] ?? 0) : 0;
            $resolvedLines[] = $line;
        }
        if (!$resolvedLines) {
            throw new Exception('No orderable NAV invoice lines remain.');
        }

        $order = new CommandeFournisseur($this->db);
        $order->socid = $supplierId;
        $order->fourn_id = $supplierId;
        $order->date = $timestamp;
        $order->ref = '(PROV)';
        $order->source = 0;
        $order->note_private = "NAV Online Invoice purchase reconstruction\n"
            .'invoice='.(string) ($workbench['preview']['invoice_number'] ?? $record->invoice_number ?? '')."\n"
            .'mirror_rowid='.(int) $record->rowid;

        $linkedInvoiceId = (int) ($workbench['linked_invoice_id'] ?? 0);
        if ($linkedInvoiceId > 0) {
            $order->linked_objects = array('invoice_supplier' => $linkedInvoiceId);
        }

        $orderId = $order->create($user);
        if ($orderId <= 0) {
            throw new Exception('Supplier order creation failed: '.$this->objectError($order));
        }

        try {
            foreach ($resolvedLines as $line) {
                $qty = (float) ($line['quantity'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $result = $order->addline(
                    (string) ($line['description'] ?? ''),
                    (float) ($line['unit_price_ht'] ?? 0),
                    $qty,
                    (float) ($line['vat_rate'] ?? 0),
                    0.0,
                    0.0,
                    (int) ($line['_order_product_id'] ?? 0),
                    (int) ($line['_order_supplier_price_id'] ?? 0),
                    (string) ($line['supplier_ref'] ?? ''),
                    0.0,
                    'HT',
                    0.0,
                    (int) ($line['product_type'] ?? 0),
                    0,
                    0,
                    null,
                    null,
                    array(),
                    !empty($line['unit_id']) ? (int) $line['unit_id'] : null
                );
                if ($result <= 0) {
                    throw new Exception('Supplier order line creation failed: '.$this->objectError($order));
                }
            }

            $this->linkOrder((int) $record->rowid, (int) $orderId);
        } catch (Throwable $e) {
            try {
                $order->delete($user);
            } catch (Throwable $cleanupError) {
                dol_syslog('NAV purchase workbench could not roll back supplier order '.$orderId.': '.$cleanupError->getMessage(), LOG_ERR);
            }
            throw $e;
        }

        if ($order->fetch((int) $orderId) <= 0) {
            throw new Exception('Created supplier order could not be reloaded.');
        }
        if ((int) ($order->status ?? $order->statut ?? -1) !== CommandeFournisseur::STATUS_DRAFT) {
            throw new Exception('Reconstructed supplier order unexpectedly left draft status.');
        }

        return array(
            'id' => (int) $orderId,
            'ref' => (string) $order->ref,
            'url' => DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $orderId,
        );
    }

    /** @return array<string,mixed>|null */
    public function findLinkedOrder(int $mirrorId): ?array
    {
        $this->ensureSchema();
        $sql = 'SELECT l.fk_commande_fourn, c.ref, c.fk_statut';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_purchase_link AS l';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'commande_fournisseur AS c ON c.rowid = l.fk_commande_fourn';
        $sql .= ' WHERE l.entity = '.$this->entity.' AND l.fk_navinvoice_invoice = '.$mirrorId;
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Could not read NAV purchase-workbench link: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }
        if (empty($obj->ref)) {
            $this->deleteLinkByMirror($mirrorId);
            return null;
        }
        return array(
            'id' => (int) $obj->fk_commande_fourn,
            'ref' => (string) $obj->ref,
            'status' => (int) $obj->fk_statut,
            'url' => DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $obj->fk_commande_fourn,
        );
    }

    /** @return array<string,mixed>|null */
    private function findSupplierPrice(int $supplierId, int $productId, string $supplierRef): ?array
    {
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
        $sql .= ' WHERE entity IN ('.getEntity('productsupplierprice').')';
        $sql .= ' AND fk_soc = '.$supplierId.' AND fk_product = '.$productId;
        $sql .= " AND LOWER(TRIM(ref_fourn)) = LOWER('".$this->db->escape($supplierRef)."')";
        $sql .= ' ORDER BY rowid LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Supplier price lookup failed: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (array) $obj : null;
    }

    /** @return array<string,mixed>|null */
    private function supplierPriceDetails(int $supplierPriceId): ?array
    {
        if ($supplierPriceId <= 0) {
            return null;
        }
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
        $sql .= ' WHERE rowid = '.$supplierPriceId;
        $sql .= ' AND entity IN ('.getEntity('productsupplierprice').')';
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Supplier price lookup failed: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (array) $obj : null;
    }

    /** @param array<string,mixed> $details */
    private function supplierPriceUnitPrice(array $details): ?float
    {
        if (isset($details['unitprice']) && $details['unitprice'] !== null && $details['unitprice'] !== '') {
            return (float) $details['unitprice'];
        }
        $qty = (float) ($details['quantity'] ?? 0);
        if ($qty > 0 && isset($details['price'])) {
            return (float) $details['price'] / $qty;
        }
        return null;
    }

    private function temporaryProductRef(int $mirrorId, string $lineNumber): string
    {
        $suffix = preg_replace('/[^A-Za-z0-9_-]+/', '-', trim($lineNumber));
        $suffix = trim((string) $suffix, '-');
        if ($suffix === '') {
            $suffix = 'LINE';
        }
        return substr('NAVTMP-'.$mirrorId.'-'.$suffix, 0, 120);
    }

    private function dateToTimestamp(string $date): int
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if (!$parsed || $parsed->format('Y-m-d') !== trim($date)) {
            return 0;
        }
        return $parsed->getTimestamp();
    }

    private function linkOrder(int $mirrorId, int $orderId): void
    {
        $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'navinvoice_purchase_link(entity, fk_navinvoice_invoice, fk_commande_fourn, datec) VALUES (';
        $sql .= $this->entity.', '.$mirrorId.', '.$orderId.', ';
        $sql .= "'".$this->db->idate(dol_now())."')";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Could not link supplier order to NAV invoice: '.$this->db->lasterror());
        }
    }

    private function deleteLinkByMirror(int $mirrorId): void
    {
        $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'navinvoice_purchase_link';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_navinvoice_invoice = '.$mirrorId;
        $this->db->query($sql);
    }

    private function ensureSchema(): void
    {
        $sql = 'CREATE TABLE IF NOT EXISTS '.MAIN_DB_PREFIX.'navinvoice_purchase_link (';
        $sql .= 'rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
        $sql .= 'entity INTEGER NOT NULL,';
        $sql .= 'fk_navinvoice_invoice INTEGER NOT NULL,';
        $sql .= 'fk_commande_fourn INTEGER NOT NULL,';
        $sql .= 'datec DATETIME NOT NULL,';
        $sql .= 'tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
        $sql .= 'UNIQUE KEY uk_navinvoice_purchase_mirror (entity, fk_navinvoice_invoice),';
        $sql .= 'UNIQUE KEY uk_navinvoice_purchase_order (entity, fk_commande_fourn),';
        $sql .= 'KEY idx_navinvoice_purchase_order (fk_commande_fourn)';
        $sql .= ') ENGINE=InnoDB';
        if (!$this->db->query($sql)) {
            throw new Exception('Could not initialize NAV purchase-workbench schema: '.$this->db->lasterror());
        }
    }

    private function objectError($object): string
    {
        $parts = array();
        if (!empty($object->error)) {
            $parts[] = (string) $object->error;
        }
        if (!empty($object->errors) && is_array($object->errors)) {
            $parts = array_merge($parts, array_map('strval', $object->errors));
        }
        return $parts ? implode('; ', $parts) : $this->db->lasterror();
    }
}
