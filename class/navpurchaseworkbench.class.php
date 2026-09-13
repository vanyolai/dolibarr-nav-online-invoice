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

        return array(
            'available' => !$reasons,
            'reasons' => array_values(array_unique($reasons)),
            'preview' => $preview,
            'partner_id' => $partnerId,
            'partner' => $preview['partner'] ?? null,
            'linked_invoice_id' => (int) ($record->fk_facture_fourn ?? 0),
            'order' => $this->findLinkedOrder((int) $record->rowid),
            'lines' => $lines,
        );
    }

    /**
     * Create a real Dolibarr product/service plus its supplier reference/price.
     *
     * @param array<string,mixed> $line Workbench line rebuilt server-side.
     * @param array<string,mixed> $input User-confirmed candidate values.
     * @return array{id:int,ref:string,label:string,supplier_price_id:int}
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

        $supplier = $this->loadSupplier($supplierId);
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
            $supplierPriceId = $this->writeSupplierPrice($product, $supplier, 0, $supplierRef, $unitPrice, $vatRate, null, $user);
        } catch (Throwable $e) {
            try {
                $product->delete($user);
            } catch (Throwable $cleanupError) {
                dol_syslog('NAV purchase workbench could not roll back product '.$productId.': '.$cleanupError->getMessage(), LOG_ERR);
            }
            throw $e;
        }

        return array(
            'id' => (int) $productId,
            'ref' => (string) $product->ref,
            'label' => (string) $product->label,
            'supplier_price_id' => $supplierPriceId,
        );
    }

    /**
     * Associate an existing product/variant with this supplier reference.
     * Existing supplier price data is not overwritten here.
     *
     * @return int Supplier-price row id.
     */
    public function linkExistingProduct(array $line, int $supplierId, int $productId, User $user): int
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';

        if ($supplierId <= 0 || $productId <= 0) {
            throw new Exception('Supplier and product are required.');
        }
        $supplierRef = trim((string) ($line['supplier_ref'] ?? ''));
        if ($supplierRef === '') {
            throw new Exception('NAV line has no deterministic supplier reference.');
        }
        if (!$this->productIsAccessible($productId)) {
            throw new Exception('Selected Dolibarr product is outside the active entity scope.');
        }

        $product = new ProductFournisseur($this->db);
        if ($product->fetch($productId) <= 0) {
            throw new Exception('Selected Dolibarr product could not be loaded.');
        }
        if ((int) $product->type !== (int) ($line['product_type'] ?? 0)) {
            throw new Exception('Selected Dolibarr product/service type differs from the NAV line type.');
        }

        $supplier = $this->loadSupplier($supplierId);
        $existing = $this->findSupplierPrice($supplierId, $productId, $supplierRef);
        if ($existing === null) {
            $supplierPriceId = $this->writeSupplierPrice(
                $product,
                $supplier,
                0,
                $supplierRef,
                (float) ($line['unit_price_ht'] ?? 0),
                (float) ($line['vat_rate'] ?? 0),
                null,
                $user
            );
        } else {
            $supplierPriceId = (int) $existing['rowid'];
        }

        if (empty($product->status_buy)) {
            $product->status_buy = 1;
            if ($product->update($productId, $user) <= 0) {
                throw new Exception('Product could not be enabled for purchase: '.$this->objectError($product));
            }
        }

        return $supplierPriceId;
    }

    /** Update an already matched supplier-price row after explicit confirmation. */
    public function updateSupplierPrice(array $line, int $supplierId, User $user): void
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';

        $match = is_array($line['product_match'] ?? null) ? $line['product_match'] : array();
        $productInfo = is_array($match['product'] ?? null) ? $match['product'] : null;
        $productId = $productInfo !== null ? (int) ($productInfo['id'] ?? 0) : 0;
        $supplierPriceId = $productInfo !== null ? (int) ($productInfo['supplier_price_id'] ?? 0) : 0;
        if ((string) ($match['status'] ?? '') !== 'matched' || $productId <= 0 || $supplierPriceId <= 0) {
            throw new Exception('NAV line is not linked to an updatable supplier price.');
        }

        $details = $this->supplierPriceDetails($supplierPriceId);
        if ($details === null || (int) $details['fk_soc'] !== $supplierId || (int) $details['fk_product'] !== $productId) {
            throw new Exception('Supplier price relationship changed since the workbench preview.');
        }

        $supplier = $this->loadSupplier($supplierId);
        $product = new ProductFournisseur($this->db);
        if ($product->fetch($productId) <= 0) {
            throw new Exception('Product could not be loaded.');
        }

        $this->writeSupplierPrice(
            $product,
            $supplier,
            $supplierPriceId,
            (string) ($details['ref_fourn'] ?? ($line['supplier_ref'] ?? '')),
            (float) ($line['unit_price_ht'] ?? 0),
            (float) ($details['tva_tx'] ?? ($line['vat_rate'] ?? 0)),
            $details,
            $user
        );
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

    /** @return Societe */
    private function loadSupplier(int $supplierId)
    {
        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
        $supplier = new Societe($this->db);
        if ($supplier->fetch($supplierId) <= 0 || (int) $supplier->entity !== $this->entity) {
            throw new Exception('Supplier could not be loaded.');
        }
        return $supplier;
    }

    /**
     * Create or update a Dolibarr supplier-price row using the native business
     * method. In base-currency NAV imports, multicurrency mirrors the same amount
     * with tx=1 so an enabled multicurrency module cannot zero the base price.
     *
     * @param array<string,mixed>|null $current Existing supplier-price row.
     */
    private function writeSupplierPrice($product, $supplier, int $supplierPriceId, string $supplierRef, float $unitPrice, float $vatRate, ?array $current, User $user): int
    {
        $quantity = $current !== null ? (float) ($current['quantity'] ?? 1) : 1.0;
        if ($quantity <= 0) {
            $quantity = 1.0;
        }
        $totalPrice = $unitPrice * $quantity;

        $product->product_fourn_price_id = $supplierPriceId;
        if ($current !== null && isset($current['packaging'])) {
            $product->product_fourn_packaging = (float) $current['packaging'];
        }

        $result = $product->update_buyprice(
            qty: $quantity,
            buyprice: $totalPrice,
            user: $user,
            price_base_type: 'HT',
            fourn: $supplier,
            availability: (int) ($current['fk_availability'] ?? 0),
            ref_fourn: $supplierRef,
            tva_tx: $vatRate,
            charges: (float) ($current['charges'] ?? 0),
            remise_percent: (float) ($current['remise_percent'] ?? 0),
            remise: (float) ($current['remise'] ?? 0),
            newnpr: !empty($current['info_bits']) ? 1 : 0,
            delivery_time_days: $current['delivery_time_days'] ?? 0,
            supplier_reputation: (string) ($current['supplier_reputation'] ?? ''),
            localtaxes_array: array(
                (string) ($current['localtax1_type'] ?? '0'),
                (float) ($current['localtax1_tx'] ?? 0),
                (string) ($current['localtax2_type'] ?? '0'),
                (float) ($current['localtax2_tx'] ?? 0),
            ),
            newdefaultvatcode: (string) ($current['default_vat_code'] ?? ''),
            multicurrency_buyprice: $totalPrice,
            multicurrency_price_base_type: 'HT',
            multicurrency_tx: 1,
            multicurrency_code: $this->baseCurrency,
            desc_fourn: (string) ($current['desc_fourn'] ?? ''),
            barcode: (string) ($current['barcode'] ?? ''),
            fk_barcode_type: (int) ($current['fk_barcode_type'] ?? 0)
        );
        if ($result < 0) {
            throw new Exception('Supplier price write failed: '.$this->objectError($product));
        }

        $stored = $this->findSupplierPrice((int) $supplier->id, (int) $product->id, $supplierRef);
        if ($stored === null) {
            throw new Exception('Supplier price was written but could not be reloaded.');
        }
        return (int) $stored['rowid'];
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

    private function productIsAccessible(int $productId): bool
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE rowid = '.$productId.' AND entity IN ('.getEntity('product').') LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Product entity check failed: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return (bool) $obj;
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
        if (!$this->db->query($sql)) {
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
