<?php

dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpolicy.class.php');
dol_include_once('/navinvoice/class/navinvoiceaggregatesupport.class.php');
dol_include_once('/navinvoice/class/navproductmatcher.class.php');

/**
 * Extend the established accounting/data preview with NAV operation semantics
 * and conservative NAV-line to Dolibarr-product resolution.
 *
 * The base preview remains responsible for partner, VAT, currency, line and
 * duplicate checks. This wrapper replaces its blanket non-CREATE blocker with
 * the stricter relation/authoritative-chain/mapping policy and enriches every
 * mapped line with an exact product match when one is deterministic.
 */
class NavInvoiceOperationPreview
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var NavInvoiceImportPreview */
    private $basePreview;

    /** @var NavInvoiceOperationPolicy */
    private $operationPolicy;

    /** @var NavInvoiceAggregateSupport */
    private $aggregateSupport;

    /** @var NavProductMatcher */
    private $productMatcher;

    public function __construct($db, int $entity, string $baseCurrency = 'HUF')
    {
        global $langs;
        if (is_object($langs)) {
            $langs->load('navoperation@navinvoice');
        }

        $this->db = $db;
        $this->entity = $entity;
        $this->basePreview = new NavInvoiceImportPreview($db, $entity, $baseCurrency);
        $this->operationPolicy = new NavInvoiceOperationPolicy($db, $entity);
        $this->aggregateSupport = new NavInvoiceAggregateSupport();
        $this->productMatcher = new NavProductMatcher($db, $entity);
    }

    /**
     * @param array<string,mixed> $parsed
     * @param object $record
     * @param array<string,mixed>|null $partnerMatch
     * @return array<string,mixed>
     */
    public function build(array $parsed, $record, ?array $partnerMatch): array
    {
        $sourceXml = (string) ($record->invoice_data ?? '');
        $preview = $this->basePreview->build($parsed, $record, $partnerMatch);
        $preview = $this->aggregateSupport->enrich($preview, $sourceXml);
        $preview = $this->applyNavLineDiscounts($preview, $sourceXml);
        $preview = $this->applyProductMatches($preview);

        $operation = strtoupper(trim((string) ($preview['operation'] ?? 'CREATE')));
        $direction = strtoupper(trim((string) ($preview['direction'] ?? 'OUTBOUND')));
        $isAdvanceInvoice = $operation === 'CREATE' && $this->isAdvanceOnlyInvoice($preview);
        $preview['is_advance_invoice'] = $isAdvanceInvoice;
        $preview['operation_mapping'] = $operation === 'CREATE'
            ? ($isAdvanceInvoice ? 'deposit' : 'standard')
            : '';
        $preview['source_invoice_id'] = 0;
        $preview['standalone_without_master'] = false;
        $preview['operation_policy'] = null;

        // CREATE invoices do not need chain resolution. Deposit import is now
        // enabled for inbound supplier invoices. Outbound deposit invoices stay
        // blocked until NAV-number validation and outbound policy are enabled.
        if ($operation === 'CREATE') {
            if ($isAdvanceInvoice && $direction !== 'INBOUND') {
                $preview['blockers'] = array_values(array_unique(array_merge(
                    array_map('strval', $preview['blockers'] ?? array()),
                    array('advance_invoice_mapping_pending')
                )));
                $preview['state'] = 'blocked';
            }
            return $preview;
        }

        // The base preview intentionally blocks every non-CREATE operation.
        // Replace that generic blocker with concrete operation-policy results.
        $blockers = array_values(array_diff(
            array_map('strval', $preview['blockers'] ?? array()),
            array('operation_relation')
        ));
        $warnings = array_values(array_unique(array_map('strval', $preview['warnings'] ?? array())));

        $policy = $this->operationPolicy->evaluate($parsed, $record);
        $preview['operation_policy'] = $policy;
        $preview['operation_mapping'] = (string) ($policy['mapping'] ?? '');
        $preview['source_invoice_id'] = (int) ($policy['source_invoice_id'] ?? 0);
        $preview['standalone_without_master'] = !empty($policy['standalone_without_master']);

        foreach (($policy['blockers'] ?? array()) as $blocker) {
            $blockers[] = 'operation_'.(string) $blocker;
        }
        foreach (($policy['warnings'] ?? array()) as $warning) {
            $warnings[] = 'operation_'.(string) $warning;
        }

        $partnerId = is_array($preview['partner'] ?? null) ? (int) ($preview['partner']['id'] ?? 0) : 0;
        if ($preview['source_invoice_id'] > 0 && $partnerId > 0
            && !$this->sourceInvoiceBelongsToPartner(
                (string) ($preview['direction'] ?? ''),
                (int) $preview['source_invoice_id'],
                $partnerId
            )) {
            $blockers[] = 'operation_source_partner_mismatch';
        }

        $preview['blockers'] = array_values(array_unique($blockers));
        $preview['warnings'] = array_values(array_unique($warnings));
        $preview['state'] = $preview['blockers'] ? 'blocked' : ($preview['warnings'] ? 'review' : 'ready');
        return $preview;
    }

    /**
     * Replace an effective preview unit price with the original NAV unitPrice
     * when lineDiscountData deterministically explains the authoritative line
     * net amount. This makes the preview match the native Dolibarr
     * unit-price + remise_percent representation restored by the import trigger.
     *
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function applyNavLineDiscounts(array $preview, string $xml): array
    {
        if ($xml === '' || empty($preview['lines']) || !is_array($preview['lines'])) {
            return $preview;
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$document instanceof SimpleXMLElement) {
            return $preview;
        }

        $navLines = $document->xpath('//*[local-name()="invoiceLines"]/*[local-name()="line"]');
        if (!$navLines || count($navLines) !== count($preview['lines'])) {
            return $preview;
        }

        foreach ($navLines as $index => $navLine) {
            if (!isset($preview['lines'][$index]) || !is_array($preview['lines'][$index])) {
                continue;
            }

            $preview['lines'][$index]['discount_percent'] = 0.0;
            $preview['lines'][$index]['discount_value'] = null;
            $preview['lines'][$index]['discount_rate'] = null;
            $preview['lines'][$index]['discount_description'] = '';
            $preview['lines'][$index]['discount_native'] = false;

            $quantityText = $this->xmlText($navLine, './*[local-name()="quantity"]');
            $unitPriceText = $this->xmlText($navLine, './*[local-name()="unitPrice"]');
            $netText = $this->xmlText($navLine, './*[local-name()="lineAmountsNormal"]/*[local-name()="lineNetAmountData"]/*[local-name()="lineNetAmount"]');
            if ($quantityText === '' || $unitPriceText === '' || $netText === ''
                || !is_numeric($quantityText) || !is_numeric($unitPriceText) || !is_numeric($netText)) {
                continue;
            }

            $quantity = (float) $quantityText;
            $unitPrice = (float) $unitPriceText;
            $net = (float) $netText;
            if (abs($quantity) <= 0.000000001) {
                continue;
            }

            $extended = $quantity * $unitPrice;
            if ($this->amountsClose($extended, $net)) {
                continue;
            }

            $discountPercent = $this->validatedDiscountPercent($navLine, $extended, $net);
            if ($discountPercent === null) {
                continue;
            }

            $valueText = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountValue"]');
            $rateText = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountRate"]');
            $description = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountDescription"]');

            $preview['lines'][$index]['unit_price_ht'] = $unitPrice;
            $preview['lines'][$index]['unit_price_adjusted'] = false;
            $preview['lines'][$index]['discount_percent'] = $discountPercent;
            $preview['lines'][$index]['discount_value'] = $valueText !== '' && is_numeric($valueText) ? (float) $valueText : null;
            $preview['lines'][$index]['discount_rate'] = $rateText !== '' && is_numeric($rateText) ? (float) $rateText : null;
            $preview['lines'][$index]['discount_description'] = $description;
            $preview['lines'][$index]['discount_native'] = true;
        }

        return $preview;
    }

    /** @return float|null */
    private function validatedDiscountPercent(SimpleXMLElement $navLine, float $extended, float $net): ?float
    {
        if (abs($extended) <= 0.000000001) {
            return null;
        }

        $rateText = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountRate"]');
        if ($rateText !== '' && is_numeric($rateText)) {
            $rate = abs((float) $rateText);
            if ($rate <= 1.0) {
                $percent = $rate * 100.0;
                if ($percent <= 100.0 && $this->amountsClose($extended * (1.0 - $rate), $net)) {
                    return $percent;
                }
            }
        }

        $valueText = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountValue"]');
        if ($valueText !== '' && is_numeric($valueText)) {
            $discountValue = abs((float) $valueText);
            $percent = 100.0 * $discountValue / abs($extended);
            if ($percent >= 0.0 && $percent <= 100.0
                && $this->amountsClose($extended * (1.0 - ($percent / 100.0)), $net)) {
                return $percent;
            }
        }

        return null;
    }

    private function amountsClose(float $left, float $right): bool
    {
        return abs($left - $right) <= 0.01;
    }

    private function xmlText(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        if (!$nodes) {
            return '';
        }
        return trim((string) $nodes[0]);
    }

    /**
     * A CREATE is considered a deposit/advance invoice only when at least one
     * monetary line exists and every monetary line is explicitly flagged by NAV
     * as advance. Zero-amount descriptive rows are ignored for this decision.
     * This deliberately avoids classifying final invoices that merely contain an
     * advance settlement line as deposit invoices.
     */
    private function isAdvanceOnlyInvoice(array $preview): bool
    {
        $hasMonetaryLine = false;
        foreach (($preview['lines'] ?? array()) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $monetary = abs((float) ($line['net'] ?? 0)) > 0.0000001
                || abs((float) ($line['vat'] ?? 0)) > 0.0000001
                || abs((float) ($line['gross'] ?? 0)) > 0.0000001;
            if (!$monetary) {
                continue;
            }
            $hasMonetaryLine = true;
            if (($line['advance'] ?? null) !== true) {
                return false;
            }
        }
        return $hasMonetaryLine;
    }

    /**
     * Add read-only product resolution to every mapped line. Missing or
     * uncertain product matches never block invoice import: such rows remain
     * free-text invoice lines until the product master data is resolved.
     *
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function applyProductMatches(array $preview): array
    {
        $direction = strtoupper(trim((string) ($preview['direction'] ?? '')));
        $partnerId = is_array($preview['partner'] ?? null) ? (int) ($preview['partner']['id'] ?? 0) : 0;
        $summary = array(
            'matched' => 0,
            'unmatched' => 0,
            'ambiguous' => 0,
            'review' => 0,
        );

        foreach (($preview['lines'] ?? array()) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $match = $this->productMatcher->matchLine($direction, $partnerId, $line);
            $preview['lines'][$index]['product_match'] = $match;
            $preview['lines'][$index]['product_id'] = !empty($match['auto_link']) && is_array($match['product'] ?? null)
                ? (int) ($match['product']['id'] ?? 0)
                : 0;

            $status = (string) ($match['status'] ?? 'none');
            if ($status === 'matched') {
                $summary['matched']++;
            } elseif ($status === 'ambiguous') {
                $summary['ambiguous']++;
            } elseif (in_array($status, array('type_mismatch', 'inactive'), true)) {
                $summary['review']++;
            } else {
                $summary['unmatched']++;
            }
        }

        $preview['product_match_summary'] = $summary;
        return $preview;
    }

    private function sourceInvoiceBelongsToPartner(string $direction, int $invoiceId, int $partnerId): bool
    {
        $direction = strtoupper(trim($direction));
        if ($direction === 'INBOUND') {
            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture_fourn';
        } elseif ($direction === 'OUTBOUND') {
            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture';
        } else {
            return false;
        }

        $sql .= ' WHERE rowid = '.$invoiceId;
        $sql .= ' AND entity = '.$this->entity;
        $sql .= ' AND fk_soc = '.$partnerId;
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to verify source invoice partner: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return (bool) $obj;
    }
}
