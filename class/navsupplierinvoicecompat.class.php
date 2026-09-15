<?php

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');

/**
 * Dolibarr 23 supplier-credit-note compatibility boundary.
 *
 * FactureFournisseur::create() in Dolibarr 23 forces every supplier credit-note
 * line to positive quantity + negative unit price. NAV MODIFY/STORNO documents
 * may legitimately contain both positive and negative financial lines, so that
 * core normalization loses source semantics. Standard supplier invoices do not
 * need this adapter and are left entirely to native Dolibarr creation.
 */
class NavSupplierInvoiceCompatibility
{
    /** @var DoliDB */
    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * @param CommonObject $invoice
     * @param Conf $conf
     * @return int 0 not applicable, 1 corrected, -1 error
     */
    public function apply($invoice, Conf $conf): int
    {
        if (!is_object($invoice) || empty($invoice->id) || (int) ($invoice->type ?? 0) !== 2) {
            return 0;
        }

        $entity = !empty($invoice->entity) ? (int) $invoice->entity : (int) $conf->entity;
        $xml = $this->loadSourceXml($invoice, $entity);
        if ($xml === null) {
            // Not a NAV-created supplier invoice.
            return 0;
        }
        if ($xml === '') {
            return $this->error('NAV source XML is missing for supplier credit-note compatibility correction.');
        }

        try {
            $parsed = (new NavInvoiceParser())->parse($xml);
        } catch (Throwable $e) {
            return $this->error('NAV source XML could not be parsed: '.$e->getMessage());
        }
        $sourceLines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : array();
        if (!$sourceLines) {
            return $this->error('NAV source invoice contains no lines.');
        }

        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture_fourn_det';
        $sql .= ' WHERE fk_facture_fourn = '.((int) $invoice->id);
        $sql .= ' ORDER BY rang, rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->error('Could not load created supplier invoice lines: '.$this->db->lasterror());
        }
        $lineIds = array();
        while ($row = $this->db->fetch_object($resql)) {
            $lineIds[] = (int) $row->rowid;
        }
        $this->db->free($resql);

        if (count($lineIds) !== count($sourceLines)) {
            return $this->error('NAV/Dolibarr supplier line count differs: NAV '.count($sourceLines).' vs Dolibarr '.count($lineIds).'.');
        }

        foreach ($sourceLines as $index => $sourceLine) {
            if (!is_array($sourceLine)) {
                continue;
            }
            $quantityRaw = $sourceLine['quantity'] ?? null;
            $unitPriceRaw = $sourceLine['unit_price'] ?? null;
            $amounts = is_array($sourceLine['amounts'] ?? null) ? $sourceLine['amounts'] : array();
            $netRaw = $amounts['net'] ?? null;
            if (!is_numeric($quantityRaw) || !is_numeric($netRaw)) {
                continue;
            }

            $quantity = abs((float) $quantityRaw);
            $net = (float) $netRaw;
            if ($quantity <= 0.000000001) {
                continue;
            }

            $unitPrice = is_numeric($unitPriceRaw) ? (float) $unitPriceRaw : $net / $quantity;
            $unitPrice = $net < -0.0000001 ? -abs($unitPrice) : abs($unitPrice);

            $discount = is_array($sourceLine['discount'] ?? null) ? $sourceLine['discount'] : array();
            $discountPercent = $this->validatedDiscountPercent($discount, $quantity * $unitPrice, $net);
            if ($discountPercent === null) {
                if (!$this->amountsClose($quantity * $unitPrice, $net)) {
                    $unitPrice = $net / $quantity;
                }
                $discountPercent = 0.0;
            }

            $vatRaw = $amounts['vat'] ?? null;
            $grossRaw = $amounts['gross'] ?? null;
            $vat = is_numeric($vatRaw) ? (float) $vatRaw : 0.0;
            $gross = is_numeric($grossRaw) ? (float) $grossRaw : $net + $vat;
            $vatRate = $this->vatPercent(is_array($sourceLine['vat'] ?? null) ? $sourceLine['vat'] : array());
            $unitPriceTtc = $unitPrice * (1.0 + ($vatRate / 100.0));

            // This is intentionally the only direct supplier-line correction in
            // the module. It compensates for Dolibarr 23's credit-note sign rule;
            // all standard invoices remain native business-object writes.
            $sql = 'UPDATE '.MAIN_DB_PREFIX.'facture_fourn_det SET';
            $sql .= ' qty = '.$this->number($quantity);
            $sql .= ', pu_ht = '.$this->number($unitPrice);
            $sql .= ', pu_ttc = '.$this->number($unitPriceTtc);
            $sql .= ', remise_percent = '.$this->number($discountPercent);
            $sql .= ', total_ht = '.$this->number($net);
            $sql .= ', tva = '.$this->number($vat);
            $sql .= ', total_ttc = '.$this->number($gross);
            $sql .= ' WHERE rowid = '.$lineIds[(int) $index];
            if (!$this->db->query($sql)) {
                return $this->error('Could not apply NAV supplier credit-note compatibility correction: '.$this->db->lasterror());
            }
        }

        return 1;
    }

    /**
     * @return string|null null means the invoice is not a NAVinvoice document.
     */
    private function loadSourceXml($invoice, int $entity): ?string
    {
        // New imports carry the authoritative mirror row id in the audit note,
        // which avoids all invoice-number/supplier collision ambiguity.
        $note = (string) ($invoice->note_private ?? '');
        if (preg_match('/(?:^|\n)mirror_rowid=(\d+)(?:\n|$)/', $note, $matches)) {
            $mirrorId = (int) $matches[1];
            if ($mirrorId > 0) {
                $sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
                $sql .= ' WHERE rowid = '.$mirrorId.' AND entity = '.$entity;
                $sql .= " AND invoice_direction = 'INBOUND' LIMIT 1";
                $resql = $this->db->query($sql);
                if (!$resql) {
                    return '';
                }
                $mirror = $this->db->fetch_object($resql);
                $this->db->free($resql);
                return $mirror ? trim((string) ($mirror->invoice_data ?? '')) : '';
            }
        }

        // Backward compatibility for drafts created before mirror_rowid was
        // embedded in note_private. Do not use this path for new imports.
        $refExt = trim((string) ($invoice->ref_ext ?? ''));
        if (!preg_match('/^NAV\|INBOUND\|(.+)\|(\d+)$/', $refExt, $matches)) {
            return null;
        }
        $invoiceNumber = (string) $matches[1];
        $batchIndex = (int) $matches[2];
        $sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$entity." AND invoice_direction = 'INBOUND'";
        $sql .= " AND invoice_number = '".$this->db->escape($invoiceNumber)."'";
        $sql .= ' AND batch_index = '.$batchIndex.' ORDER BY rowid DESC LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return '';
        }
        $mirror = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $mirror ? trim((string) ($mirror->invoice_data ?? '')) : '';
    }

    /** @param array<string,mixed> $discount */
    private function validatedDiscountPercent(array $discount, float $extended, float $net): ?float
    {
        if (abs($extended) <= 0.000000001) {
            return null;
        }
        if (isset($discount['rate']) && $discount['rate'] !== null && $discount['rate'] !== '' && is_numeric($discount['rate'])) {
            $rate = abs((float) $discount['rate']);
            if ($rate <= 1.0 && $this->amountsClose($extended * (1.0 - $rate), $net)) {
                return $rate * 100.0;
            }
        }
        if (isset($discount['value']) && $discount['value'] !== null && $discount['value'] !== '' && is_numeric($discount['value'])) {
            $percent = 100.0 * abs((float) $discount['value']) / abs($extended);
            if ($percent <= 100.0 && $this->amountsClose($extended * (1.0 - ($percent / 100.0)), $net)) {
                return $percent;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $vat */
    private function vatPercent(array $vat): float
    {
        if (($vat['kind'] ?? '') === 'percentage' && is_numeric($vat['value'] ?? null)) {
            return (float) $vat['value'] * 100.0;
        }
        return 0.0;
    }

    private function amountsClose(float $a, float $b): bool
    {
        return abs($a - $b) <= 0.01;
    }

    private function number(float $value): string
    {
        $number = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');
        return $number === '' || $number === '-0' ? '0' : $number;
    }

    private function error(string $message): int
    {
        dol_syslog('NavSupplierInvoiceCompatibility: '.$message, LOG_ERR);
        return -1;
    }
}
