<?php

dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpolicy.class.php');

/**
 * Extend the established accounting/data preview with NAV operation semantics.
 *
 * The base preview remains responsible for partner, VAT, currency, line and
 * duplicate checks. This wrapper replaces its blanket non-CREATE blocker with
 * the stricter relation/authoritative-chain/mapping policy.
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
        $operation = strtoupper(trim((string) ($preview['operation'] ?? 'CREATE')));
        $preview['operation_mapping'] = $operation === 'CREATE' ? 'standard' : '';
        $preview['source_invoice_id'] = 0;
        $preview['operation_policy'] = null;

        if ($operation === 'CREATE') {
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
