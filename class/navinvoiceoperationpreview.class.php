<?php

dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpolicy.class.php');
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
        $preview = $this->applyProductMatches($preview);

        $operation = strtoupper(trim((string) ($preview['operation'] ?? 'CREATE')));
        $isAdvanceInvoice = $operation === 'CREATE' && $this->isAdvanceOnlyInvoice($preview);
        $preview['is_advance_invoice'] = $isAdvanceInvoice;
        $preview['operation_mapping'] = $operation === 'CREATE'
            ? ($isAdvanceInvoice ? 'deposit' : 'standard')
            : '';
        $preview['source_invoice_id'] = 0;
        $preview['operation_policy'] = null;

        if ($operation === 'CREATE') {
            if ($isAdvanceInvoice) {
                // Do not silently import an advance invoice as TYPE_STANDARD.
                // The preview already knows the intended Dolibarr mapping, but
                // the importer must explicitly support TYPE_DEPOSIT before this
                // case becomes importable.
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
