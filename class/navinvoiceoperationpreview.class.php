<?php

dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpolicy.class.php');
dol_include_once('/navinvoice/class/navinvoiceaggregatesupport.class.php');
dol_include_once('/navinvoice/class/navproductmatcher.class.php');

/**
 * Extend the accounting/data preview with NAV operation semantics and
 * conservative NAV-line to Dolibarr-product resolution.
 *
 * NavInvoiceParser is the only XML parser. This class consumes the normalized
 * parsed structure and never reparses the raw NAV XML.
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
        $preview = $this->basePreview->build($parsed, $record, $partnerMatch);
        $preview = $this->aggregateSupport->enrich($preview, $parsed);
        $preview = $this->applyParsedLineDiscounts($preview, $parsed);
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
     * Restore the native NAV list-price + line-discount representation from the
     * canonical parsed model. NavInvoiceImportPreview historically flattened
     * such rows to an effective unit price; this compatibility step is now based
     * solely on parsed source semantics and contains no XML recovery logic.
     *
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    private function applyParsedLineDiscounts(array $preview, array $parsed): array
    {
        $sourceLines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : array();
        if (!$sourceLines || empty($preview['lines']) || !is_array($preview['lines'])) {
            return $preview;
        }

        $sourceByNumber = array();
        foreach ($sourceLines as $sourceLine) {
            if (!is_array($sourceLine)) {
                continue;
            }
            $number = trim((string) ($sourceLine['number'] ?? ''));
            if ($number !== '') {
                $sourceByNumber[$number] = $sourceLine;
            }
        }

        foreach ($preview['lines'] as $index => $mappedLine) {
            if (!is_array($mappedLine)) {
                continue;
            }
            $number = trim((string) ($mappedLine['number'] ?? ''));
            $sourceLine = $number !== '' && isset($sourceByNumber[$number])
                ? $sourceByNumber[$number]
                : ($sourceLines[(int) $index] ?? array());
            if (!is_array($sourceLine)) {
                continue;
            }

            $preview['lines'][$index]['discount_percent'] = 0.0;
            $preview['lines'][$index]['discount_value'] = null;
            $preview['lines'][$index]['discount_rate'] = null;
            $preview['lines'][$index]['discount_description'] = '';
            $preview['lines'][$index]['discount_native'] = false;

            $discount = is_array($sourceLine['discount'] ?? null) ? $sourceLine['discount'] : array();
            $quantity = $sourceLine['quantity'] ?? null;
            $unitPrice = $sourceLine['unit_price'] ?? null;
            $net = is_array($sourceLine['amounts'] ?? null) ? ($sourceLine['amounts']['net'] ?? null) : null;
            if (!is_numeric($quantity) || !is_numeric($unitPrice) || !is_numeric($net) || abs((float) $quantity) <= 0.000000001) {
                continue;
            }

            $extended = (float) $quantity * (float) $unitPrice;
            if ($this->amountsClose($extended, (float) $net)) {
                continue;
            }

            $discountPercent = $this->validatedDiscountPercent($discount, $extended, (float) $net);
            if ($discountPercent === null) {
                continue;
            }

            $preview['lines'][$index]['unit_price_ht'] = (float) $unitPrice;
            $preview['lines'][$index]['unit_price_adjusted'] = false;
            $preview['lines'][$index]['discount_percent'] = $discountPercent;
            $preview['lines'][$index]['discount_value'] = isset($discount['value']) && is_numeric($discount['value']) ? (float) $discount['value'] : null;
            $preview['lines'][$index]['discount_rate'] = isset($discount['rate']) && is_numeric($discount['rate']) ? (float) $discount['rate'] : null;
            $preview['lines'][$index]['discount_description'] = trim((string) ($discount['description'] ?? ''));
            $preview['lines'][$index]['discount_native'] = true;
        }

        return $preview;
    }

    /** @param array<string,mixed> $discount */
    private function validatedDiscountPercent(array $discount, float $extended, float $net): ?float
    {
        if (abs($extended) <= 0.000000001) {
            return null;
        }

        if (isset($discount['rate']) && $discount['rate'] !== null && $discount['rate'] !== '' && is_numeric($discount['rate'])) {
            $rate = abs((float) $discount['rate']);
            if ($rate <= 1.0) {
                $percent = $rate * 100.0;
                if ($percent <= 100.0 && $this->amountsClose($extended * (1.0 - $rate), $net)) {
                    return $percent;
                }
            }
        }

        if (isset($discount['value']) && $discount['value'] !== null && $discount['value'] !== '' && is_numeric($discount['value'])) {
            $discountValue = abs((float) $discount['value']);
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

    /**
     * A CREATE is a deposit only when every monetary line is explicitly flagged
     * by NAV as advance. Zero-amount descriptive rows do not affect the decision.
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

    /** @param array<string,mixed> $preview @return array<string,mixed> */
    private function applyProductMatches(array $preview): array
    {
        $direction = strtoupper(trim((string) ($preview['direction'] ?? '')));
        $partnerId = is_array($preview['partner'] ?? null) ? (int) ($preview['partner']['id'] ?? 0) : 0;
        $summary = array('matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'review' => 0);

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
