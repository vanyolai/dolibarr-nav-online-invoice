<?php

dol_include_once('/navinvoice/class/navinvoicelinkmanager.class.php');

/**
 * Resolve NAV CREATE / MODIFY / STORNO relationships from the local mirror.
 *
 * This class does not decide how a non-CREATE document is represented in
 * Dolibarr. It builds the auditable relation graph needed before that decision
 * is allowed: original NAV invoice, modification index, known chain members
 * and the Dolibarr invoice linked to the original mirror row.
 */
class NavInvoiceRelationResolver
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var NavInvoiceLinkManager */
    private $linkManager;

    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->linkManager = new NavInvoiceLinkManager($db, $entity);
    }

    /**
     * @param object $record NAV mirror record.
     * @param array<string,mixed>|null $parsed Parsed InvoiceData when available.
     * @return array<string,mixed>
     */
    public function resolve($record, ?array $parsed = null): array
    {
        $direction = $this->normalizeDirection((string) ($record->invoice_direction ?? 'OUTBOUND'));
        $operation = strtoupper(trim((string) ($record->invoice_operation ?? 'CREATE')));
        $invoiceNumber = trim((string) ($record->invoice_number ?? ''));

        $parsedReference = is_array($parsed['reference'] ?? null) ? $parsed['reference'] : array();
        $originalInvoiceNumber = trim((string) ($parsedReference['original_invoice_number'] ?? $record->original_invoice_number ?? ''));
        $modificationIndex = $parsedReference['modification_index'] ?? $record->modification_index ?? null;
        $modificationIndex = ($modificationIndex === '' || $modificationIndex === null) ? null : (int) $modificationIndex;
        $modifyWithoutMaster = array_key_exists('modify_without_master', $parsedReference)
            ? $parsedReference['modify_without_master']
            : null;

        $result = array(
            'operation' => $operation,
            'direction' => $direction,
            'invoice_number' => $invoiceNumber,
            'requires_relation' => $operation !== 'CREATE',
            'relationship_kind' => $this->relationshipKind($operation),
            'original_invoice_number' => $originalInvoiceNumber,
            'modification_index' => $modificationIndex,
            'modify_without_master' => $modifyWithoutMaster,
            'standalone_without_master' => false,
            'original_record' => null,
            'original_dolibarr_invoice_id' => 0,
            'original_dolibarr_url' => '',
            'chain' => array(),
            'prior_unimported' => array(),
            'blockers' => array(),
            'warnings' => array(),
            'ready' => true,
        );

        if ($operation === 'CREATE') {
            $root = $invoiceNumber;
            if ($root !== '') {
                $result['chain'] = $this->loadChain($direction, $root);
            }
            return $result;
        }

        if (!in_array($operation, array('MODIFY', 'STORNO'), true)) {
            $result['blockers'][] = 'operation_unsupported';
        }
        if ($originalInvoiceNumber === '') {
            $result['blockers'][] = 'original_invoice_number_missing';
        }
        if ($modificationIndex === null || $modificationIndex <= 0) {
            $result['warnings'][] = 'modification_index_missing';
        }

        if ($originalInvoiceNumber !== '') {
            $original = $this->findOriginalRecord($direction, $originalInvoiceNumber);
            $result['original_record'] = $original;
            $result['chain'] = $this->loadChain($direction, $originalInvoiceNumber);

            if ($original === null) {
                if ($modifyWithoutMaster === true) {
                    // NAV explicitly permits an antecedent-less modification.
                    // There is no source Dolibarr invoice to link, but this is a
                    // legitimate relation state rather than a broken chain. The
                    // operation policy still verifies the authoritative NAV chain
                    // and the financial mapping before import is allowed.
                    $result['standalone_without_master'] = true;
                    $result['warnings'][] = 'original_mirror_missing_allowed';
                } else {
                    $result['blockers'][] = 'original_mirror_missing';
                }
            } else {
                $linkedId = $this->linkManager->resolve($original, $direction);
                $result['original_dolibarr_invoice_id'] = $linkedId;
                if ($linkedId > 0) {
                    $result['original_dolibarr_url'] = $direction === 'INBOUND'
                        ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedId
                        : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$linkedId;
                } else {
                    $result['blockers'][] = 'original_not_imported';
                }
            }
        }

        if ($modificationIndex !== null && $modificationIndex > 0 && $result['chain']) {
            $sameIndex = 0;
            foreach ($result['chain'] as $item) {
                if (strtoupper((string) ($item['operation'] ?? '')) === 'CREATE') {
                    continue;
                }
                $itemModificationIndex = (int) ($item['modification_index'] ?? 0);
                if ($itemModificationIndex === $modificationIndex) {
                    $sameIndex++;
                }
                if ($itemModificationIndex > 0
                    && $itemModificationIndex < $modificationIndex
                    && (int) ($item['dolibarr_invoice_id'] ?? 0) <= 0) {
                    $result['prior_unimported'][] = $item;
                }
            }
            if ($sameIndex > 1) {
                $result['blockers'][] = 'modification_index_ambiguous';
            }
            if ($result['prior_unimported']) {
                $result['blockers'][] = 'prior_modification_not_imported';
            }
        }

        $result['blockers'] = array_values(array_unique(array_map('strval', $result['blockers'])));
        $result['warnings'] = array_values(array_unique(array_map('strval', $result['warnings'])));
        $result['ready'] = !$result['blockers'];
        return $result;
    }

    /**
     * @return object|null
     */
    private function findOriginalRecord(string $direction, string $invoiceNumber)
    {
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND invoice_direction = '".$this->db->escape($direction)."'";
        $sql .= " AND invoice_number = '".$this->db->escape($invoiceNumber)."'";
        $sql .= " AND UPPER(COALESCE(invoice_operation, 'CREATE')) = 'CREATE'";
        $sql .= ' ORDER BY batch_index ASC, rowid ASC';
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to resolve original NAV invoice: '.$this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadChain(string $direction, string $originalInvoiceNumber): array
    {
        $sql = 'SELECT rowid, invoice_number, batch_index, invoice_operation, invoice_issue_date,';
        $sql .= ' original_invoice_number, modification_index, transaction_id, transaction_index,';
        $sql .= ' fk_facture, fk_facture_fourn';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND invoice_direction = '".$this->db->escape($direction)."'";
        $sql .= " AND (invoice_number = '".$this->db->escape($originalInvoiceNumber)."'";
        $sql .= " OR original_invoice_number = '".$this->db->escape($originalInvoiceNumber)."')";
        $sql .= " ORDER BY CASE WHEN UPPER(COALESCE(invoice_operation, 'CREATE')) = 'CREATE' THEN 0 ELSE 1 END,";
        $sql .= ' COALESCE(modification_index, 0), invoice_issue_date, transaction_index, rowid';

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to load NAV invoice relation chain: '.$this->db->lasterror());
        }

        $items = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $operation = strtoupper(trim((string) ($obj->invoice_operation ?: 'CREATE')));
            $linkedId = $this->linkManager->resolve($obj, $direction);
            $items[] = array(
                'id' => (int) $obj->rowid,
                'invoice_number' => (string) $obj->invoice_number,
                'batch_index' => (int) $obj->batch_index,
                'operation' => $operation,
                'invoice_issue_date' => (string) $obj->invoice_issue_date,
                'original_invoice_number' => (string) $obj->original_invoice_number,
                'modification_index' => $obj->modification_index === null ? null : (int) $obj->modification_index,
                'transaction_id' => (string) $obj->transaction_id,
                'transaction_index' => $obj->transaction_index === null ? null : (int) $obj->transaction_index,
                'dolibarr_invoice_id' => $linkedId,
                'dolibarr_url' => $linkedId > 0
                    ? ($direction === 'INBOUND'
                        ? DOL_URL_ROOT.'/fourn/facture/card.php?facid='.$linkedId
                        : DOL_URL_ROOT.'/compta/facture/card.php?facid='.$linkedId)
                    : '',
            );
        }
        $this->db->free($resql);
        return $items;
    }

    private function relationshipKind(string $operation): string
    {
        if ($operation === 'MODIFY') {
            return 'modification';
        }
        if ($operation === 'STORNO') {
            return 'storno';
        }
        if ($operation === 'CREATE') {
            return 'master';
        }
        return 'unknown';
    }

    private function normalizeDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, array('INBOUND', 'OUTBOUND'), true)) {
            throw new InvalidArgumentException('NAV invoice direction must be INBOUND or OUTBOUND.');
        }
        return $direction;
    }
}
