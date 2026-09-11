<?php

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceimporter.class.php');

/**
 * Preflight and execute batch imports for NAV inbound invoices.
 *
 * Batch import deliberately accepts READY invoices only and creates Dolibarr
 * supplier invoices as drafts. Every selected record is re-evaluated just
 * before import so a stale browser page cannot bypass duplicate or partner
 * checks performed by NavInvoiceImportPreview.
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

    /** @var NavInvoiceImportPreview */
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
        $this->previewBuilder = new NavInvoiceImportPreview($db, $entity, $this->baseCurrency);
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
            $records[] = $obj;
        }
        $this->db->free($resql);
        return $records;
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

        foreach ($ids as $id) {
            $record = null;
            try {
                $record = $this->loadRecord($id);
                if ($record === null) {
                    $result['errors'][] = array('id' => $id, 'invoice_number' => '', 'message' => 'NAV mirror record not found.');
                    continue;
                }

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
                );
            } catch (Throwable $e) {
                $result['errors'][] = array(
                    'id' => $id,
                    'invoice_number' => is_object($record) ? (string) ($record->invoice_number ?? '') : '',
                    'message' => $e->getMessage(),
                );
            }
        }

        return $result;
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
