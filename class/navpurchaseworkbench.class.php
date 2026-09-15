<?php

dol_include_once('/navinvoice/class/navinvoiceoperationpreview.class.php');

/**
 * Purchase workbench for inbound NAV invoices.
 *
 * The workbench deliberately separates master-data preparation from accounting
 * import. It can resolve invoice rows to existing products, create editable
 * product candidates, maintain supplier references/prices and reconstruct DRAFT
 * supplier orders. It never validates, approves, orders or receives an order.
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
            $productId = $product !== null ? (int) ($product['id'] ?? 0) : 0;
            $supplierRef = trim((string) ($line['supplier_ref'] ?? ''));
            $priceSelection = null;
            $productDetails = null;

            if ($partnerId > 0 && $productId > 0) {
                $productDetails = $this->productDetails($productId);
                $priceSelection = $this->selectSupplierPriceForLine($partnerId, $productId, $supplierRef, $line);
                if ($priceSelection !== null && $product !== null) {
                    // The product matcher intentionally identifies the product, not
                    // the correct supplier-price tier. The workbench chooses the
                    // most appropriate tier from unit identity, quantity and price.
                    $match['product']['supplier_price_id'] = (int) $priceSelection['price']['rowid'];
                    $line['product_match'] = $match;
                    $product = $match['product'];
                }
            }

            $normalization = $this->normalizePurchaseLine(
                $line,
                $priceSelection !== null ? $priceSelection['price'] : null,
                $productDetails
            );

            $line['workbench_index'] = (int) $index;
            $line['supplier_price'] = $priceSelection !== null ? $priceSelection['price'] : null;
            $line['supplier_price_unit_price'] = $normalization['supplier_unit_price'];
            $line['supplier_price_differs'] = $normalization['price_differs'];
            $line['purchase_normalization'] = $normalization;
            $line['skip_for_order'] = !empty($line['informational_zero_line']);
            $line['candidate'] = array(
                'ref' => $this->temporaryProductRef((int) $record->rowid, (string) ($line['number'] ?? $index + 1)),
                'label' => trim((string) ($line['description'] ?? '')),
                'description' => trim((string) ($line['description'] ?? '')),
                'product_type' => (int) ($line['product_type'] ?? 0),
                'unit_id' => (int) ($line['unit_id'] ?? 0),
                'stockable' => (int) ($line['product_type'] ?? 0) === 0 ? 1 : 0,
                'tosell' => 0,
                'supplier_ref' => $supplierRef,
                // Dolibarr supplier prices store the list price for the minimum
                // quantity and a separate remise_percent. Preserve the same NAV
                // representation instead of flattening the discount into price.
                'supplier_price_total' => $line['unit_price_ht'] ?? null,
                'supplier_quantity' => 1,
                'supplier_packaging' => 1,
                'supplier_discount_percent' => (float) ($line['discount_percent'] ?? 0),
                'unit_price_ht' => $line['unit_price_ht'] ?? null,
                'vat_rate' => $line['vat_rate'] ?? 0,
            );
            $lines[] = $line;
        }

        $linkedOrders = $this->findLinkedOrders((int) $record->rowid);

        return array(
            'available' => !$reasons,
            'reasons' => array_values(array_unique($reasons)),
            'preview' => $preview,
            'partner_id' => $partnerId,
            'partner' => $preview['partner'] ?? null,
            'linked_invoice_id' => (int) ($record->fk_facture_fourn ?? 0),
            // Backward compatibility for the initial workbench UI.
            'order' => $linkedOrders ? $linkedOrders[0] : null,
            'orders' => $linkedOrders,
            'candidate_orders' => $partnerId > 0 ? $this->candidateOrders($partnerId, (int) $record->rowid) : array(),
            'lines' => $lines,
        );
    }

    /**
     * Create a real Dolibarr product/service plus its supplier reference/price.
     *
     * Optional supplier_quantity / supplier_packaging / supplier_price_total
     * inputs mirror Dolibarr's native MOQ supplier-price model. Older callers
     * that only send unit_price_ht remain compatible and create a qty=1 tier.
     *
     * @param array<string,mixed> $line Workbench line rebuilt server-side.
     * @param array<string,mixed> $input User-confirmed candidate values.
     * @return array{id:int,ref:string,label:string,supplier_price_id:int}
     */
    public function createProduct(array $line, int $supplierId, array $input, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.product.class.php';

        $ref = trim((string) ($input['ref'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $sourceType = (int) ($line['product_type'] ?? 0);
        $type = isset($input['product_type']) ? (int) $input['product_type'] : $sourceType;
        $unitId = (int) ($input['unit_id'] ?? ($line['unit_id'] ?? 0));
        $stockable = !empty($input['stockable']) && $type === 0 ? 1 : 0;
        $tosell = !empty($input['tosell']) ? 1 : 0;
        $supplierRef = trim((string) ($input['supplier_ref'] ?? ($line['supplier_ref'] ?? '')));
        $vatRate = (float) ($input['vat_rate'] ?? ($line['vat_rate'] ?? 0));
        $discountPercent = $this->discountPercent((float) ($input['supplier_discount_percent'] ?? ($line['discount_percent'] ?? 0)));

        $quantity = (float) ($input['supplier_quantity'] ?? 1);
        if ($quantity <= 0) {
            $quantity = 1;
        }
        $packaging = (float) ($input['supplier_packaging'] ?? $quantity);
        if ($packaging <= 0) {
            $packaging = $quantity;
        }

        $legacyUnitPrice = (float) ($input['unit_price_ht'] ?? ($line['unit_price_ht'] ?? 0));
        $totalPrice = array_key_exists('supplier_price_total', $input)
            ? (float) $input['supplier_price_total']
            : $legacyUnitPrice * $quantity;
        $unitPrice = $quantity > 0 ? $totalPrice / $quantity : $legacyUnitPrice;

        if ($supplierId <= 0) {
            throw new Exception('Supplier is required before creating a product from NAV.');
        }
        if ($ref === '' || $label === '') {
            throw new Exception('Product reference and label are required.');
        }
        if (!in_array($type, array(0, 1), true) || $type !== $sourceType) {
            throw new Exception('Product/service type must remain consistent with the NAV line.');
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
            $supplierPriceId = $this->writeSupplierPrice(
                $product,
                $supplier,
                0,
                $supplierRef,
                $unitPrice,
                $vatRate,
                null,
                $user,
                $quantity,
                $packaging,
                $totalPrice,
                $discountPercent
            );
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

    /** Associate an existing product/variant with this supplier reference. */
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
                $user,
                null,
                null,
                null,
                $this->discountPercent((float) ($line['discount_percent'] ?? 0))
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

        $normalization = $this->normalizePurchaseLine($line, $details, $this->productDetails($productId));
        if (empty($normalization['price_differs'])) {
            return;
        }
        if (empty($normalization['can_update_price'])) {
            throw new Exception('Supplier price cannot be updated safely until the NAV-to-Dolibarr quantity conversion is resolved.');
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
            (float) ($normalization['normalized_unit_price'] ?? ($line['unit_price_ht'] ?? 0)),
            (float) ($details['tva_tx'] ?? ($line['vat_rate'] ?? 0)),
            $details,
            $user,
            null,
            null,
            null,
            $this->discountPercent((float) ($line['discount_percent'] ?? 0))
        );
    }

    /**
     * Create a supplier order in DRAFT status only.
     *
     * Product-linked lines use normalized Dolibarr quantities/unit prices. An
     * invoice that is already linked to any supplier order is not reconstructed
     * into another order automatically; additional relations should use
     * linkExistingOrder().
     *
     * @param array<int,int> $freeTextIndexes Explicitly accepted unresolved rows.
     * @return array{id:int,ref:string,url:string}
     */
    public function createDraftOrder(array $workbench, $record, string $orderDate, array $freeTextIndexes, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';

        if (empty($workbench['available'])) {
            throw new Exception('Purchase workbench is not available for this NAV invoice.');
        }
        if (!empty($workbench['orders'])) {
            throw new Exception('At least one supplier order is already linked to this NAV invoice.');
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

            $normalization = is_array($line['purchase_normalization'] ?? null)
                ? $line['purchase_normalization']
                : $this->normalizePurchaseLine($line, null, null);

            if ($matched && empty($normalization['quantity_mapping_safe'])) {
                throw new Exception('Matched product has incompatible or unresolved NAV/Dolibarr unit semantics; supplier order quantity cannot be reconstructed safely.');
            }

            $line['_order_product_id'] = $matched ? (int) ($product['id'] ?? 0) : 0;
            $line['_order_supplier_price_id'] = $matched ? (int) ($product['supplier_price_id'] ?? 0) : 0;
            $line['_order_qty'] = $matched ? (float) $normalization['normalized_quantity'] : (float) ($line['quantity'] ?? 0);
            $line['_order_unit_price'] = $matched ? (float) $normalization['normalized_unit_price'] : (float) ($line['unit_price_ht'] ?? 0);
            $line['_order_discount_percent'] = $this->discountPercent((float) ($line['discount_percent'] ?? 0));
            $line['_order_unit_id'] = $matched && !empty($normalization['product_unit_id'])
                ? (int) $normalization['product_unit_id']
                : (!empty($line['unit_id']) ? (int) $line['unit_id'] : null);
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
                $qty = (float) ($line['_order_qty'] ?? 0);
                if ($qty <= 0) {
                    continue;
                }
                $result = $order->addline(
                    (string) ($line['description'] ?? ''),
                    (float) ($line['_order_unit_price'] ?? 0),
                    $qty,
                    (float) ($line['vat_rate'] ?? 0),
                    0.0,
                    0.0,
                    (int) ($line['_order_product_id'] ?? 0),
                    (int) ($line['_order_supplier_price_id'] ?? 0),
                    (string) ($line['supplier_ref'] ?? ''),
                    (float) ($line['_order_discount_percent'] ?? 0),
                    'HT',
                    0.0,
                    (int) ($line['product_type'] ?? 0),
                    0,
                    0,
                    null,
                    null,
                    array(),
                    $line['_order_unit_id']
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

    /** Link this NAV invoice to an already existing order without modifying it. */
    public function linkExistingOrder(array $workbench, $record, int $orderId, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';

        if (empty($workbench['available'])) {
            throw new Exception('Purchase workbench is not available for this NAV invoice.');
        }
        if ($orderId <= 0) {
            throw new Exception('Supplier order is required.');
        }

        $supplierId = (int) ($workbench['partner_id'] ?? 0);
        $order = new CommandeFournisseur($this->db);
        if ($order->fetch($orderId) <= 0) {
            throw new Exception('Selected supplier order could not be loaded.');
        }
        if ((int) ($order->socid ?? $order->fourn_id ?? 0) !== $supplierId) {
            throw new Exception('Selected supplier order belongs to a different supplier.');
        }
        if (in_array((int) ($order->status ?? $order->statut ?? 0), array(6, 7, 9), true)) {
            throw new Exception('Canceled/refused supplier orders cannot be linked from the workbench.');
        }

        $this->linkOrder((int) $record->rowid, $orderId);

        $linkedInvoiceId = (int) ($workbench['linked_invoice_id'] ?? 0);
        if ($linkedInvoiceId > 0) {
            try {
                $order->add_object_linked('invoice_supplier', $linkedInvoiceId);
            } catch (Throwable $e) {
                dol_syslog('NAV purchase workbench could not create core supplier order/invoice link: '.$e->getMessage(), LOG_WARNING);
            }
        }

        return array(
            'id' => $orderId,
            'ref' => (string) $order->ref,
            'url' => DOL_URL_ROOT.'/fourn/commande/card.php?id='.$orderId,
        );
    }

    /** @return array<int,array<string,mixed>> */
    public function findLinkedOrders(int $mirrorId): array
    {
        $this->ensureSchema();
        $sql = 'SELECT l.fk_commande_fourn, c.ref, c.fk_statut, c.date_commande, c.date_creation, c.total_ht, c.billed';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_purchase_link AS l';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'commande_fournisseur AS c ON c.rowid = l.fk_commande_fourn';
        $sql .= ' WHERE l.entity = '.$this->entity.' AND l.fk_navinvoice_invoice = '.$mirrorId;
        $sql .= ' ORDER BY l.rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Could not read NAV purchase-workbench links: '.$this->db->lasterror());
        }

        $orders = array();
        $stale = array();
        while ($obj = $this->db->fetch_object($resql)) {
            if (empty($obj->ref)) {
                $stale[] = (int) $obj->fk_commande_fourn;
                continue;
            }
            $orders[] = array(
                'id' => (int) $obj->fk_commande_fourn,
                'ref' => (string) $obj->ref,
                'status' => (int) $obj->fk_statut,
                'date' => (string) ($obj->date_commande ?: $obj->date_creation),
                'total_ht' => (float) $obj->total_ht,
                'billed' => (int) $obj->billed,
                'url' => DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $obj->fk_commande_fourn,
            );
        }
        $this->db->free($resql);

        foreach ($stale as $orderId) {
            $this->deleteLink($mirrorId, $orderId);
        }
        return $orders;
    }

    /** Backward-compatible single-order accessor. */
    public function findLinkedOrder(int $mirrorId): ?array
    {
        $orders = $this->findLinkedOrders($mirrorId);
        return $orders ? $orders[0] : null;
    }

    /** @return array<int,array<string,mixed>> */
    public function candidateOrders(int $supplierId, int $mirrorId = 0): array
    {
        if ($supplierId <= 0) {
            return array();
        }

        $linked = array();
        if ($mirrorId > 0) {
            foreach ($this->findLinkedOrders($mirrorId) as $order) {
                $linked[(int) $order['id']] = true;
            }
        }

        $sql = 'SELECT rowid, ref, fk_statut, date_commande, date_creation, total_ht, billed';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'commande_fournisseur';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_soc = '.$supplierId;
        $sql .= ' AND fk_statut NOT IN (6,7,9)';
        $sql .= ' ORDER BY COALESCE(date_commande, date_creation) DESC, rowid DESC LIMIT 50';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Could not load supplier-order candidates: '.$this->db->lasterror());
        }

        $orders = array();
        while ($obj = $this->db->fetch_object($resql)) {
            if (isset($linked[(int) $obj->rowid])) {
                continue;
            }
            $orders[] = array(
                'id' => (int) $obj->rowid,
                'ref' => (string) $obj->ref,
                'status' => (int) $obj->fk_statut,
                'date' => (string) ($obj->date_commande ?: $obj->date_creation),
                'total_ht' => (float) $obj->total_ht,
                'billed' => (int) $obj->billed,
                'url' => DOL_URL_ROOT.'/fourn/commande/card.php?id='.(int) $obj->rowid,
            );
        }
        $this->db->free($resql);
        return $orders;
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
     * Choose the supplier price tier that best explains the invoice line.
     *
     * Dolibarr product_fournisseur_price.quantity is a minimum quantity for a
     * price tier, while product_fournisseur_price.packaging is only the ordering
     * multiple/rounding step. Neither field is ever a physical unit-conversion
     * factor. Unit conversion must come from explicit unit metadata only.
     *
     * @return array{price:array<string,mixed>,normalization:array<string,mixed>}|null
     */
    private function selectSupplierPriceForLine(int $supplierId, int $productId, string $supplierRef, array $line): ?array
    {
        $prices = $this->supplierPrices($supplierId, $productId, $supplierRef);
        if (!$prices) {
            return null;
        }

        $product = $this->productDetails($productId);
        $best = null;
        $bestScore = -INF;
        foreach ($prices as $price) {
            $normalization = $this->normalizePurchaseLine($line, $price, $product);
            $score = (float) ($normalization['match_score'] ?? 0);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = array('price' => $price, 'normalization' => $normalization);
            }
        }
        return $best;
    }

    /**
     * Normalize NAV quantity/unit-price semantics to Dolibarr product units.
     *
     * The invoice line has two price concepts: NAV unitPrice is the list price,
     * while lineNetAmount/quantity is the authoritative effective unit price
     * after lineDiscountData. Dolibarr supplier prices have the same distinction
     * (unitprice + remise_percent/remise). Compare both the effective price and
     * the list-price/discount representation so a historical flattened price can
     * be repaired back to the NAV-native list price plus discount.
     *
     * MOQ and packaging are commercial ordering constraints, not unit conversion.
     * Quantities remain 1:1 unless explicit NAV and Dolibarr unit metadata both
     * exist and contradict one another; in that case automatic price/order writes
     * are blocked rather than guessed from prices or packaging.
     *
     * @param array<string,mixed>|null $price
     * @param array<string,mixed>|null $product
     * @return array<string,mixed>
     */
    private function normalizePurchaseLine(array $line, ?array $price, ?array $product): array
    {
        $navQty = (float) ($line['quantity'] ?? 0);
        $navListUnit = (float) ($line['unit_price_ht'] ?? 0);
        $navDiscount = $this->discountPercent((float) ($line['discount_percent'] ?? 0));
        $navEffectiveUnit = $navListUnit * (1.0 - ($navDiscount / 100.0));
        if ($navQty != 0.0 && isset($line['net']) && $line['net'] !== null && $line['net'] !== '' && is_numeric($line['net'])) {
            $navEffectiveUnit = (float) $line['net'] / $navQty;
        }

        $supplierQty = $price !== null ? (float) ($price['quantity'] ?? 0) : 0.0;
        $packaging = $price !== null ? (float) ($price['packaging'] ?? 0) : 0.0;
        $supplierTotal = $price !== null ? (float) ($price['price'] ?? 0) : 0.0;
        $supplierListUnit = $price !== null ? $this->supplierPriceUnitPrice($price) : null;
        $supplierDiscount = $price !== null ? $this->discountPercent((float) ($price['remise_percent'] ?? 0)) : 0.0;
        $supplierFixedDiscount = $price !== null ? (float) ($price['remise'] ?? 0) : 0.0;
        $supplierEffectiveUnit = $price !== null ? $this->supplierPriceEffectiveUnitPrice($price) : null;

        $unitsEnabled = (bool) getDolGlobalInt('PRODUCT_USE_UNITS');
        $lineUnitId = (int) ($line['unit_id'] ?? 0);
        $productUnitId = $product !== null ? (int) ($product['fk_unit'] ?? 0) : 0;
        $sameUnit = $lineUnitId > 0 && $productUnitId > 0 && $lineUnitId === $productUnitId;
        $explicitUnitMismatch = $unitsEnabled && $lineUnitId > 0 && $productUnitId > 0 && !$sameUnit;
        $quantityMappingSafe = true;
        $factor = 1.0;
        $mode = 'implicit_unit';
        $score = 0.0;

        if (!$unitsEnabled) {
            // With Dolibarr unit management disabled there is no second unit
            // system to convert into. Keep the NAV numerical quantity verbatim.
            $mode = 'units_disabled';
            $score = 180.0;
        } elseif ($sameUnit) {
            $mode = 'unit';
            $score = 220.0;
        } elseif ($productUnitId <= 0) {
            // A product without fk_unit uses Dolibarr's implicit base quantity.
            // There is no configured target unit that could contradict the NAV
            // quantity, so preserve the invoice quantity 1:1. MOQ/packaging must
            // never be promoted to a conversion factor here.
            $mode = 'product_unit_unset';
            $score = 180.0;
        } elseif ($lineUnitId <= 0) {
            // Dolibarr has an explicit product unit but the NAV unit could not be
            // resolved. Do not infer equivalence from a coincidentally equal price.
            $mode = 'nav_unit_unresolved';
            $quantityMappingSafe = false;
            $score = 20.0;
        } elseif ($explicitUnitMismatch) {
            $mode = 'unit_mismatch';
            $quantityMappingSafe = false;
            $score = 0.0;
        }

        // Price proximity helps select among multiple supplier-price tiers, but
        // never establishes unit identity. MOQ selects the applicable tier and
        // packaging is used only as a weak ordering-multiple preference.
        if ($supplierEffectiveUnit !== null) {
            $denom = max(abs($navEffectiveUnit), abs($supplierEffectiveUnit), 1.0);
            $distance = min(100.0, 100.0 * abs($navEffectiveUnit - $supplierEffectiveUnit) / $denom);
            $score += $quantityMappingSafe ? (40.0 - min(40.0, $distance * 0.4)) : (20.0 - min(20.0, $distance * 0.2));
        }
        if ($supplierQty <= 0.0 || abs($navQty) + 0.000001 >= $supplierQty) {
            $score += 30.0;
        } else {
            $score -= 30.0;
        }
        if ($packaging > 0.0 && abs($navQty) > 0.0) {
            $multiple = abs($navQty) / $packaging;
            if (abs($multiple - round($multiple)) <= 0.000001) {
                $score += 5.0;
            }
        }

        // The invariant is deliberately simple: there is no inferred factor.
        // Physical unit conversion requires explicit conversion metadata, which
        // the supplier MOQ/packaging model does not provide.
        $normalizedQty = $navQty;
        $normalizedListUnit = $navListUnit;
        $normalizedEffectiveUnit = $navEffectiveUnit;

        $effectiveDiffers = $supplierEffectiveUnit !== null
            && !$this->moneyEqual($normalizedEffectiveUnit, $supplierEffectiveUnit);
        $listDiffers = $supplierListUnit !== null
            && !$this->moneyEqual($normalizedListUnit, $supplierListUnit);
        $discountDiffers = $price !== null && abs($navDiscount - $supplierDiscount) > 0.000001;
        $fixedDiscountDiffers = $price !== null && abs($supplierFixedDiscount) > 0.000001;
        $priceDiffers = $supplierListUnit !== null
            && ($effectiveDiffers || $listDiffers || $discountDiffers || $fixedDiscountDiffers);
        $canUpdatePrice = $priceDiffers && $quantityMappingSafe;

        return array(
            'mode' => $mode,
            'match_score' => $score,
            'factor' => $factor,
            'nav_quantity' => $navQty,
            'nav_unit_price' => $navListUnit,
            'nav_effective_unit_price' => $navEffectiveUnit,
            'nav_discount_percent' => $navDiscount,
            'normalized_quantity' => $normalizedQty,
            'normalized_unit_price' => $normalizedListUnit,
            'normalized_effective_unit_price' => $normalizedEffectiveUnit,
            'supplier_quantity' => $supplierQty,
            'supplier_packaging' => $packaging,
            'supplier_price_total' => $supplierTotal,
            'supplier_unit_price' => $supplierListUnit,
            'supplier_effective_unit_price' => $supplierEffectiveUnit,
            'supplier_discount_percent' => $supplierDiscount,
            'supplier_fixed_discount' => $supplierFixedDiscount,
            'product_unit_id' => $productUnitId,
            'line_unit_id' => $lineUnitId,
            'units_enabled' => $unitsEnabled,
            'unit_identity' => $sameUnit,
            'explicit_unit_mismatch' => $explicitUnitMismatch,
            'quantity_mapping_safe' => $quantityMappingSafe,
            'price_differs' => $priceDiffers,
            'can_update_price' => $canUpdatePrice,
        );
    }

    /**
     * Create/update a Dolibarr supplier-price row using the native business API.
     *
     * @param array<string,mixed>|null $current Existing supplier-price row.
     */
    private function writeSupplierPrice(
        $product,
        $supplier,
        int $supplierPriceId,
        string $supplierRef,
        float $unitPrice,
        float $vatRate,
        ?array $current,
        User $user,
        ?float $quantityOverride = null,
        ?float $packagingOverride = null,
        ?float $totalPriceOverride = null,
        ?float $discountPercentOverride = null
    ): int {
        $quantity = $quantityOverride ?? ($current !== null ? (float) ($current['quantity'] ?? 1) : 1.0);
        if ($quantity <= 0) {
            $quantity = 1.0;
        }
        $totalPrice = $totalPriceOverride ?? ($unitPrice * $quantity);

        // Packaging is an ordering multiple, independent from MOQ and unit
        // semantics. Preserve the stored value exactly on price-only updates;
        // never manufacture packaging=MOQ as a side effect of updating price.
        if ($packagingOverride !== null) {
            $packaging = max(0.0, $packagingOverride);
        } elseif ($current !== null) {
            $packaging = max(0.0, (float) ($current['packaging'] ?? 0));
        } else {
            $packaging = 0.0;
        }

        $discountPercent = $discountPercentOverride !== null
            ? $this->discountPercent($discountPercentOverride)
            : $this->discountPercent((float) ($current['remise_percent'] ?? 0));
        // A NAV line discount represented as a percentage supersedes any old
        // fixed supplier-price discount amount to avoid applying two discounts.
        $discountAmount = $discountPercentOverride !== null ? 0.0 : (float) ($current['remise'] ?? 0);

        $product->product_fourn_price_id = $supplierPriceId;
        $product->product_fourn_packaging = $packaging;

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
            remise_percent: $discountPercent,
            remise: $discountAmount,
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

        $stored = $this->findSupplierPrice((int) $supplier->id, (int) $product->id, $supplierRef, $quantity);
        if ($stored === null) {
            throw new Exception('Supplier price was written but could not be reloaded.');
        }
        return (int) $stored['rowid'];
    }

    /** @return array<int,array<string,mixed>> */
    private function supplierPrices(int $supplierId, int $productId, string $supplierRef): array
    {
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
        $sql .= ' WHERE entity IN ('.getEntity('productsupplierprice').')';
        $sql .= ' AND fk_soc = '.$supplierId.' AND fk_product = '.$productId;
        if ($supplierRef !== '') {
            $sql .= " AND LOWER(TRIM(ref_fourn)) = LOWER('".$this->db->escape($supplierRef)."')";
        }
        $sql .= ' ORDER BY quantity, rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Supplier price lookup failed: '.$this->db->lasterror());
        }
        $rows = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = (array) $obj;
        }
        $this->db->free($resql);
        return $rows;
    }

    /** @return array<string,mixed>|null */
    private function findSupplierPrice(int $supplierId, int $productId, string $supplierRef, ?float $quantity = null): ?array
    {
        $prices = $this->supplierPrices($supplierId, $productId, $supplierRef);
        if ($quantity !== null) {
            foreach ($prices as $price) {
                if (abs((float) ($price['quantity'] ?? 0) - $quantity) <= 0.000001) {
                    return $price;
                }
            }
        }
        return $prices ? $prices[0] : null;
    }

    /** @return array<string,mixed>|null */
    private function supplierPriceDetails(int $supplierPriceId): ?array
    {
        if ($supplierPriceId <= 0) {
            return null;
        }
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
        $sql .= ' WHERE rowid = '.$supplierPriceId;
        $sql .= ' AND entity IN ('.getEntity('productsupplierprice').') LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Supplier price lookup failed: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (array) $obj : null;
    }

    /** @return array<string,mixed>|null */
    private function productDetails(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }
        $sql = 'SELECT rowid, ref, label, fk_product_type, fk_unit, tobuy, tosell';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product';
        $sql .= ' WHERE rowid = '.$productId.' AND entity IN ('.getEntity('product').') LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Product lookup failed: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (array) $obj : null;
    }

    private function productIsAccessible(int $productId): bool
    {
        return $this->productDetails($productId) !== null;
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

    /** @param array<string,mixed> $details */
    private function supplierPriceEffectiveUnitPrice(array $details): ?float
    {
        $unit = $this->supplierPriceUnitPrice($details);
        if ($unit === null) {
            return null;
        }
        $percent = $this->discountPercent((float) ($details['remise_percent'] ?? 0));
        $effective = $unit * (1.0 - ($percent / 100.0));
        $qty = (float) ($details['quantity'] ?? 0);
        $fixedDiscount = (float) ($details['remise'] ?? 0);
        if ($qty > 0 && $fixedDiscount != 0.0) {
            $effective -= $fixedDiscount / $qty;
        }
        return $effective;
    }

    private function discountPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    private function moneyEqual(float $a, float $b): bool
    {
        $tolerance = max(0.01, max(abs($a), abs($b)) * 0.00001);
        return abs($a - $b) <= $tolerance;
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
        $sql = 'INSERT IGNORE INTO '.MAIN_DB_PREFIX.'navinvoice_purchase_link';
        $sql .= ' (entity, fk_navinvoice_invoice, fk_commande_fourn, datec) VALUES (';
        $sql .= $this->entity.', '.$mirrorId.', '.$orderId.', ';
        $sql .= "'".$this->db->idate(dol_now())."')";
        if (!$this->db->query($sql)) {
            throw new Exception('Could not link supplier order to NAV invoice: '.$this->db->lasterror());
        }
    }

    private function deleteLink(int $mirrorId, int $orderId): void
    {
        $sql = 'DELETE FROM '.MAIN_DB_PREFIX.'navinvoice_purchase_link';
        $sql .= ' WHERE entity = '.$this->entity.' AND fk_navinvoice_invoice = '.$mirrorId;
        $sql .= ' AND fk_commande_fourn = '.$orderId;
        $this->db->query($sql);
    }

    private function ensureSchema(): void
    {
        $table = MAIN_DB_PREFIX.'navinvoice_purchase_link';
        $sql = 'CREATE TABLE IF NOT EXISTS '.$table.' (';
        $sql .= 'rowid INTEGER AUTO_INCREMENT PRIMARY KEY,';
        $sql .= 'entity INTEGER NOT NULL,';
        $sql .= 'fk_navinvoice_invoice INTEGER NOT NULL,';
        $sql .= 'fk_commande_fourn INTEGER NOT NULL,';
        $sql .= 'datec DATETIME NOT NULL,';
        $sql .= 'tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,';
        $sql .= 'UNIQUE KEY uk_navinvoice_purchase_pair (entity, fk_navinvoice_invoice, fk_commande_fourn),';
        $sql .= 'KEY idx_navinvoice_purchase_mirror (entity, fk_navinvoice_invoice),';
        $sql .= 'KEY idx_navinvoice_purchase_order (entity, fk_commande_fourn)';
        $sql .= ') ENGINE=InnoDB';
        if (!$this->db->query($sql)) {
            throw new Exception('Could not initialize NAV purchase-workbench schema: '.$this->db->lasterror());
        }

        // Migrate the initial 1:1 schema to many-to-many in place.
        foreach (array('uk_navinvoice_purchase_mirror', 'uk_navinvoice_purchase_order') as $legacyIndex) {
            if ($this->indexExists($table, $legacyIndex)) {
                if (!$this->db->query('ALTER TABLE '.$table.' DROP INDEX '.$legacyIndex)) {
                    throw new Exception('Could not migrate purchase-workbench relation index '.$legacyIndex.': '.$this->db->lasterror());
                }
            }
        }
        if (!$this->indexExists($table, 'uk_navinvoice_purchase_pair')) {
            if (!$this->db->query('ALTER TABLE '.$table.' ADD UNIQUE KEY uk_navinvoice_purchase_pair (entity, fk_navinvoice_invoice, fk_commande_fourn)')) {
                throw new Exception('Could not add purchase-workbench pair index: '.$this->db->lasterror());
            }
        }
        if (!$this->indexExists($table, 'idx_navinvoice_purchase_mirror')) {
            $this->db->query('ALTER TABLE '.$table.' ADD KEY idx_navinvoice_purchase_mirror (entity, fk_navinvoice_invoice)');
        }
        if (!$this->indexExists($table, 'idx_navinvoice_purchase_order')) {
            $this->db->query('ALTER TABLE '.$table.' ADD KEY idx_navinvoice_purchase_order (entity, fk_navinvoice_invoice)');
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        $resql = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = '".$this->db->escape($index)."'");
        if (!$resql) {
            throw new Exception('Could not inspect purchase-workbench schema: '.$this->db->lasterror());
        }
        $exists = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $exists;
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
