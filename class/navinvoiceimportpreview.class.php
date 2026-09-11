<?php

/**
 * Build a read-only preview of how a NAV invoice would map into Dolibarr.
 * No Dolibarr business object is created or modified here.
 */
class NavInvoiceImportPreview
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * @param array<string,mixed> $parsed
     * @param object $record
     * @param array<string,mixed>|null $partnerMatch
     * @return array<string,mixed>
     */
    public function build(array $parsed, $record, ?array $partnerMatch): array
    {
        $direction = strtoupper((string) ($record->invoice_direction ?? 'OUTBOUND'));
        $inbound = $direction === 'INBOUND';
        $operation = strtoupper((string) ($record->invoice_operation ?? 'CREATE'));
        $invoiceNumber = (string) ($parsed['invoice_number'] ?? $record->invoice_number ?? '');
        $blockers = array();
        $warnings = array();

        $partner = is_array($partnerMatch['match'] ?? null) ? $partnerMatch['match'] : null;
        $partnerStatus = (string) ($partnerMatch['status'] ?? 'none');
        if ($partner === null) {
            $blockers[] = 'partner_missing';
        } elseif (in_array($partnerStatus, array('name', 'candidate'), true)) {
            $warnings[] = 'partner_review';
        }
        if (!empty($partnerMatch['can_fill_tax_number'])) {
            $warnings[] = 'partner_tax_missing';
        }

        if ($operation !== 'CREATE') {
            $blockers[] = 'operation_relation';
        }

        $duplicate = $this->findExistingInvoice($direction, $invoiceNumber);
        if ($duplicate !== null) {
            $blockers[] = 'duplicate';
        }

        $lines = array();
        foreach (($parsed['lines'] ?? array()) as $line) {
            $mapped = $this->mapLine($line);
            if (!empty($mapped['warning'])) {
                $warnings[] = $mapped['warning'];
            }
            $lines[] = $mapped;
        }
        if (!$lines) {
            $blockers[] = 'no_lines';
        }

        $totals = $parsed['totals'] ?? array();
        if (($totals['net'] ?? null) === null || ($totals['vat'] ?? null) === null || ($totals['gross'] ?? null) === null) {
            $warnings[] = 'totals_incomplete';
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));
        $state = $blockers ? 'blocked' : ($warnings ? 'review' : 'ready');

        return array(
            'state' => $state,
            'target_class' => $inbound ? 'FactureFournisseur' : 'Facture',
            'direction' => $direction,
            'operation' => $operation,
            'invoice_number' => $invoiceNumber,
            'partner' => $partner,
            'partner_status' => $partnerStatus,
            'duplicate' => $duplicate,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'header' => array(
                'invoice_date' => (string) ($parsed['invoice_issue_date'] ?? ''),
                'delivery_date' => (string) ($parsed['detail']['delivery_date'] ?? ''),
                'due_date' => (string) ($parsed['detail']['payment_date'] ?? ''),
                'payment_method' => (string) ($parsed['detail']['payment_method'] ?? ''),
                'currency' => (string) ($parsed['detail']['currency'] ?? ''),
                'exchange_rate' => (string) ($parsed['detail']['exchange_rate'] ?? ''),
                'supplier_reference' => $inbound ? $invoiceNumber : '',
                'customer_invoice_reference' => $inbound ? '' : $invoiceNumber,
            ),
            'totals' => $totals,
            'lines' => $lines,
        );
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    private function mapLine(array $line): array
    {
        $vat = $line['vat'] ?? array();
        $kind = (string) ($vat['kind'] ?? '');
        $value = $vat['value'] ?? '';
        $vatRate = null;
        $warning = '';

        if ($kind === 'percentage' && $value !== '') {
            $vatRate = (float) $value * 100;
        } elseif ($kind === 'zero') {
            $vatRate = 0.0;
        } elseif ($kind === 'content' && $value !== '') {
            $content = (float) $value;
            if ($content >= 0 && $content < 1) {
                $vatRate = $content < 1 ? ($content / (1 - $content)) * 100 : null;
                $warning = 'vat_content_derived';
            }
        } elseif ($kind === 'special') {
            $warning = 'vat_special';
        } elseif ($kind === '') {
            $warning = 'vat_missing';
        }

        $qty = $line['quantity'] ?? null;
        $unitPrice = $line['unit_price'] ?? null;
        if (($unitPrice === null || $unitPrice === '') && $qty !== null && $qty !== '' && (float) $qty != 0.0 && ($line['amounts']['net'] ?? null) !== null) {
            $unitPrice = (float) $line['amounts']['net'] / (float) $qty;
        }

        return array(
            'number' => (string) ($line['number'] ?? ''),
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => $qty,
            'unit' => (string) (($line['unit_own'] ?? '') !== '' ? $line['unit_own'] : ($line['unit'] ?? '')),
            'unit_price_ht' => $unitPrice,
            'vat_rate' => $vatRate,
            'vat_label' => (string) ($vat['label'] ?? ''),
            'net' => $line['amounts']['net'] ?? null,
            'vat' => $line['amounts']['vat'] ?? null,
            'gross' => $line['amounts']['gross'] ?? null,
            'warning' => $warning,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findExistingInvoice(string $direction, string $invoiceNumber): ?array
    {
        if ($invoiceNumber === '') {
            return null;
        }

        if ($direction === 'INBOUND') {
            $sql = 'SELECT rowid, ref, ref_supplier FROM '.MAIN_DB_PREFIX.'facture_fourn';
            $sql .= " WHERE entity = ".$this->entity;
            $sql .= " AND ref_supplier = '".$this->db->escape($invoiceNumber)."'";
            $sql .= ' ORDER BY rowid DESC';
            $resql = $this->db->query($sql);
            if (!$resql) {
                throw new Exception($this->db->lasterror());
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($obj) {
                return array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'supplier_ref' => (string) $obj->ref_supplier, 'type' => 'supplier');
            }
            return null;
        }

        $sql = 'SELECT rowid, ref FROM '.MAIN_DB_PREFIX.'facture';
        $sql .= " WHERE entity = ".$this->entity;
        $sql .= " AND ref = '".$this->db->escape($invoiceNumber)."'";
        $sql .= ' ORDER BY rowid DESC';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if ($obj) {
            return array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'type' => 'customer');
        }
        return null;
    }
}
