<?php

dol_include_once('/navinvoice/class/navunitresolver.class.php');
dol_include_once('/navinvoice/class/navpaymentresolver.class.php');

/**
 * Import a validated NAV import preview into Dolibarr.
 *
 * Invoices are created and reconciled as drafts first. Inbound supplier
 * invoices may then be validated through Dolibarr's native validation flow when
 * the module setting allows it and the preflight state is fully READY.
 *
 * Source line semantics must remain internally consistent in Dolibarr. This
 * importer never repairs mismatches by overwriting calculated line totals: a
 * line must be representable through quantity, unit price, discount and VAT or
 * the import fails. Only invoice-summary rounding may be reconciled separately
 * after every created line already matches NAV.
 */
class NavInvoiceImporter
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var string */
    private $baseCurrency;

    /** @var NavUnitResolver */
    private $unitResolver;

    /** @var NavPaymentResolver */
    private $paymentResolver;

    public function __construct($db, int $entity, string $baseCurrency)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
        $this->unitResolver = new NavUnitResolver($db);
        $this->paymentResolver = new NavPaymentResolver($db, $entity);
    }

    /**
     * @param array<string,mixed> $preview
     * @param object $record
     * @return array<string,mixed>
     */
    public function importDraft(array $preview, $record, User $user): array
    {
        if (!empty($preview['blockers'])) {
            throw new Exception('NAV invoice import is blocked by preview validation.');
        }
        if (!in_array((string) ($preview['state'] ?? ''), array('ready', 'review'), true)) {
            throw new Exception('NAV invoice import preview is not importable.');
        }
        if (!is_array($preview['partner'] ?? null) || empty($preview['partner']['id'])) {
            throw new Exception('A confirmed Dolibarr third party is required for import.');
        }
        if (strtoupper((string) ($preview['header']['currency'] ?? '')) !== $this->baseCurrency) {
            throw new Exception('Only invoices in the Dolibarr base currency can currently be imported.');
        }

        $operation = strtoupper(trim((string) ($preview['operation'] ?? $record->invoice_operation ?? 'CREATE')));
        $operationMapping = trim((string) ($preview['operation_mapping'] ?? ($operation === 'CREATE' ? 'standard' : '')));
        $sourceInvoiceId = (int) ($preview['source_invoice_id'] ?? 0);
        $direction = strtoupper((string) ($preview['direction'] ?? $record->invoice_direction ?? 'OUTBOUND'));
        $inbound = $direction === 'INBOUND';
        $standaloneWithoutMaster = !empty($preview['standalone_without_master'])
            || !empty($preview['operation_policy']['standalone_without_master']);

        if ($operation === 'CREATE') {
            if (!in_array($operationMapping, array('standard', 'deposit'), true)) {
                throw new Exception('CREATE NAV invoice has no supported Dolibarr mapping.');
            }
            if ($operationMapping === 'deposit' && !$inbound) {
                throw new Exception('Outbound NAV deposit invoice import is not enabled yet.');
            }
        } elseif ($operation === 'MODIFY') {
            if (!in_array($operationMapping, array('credit_note', 'standard_adjustment'), true)
                || ($sourceInvoiceId <= 0 && !$standaloneWithoutMaster)) {
                throw new Exception('MODIFY NAV invoice has no deterministic Dolibarr mapping/source invoice.');
            }
        } elseif ($operation === 'STORNO') {
            if ($operationMapping !== 'credit_note' || ($sourceInvoiceId <= 0 && !$standaloneWithoutMaster)) {
                throw new Exception('STORNO NAV invoice must map to a verified Dolibarr credit note.');
            }
        } else {
            throw new Exception('Unsupported NAV invoice operation: '.$operation);
        }

        $category = strtoupper((string) ($preview['category'] ?? ''));
        $invoiceId = 0;
        $invoice = null;

        try {
            if ($inbound) {
                $result = $this->createSupplierInvoice($preview, $record, $user);
            } else {
                $result = $this->createCustomerInvoice($preview, $record, $user);
            }

            $invoiceId = (int) $result['id'];
            $invoice = $result['object'];

            $reconciliation = $this->reconcileRoundingWithNav($invoice, $preview, $inbound);
            if ($reconciliation === 'none' && $inbound && in_array($category, array('NORMAL', 'AGGREGATE'), true)
                && $this->prepareSupplierLinesForNavSummary($invoice, $preview)) {
                $this->preserveSupplierNavSummary($invoice, $preview, $user);
                $reconciliation = 'nav_summary';
            }

            // If native Dolibarr semantics plus supported rounding still cannot
            // reproduce the NAV source, fail and roll the draft back. Never leave
            // a line whose stored totals contradict qty/price/discount/VAT.
            $this->assertCreatedTotals($invoice, $preview);
            $this->assertOperationMapping($invoice, $preview);
            $this->linkMirrorRecord((int) $record->rowid, $direction, $invoiceId);

            $validation = $this->maybeAutoValidateInbound($invoice, $preview, $user, $inbound);

            return array(
                'id' => $invoiceId,
                'type' => $inbound ? 'supplier' : 'customer',
                'ref' => (string) $invoice->ref,
                'url' => $inbound
                    ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$invoiceId
                    : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$invoiceId,
                'reconciliation' => $reconciliation,
                'operation' => $operation,
                'operation_mapping' => $operationMapping,
                'source_invoice_id' => $sourceInvoiceId,
                'standalone_without_master' => $standaloneWithoutMaster,
                'validation_attempted' => (bool) $validation['attempted'],
                'validated' => (bool) $validation['validated'],
                'validation_skipped_reason' => (string) $validation['skipped_reason'],
                'validation_error' => (string) $validation['error'],
            );
        } catch (Throwable $e) {
            if ($invoiceId > 0 && is_object($invoice)) {
                try {
                    $invoice->delete($user, 1);
                } catch (Throwable $cleanupError) {
                    dol_syslog('NavInvoiceImporter cleanup failed for invoice '.$invoiceId.': '.$cleanupError->getMessage(), LOG_ERR);
                }
            }
            throw $e;
        }
    }

    /** @return array{id:int,object:Facture} */
    private function createCustomerInvoice(array $preview, $record, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
        require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/factureligne.class.php';

        $mapping = (string) ($preview['operation_mapping'] ?? 'standard');
        $invoice = new Facture($this->db);
        $invoice->socid = (int) $preview['partner']['id'];
        $invoice->type = $mapping === 'credit_note' ? Facture::TYPE_CREDIT_NOTE : Facture::TYPE_STANDARD;
        $invoice->fk_facture_source = (int) ($preview['source_invoice_id'] ?? 0);
        $invoice->date = $this->dateToTimestamp((string) $preview['header']['invoice_date']);
        $pointOfTaxSource = (string) ($preview['header']['point_of_tax_date']
            ?? $preview['header']['accounting_delivery_date']
            ?? $preview['header']['delivery_date']
            ?? '');
        $invoice->date_pointoftax = $this->dateToTimestampOrZero($pointOfTaxSource);
        $invoice->ref_customer = (string) $preview['invoice_number'];
        $invoice->ref_ext = (string) $preview['external_key'];
        $invoice->module_source = 'navinvoice';
        $invoice->mode_reglement_id = $this->paymentResolver->paymentModeId((string) ($preview['header']['payment_method'] ?? ''));
        $invoice->multicurrency_code = $this->baseCurrency;
        $invoice->multicurrency_tx = 1;
        $invoice->note_private = $this->auditNote($preview, $record);
        $invoice->lines = array();

        $rank = 0;
        foreach ($preview['lines'] as $mapped) {
            $values = $this->dolibarrLineValues($mapped, $mapping);
            $line = new FactureLigne($this->db);
            $line->id = 0;
            $line->desc = (string) $mapped['description'];
            $line->label = '';
            $line->subprice = $values['unit_price'];
            $line->qty = $values['quantity'];
            $line->tva_tx = (float) $mapped['vat_rate'];
            $line->vat_src_code = '';
            $line->localtax1_tx = 0;
            $line->localtax2_tx = 0;
            $line->fk_product = (int) ($mapped['product_id'] ?? 0);
            $line->remise_percent = $this->discountPercent((float) ($mapped['discount_percent'] ?? 0));
            $line->date_start = null;
            $line->date_end = null;
            $line->fk_code_ventilation = 0;
            $line->info_bits = 0;
            $line->fk_remise_except = 0;
            $line->product_type = (int) $mapped['product_type'];
            $line->rang = ++$rank;
            $line->special_code = 0;
            $line->origin_type = '';
            $line->origin_id = 0;
            $line->fk_parent_line = 0;
            $line->fk_fournprice = 0;
            $line->pa_ht = 0;
            $line->array_options = array();
            $line->situation_percent = 100;
            $line->fk_prev_id = 0;
            $line->fk_unit = $this->resolveUnitId($mapped);
            $line->multicurrency_subprice = 0;
            $line->ref_ext = 'NAVLINE|'.(string) $mapped['number'];
            $invoice->lines[] = $line;
        }

        $dueDate = $this->dateToTimestampOrZero((string) ($preview['header']['due_date'] ?? ''));
        $id = $invoice->create($user, 0, $dueDate);
        if ($id <= 0) {
            throw new Exception('Dolibarr customer invoice creation failed: '.$this->objectError($invoice));
        }
        if ($dueDate <= 0) {
            $this->clearCustomerDueDate((int) $id);
        }
        if ($invoice->fetch($id) <= 0) {
            throw new Exception('Created Dolibarr customer invoice could not be reloaded.');
        }

        return array('id' => (int) $id, 'object' => $invoice);
    }

    /** @return array{id:int,object:FactureFournisseur} */
    private function createSupplierInvoice(array $preview, $record, User $user): array
    {
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
        require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.ligne.class.php';

        $mapping = (string) ($preview['operation_mapping'] ?? 'standard');
        $invoice = new FactureFournisseur($this->db);
        $invoice->socid = (int) $preview['partner']['id'];
        if ($mapping === 'credit_note') {
            $invoice->type = FactureFournisseur::TYPE_CREDIT_NOTE;
        } elseif ($mapping === 'deposit') {
            $invoice->type = FactureFournisseur::TYPE_DEPOSIT;
        } else {
            $invoice->type = FactureFournisseur::TYPE_STANDARD;
        }
        $invoice->fk_facture_source = (int) ($preview['source_invoice_id'] ?? 0);
        $invoice->date = $this->dateToTimestamp((string) $preview['header']['invoice_date']);
        $pointOfTaxSource = (string) ($preview['header']['point_of_tax_date']
            ?? $preview['header']['accounting_delivery_date']
            ?? $preview['header']['delivery_date']
            ?? '');
        $pointOfTaxDate = $this->dateToTimestampOrZero($pointOfTaxSource);
        $invoice->date_pointoftax = $pointOfTaxDate;
        $dueDate = $this->dateToTimestampOrZero((string) ($preview['header']['due_date'] ?? ''));
        $invoice->date_echeance = $dueDate > 0 ? $dueDate : null;
        $invoice->ref_supplier = (string) $preview['invoice_number'];
        $invoice->ref_ext = (string) $preview['external_key'];
        $invoice->mode_reglement_id = $this->paymentResolver->paymentModeId((string) ($preview['header']['payment_method'] ?? ''));
        $invoice->multicurrency_code = $this->baseCurrency;
        $invoice->multicurrency_tx = 1;
        $invoice->note_private = $this->auditNote($preview, $record);
        $invoice->note_public = '';
        $invoice->lines = array();

        $rank = 0;
        foreach ($preview['lines'] as $mapped) {
            $values = $this->dolibarrLineValues($mapped, $mapping);
            $line = new SupplierInvoiceLine($this->db);
            $line->desc = (string) $mapped['description'];
            $line->description = (string) $mapped['description'];
            $line->subprice = $values['unit_price'];
            $line->qty = $values['quantity'];
            $line->tva_tx = (float) $mapped['vat_rate'];
            $line->vat_src_code = '';
            $line->localtax1_tx = 0;
            $line->localtax2_tx = 0;
            $line->fk_product = (int) ($mapped['product_id'] ?? 0);
            $line->remise_percent = $this->discountPercent((float) ($mapped['discount_percent'] ?? 0));
            $line->date_start = null;
            $line->date_end = null;
            $line->info_bits = 0;
            $line->product_type = (int) $mapped['product_type'];
            $line->rang = ++$rank;
            $line->special_code = 0;
            $line->array_options = array();
            $line->fk_unit = $this->resolveUnitId($mapped);
            $line->multicurrency_subprice = 0;
            $line->ref_supplier = (string) ($mapped['supplier_ref'] ?? '');
            $invoice->lines[] = $line;
        }

        $id = $invoice->create($user);
        if ($id <= 0) {
            throw new Exception('Dolibarr supplier invoice creation failed: '.$this->objectError($invoice));
        }
        $this->persistSupplierPointOfTax((int) $id, $pointOfTaxDate);
        if ($invoice->fetch($id) <= 0) {
            throw new Exception('Created Dolibarr supplier invoice could not be reloaded.');
        }

        return array('id' => (int) $id, 'object' => $invoice);
    }

    /**
     * Normalize NAV signs to Dolibarr invoice-type conventions. Credit notes use
     * positive quantities, while each line's unit-price sign follows that NAV
     * line's authoritative financial effect. Dolibarr 23 subsequently forces
     * all supplier-credit-note prices negative during create(); the explicit
     * NavSupplierInvoiceCompatibility adapter restores positive correction rows.
     *
     * @param array<string,mixed> $mapped
     * @return array{quantity:float,unit_price:float}
     */
    private function dolibarrLineValues(array $mapped, string $mapping): array
    {
        $quantity = (float) ($mapped['quantity'] ?? 0);
        $unitPrice = (float) ($mapped['unit_price_ht'] ?? 0);
        if ($mapping === 'credit_note') {
            $quantity = abs($quantity);
            $lineAmount = null;
            if (($mapped['net'] ?? null) !== null && ($mapped['net'] ?? '') !== '' && is_numeric($mapped['net'])) {
                $lineAmount = (float) $mapped['net'];
            } elseif (($mapped['gross'] ?? null) !== null && ($mapped['gross'] ?? '') !== '' && is_numeric($mapped['gross'])) {
                $lineAmount = (float) $mapped['gross'];
            }
            $unitPrice = $lineAmount !== null && $lineAmount > 0.0000001
                ? abs($unitPrice)
                : -abs($unitPrice);
        }
        return array('quantity' => $quantity, 'unit_price' => $unitPrice);
    }

    private function discountPercent(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }

    private function persistSupplierPointOfTax(int $invoiceId, int $timestamp): void
    {
        if ($timestamp <= 0) {
            return;
        }
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'facture_fourn';
        $sql .= " SET date_pointoftax = '".$this->db->idate($timestamp)."'";
        $sql .= ' WHERE rowid = '.$invoiceId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to store supplier invoice point-of-tax date: '.$this->db->lasterror());
        }
    }

    private function clearCustomerDueDate(int $invoiceId): void
    {
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'facture';
        $sql .= ' SET date_lim_reglement = NULL';
        $sql .= ' WHERE rowid = '.$invoiceId;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to preserve missing NAV due date: '.$this->db->lasterror());
        }
    }

    /** @return array<int,int> */
    private function supplierLineIds(FactureFournisseur $invoice): array
    {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture_fourn_det';
        $sql .= ' WHERE fk_facture_fourn = '.((int) $invoice->id);
        $sql .= ' ORDER BY rang, rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Could not load created supplier invoice line identifiers: '.$this->db->lasterror());
        }
        $lineIds = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $lineIds[] = (int) $obj->rowid;
        }
        $this->db->free($resql);
        return $lineIds;
    }

    private function supplierLinesMatchNav(FactureFournisseur $invoice, array $preview): bool
    {
        $mappedLines = is_array($preview['lines'] ?? null) ? $preview['lines'] : array();
        if (!$mappedLines) {
            return false;
        }
        $lineIds = $this->supplierLineIds($invoice);
        if (count($lineIds) !== count($mappedLines)) {
            return false;
        }
        foreach ($lineIds as $index => $lineId) {
            $mapped = $mappedLines[$index];
            if (($mapped['net'] ?? null) === null || ($mapped['vat'] ?? null) === null || ($mapped['gross'] ?? null) === null) {
                return false;
            }
            $line = new SupplierInvoiceLine($this->db);
            if ($line->fetch($lineId) <= 0) {
                throw new Exception('Could not reload created supplier invoice line '.$lineId.'.');
            }
            if (!$this->amountsEqual((float) $line->total_ht, (float) $mapped['net'])
                || !$this->amountsEqual((float) $line->total_tva, (float) $mapped['vat'])
                || !$this->amountsEqual((float) $line->total_ttc, (float) $mapped['gross'])) {
                return false;
            }
        }
        return true;
    }

    private function prepareSupplierLinesForNavSummary(FactureFournisseur $invoice, array $preview): bool
    {
        if ($this->supplierLinesMatchNav($invoice, $preview)) {
            return true;
        }
        $result = $invoice->fetch_thirdparty();
        if ($result < 0 || !is_object($invoice->thirdparty)) {
            throw new Exception('Could not load supplier for NAV line reconciliation.');
        }
        foreach (array('0', '1') as $roundingMode) {
            $result = $invoice->update_price(1, $roundingMode, 0, $invoice->thirdparty);
            if ($result <= 0) {
                throw new Exception('Dolibarr supplier line reconciliation failed in mode '.$roundingMode.': '.$this->objectError($invoice));
            }
            if ($invoice->fetch((int) $invoice->id) <= 0) {
                throw new Exception('Dolibarr supplier invoice could not be reloaded after line reconciliation.');
            }
            if ($this->supplierLinesMatchNav($invoice, $preview)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Reconcile only NAV header-summary rounding after every supplier line has
     * already been proven equal to its NAV source line.
     */
    private function preserveSupplierNavSummary(FactureFournisseur $invoice, array $preview, User $user): void
    {
        $expected = $preview['totals'] ?? array();
        if (($expected['net'] ?? null) === null || ($expected['vat'] ?? null) === null || ($expected['gross'] ?? null) === null) {
            throw new Exception('NAV authoritative invoice totals are incomplete.');
        }
        if (!$this->supplierLinesMatchNav($invoice, $preview)) {
            throw new Exception('Cannot apply NAV summary-only reconciliation because supplier line totals differ.');
        }
        $invoice->total_ht = (float) $expected['net'];
        $invoice->total_tva = (float) $expected['vat'];
        $invoice->total_ttc = (float) $expected['gross'];
        if (strpos((string) $invoice->note_private, 'nav_summary_reconciled=1') === false) {
            $invoice->note_private = rtrim((string) $invoice->note_private)."\nnav_summary_reconciled=1";
        }
        if ($invoice->update($user, 1) <= 0) {
            throw new Exception('Could not preserve authoritative NAV supplier invoice summary: '.$this->objectError($invoice));
        }
        if ($invoice->fetch((int) $invoice->id) <= 0) {
            throw new Exception('Supplier invoice could not be reloaded after preserving NAV summary.');
        }
        dol_syslog('NavInvoiceImporter preserved authoritative NAV summary without rewriting lines on supplier invoice '.((int) $invoice->id), LOG_INFO);
    }

    private function reconcileRoundingWithNav($invoice, array $preview, bool $inbound): string
    {
        if ($this->totalsMatch($invoice, $preview)) {
            dol_syslog('NavInvoiceImporter matched NAV totals using Dolibarr create/default calculation', LOG_INFO);
            return 'default';
        }
        global $mysoc;
        $seller = $mysoc;
        if ($inbound) {
            $result = $invoice->fetch_thirdparty();
            if ($result < 0 || !is_object($invoice->thirdparty)) {
                throw new Exception('Could not load supplier for NAV rounding reconciliation.');
            }
            $seller = $invoice->thirdparty;
        }
        foreach (array('0', '1') as $roundingMode) {
            $result = $invoice->update_price(1, $roundingMode, 0, $seller);
            if ($result <= 0) {
                throw new Exception('Dolibarr invoice rounding reconciliation failed in mode '.$roundingMode.': '.$this->objectError($invoice));
            }
            if ($invoice->fetch((int) $invoice->id) <= 0) {
                throw new Exception('Dolibarr invoice could not be reloaded after rounding reconciliation.');
            }
            if ($this->totalsMatch($invoice, $preview)) {
                $uiMode = $roundingMode === '0' ? '1' : '2';
                dol_syslog('NavInvoiceImporter matched NAV totals using Dolibarr calculation Mode '.$uiMode, LOG_INFO);
                return $roundingMode === '0' ? 'mode1' : 'mode2';
            }
        }
        dol_syslog('NavInvoiceImporter could not reproduce NAV totals with either native Dolibarr calculation mode', LOG_INFO);
        return 'none';
    }

    private function totalsMatch($invoice, array $preview): bool
    {
        $expected = $preview['totals'];
        if (strtoupper((string) ($preview['category'] ?? '')) === 'SIMPLIFIED') {
            return $this->amountsEqual((float) $invoice->total_ttc, (float) $expected['gross']);
        }
        $currency = strtoupper(trim((string) ($preview['header']['currency'] ?? $this->baseCurrency)));
        if ($currency === 'HUF') {
            // NAV may expose fractional-forint VAT while the legally payable HUF
            // gross and Dolibarr's accounting totals are whole forints. Keep net
            // and gross authoritative and only tolerate a sub-forint VAT delta.
            return $this->amountsEqual((float) $invoice->total_ht, (float) $expected['net'])
                && abs((float) $invoice->total_tva - (float) $expected['vat']) < 1.0
                && $this->amountsEqual((float) $invoice->total_ttc, (float) $expected['gross']);
        }
        return $this->amountsEqual((float) $invoice->total_ht, (float) $expected['net'])
            && $this->amountsEqual((float) $invoice->total_tva, (float) $expected['vat'])
            && $this->amountsEqual((float) $invoice->total_ttc, (float) $expected['gross']);
    }

    private function amountsEqual(float $actual, float $expected): bool
    {
        return abs($actual - $expected) <= 0.00001;
    }

    private function assertCreatedTotals($invoice, array $preview): void
    {
        if ($this->totalsMatch($invoice, $preview)) {
            return;
        }
        $expected = $preview['totals'];
        if (strtoupper((string) ($preview['category'] ?? '')) === 'SIMPLIFIED') {
            throw new Exception('Created Dolibarr simplified invoice gross differs from NAV gross: Dolibarr '.$invoice->total_ttc.' vs NAV '.$expected['gross']);
        }
        throw new Exception(
            'Created Dolibarr invoice totals differ from NAV totals: Dolibarr '
            .$invoice->total_ht.'/'.$invoice->total_tva.'/'.$invoice->total_ttc
            .' vs NAV '.$expected['net'].'/'.$expected['vat'].'/'.$expected['gross']
        );
    }

    private function assertOperationMapping($invoice, array $preview): void
    {
        $operation = strtoupper(trim((string) ($preview['operation'] ?? 'CREATE')));
        $mapping = (string) ($preview['operation_mapping'] ?? ($operation === 'CREATE' ? 'standard' : ''));
        $sourceInvoiceId = (int) ($preview['source_invoice_id'] ?? 0);
        $standaloneWithoutMaster = !empty($preview['standalone_without_master'])
            || !empty($preview['operation_policy']['standalone_without_master']);
        if ($mapping === 'credit_note') {
            $expectedType = 2;
        } elseif ($mapping === 'deposit') {
            $expectedType = 3;
        } else {
            $expectedType = 0;
        }

        if ((int) ($invoice->type ?? -1) !== $expectedType) {
            throw new Exception('Created Dolibarr invoice type does not match the NAV operation mapping.');
        }
        if ($operation !== 'CREATE') {
            $actualSourceId = (int) ($invoice->fk_facture_source ?? 0);
            if ($sourceInvoiceId > 0 && $actualSourceId !== $sourceInvoiceId) {
                throw new Exception('Created Dolibarr modification/storno invoice lost its source-invoice relationship.');
            }
            if ($sourceInvoiceId <= 0 && $standaloneWithoutMaster && $actualSourceId > 0) {
                throw new Exception('Standalone modifyWithoutMaster correction unexpectedly acquired a Dolibarr source invoice.');
            }
        }
        if ($mapping === 'credit_note' && (float) ($invoice->total_ht ?? 0) > 0.00001) {
            throw new Exception('Created Dolibarr credit note has a positive HT total.');
        }
        if ($mapping === 'deposit' && (float) ($invoice->total_ht ?? 0) < -0.00001) {
            throw new Exception('Created Dolibarr deposit invoice has a negative HT total.');
        }
    }

    /** @param array<string,mixed> $mapped */
    private function resolveUnitId(array $mapped): ?int
    {
        if (!$this->unitResolver->isEnabled()) {
            return null;
        }
        if (!empty($mapped['unit_id'])) {
            return (int) $mapped['unit_id'];
        }
        $resolution = $this->unitResolver->resolve(array(
            'unit' => (string) ($mapped['unit'] ?? ''),
            'unit_own' => '',
        ));
        return ($resolution['status'] ?? '') === 'resolved' ? (int) $resolution['id'] : null;
    }

    private function linkMirrorRecord(int $mirrorId, string $direction, int $invoiceId): void
    {
        $field = $direction === 'INBOUND' ? 'fk_facture_fourn' : 'fk_facture';
        $otherField = $direction === 'INBOUND' ? 'fk_facture' : 'fk_facture_fourn';
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' SET '.$field.' = '.$invoiceId;
        $sql .= ' WHERE rowid = '.$mirrorId.' AND entity = '.$this->entity;
        $sql .= ' AND ('.$field.' IS NULL OR '.$field.' = 0)';
        $sql .= ' AND ('.$otherField.' IS NULL OR '.$otherField.' = 0)';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to link the created Dolibarr invoice to the NAV mirror record: '.$this->db->lasterror());
        }
        $sql = 'SELECT '.$field.' AS linked_id FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE rowid = '.$mirrorId.' AND entity = '.$this->entity;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to verify NAV mirror link: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj || (int) $obj->linked_id !== $invoiceId) {
            throw new Exception('The NAV mirror record was not linked to the created Dolibarr invoice.');
        }
    }

    /** @return array{attempted:bool,validated:bool,skipped_reason:string,error:string} */
    private function maybeAutoValidateInbound($invoice, array $preview, User $user, bool $inbound): array
    {
        $status = array('attempted' => false, 'validated' => false, 'skipped_reason' => '', 'error' => '');
        if (!$inbound || !getDolGlobalInt('NAVINVOICE_AUTO_VALIDATE_INBOUND')) {
            return $status;
        }
        if ((string) ($preview['state'] ?? '') !== 'ready') {
            $status['skipped_reason'] = 'preview_not_ready';
            return $status;
        }

        $canValidate = (!getDolGlobalString('MAIN_USE_ADVANCED_PERMS')
            && ($user->hasRight('fournisseur', 'facture', 'creer') || $user->hasRight('supplier_invoice', 'creer')))
            || (getDolGlobalString('MAIN_USE_ADVANCED_PERMS')
                && $user->hasRight('fournisseur', 'supplier_invoice_advance', 'validate'));
        if (!$canValidate) {
            $status['skipped_reason'] = 'validation_permission_missing';
            dol_syslog('NavInvoiceImporter skipped automatic validation for supplier invoice '.((int) ($invoice->id ?? 0)).' because user '.$user->id.' has no supplier invoice validation permission', LOG_WARNING);
            return $status;
        }

        if (isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL')) {
            $status['skipped_reason'] = 'stock_warehouse_required';
            dol_syslog('NavInvoiceImporter skipped automatic validation for supplier invoice '.((int) ($invoice->id ?? 0)).' because STOCK_CALCULATE_ON_SUPPLIER_BILL is enabled', LOG_WARNING);
            return $status;
        }
        if (!($invoice instanceof FactureFournisseur)) {
            $status['skipped_reason'] = 'not_supplier_invoice';
            return $status;
        }

        $status['attempted'] = true;
        try {
            $result = $invoice->validate($user);
            if ($result < 0) {
                $status['error'] = $this->objectError($invoice);
                dol_syslog('NavInvoiceImporter automatic validation failed for supplier invoice '.((int) $invoice->id).': '.$status['error'], LOG_WARNING);
                return $status;
            }
            if ($invoice->fetch((int) $invoice->id) <= 0) {
                $status['error'] = 'Validated supplier invoice could not be reloaded.';
                return $status;
            }
            $status['validated'] = (int) ($invoice->status ?? 0) >= FactureFournisseur::STATUS_VALIDATED;
            if (!$status['validated']) {
                $status['error'] = 'Dolibarr validation returned without moving the invoice out of draft status.';
            }
        } catch (Throwable $e) {
            $status['error'] = $e->getMessage();
            dol_syslog('NavInvoiceImporter automatic validation threw for supplier invoice '.((int) ($invoice->id ?? 0)).': '.$e->getMessage(), LOG_WARNING);
        }
        return $status;
    }

    private function auditNote(array $preview, $record): string
    {
        $operation = strtoupper(trim((string) ($preview['operation'] ?? $record->invoice_operation ?? 'CREATE')));
        $parts = array(
            'NAV Online Invoice import',
            'direction='.(string) $preview['direction'],
            'operation='.$operation,
            'operation_mapping='.(string) ($preview['operation_mapping'] ?? ($operation === 'CREATE' ? 'standard' : '')),
            'source_invoice_id='.(int) ($preview['source_invoice_id'] ?? 0),
            'invoice='.(string) $preview['invoice_number'],
            'mirror_rowid='.(int) $record->rowid,
            'external_key='.(string) $preview['external_key'],
        );
        if (!empty($record->invoice_data_hash)) {
            $parts[] = 'xml_sha256='.(string) $record->invoice_data_hash;
        }
        if (!empty($preview['header']['delivery_date'])) {
            $parts[] = 'delivery_date='.(string) $preview['header']['delivery_date'];
        }
        if (!empty($preview['header']['accounting_delivery_date'])) {
            $parts[] = 'accounting_delivery_date='.(string) $preview['header']['accounting_delivery_date'];
        }
        if (!empty($preview['header']['point_of_tax_date'])) {
            $parts[] = 'point_of_tax_date='.(string) $preview['header']['point_of_tax_date'];
        }

        $relation = is_array($preview['operation_policy']['relation'] ?? null)
            ? $preview['operation_policy']['relation']
            : array();
        if (!empty($relation['original_invoice_number'])) {
            $parts[] = 'original_invoice_number='.(string) $relation['original_invoice_number'];
        }
        if (array_key_exists('modify_without_master', $relation) && $relation['modify_without_master'] !== null) {
            $parts[] = 'modify_without_master='.(!empty($relation['modify_without_master']) ? '1' : '0');
        }
        if (!empty($relation['modification_index'])) {
            $parts[] = 'modification_index='.(int) $relation['modification_index'];
        }
        if (!empty($preview['standalone_without_master']) || !empty($preview['operation_policy']['standalone_without_master'])) {
            $parts[] = 'standalone_without_master=1';
        }

        if (strtoupper((string) ($preview['category'] ?? '')) === 'AGGREGATE') {
            $lineDeliveryDates = array();
            $lineExchangeRates = array();
            foreach (($preview['lines'] ?? array()) as $index => $line) {
                if (!is_array($line)) {
                    continue;
                }
                $lineKey = trim((string) ($line['number'] ?? ''));
                if ($lineKey === '') {
                    $lineKey = (string) ($index + 1);
                }
                if (!empty($line['aggregate_delivery_date'])) {
                    $lineDeliveryDates[] = $lineKey.':'.(string) $line['aggregate_delivery_date'];
                }
                if (($line['aggregate_exchange_rate'] ?? '') !== '') {
                    $lineExchangeRates[] = $lineKey.':'.(string) $line['aggregate_exchange_rate'];
                }
            }
            if ($lineDeliveryDates) {
                $parts[] = 'aggregate_line_delivery_dates='.implode(',', $lineDeliveryDates);
            }
            if ($lineExchangeRates) {
                $parts[] = 'aggregate_line_exchange_rates='.implode(',', $lineExchangeRates);
            }
        }

        return implode("\n", $parts);
    }

    private function dateToTimestamp(string $date): int
    {
        $timestamp = $this->dateToTimestampOrZero($date);
        if ($timestamp <= 0) {
            throw new Exception('Invalid invoice date: '.$date);
        }
        return $timestamp;
    }

    private function dateToTimestampOrZero(string $date): int
    {
        if ($date === '') {
            return 0;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return 0;
        }
        return $parsed->getTimestamp();
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
