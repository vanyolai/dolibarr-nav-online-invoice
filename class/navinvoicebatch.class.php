<?php

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceimporter.class.php');

/**
 * Preflight and execute batch imports for NAV inbound invoices.
 *
 * Batch import deliberately accepts READY invoices only and creates Dolibarr
 * supplier invoices as drafts. Every selected record is re-evaluated just
 * before import so a stale browser page cannot bypass duplicate, partner,
 * relation or authoritative NAV-chain checks.
 */
class NavInvoiceBatchService
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var string */
    private $baseCurrency;

    /** @var NavInvoiceParser */
    private $parser;

    /** @var NavPartnerMatcher */
    private $matcher;

    /** @var NavInvoiceOperationPreview */
    private $previewBuilder;

    /** @var NavInvoiceImporter */
    private $importer;

    public function __construct($db, int $entity, string $baseCurrency)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->baseCurrency = strtoupper(trim($baseCurrency));
        $this->parser = new NavInvoiceParser();
        $this->matcher = new NavPartnerMatcher($db, $entity);
        $this->previewBuilder = new NavInvoiceOperationPreview($db, $entity, $this->baseCurrency);
        $this->importer = new NavInvoiceImporter($db, $entity, $this->baseCurrency);
    }

    /** @return array<int,object> */
    public function loadInboundRecords(string $dateFrom, string $dateTo, int $limit = 300): array
    {
        if (!$this->validDate($dateFrom) || !$this->validDate($dateTo)) {
            throw new InvalidArgumentException('Invalid batch import date range.');
        }
        if ($dateFrom > $dateTo) {
            throw new InvalidArgumentException('Batch import start date must not be after end date.');
        }

        $limit = max(1, min($limit, 1000));
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND invoice_direction = 'INBOUND'";
        $sql .= " AND invoice_issue_date >= '".$this->db->escape($dateFrom)."'";
        $sql .= " AND invoice_issue_date <= '".$this->db->escape($dateTo)."'";
        $sql .= ' ORDER BY invoice_issue_date DESC, rowid DESC';
        $sql .= ' LIMIT '.$limit;

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }

        $records = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $obj->_nav_batch_dependency = false;
            $records[] = $obj;
        }
        $this->db->free($resql);

        return $this->expandRelationDependencies($records, $limit);
    }

    /** @return array<string,mixed> */
    public function preflightRecord($record): array
    {
        $row = array(
            'record' => $record,
            'state' => 'blocked',
            'preview' => null,
            'error' => '',
            'notices' => array(),
        );

        if (strtoupper((string) ($record->invoice_direction ?? '')) !== 'INBOUND') {
            $row['error'] = 'Only inbound invoices are supported by batch import.';
            return $row;
        }

        if (empty($record->invoice_data)) {
            $row['preview'] = array(
                'blockers' => array('xml_missing'),
                'warnings' => array(),
                'notices' => array(),
            );
            return $row;
        }

        try {
            $parsed = $this->parser->parse((string) $record->invoice_data);
            $partnerMatch = $this->matcher->match($parsed['supplier'] ?? array(), 'supplier');
            $preview = $this->previewBuilder->build($parsed, $record, $partnerMatch);
            $preview = $this->normalizePreview($preview);

            $row['preview'] = $preview;
            $row['state'] = (string) $preview['state'];
            $row['notices'] = $preview['notices'];
        } catch (Throwable $e) {
            $row['error'] = $e->getMessage();
        }

        return $row;
    }

    /** @param array<int,object> $records @return array<int,array<string,mixed>> */
    public function preflightMany(array $records): array
    {
        $rows = array();
        foreach ($records as $record) {
            $rows[] = $this->preflightRecord($record);
        }
        return $rows;
    }

    /**
     * Import selected READY inbound invoices. Each invoice is isolated: one
     * failure is reported and processing continues with the remaining rows.
     *
     * Selected records are processed in accounting dependency order. This is
     * important when a batch contains an older CREATE invoice pulled in as a
     * dependency of a newer MODIFY/STORNO invoice.
     *
     * @param array<int,int> $ids
     * @param User $user
     * @return array<string,mixed>
     */
    public function importSelected(array $ids, User $user): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function (int $id): bool {
            return $id > 0;
        })));

        if (count($ids) > 300) {
            throw new InvalidArgumentException('A maximum of 300 invoices can be imported in one batch.');
        }

        $result = array(
            'success' => array(),
            'skipped' => array(),
            'errors' => array(),
        );

        $records = array();
        foreach ($ids as $id) {
            try {
                $record = $this->loadRecord($id);
                if ($record === null) {
                    $result['errors'][] = array('id' => $id, 'invoice_number' => '', 'message' => 'NAV mirror record not found.');
                    continue;
                }
                $records[] = $record;
            } catch (Throwable $e) {
                $result['errors'][] = array('id' => $id, 'invoice_number' => '', 'message' => $e->getMessage());
            }
        }

        usort($records, static function ($a, $b): int {
            $dateCompare = strcmp((string) ($a->invoice_issue_date ?? ''), (string) ($b->invoice_issue_date ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            $aOperation = strtoupper((string) ($a->invoice_operation ?? 'CREATE'));
            $bOperation = strtoupper((string) ($b->invoice_operation ?? 'CREATE'));
            if (($aOperation === 'CREATE') !== ($bOperation === 'CREATE')) {
                return $aOperation === 'CREATE' ? -1 : 1;
            }
            $aIndex = (int) ($a->modification_index ?? 0);
            $bIndex = (int) ($b->modification_index ?? 0);
            if ($aIndex !== $bIndex) {
                return $aIndex <=> $bIndex;
            }
            return ((int) ($a->rowid ?? 0)) <=> ((int) ($b->rowid ?? 0));
        });

        foreach ($records as $record) {
            $id = (int) ($record->rowid ?? 0);
            try {
                $preflight = $this->preflightRecord($record);
                $preview = is_array($preflight['preview'] ?? null) ? $preflight['preview'] : null;
                if (($preflight['state'] ?? '') !== 'ready' || $preview === null) {
                    $result['skipped'][] = array(
                        'id' => $id,
                        'invoice_number' => (string) ($record->invoice_number ?? ''),
                        'state' => (string) ($preflight['state'] ?? 'blocked'),
                        'message' => (string) ($preflight['error'] ?? ''),
                    );
                    continue;
                }

                $imported = $this->importer->importDraft($preview, $record, $user);
                $result['success'][] = array(
                    'id' => $id,
                    'invoice_number' => (string) ($record->invoice_number ?? ''),
                    'invoice_id' => (int) $imported['id'],
                    'ref' => (string) $imported['ref'],
                    'url' => (string) $imported['url'],
                    'reconciliation' => (string) ($imported['reconciliation'] ?? ''),
                    'operation_mapping' => (string) ($imported['operation_mapping'] ?? ''),
                );
            } catch (Throwable $e) {
                $result['errors'][] = array(
                    'id' => $id,
                    'invoice_number' => (string) ($record->invoice_number ?? ''),
                    'message' => $e->getMessage(),
                );
            }
        }

        return $result;
    }

    /**
     * Include the prerequisite members of any non-CREATE chain found inside the
     * requested date range. This makes an old master invoice visible/importable
     * without forcing the user to widen the batch date filter manually.
     *
     * @param array<int,object> $records
     * @return array<int,object>
     */
    private function expandRelationDependencies(array $records, int $limit): array
    {
        $byId = array();
        foreach ($records as $record) {
            $byId[(int) $record->rowid] = $record;
        }

        foreach ($records as $record) {
            $operation = strtoupper(trim((string) ($record->invoice_operation ?? 'CREATE')));
            if ($operation === '' || $operation === 'CREATE') {
                continue;
            }

            $root = trim((string) ($record->original_invoice_number ?? ''));
            if ($root === '') {
                continue;
            }
            $currentIndex = ($record->modification_index ?? null) !== null
                ? (int) $record->modification_index
                : null;

            $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
            $sql .= ' WHERE entity = '.$this->entity;
            $sql .= " AND invoice_direction = 'INBOUND'";
            $sql .= " AND (invoice_number = '".$this->db->escape($root)."'";
            $sql .= " OR original_invoice_number = '".$this->db->escape($root)."')";
            $sql .= ' ORDER BY invoice_issue_date ASC, modification_index ASC, rowid ASC';
            $resql = $this->db->query($sql);
            if (!$resql) {
                throw new Exception($this->db->lasterror());
            }

            while ($candidate = $this->db->fetch_object($resql)) {
                $candidateId = (int) $candidate->rowid;
                if (isset($byId[$candidateId])) {
                    continue;
                }

                $candidateNumber = trim((string) ($candidate->invoice_number ?? ''));
                $candidateIndex = ($candidate->modification_index ?? null) !== null
                    ? (int) $candidate->modification_index
                    : null;
                $isMaster = $candidateNumber === $root;

                if (!$isMaster) {
                    if ($currentIndex === null || $currentIndex <= 0) {
                        continue;
                    }
                    if ($candidateIndex === null || $candidateIndex <= 0 || $candidateIndex >= $currentIndex) {
                        continue;
                    }
                }

                $candidate->_nav_batch_dependency = true;
                $candidate->_nav_batch_dependency_for = (string) ($record->invoice_number ?? '');
                $byId[$candidateId] = $candidate;

                if (count($byId) >= $limit) {
                    break 2;
                }
            }
            $this->db->free($resql);
        }

        $expanded = array_values($byId);
        usort($expanded, static function ($a, $b): int {
            $aDependency = !empty($a->_nav_batch_dependency);
            $bDependency = !empty($b->_nav_batch_dependency);
            if ($aDependency !== $bDependency) {
                return $aDependency ? -1 : 1;
            }
            $dateCompare = strcmp((string) ($b->invoice_issue_date ?? ''), (string) ($a->invoice_issue_date ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return ((int) ($b->rowid ?? 0)) <=> ((int) ($a->rowid ?? 0));
        });

        return $expanded;
    }

    /**
     * Move deterministic, audit-only messages out of REVIEW/BLOCKED status.
     * They are still shown to the user, but they do not require a human decision.
     *
     * NORMAL NAV invoices may legitimately contain independently rounded line,
     * VAT-summary and invoice-summary amounts. A line/header totals mismatch is
     * therefore informational here; the importer reconciles the authoritative
     * NAV summary after first trying Dolibarr's native calculation modes.
     *
     * A single remaining partner_missing blocker is promoted to a dedicated
     * partner_required state. This lets the batch UI route the invoice into the
     * NAV taxpayer/third-party resolution workflow instead of presenting it as
     * an undifferentiated import failure.
     *
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    private function normalizePreview(array $preview): array
    {
        $informational = array(
            'simplified_invoice_derived',
            'unit_price_adjusted',
        );

        $warnings = array_values(array_unique(array_map('strval', $preview['warnings'] ?? array())));
        $notices = array_values(array_unique(array_map('strval', $preview['notices'] ?? array())));
        foreach ($warnings as $key => $warning) {
            if (in_array($warning, $informational, true)) {
                $notices[] = $warning;
                unset($warnings[$key]);
            }
        }
        $warnings = array_values($warnings);

        $blockers = array_values(array_unique(array_map('strval', $preview['blockers'] ?? array())));
        if (strtoupper((string) ($preview['category'] ?? '')) === 'NORMAL' && in_array('totals_mismatch', $blockers, true)) {
            $blockers = array_values(array_diff($blockers, array('totals_mismatch')));
            $notices[] = 'totals_mismatch';
        }

        $imported = $this->isImportedMirrorLink($preview);
        if ($imported) {
            $blockers = array_values(array_diff($blockers, array('duplicate')));
        }

        $preview['blockers'] = $blockers;
        $preview['warnings'] = $warnings;
        $preview['notices'] = array_values(array_unique($notices));

        if ($imported) {
            $preview['state'] = 'imported';
        } elseif ($blockers === array('partner_missing')) {
            $preview['state'] = 'partner_required';
        } else {
            $preview['state'] = $blockers ? 'blocked' : ($warnings ? 'review' : 'ready');
        }

        return $preview;
    }

    private function isImportedMirrorLink(array $preview): bool
    {
        $duplicate = is_array($preview['duplicate'] ?? null) ? $preview['duplicate'] : null;
        return $duplicate !== null && ($duplicate['source'] ?? '') === 'mirror_link';
    }

    private function loadRecord(int $id)
    {
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE rowid = '.$id.' AND entity = '.$this->entity;
        $sql .= " AND invoice_direction = 'INBOUND'";
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ?: null;
    }

    private function validDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
