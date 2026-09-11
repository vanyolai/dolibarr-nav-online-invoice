<?php

dol_include_once('/navinvoice/class/navunitresolver.class.php');
dol_include_once('/navinvoice/class/navinvoicelinkmanager.class.php');

/**
 * Build a read-only preview of how a NAV invoice would map into Dolibarr.
 * No Dolibarr business object is created or modified here, except repairing
 * stale NAV mirror links to Dolibarr invoices that no longer exist.
 */
class NavInvoiceImportPreview
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var string */
    private $baseCurrency;

    /** @var NavUnitResolver */
    private $unitResolver;

    /** @var NavInvoiceLinkManager */
    private $linkManager;

    public function __construct($db, int $entity, string $baseCurrency = 'HUF')
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
        $this->unitResolver = new NavUnitResolver($db);
        $this->linkManager = new NavInvoiceLinkManager($db, $entity);
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
        $category = strtoupper(trim((string) ($parsed['detail']['category'] ?? $record->invoice_category ?? '')));
        $simplified = $category === 'SIMPLIFIED';
        $externalPartyKey = $inbound ? 'supplier' : 'customer';
        $navCountryCode = strtoupper(trim((string) ($parsed[$externalPartyKey]['address']['country_code'] ?? '')));
        $blockers = array();
        $warnings = array();

        $partner = is_array($partnerMatch['match'] ?? null) ? $partnerMatch['match'] : null;
        $partnerStatus = (string) ($partnerMatch['status'] ?? 'none');
        $partnerCountry = null;
        if ($partner === null) {
            $blockers[] = 'partner_missing';
        } elseif (!in_array($partnerStatus, array('tax', 'name_address'), true)) {
            $blockers[] = 'partner_review';
        } else {
            $partnerCountry = $this->partnerCountry((int) $partner['id']);
            $partnerCountryCode = strtoupper(trim((string) ($partnerCountry['country_code'] ?? '')));
            if ($partnerCountryCode === '') {
                // Dolibarr needs the third-party country to determine VAT rules
                // when invoice lines are created or edited.
                $blockers[] = 'partner_country_missing';
            } elseif ($navCountryCode !== '' && $partnerCountryCode !== $navCountryCode) {
                // A country mismatch can change VAT treatment, so never guess.
                $blockers[] = 'partner_country_mismatch';
            }
        }
        if (!empty($partnerMatch['can_fill_tax_number'])) {
            $warnings[] = 'partner_tax_missing';
        }

        if ($operation !== 'CREATE') {
            $blockers[] = 'operation_relation';
        }
        if (!in_array($category, array('NORMAL', 'SIMPLIFIED'), true)) {
            $blockers[] = 'category_unsupported';
        }
        if ($simplified) {
            $warnings[] = 'simplified_invoice_derived';
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
            $mapped = $this->mapLine($line, $category);
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
        if ($simplified) {
            // A simplified NAV invoice reports gross amounts and VAT content.
            // Net and VAT are derived values, therefore gross is authoritative.
            if (($totals['gross'] ?? null) === null) {
                $blockers[] = 'totals_incomplete';
            } elseif ($lines && !$this->lineTotalsMatchHeader($lines, $totals, $currency, $category)) {
                $blockers[] = 'totals_mismatch';
            }
        } else {
            if (($totals['net'] ?? null) === null || ($totals['vat'] ?? null) === null || ($totals['gross'] ?? null) === null) {
                $blockers[] = 'totals_incomplete';
            } elseif ($lines && !$this->lineTotalsMatchHeader($lines, $totals, $currency, $category)) {
                $blockers[] = 'totals_mismatch';
            }
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
            'category' => $category,
            'amount_basis' => $simplified ? 'gross' : 'net',
            'invoice_number' => $invoiceNumber,
            'external_key' => $this->externalKey($record, $direction, $invoiceNumber),
            'partner' => $partner,
            'partner_status' => $partnerStatus,
            'partner_country' => $partnerCountry,
            'nav_country_code' => $navCountryCode,
            'duplicate' => $duplicate,
            'blockers' => $blockers,
            'warnings' => $warnings,
            'units_enabled' => $this->unitResolver->isEnabled(),
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
    private function mapLine(array $line, string $category): array
    {
        $simplified = $category === 'SIMPLIFIED';
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
            if ($simplified && $value !== '') {
                $vatRate = $this->vatRateFromContent((float) $value);
                if ($vatRate === null) {
                    $blocker = 'vat_content_unsupported';
                }
            } else {
                $blocker = 'vat_content_unsupported';
            }
        } elseif ($kind === 'special') {
            $blocker = 'vat_special_unsupported';
        } else {
            $blocker = 'vat_missing';
        }

        $qty = $line['quantity'] ?? null;
        $sourceNet = $line['amounts']['net'] ?? null;
        $sourceGross = $line['amounts']['gross'] ?? null;
        $navUnitPrice = $line['unit_price'] ?? null;
        $unitPrice = null;
        $net = $sourceNet;
        $vatAmount = $line['amounts']['vat'] ?? null;
        $gross = $sourceGross;
        $adjusted = false;
        $quantityDerived = false;
        $unitPriceDerived = false;
        $nonExpressionLine = array_key_exists('expression', $line) && $line['expression'] === false;

        if ($qty === null || $qty === '' || (float) $qty == 0.0) {
            if ($nonExpressionLine) {
                // NAV allows quantity and unitPrice to be omitted when the line
                // cannot be expressed in a natural unit. Dolibarr still needs a
                // quantity, so represent the complete line as one technical unit.
                // This does not claim that the source invoice contained quantity 1.
                $qty = 1;
                $quantityDerived = true;
            } else {
                $blocker = $blocker ?: 'quantity_invalid';
            }
        }

        if ($qty !== null && $qty !== '' && (float) $qty != 0.0) {
            if ($simplified) {
                if ($gross === null || $gross === '') {
                    $blocker = $blocker ?: 'line_gross_missing';
                } elseif ($vatRate !== null) {
                    // On SIMPLIFIED invoices NAV unit price and line amount are gross.
                    // Convert authoritative gross to the legal VAT rate Dolibarr needs,
                    // then derive an HT unit price without treating NAV unitPrice as HT.
                    $grossFloat = (float) $gross;
                    $netFloat = $vatRate == 0.0 ? $grossFloat : $grossFloat / (1 + ($vatRate / 100));
                    $net = $this->decimal($netFloat);
                    $vatAmount = $this->decimal($grossFloat - $netFloat);
                    $unitPrice = $netFloat / (float) $qty;
                    $unitPriceDerived = $quantityDerived || $navUnitPrice === null || $navUnitPrice === '';
                }
            } elseif ($sourceNet === null || $sourceNet === '') {
                $blocker = $blocker ?: 'line_net_missing';
            } else {
                // For NORMAL invoices preserve NAV's explicit unitPrice exactly.
                // Reconstructing it from lineNetAmount / quantity loses source
                // precision (for example 1129.527 becomes 1129.525). Only derive
                // a technical unit price when NAV omitted unitPrice altogether.
                if ($navUnitPrice !== null && $navUnitPrice !== '') {
                    $unitPrice = (float) $navUnitPrice;
                    $unitPriceDerived = false;
                } else {
                    $unitPrice = (float) $sourceNet / (float) $qty;
                    $unitPriceDerived = true;
                }
            }
        }

        // Supplier reference priority:
        // 1) NAV productCodes/OWN = issuer's own product code.
        // 2) conventionalLineInfo/itemNumbers/itemNumber = conventional item id.
        // Other code categories (GTIN/VTSZ/TESZOR/etc.) are not supplier refs.
        $supplierRef = '';
        $supplierRefSource = '';
        foreach (($line['product_codes'] ?? array()) as $productCode) {
            $codeCategory = strtoupper(trim((string) ($productCode['category'] ?? '')));
            $codeValue = trim((string) ($productCode['value'] ?? ''));
            if ($codeCategory === 'OWN' && $codeValue !== '') {
                $supplierRef = $codeValue;
                $supplierRefSource = 'own';
                break;
            }
        }
        if ($supplierRef === '') {
            foreach (($line['item_numbers'] ?? array()) as $itemNumber) {
                $itemNumber = trim((string) $itemNumber);
                if ($itemNumber !== '') {
                    $supplierRef = $itemNumber;
                    $supplierRefSource = 'item_number';
                    break;
                }
            }
        }

        $nature = strtoupper((string) ($line['nature'] ?? ''));
        $unitResolution = $this->unitResolver->resolve($line);

        return array(
            'number' => (string) ($line['number'] ?? ''),
            'description' => (string) ($line['description'] ?? ''),
            'expression' => $line['expression'] ?? null,
            'quantity' => $qty,
            'quantity_derived' => $quantityDerived,
            'unit' => (string) ($unitResolution['source'] ?? ''),
            'unit_id' => (int) ($unitResolution['id'] ?? 0),
            'unit_code' => (string) ($unitResolution['code'] ?? ''),
            'unit_short_label' => (string) ($unitResolution['short_label'] ?? ''),
            'unit_status' => (string) ($unitResolution['status'] ?? ''),
            'nav_unit_price' => $navUnitPrice,
            'nav_unit_price_basis' => $simplified ? 'gross' : 'net',
            'nav_unit_price_ht' => $simplified ? null : $navUnitPrice,
            'unit_price_ht' => $unitPrice,
            'unit_price_derived' => $unitPriceDerived,
            'unit_price_adjusted' => $adjusted,
            'supplier_ref' => $supplierRef,
            'supplier_ref_source' => $supplierRefSource,
            'product_codes' => is_array($line['product_codes'] ?? null) ? $line['product_codes'] : array(),
            'item_numbers' => is_array($line['item_numbers'] ?? null) ? $line['item_numbers'] : array(),
            'vat_rate' => $vatRate,
            'vat_content' => $kind === 'content' ? $value : null,
            'vat_label' => (string) ($vat['label'] ?? ''),
            'net' => $net,
            'vat' => $vatAmount,
            'gross' => $gross,
            'product_type' => $nature === 'SERVICE' ? 1 : 0,
            'blocker' => $blocker,
            'warning' => $warning,
        );
    }

    /**
     * Convert NAV VAT-content ratio from a simplified invoice to the legal
     * VAT percentage Dolibarr expects.
     */
    private function vatRateFromContent(float $content): ?float
    {
        if ($content < 0 || $content >= 1) {
            return null;
        }

        // Do not use floating point values as PHP array keys: they are cast to int.
        $known = array(
            array(0.0476, 5.0),
            array(0.1525, 18.0),
            array(0.2126, 27.0),
        );
        foreach ($known as $entry) {
            if (abs($content - $entry[0]) <= 0.00005) {
                return $entry[1];
            }
        }

        // VAT content = r / (100 + r). NAV stores a rounded content value,
        // therefore only accept inversion when it is very close to an integer rate.
        $derived = 100 * $content / (1 - $content);
        $integerRate = round($derived);
        return abs($derived - $integerRate) <= 0.05 ? (float) $integerRate : null;
    }

    /** @param array<int,array<string,mixed>> $lines @param array<string,mixed> $totals */
    private function lineTotalsMatchHeader(array $lines, array $totals, string $currency, string $category): bool
    {
        $decimals = in_array($currency, array('HUF', 'JPY'), true) ? 0 : 2;

        if ($category === 'SIMPLIFIED') {
            $gross = 0.0;
            foreach ($lines as $line) {
                if ($line['gross'] === null || $line['gross'] === '') {
                    return false;
                }
                $gross += (float) $line['gross'];
            }
            return round($gross, $decimals) == round((float) $totals['gross'], $decimals);
        }

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

        return round($net, $decimals) == round((float) $totals['net'], $decimals)
            && round($vat, $decimals) == round((float) $totals['vat'], $decimals)
            && round($gross, $decimals) == round((float) $totals['gross'], $decimals);
    }

    private function decimal(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }

    /** @return array<string,mixed>|null */
    private function partnerCountry(int $partnerId): ?array
    {
        if ($partnerId <= 0) {
            return null;
        }

        $sql = 'SELECT s.fk_pays, c.code AS country_code, c.label AS country_label';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'societe AS s';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_country AS c ON c.rowid = s.fk_pays';
        $sql .= ' WHERE s.rowid = '.$partnerId.' AND s.entity = '.$this->entity;
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }

        return array(
            'id' => (int) $obj->fk_pays,
            'country_code' => (string) $obj->country_code,
            'country_label' => (string) $obj->country_label,
        );
    }

    /** @return array<string,mixed>|null */
    private function findExistingInvoice($record, string $direction, string $invoiceNumber, int $partnerId): ?array
    {
        // Validate the stored mirror link first. If the draft was deleted in
        // Dolibarr, resolve() clears the stale link and import can proceed again.
        $linkedId = $this->linkManager->resolve($record, $direction);
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
