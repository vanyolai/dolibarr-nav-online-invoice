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

    /** @var string */
    private $baseCurrency;

    public function __construct($db, int $entity, string $baseCurrency = 'HUF')
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
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
        $invoiceNumber = trim((string) ($parsed['invoice_number'] ?? $record->invoice_number ?? ''));
        $currency = strtoupper(trim((string) ($parsed['detail']['currency'] ?? $record->currency ?? '')));
        $blockers = array();
        $warnings = array();

        $partner = is_array($partnerMatch['match'] ?? null) ? $partnerMatch['match'] : null;
        $partnerStatus = (string) ($partnerMatch['status'] ?? 'none');
        if ($partner === null) {
            $blockers[] = 'partner_missing';
        } elseif (!in_array($partnerStatus, array('tax', 'name_address'), true)) {
            $blockers[] = 'partner_review';
        }
        if (!empty($partnerMatch['can_fill_tax_number'])) {
            $warnings[] = 'partner_tax_missing';
        }

        if ($operation !== 'CREATE') {
            $blockers[] = 'operation_relation';
        }
        if ($invoiceNumber === '') {
            $blockers[] = 'invoice_number_missing';
        }
        if (empty($record->invoice_data)) {
            $blockers[] = 'xml_missing';
        }
        if ($currency === '' || $currency !== $this->baseCurrency) {
            $blockers[] = 'currency_unsupported';
        }

        $duplicate = $this->findExistingInvoice($record, $direction, $invoiceNumber, $partner !== null ? (int) $partner['id'] : 0);
        if ($duplicate !== null) {
            $blockers[] = 'duplicate';
        }

        $lines = array();
        foreach (($parsed['lines'] ?? array()) as $line) {
            $mapped = $this->mapLine($line);
            if (!empty($mapped['blocker'])) {
                $blockers[] = (string) $mapped['blocker'];
            }
            if (!empty($mapped['warning'])) {
                $warnings[] = (string) $mapped['warning'];
            }
            $lines[] = $mapped;
        }
        if (!$lines) {
            $blockers[] = 'no_lines';
        }

        $totals = $parsed['totals'] ?? array();
        if (($totals['net'] ?? null) === null || ($totals['vat'] ?? null) === null || ($totals['gross'] ?? null) === null) {
            $blockers[] = 'totals_incomplete';
        } elseif ($lines && !$this->lineTotalsMatchHeader($lines, $totals, $currency)) {
            $blockers[] = 'totals_mismatch';
        }

        $invoiceDate = (string) ($parsed['invoice_issue_date'] ?? '');
        if (!$this->validDate($invoiceDate)) {
            $blockers[] = 'invoice_date_missing';
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
            'external_key' => $this->externalKey($record, $direction, $invoiceNumber),
            'partner' => $partner,
            'partner_status' => $partnerStatus,
            'duplicate' => $duplicate,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'header' => array(
                'invoice_date' => $invoiceDate,
                'delivery_date' => (string) ($parsed['detail']['delivery_date'] ?? ''),
                'due_date' => (string) ($parsed['detail']['payment_date'] ?? ''),
                'payment_method' => (string) ($parsed['detail']['payment_method'] ?? ''),
                'currency' => $currency,
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
        $blocker = '';
        $warning = '';

        if ($kind === 'percentage' && $value !== '') {
            $vatRate = (float) $value * 100;
        } elseif ($kind === 'zero') {
            $vatRate = 0.0;
        } elseif ($kind === 'content') {
            $blocker = 'vat_content_unsupported';
        } elseif ($kind === 'special') {
            $blocker = 'vat_special_unsupported';
        } else {
            $blocker = 'vat_missing';
        }

        $qty = $line['quantity'] ?? null;
        $net = $line['amounts']['net'] ?? null;
        $navUnitPrice = $line['unit_price'] ?? null;
        $unitPrice = null;
        $adjusted = false;

        if ($qty === null || $qty === '' || (float) $qty == 0.0) {
            $blocker = $blocker ?: 'quantity_invalid';
        } elseif ($net === null || $net === '') {
            $blocker = $blocker ?: 'line_net_missing';
        } else {
            // NAV line totals are authoritative. Derive the Dolibarr unit price from
            // line net / quantity so Dolibarr reproduces the authoritative line net
            // instead of introducing a rounding difference from the displayed unit price.
            $unitPrice = (float) $net / (float) $qty;
            if ($navUnitPrice !== null && $navUnitPrice !== '') {
                $adjusted = abs((float) $navUnitPrice - $unitPrice) > 0.000001;
                if ($adjusted) {
                    $warning = 'unit_price_adjusted';
                }
            }
        }

        $nature = strtoupper((string) ($line['nature'] ?? ''));

        return array(
            'number' => (string) ($line['number'] ?? ''),
            'description' => (string) ($line['description'] ?? ''),
            'quantity' => $qty,
            'unit' => (string) (($line['unit_own'] ?? '') !== '' ? $line['unit_own'] : ($line['unit'] ?? '')),
            'nav_unit_price_ht' => $navUnitPrice,
            'unit_price_ht' => $unitPrice,
            'unit_price_adjusted' => $adjusted,
            'vat_rate' => $vatRate,
            'vat_label' => (string) ($vat['label'] ?? ''),
            'net' => $net,
            'vat' => $line['amounts']['vat'] ?? null,
            'gross' => $line['amounts']['gross'] ?? null,
            'product_type' => $nature === 'SERVICE' ? 1 : 0,
            'blocker' => $blocker,
            'warning' => $warning,
        );
    }

    /** @param array<int,array<string,mixed>> $lines @param array<string,mixed> $totals */
    private function lineTotalsMatchHeader(array $lines, array $totals, string $currency): bool
    {
        $net = 0.0;
        $vat = 0.0;
        foreach ($lines as $line) {
            if ($line['net'] === null || $line['net'] === '' || $line['vat'] === null || $line['vat'] === '') {
                return false;
            }
            $net += (float) $line['net'];
            $vat += (float) $line['vat'];
        }
        $gross = $net + $vat;
        $decimals = in_array($currency, array('HUF', 'JPY'), true) ? 0 : 2;

        return round($net, $decimals) == round((float) $totals['net'], $decimals)
            && round($vat, $decimals) == round((float) $totals['vat'], $decimals)
            && round($gross, $decimals) == round((float) $totals['gross'], $decimals);
    }

    /** @return array<string,mixed>|null */
    private function findExistingInvoice($record, string $direction, string $invoiceNumber, int $partnerId): ?array
    {
        $linkedId = $direction === 'INBOUND' ? (int) ($record->fk_facture_fourn ?? 0) : (int) ($record->fk_facture ?? 0);
        if ($linkedId > 0) {
            return array('id' => $linkedId, 'type' => $direction === 'INBOUND' ? 'supplier' : 'customer', 'source' => 'mirror_link');
        }
        if ($invoiceNumber === '') {
            return null;
        }

        $externalKey = $this->externalKey($record, $direction, $invoiceNumber);
        if ($direction === 'INBOUND') {
            $sql = 'SELECT rowid, ref, ref_supplier FROM '.MAIN_DB_PREFIX.'facture_fourn';
            $sql .= ' WHERE entity = '.$this->entity;
            $sql .= " AND (ref_ext = '".$this->db->escape($externalKey)."'";
            if ($partnerId > 0) {
                $sql .= " OR (fk_soc = ".$partnerId." AND ref_supplier = '".$this->db->escape($invoiceNumber)."')";
            }
            $sql .= ') ORDER BY rowid DESC';
            $resql = $this->db->query($sql);
            if (!$resql) {
                throw new Exception($this->db->lasterror());
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($obj) {
                return array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'supplier_ref' => (string) $obj->ref_supplier, 'type' => 'supplier', 'source' => 'dolibarr');
            }
            return null;
        }

        $sql = 'SELECT rowid, ref, ref_client FROM '.MAIN_DB_PREFIX.'facture';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND (ref_ext = '".$this->db->escape($externalKey)."'";
        $sql .= " OR ref = '".$this->db->escape($invoiceNumber)."'";
        $sql .= " OR ref_client = '".$this->db->escape($invoiceNumber)."')";
        $sql .= ' ORDER BY rowid DESC';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if ($obj) {
            return array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'customer_ref' => (string) $obj->ref_client, 'type' => 'customer', 'source' => 'dolibarr');
        }
        return null;
    }

    private function externalKey($record, string $direction, string $invoiceNumber): string
    {
        $batchIndex = (int) ($record->batch_index ?? 0);
        return substr('NAV|'.$direction.'|'.$invoiceNumber.'|'.$batchIndex, 0, 255);
    }

    private function validDate(string $value): bool
    {
        if ($value === '') {
            return false;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
