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

            // Dolibarr can be configured either as "total of rounded lines" or
            // "rounded total". Existing external invoices must reproduce the
            // authoritative NAV totals, so if the default mode differs, try the
            // two native Dolibarr rounding modes explicitly before rejecting the
            // import. No global Dolibarr setting is changed by this operation.
            $this->reconcileRoundingWithNav($invoice, $preview, $inbound);
            $this->assertCreatedTotals($invoice, $preview);
            $this->linkMirrorRecord((int) $record->rowid, $direction, $invoiceId);

            return array(
                'id' => $invoiceId,
                'type' => $inbound ? 'supplier' : 'customer',
                'ref' => (string) $invoice->ref,
                'url' => $inbound
                    ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$invoiceId
                    : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$invoiceId,
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
            $line->date_start = 0;
            $line->date_end = 0;
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
        if ($dueDate <= 0) {
            $dueDate = $invoice->date;
        }

        $id = $invoice->create($user, 0, $dueDate);
        if ($id <= 0) {
            throw new Exception('Dolibarr customer invoice creation failed: '.$this->objectError($invoice));
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
        $invoice->date_echeance = $this->dateToTimestampOrZero((string) ($preview['header']['due_date'] ?? ''));
        if ($invoice->date_echeance <= 0) {
            $invoice->date_echeance = $invoice->date;
        }
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
            $line->date_start = 0;
            $line->date_end = 0;
            $line->info_bits = 0;
            $line->product_type = (int) $mapped['product_type'];
            $line->rang = ++$rank;
            $line->special_code = 0;
            $line->array_options = array();
            $line->fk_unit = $this->resolveUnitId($mapped);
            $line->multicurrency_subprice = 0;
            $line->ref_supplier = '';
            $invoice->lines[] = $line;
        }

        $id = $invoice->create($user);
        if ($id <= 0) {
            throw new Exception('Dolibarr supplier invoice creation failed: '.$this->objectError($invoice));
        }
        if ($invoice->fetch($id) <= 0) {
            throw new Exception('Created Dolibarr supplier invoice could not be reloaded.');
        }

        return array('id' => (int) $id, 'object' => $invoice);
    }

    private function reconcileRoundingWithNav($invoice, array $preview, bool $inbound): void
    {
        if ($this->totalsMatch($invoice, $preview)) {
            return;
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

        // Try Dolibarr's native "rounding of total" first. This is the common
        // representation for NAV invoices whose decimal line totals add up to
        // the authoritative whole-currency invoice total.
        foreach (array('1', '0') as $roundingMode) {
            $result = $invoice->update_price(1, $roundingMode, 0, $seller);
            if ($result <= 0) {
                throw new Exception('Dolibarr invoice rounding reconciliation failed in mode '.$roundingMode.': '.$this->objectError($invoice));
            }
            if ($invoice->fetch((int) $invoice->id) <= 0) {
                throw new Exception('Dolibarr invoice could not be reloaded after rounding reconciliation.');
            }
            if ($this->totalsMatch($invoice, $preview)) {
                dol_syslog('NavInvoiceImporter matched NAV totals using Dolibarr rounding mode '.$roundingMode, LOG_INFO);
                return;
            }
        }
    }

    private function totalsMatch($invoice, array $preview): bool
    {
        $currency = strtoupper((string) $preview['header']['currency']);
        $decimals = in_array($currency, array('HUF', 'JPY'), true) ? 0 : 2;
        $expected = $preview['totals'];

        return round((float) $invoice->total_ht, $decimals) == round((float) $expected['net'], $decimals)
            && round((float) $invoice->total_tva, $decimals) == round((float) $expected['vat'], $decimals)
            && round((float) $invoice->total_ttc, $decimals) == round((float) $expected['gross'], $decimals);
    }

    private function assertCreatedTotals($invoice, array $preview): void
    {
        if (!$this->totalsMatch($invoice, $preview)) {
            $expected = $preview['totals'];
            throw new Exception(
                'Created Dolibarr invoice totals differ from NAV totals: Dolibarr '
                .$invoice->total_ht.'/'.$invoice->total_tva.'/'.$invoice->total_ttc
                .' vs NAV '.$expected['net'].'/'.$expected['vat'].'/'.$expected['gross']
            );
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
