<?php

dol_include_once('/navinvoice/class/navunitresolver.class.php');

/**
 * Import a validated NAV import preview into Dolibarr as a draft invoice.
 *
 * The importer deliberately creates drafts only. Outbound NAV invoice numbers
 * are stored as customer reference and can later be used as the forced Dolibarr
 * invoice number when the draft is explicitly validated.
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

    public function __construct($db, int $entity, string $baseCurrency)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
        $this->unitResolver = new NavUnitResolver($db);
    }

    /**
     * @param array<string,mixed> $preview Result of NavInvoiceImportPreview::build().
     * @param object $record NAV mirror record.
     * @param User $user Current Dolibarr user.
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

        $direction = strtoupper((string) $preview['direction']);
        $inbound = $direction === 'INBOUND';
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

            // Prefer Dolibarr's native calculation rules. First keep the result
            // produced by create() when it already matches NAV, otherwise try the
            // same Mode 1 / Mode 2 recalculations that are available on the invoice
            // card. If only the invoice-level summary differs while a native mode
            // reproduces every NAV line total, preserve the lines and reconcile the
            // summary only. Full NAV line/header fallback is the last resort.
            $reconciliation = $this->reconcileRoundingWithNav($invoice, $preview, $inbound);
            if ($reconciliation === 'none' && $inbound && $category === 'NORMAL') {
                if ($this->prepareSupplierLinesForNavSummary($invoice, $preview)) {
                    $this->preserveSupplierNavSummary($invoice, $preview, $user);
                    $reconciliation = 'nav_summary';
                } else {
                    $this->preserveSupplierNavTotals($invoice, $preview, $user);
                    $reconciliation = 'nav_fallback';
                }
            }

            $this->assertCreatedTotals($invoice, $preview);
            $this->linkMirrorRecord((int) $record->rowid, $direction, $invoiceId);

            return array(
                'id' => $invoiceId,
                'type' => $inbound ? 'supplier' : 'customer',
                'ref' => (string) $invoice->ref,
                'url' => $inbound
                    ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$invoiceId
                    : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$invoiceId,
                'reconciliation' => $reconciliation,
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

        $invoice = new Facture($this->db);
        $invoice->socid = (int) $preview['partner']['id'];
        $invoice->type = Facture::TYPE_STANDARD;
        $invoice->date = $this->dateToTimestamp((string) $preview['header']['invoice_date']);
        $invoice->date_pointoftax = $this->dateToTimestampOrZero((string) ($preview['header']['delivery_date'] ?? ''));
        $invoice->ref_customer = (string) $preview['invoice_number'];
        $invoice->ref_ext = (string) $preview['external_key'];
        $invoice->module_source = 'navinvoice';
        $invoice->mode_reglement_id = $this->paymentModeId((string) ($preview['header']['payment_method'] ?? ''));
        $invoice->multicurrency_code = $this->baseCurrency;
        $invoice->multicurrency_tx = 1;
        $invoice->note_private = $this->auditNote($preview, $record);
        $invoice->lines = array();

        $rank = 0;
        foreach ($preview['lines'] as $mapped) {
            $line = new FactureLigne($this->db);
            $line->id = 0;
            $line->desc = (string) $mapped['description'];
            $line->label = '';
            $line->subprice = (float) $mapped['unit_price_ht'];
            $line->qty = (float) $mapped['quantity'];
            $line->tva_tx = (float) $mapped['vat_rate'];
            $line->vat_src_code = '';
            $line->localtax1_tx = 0;
            $line->localtax2_tx = 0;
            $line->fk_product = 0;
            $line->remise_percent = 0;
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

        $invoice = new FactureFournisseur($this->db);
        $invoice->socid = (int) $preview['partner']['id'];
        $invoice->type = FactureFournisseur::TYPE_STANDARD;
        $invoice->date = $this->dateToTimestamp((string) $preview['header']['invoice_date']);
        $pointOfTaxDate = $this->dateToTimestampOrZero((string) ($preview['header']['delivery_date'] ?? ''));
        $invoice->date_pointoftax = $pointOfTaxDate;
        $dueDate = $this->dateToTimestampOrZero((string) ($preview['header']['due_date'] ?? ''));
        $invoice->date_echeance = $dueDate > 0 ? $dueDate : null;
        $invoice->ref_supplier = (string) $preview['invoice_number'];
        $invoice->ref_ext = (string) $preview['external_key'];
        $invoice->mode_reglement_id = $this->paymentModeId((string) ($preview['header']['payment_method'] ?? ''));
        $invoice->multicurrency_code = $this->baseCurrency;
        $invoice->multicurrency_tx = 1;
        $invoice->note_private = $this->auditNote($preview, $record);
        $invoice->note_public = '';
        $invoice->lines = array();

        $rank = 0;
        foreach ($preview['lines'] as $mapped) {
            $line = new SupplierInvoiceLine($this->db);
            $line->desc = (string) $mapped['description'];
            $line->description = (string) $mapped['description'];
            $line->subprice = (float) $mapped['unit_price_ht'];
            $line->qty = (float) $mapped['quantity'];
            $line->tva_tx = (float) $mapped['vat_rate'];
            $line->vat_src_code = '';
            $line->localtax1_tx = 0;
            $line->localtax2_tx = 0;
            $line->fk_product = 0;
            $line->remise_percent = 0;
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
     * Dolibarr 23 exposes facture_fourn.date_pointoftax in the data model but
     * FactureFournisseur::create() does not persist it. Keep the NAV delivery
     * date in the native core column so accounting/reporting can use it later.
     */
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

    /**
     * Facture::create() derives a due date from payment terms when no forced
     * date is supplied. NAV imports must not invent data that the source did
     * not provide, so clear that derived value when NAV has no payment date.
     */
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

    /**
     * Return true when every created supplier line already reproduces the NAV
     * authoritative line totals. This lets invoice-level rounding be reconciled
     * without rewriting correct line data.
     */
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

    /**
     * After invoice-level reconciliation failed, find a native Dolibarr
     * calculation mode that still reproduces every NAV line exactly. The main
     * reconciliation already tried the same modes for header totals; repeating
     * them here is intentional because it may have left the invoice in Mode 2.
     */
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
     * Keep matching NAV/Dolibarr lines intact and reconcile only the invoice
     * header summary. This covers issued invoices where line totals are exact
     * but the supplier applied a separate invoice-level rounding rule.
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

        dol_syslog(
            'NavInvoiceImporter preserved authoritative NAV summary without rewriting lines on supplier invoice '.((int) $invoice->id),
            LOG_INFO
        );
    }

    /**
     * Preserve authoritative NAV line and header totals for a NORMAL supplier
     * invoice only when no native Dolibarr calculation can reproduce the lines.
     */
    private function preserveSupplierNavTotals(FactureFournisseur $invoice, array $preview, User $user): void
    {
        $mappedLines = is_array($preview['lines'] ?? null) ? $preview['lines'] : array();
        if (!$mappedLines) {
            throw new Exception('Cannot preserve NAV totals without invoice lines.');
        }

        $lineIds = $this->supplierLineIds($invoice);
        if (count($lineIds) !== count($mappedLines)) {
            throw new Exception(
                'Created supplier invoice line count differs from NAV preview: Dolibarr '
                .count($lineIds).' vs NAV '.count($mappedLines)
            );
        }

        $changed = false;
        foreach ($lineIds as $index => $lineId) {
            $mapped = $mappedLines[$index];
            if (($mapped['net'] ?? null) === null || ($mapped['vat'] ?? null) === null || ($mapped['gross'] ?? null) === null) {
                throw new Exception('NAV authoritative line totals are incomplete for line '.($index + 1).'.');
            }

            $line = new SupplierInvoiceLine($this->db);
            if ($line->fetch($lineId) <= 0) {
                throw new Exception('Could not reload created supplier invoice line '.$lineId.'.');
            }

            $navNet = (float) $mapped['net'];
            $navVat = (float) $mapped['vat'];
            $navGross = (float) $mapped['gross'];
            $lineChanged = !$this->amountsEqual((float) $line->total_ht, $navNet)
                || !$this->amountsEqual((float) $line->total_tva, $navVat)
                || !$this->amountsEqual((float) $line->total_ttc, $navGross);
            if (!$lineChanged) {
                continue;
            }

            $changed = true;
            $line->total_ht = $navNet;
            $line->total_tva = $navVat;
            $line->total_ttc = $navGross;

            // Do not emit a second modification trigger for the same imported
            // line. The invoice is still a draft and the initial create path has
            // already executed the normal Dolibarr business flow.
            if ($line->update(1) <= 0) {
                throw new Exception('Could not preserve NAV totals on supplier invoice line '.$lineId.': '.$this->objectError($line));
            }
        }

        $expected = $preview['totals'] ?? array();
        if (($expected['net'] ?? null) === null || ($expected['vat'] ?? null) === null || ($expected['gross'] ?? null) === null) {
            throw new Exception('NAV authoritative invoice totals are incomplete.');
        }

        $navNet = (float) $expected['net'];
        $navVat = (float) $expected['vat'];
        $navGross = (float) $expected['gross'];
        if (!$this->amountsEqual((float) $invoice->total_ht, $navNet)
            || !$this->amountsEqual((float) $invoice->total_tva, $navVat)
            || !$this->amountsEqual((float) $invoice->total_ttc, $navGross)) {
            $changed = true;
        }

        $invoice->total_ht = $navNet;
        $invoice->total_tva = $navVat;
        $invoice->total_ttc = $navGross;
        if ($changed && strpos((string) $invoice->note_private, 'nav_totals_preserved=1') === false) {
            $invoice->note_private = rtrim((string) $invoice->note_private)."\nnav_totals_preserved=1";
        }

        if ($invoice->update($user, 1) <= 0) {
            throw new Exception('Could not preserve authoritative NAV supplier invoice totals: '.$this->objectError($invoice));
        }
        if ($invoice->fetch((int) $invoice->id) <= 0) {
            throw new Exception('Supplier invoice could not be reloaded after preserving NAV totals.');
        }

        if ($changed) {
            dol_syslog(
                'NavInvoiceImporter preserved authoritative NAV totals on supplier invoice '.((int) $invoice->id),
                LOG_INFO
            );
        }
    }

    /**
     * Try the native Dolibarr totals produced by create(), then the same two
     * calculation rules exposed on the supplier invoice card:
     * Mode 1 = total of rounded lines (update_price(..., '0', ...))
     * Mode 2 = rounding of total      (update_price(..., '1', ...))
     *
     * @return string One of default, mode1, mode2, none.
     */
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

        dol_syslog(
            'NavInvoiceImporter could not reproduce NAV totals with either native Dolibarr calculation mode',
            LOG_INFO
        );
        return 'none';
    }

    private function totalsMatch($invoice, array $preview): bool
    {
        $expected = $preview['totals'];

        if (strtoupper((string) ($preview['category'] ?? '')) === 'SIMPLIFIED') {
            // On simplified invoices NAV gross is authoritative. Compare the
            // actual monetary value instead of assuming HUF has zero decimals;
            // NAV may legally contain fractional HUF amounts.
            return $this->amountsEqual((float) $invoice->total_ttc, (float) $expected['gross']);
        }

        return $this->amountsEqual((float) $invoice->total_ht, (float) $expected['net'])
            && $this->amountsEqual((float) $invoice->total_tva, (float) $expected['vat'])
            && $this->amountsEqual((float) $invoice->total_ttc, (float) $expected['gross']);
    }

    private function amountsEqual(float $actual, float $expected): bool
    {
        // Dolibarr stores invoice amounts with substantially more precision than
        // NAV line/summary amounts. A tiny epsilon handles binary-float noise only;
        // it is not a business tolerance and will not hide cent/forint differences.
        return abs($actual - $expected) <= 0.00001;
    }

    private function assertCreatedTotals($invoice, array $preview): void
    {
        if ($this->totalsMatch($invoice, $preview)) {
            return;
        }

        $expected = $preview['totals'];
        if (strtoupper((string) ($preview['category'] ?? '')) === 'SIMPLIFIED') {
            throw new Exception(
                'Created Dolibarr simplified invoice gross differs from NAV gross: Dolibarr '
                .$invoice->total_ttc.' vs NAV '.$expected['gross']
            );
        }

        throw new Exception(
            'Created Dolibarr invoice totals differ from NAV totals: Dolibarr '
            .$invoice->total_ht.'/'.$invoice->total_tva.'/'.$invoice->total_ttc
            .' vs NAV '.$expected['net'].'/'.$expected['vat'].'/'.$expected['gross']
        );
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

    private function paymentModeId(string $navMethod): int
    {
        $map = array(
            'CASH' => 'LIQ',
            'TRANSFER' => 'VIR',
            'CARD' => 'CB',
        );
        $code = $map[strtoupper(trim($navMethod))] ?? '';
        if ($code === '') {
            return 0;
        }

        $sql = 'SELECT id FROM '.MAIN_DB_PREFIX.'c_paiement';
        $sql .= " WHERE code = '".$this->db->escape($code)."'";
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (int) $obj->id : 0;
    }

    private function auditNote(array $preview, $record): string
    {
        $parts = array(
            'NAV Online Invoice import',
            'direction='.(string) $preview['direction'],
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
