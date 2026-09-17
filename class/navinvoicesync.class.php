<?php

require_once __DIR__.'/navapi.class.php';
require_once __DIR__.'/navinvoiceparser.class.php';

class NavInvoiceSync
{
    /** @var DoliDB */
    private $db;

    /** @var NavInvoiceParser */
    private $parser;

    public string $error = '';
    public array $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
        $this->parser = new NavInvoiceParser();
    }

    public function runScheduledSync(): int
    {
        if (!getDolGlobalInt('NAVINVOICE_SYNC_ENABLED')) {
            return 0;
        }

        $days = max(1, min(35, getDolGlobalInt('NAVINVOICE_SYNC_LOOKBACK_DAYS', 7)));
        $to = new DateTimeImmutable('today');
        $from = $to->modify('-'.($days - 1).' days');

        try {
            $stats = $this->syncPeriod(
                $from->format('Y-m-d'),
                $to->format('Y-m-d'),
                (bool) getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1),
                'BOTH'
            );
            dol_syslog(__METHOD__.': NAV sync completed: '.json_encode($stats), LOG_INFO);
            return 0;
        } catch (Throwable $e) {
            $this->error = $e->getMessage();
            $this->errors[] = $this->error;
            dol_syslog(__METHOD__.': '.$this->error, LOG_ERR);
            return -1;
        }
    }

    /**
     * Synchronize NAV invoice digests and optionally the complete XML payloads.
     *
     * @param callable|null $progressCallback function(array<string,mixed>): void
     */
    public function syncPeriod(
        string $dateFrom,
        string $dateTo,
        bool $fetchFullData = true,
        string $direction = 'BOTH',
        ?callable $progressCallback = null
    ): array {
        $start = DateTimeImmutable::createFromFormat('!Y-m-d', $dateFrom);
        $end = DateTimeImmutable::createFromFormat('!Y-m-d', $dateTo);
        if (!$start || !$end || $end < $start) {
            throw new Exception('Invalid synchronization period.');
        }

        $direction = strtoupper(trim($direction));
        if (!in_array($direction, array('OUTBOUND', 'INBOUND', 'BOTH'), true)) {
            throw new Exception('Synchronization direction must be OUTBOUND, INBOUND or BOTH.');
        }
        $directions = $direction === 'BOTH' ? array('OUTBOUND', 'INBOUND') : array($direction);

        $days = (int) $start->diff($end)->format('%a') + 1;
        $chunksPerDirection = max(1, (int) ceil($days / 35));
        $totalChunks = $chunksPerDirection * count($directions);
        $chunkIndex = 0;

        $api = new NavInvoiceApi();
        $stats = array(
            'seen' => 0,
            'inserted' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'downloaded' => 0,
            'chunks' => 0,
            'outbound' => 0,
            'inbound' => 0,
            'api_requests' => 0,
        );

        foreach ($directions as $currentDirection) {
            $chunkStart = $start;
            while ($chunkStart <= $end) {
                $chunkEnd = $chunkStart->modify('+34 days');
                if ($chunkEnd > $end) {
                    $chunkEnd = $end;
                }
                $chunkIndex++;
                $stats['chunks']++;

                $this->notifyProgress($progressCallback, array_merge($stats, array(
                    'stage' => 'chunk',
                    'current_direction' => $currentDirection,
                    'chunk_from' => $chunkStart->format('Y-m-d'),
                    'chunk_to' => $chunkEnd->format('Y-m-d'),
                    'chunk_index' => $chunkIndex,
                    'chunk_total' => $totalChunks,
                    'page' => null,
                    'available_page' => null,
                    'record_index' => null,
                    'record_total' => null,
                    'current_invoice' => null,
                )));

                $this->syncChunk(
                    $api,
                    $chunkStart->format('Y-m-d'),
                    $chunkEnd->format('Y-m-d'),
                    $fetchFullData,
                    $currentDirection,
                    $stats,
                    $progressCallback,
                    $chunkIndex,
                    $totalChunks
                );

                $this->notifyProgress($progressCallback, array_merge($stats, array(
                    'stage' => 'chunk_done',
                    'current_direction' => $currentDirection,
                    'chunk_from' => $chunkStart->format('Y-m-d'),
                    'chunk_to' => $chunkEnd->format('Y-m-d'),
                    'chunk_index' => $chunkIndex,
                    'chunk_total' => $totalChunks,
                    'record_index' => null,
                    'record_total' => null,
                    'current_invoice' => null,
                )));

                $chunkStart = $chunkEnd->modify('+1 day');
            }
        }

        $this->notifyProgress($progressCallback, array_merge($stats, array(
            'stage' => 'done',
            'chunk_index' => $totalChunks,
            'chunk_total' => $totalChunks,
            'record_index' => null,
            'record_total' => null,
            'current_invoice' => null,
        )));

        return $stats;
    }

    /**
     * Backward-compatible schema migration for installations upgraded in place.
     * New installs use the SQL definitions under sql/. This method is invoked
     * only from module activation/upgrade; normal synchronization never mutates
     * database schema.
     */
    public function migrateLegacySchema(): void
    {
        $table = MAIN_DB_PREFIX.'navinvoice_invoice';

        $resql = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'invoice_direction'");
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $hasDirection = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$hasDirection) {
            if (!$this->db->query("ALTER TABLE ".$table." ADD invoice_direction varchar(8) NOT NULL DEFAULT 'OUTBOUND' AFTER entity")) {
                throw new Exception($this->db->lasterror());
            }
        }

        // The issuer/supplier is part of invoice identity. Invoice numbers are
        // not globally unique across suppliers; the old key could collapse two
        // unrelated inbound invoices with the same number.
        $resql = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'supplier_tax_number'");
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $supplierTaxColumn = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$supplierTaxColumn) {
            if (!$this->db->query("ALTER TABLE ".$table." ADD supplier_tax_number varchar(20) NOT NULL DEFAULT '' AFTER invoice_issue_date")) {
                throw new Exception($this->db->lasterror());
            }
        }

        // Existing installations may already contain thousands of rows created
        // before supplier tax became part of the mirror identity. Recover it
        // before replacing the unique key, first from the stored digest and then
        // from the canonical InvoiceData parser. This avoids re-sync creating a
        // second mirror row for the same historical invoice.
        $this->backfillSupplierTaxNumbers($table);

        if (!$this->db->query("UPDATE ".$table." SET supplier_tax_number = '' WHERE supplier_tax_number IS NULL")) {
            throw new Exception($this->db->lasterror());
        }
        if ($supplierTaxColumn && strtoupper((string) ($supplierTaxColumn->Null ?? 'YES')) !== 'NO') {
            if (!$this->db->query("ALTER TABLE ".$table." MODIFY supplier_tax_number varchar(20) NOT NULL DEFAULT ''")) {
                throw new Exception($this->db->lasterror());
            }
        }

        $expectedUnique = array('entity', 'invoice_direction', 'supplier_tax_number', 'invoice_number', 'batch_index');
        $existingUnique = $this->indexColumns($table, 'uk_navinvoice_invoice');
        if ($existingUnique !== $expectedUnique) {
            if ($existingUnique && !$this->db->query("ALTER TABLE ".$table." DROP INDEX uk_navinvoice_invoice")) {
                throw new Exception($this->db->lasterror());
            }
            if (!$this->db->query(
                "ALTER TABLE ".$table." ADD UNIQUE INDEX uk_navinvoice_invoice "
                ."(entity, invoice_direction, supplier_tax_number, invoice_number, batch_index)"
            )) {
                throw new Exception($this->db->lasterror());
            }
        }

        $resql = $this->db->query("SHOW COLUMNS FROM ".$table." LIKE 'fk_facture_fourn'");
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $hasSupplierInvoiceLink = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$hasSupplierInvoiceLink) {
            if (!$this->db->query("ALTER TABLE ".$table." ADD fk_facture_fourn integer NULL AFTER fk_facture")) {
                throw new Exception($this->db->lasterror());
            }
            if (!$this->db->query("ALTER TABLE ".$table." ADD INDEX idx_navinvoice_fk_facture_fourn (fk_facture_fourn)")) {
                throw new Exception($this->db->lasterror());
            }
        }

        if (!$this->indexColumns($table, 'idx_navinvoice_supplier_tax')) {
            if (!$this->db->query("ALTER TABLE ".$table." ADD INDEX idx_navinvoice_supplier_tax (entity, supplier_tax_number)")) {
                throw new Exception($this->db->lasterror());
            }
        }
    }

    private function backfillSupplierTaxNumbers(string $table): void
    {
        $sql = 'SELECT rowid, raw_digest, invoice_data FROM '.$table;
        $sql .= " WHERE supplier_tax_number IS NULL OR TRIM(supplier_tax_number) = ''";
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }

        $updates = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $taxNumber = '';
            $rawDigest = trim((string) ($obj->raw_digest ?? ''));
            if ($rawDigest !== '') {
                $decoded = json_decode($rawDigest, true);
                if (is_array($decoded)) {
                    $taxNumber = trim((string) ($decoded['supplierTaxNumber'] ?? $decoded['supplier_tax_number'] ?? ''));
                }
            }

            if ($taxNumber === '') {
                $xml = trim((string) ($obj->invoice_data ?? ''));
                if ($xml !== '') {
                    try {
                        $parsed = $this->parser->parse($xml);
                        $taxNumber = trim((string) ($parsed['supplier']['tax_number'] ?? ''));
                    } catch (Throwable $e) {
                        dol_syslog(__METHOD__.': cannot backfill mirror row '.((int) $obj->rowid).': '.$e->getMessage(), LOG_WARNING);
                    }
                }
            }

            if ($taxNumber !== '') {
                $updates[(int) $obj->rowid] = $taxNumber;
            }
        }
        $this->db->free($resql);

        foreach ($updates as $rowid => $taxNumber) {
            $sql = 'UPDATE '.$table;
            $sql .= " SET supplier_tax_number = '".$this->db->escape($taxNumber)."'";
            $sql .= ' WHERE rowid = '.$rowid;
            $sql .= " AND (supplier_tax_number IS NULL OR TRIM(supplier_tax_number) = '')";
            if (!$this->db->query($sql)) {
                throw new Exception($this->db->lasterror());
            }
        }
    }

    /** @return string[] */
    private function indexColumns(string $table, string $indexName): array
    {
        $resql = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = '".$this->db->escape($indexName)."'");
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $columns = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $columns[(int) $obj->Seq_in_index] = (string) $obj->Column_name;
        }
        $this->db->free($resql);
        if (!$columns) {
            return array();
        }
        ksort($columns);
        return array_values($columns);
    }

    private function syncChunk(
        NavInvoiceApi $api,
        string $from,
        string $to,
        bool $fetchFullData,
        string $direction,
        array &$stats,
        ?callable $progressCallback,
        int $chunkIndex,
        int $chunkTotal
    ): void {
        $page = 1;
        $availablePage = 1;

        do {
            $stats['api_requests']++;
            $this->notifyProgress($progressCallback, array_merge($stats, array(
                'stage' => 'digest_request',
                'current_direction' => $direction,
                'chunk_from' => $from,
                'chunk_to' => $to,
                'chunk_index' => $chunkIndex,
                'chunk_total' => $chunkTotal,
                'page' => $page,
                'available_page' => $availablePage,
                'record_index' => 0,
                'record_total' => 0,
                'current_invoice' => null,
            )));

            $response = $api->queryInvoiceDigest($from, $to, $page, $direction);
            $pageNodes = $response->xpath('//*[local-name()="invoiceDigestResult"]/*[local-name()="availablePage"]');
            $availablePage = $pageNodes ? max(1, (int) $pageNodes[0]) : 1;
            $digests = $response->xpath('//*[local-name()="invoiceDigestResult"]/*[local-name()="invoiceDigest"]');
            $digestRows = $digests ?: array();
            $recordTotal = count($digestRows);

            $this->notifyProgress($progressCallback, array_merge($stats, array(
                'stage' => 'digest_page',
                'current_direction' => $direction,
                'chunk_from' => $from,
                'chunk_to' => $to,
                'chunk_index' => $chunkIndex,
                'chunk_total' => $chunkTotal,
                'page' => $page,
                'available_page' => $availablePage,
                'record_index' => 0,
                'record_total' => $recordTotal,
                'current_invoice' => null,
            )));

            $recordIndex = 0;
            foreach ($digestRows as $digest) {
                $recordIndex++;
                $stats['seen']++;
                $stats[strtolower($direction)]++;
                $data = $this->digestToArray($digest, $direction);
                $upsert = $this->upsertDigest($data);
                if ($upsert['inserted']) {
                    $stats['inserted']++;
                } elseif ($upsert['changed']) {
                    $stats['updated']++;
                } else {
                    $stats['unchanged']++;
                }

                if ($fetchFullData && ($upsert['changed'] || !$upsert['data_fetched'])) {
                    $stats['api_requests']++;
                    $this->notifyProgress($progressCallback, array_merge($stats, array(
                        'stage' => 'xml_request',
                        'current_direction' => $direction,
                        'chunk_from' => $from,
                        'chunk_to' => $to,
                        'chunk_index' => $chunkIndex,
                        'chunk_total' => $chunkTotal,
                        'page' => $page,
                        'available_page' => $availablePage,
                        'record_index' => max(0, $recordIndex - 1),
                        'record_total' => $recordTotal,
                        'current_invoice' => $data['invoice_number'],
                    )));

                    $supplierTaxNumber = trim((string) ($data['supplier_tax_number'] ?? ''));
                    $xml = $api->queryInvoiceData(
                        $data['invoice_number'],
                        (int) $data['batch_index'],
                        $direction,
                        $direction === 'INBOUND' && $supplierTaxNumber !== '' ? $supplierTaxNumber : null
                    );
                    $this->storeInvoiceData((int) $upsert['rowid'], $xml);
                    $stats['downloaded']++;
                } elseif ($fetchFullData && $upsert['amounts_missing']) {
                    $this->enrichStoredInvoiceAmounts((int) $upsert['rowid']);
                }

                $this->notifyProgress($progressCallback, array_merge($stats, array(
                    'stage' => 'record',
                    'current_direction' => $direction,
                    'chunk_from' => $from,
                    'chunk_to' => $to,
                    'chunk_index' => $chunkIndex,
                    'chunk_total' => $chunkTotal,
                    'page' => $page,
                    'available_page' => $availablePage,
                    'record_index' => $recordIndex,
                    'record_total' => $recordTotal,
                    'current_invoice' => $data['invoice_number'],
                )));
            }

            $this->notifyProgress($progressCallback, array_merge($stats, array(
                'stage' => 'digest_page_done',
                'current_direction' => $direction,
                'chunk_from' => $from,
                'chunk_to' => $to,
                'chunk_index' => $chunkIndex,
                'chunk_total' => $chunkTotal,
                'page' => $page,
                'available_page' => $availablePage,
                'record_index' => $recordTotal,
                'record_total' => $recordTotal,
                'current_invoice' => null,
            )));

            $page++;
        } while ($page <= $availablePage);
    }

    private function digestToArray(SimpleXMLElement $digest, string $direction): array
    {
        $field = function (string $name) use ($digest): string {
            $nodes = $digest->xpath('./*[local-name()="'.$name.'"]');
            return $nodes ? trim((string) $nodes[0]) : '';
        };

        $raw = array(
            'invoiceDirection' => $direction,
            'invoiceNumber' => $field('invoiceNumber'),
            'batchIndex' => $field('batchIndex'),
            'invoiceOperation' => $field('invoiceOperation'),
            'invoiceCategory' => $field('invoiceCategory'),
            'invoiceIssueDate' => $field('invoiceIssueDate'),
            'supplierTaxNumber' => $field('supplierTaxNumber'),
            'supplierName' => $field('supplierName'),
            'customerTaxNumber' => $field('customerTaxNumber'),
            'customerName' => $field('customerName'),
            'paymentMethod' => $field('paymentMethod'),
            'paymentDate' => $field('paymentDate'),
            'invoiceAppearance' => $field('invoiceAppearance'),
            'source' => $field('source'),
            'invoiceDeliveryDate' => $field('invoiceDeliveryDate'),
            'currency' => $field('currency'),
            'invoiceNetAmount' => $field('invoiceNetAmount'),
            'invoiceNetAmountHUF' => $field('invoiceNetAmountHUF'),
            'invoiceVatAmount' => $field('invoiceVatAmount'),
            'invoiceVatAmountHUF' => $field('invoiceVatAmountHUF'),
            'transactionId' => $field('transactionId'),
            'index' => $field('index'),
            'originalInvoiceNumber' => $field('originalInvoiceNumber'),
            'modificationIndex' => $field('modificationIndex'),
            'insDate' => $field('insDate'),
            'completenessIndicator' => $field('completenessIndicator'),
        );
        $rawJson = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array(
            'invoice_direction' => $direction,
            'invoice_number' => $raw['invoiceNumber'],
            'batch_index' => $raw['batchIndex'] !== '' ? (int) $raw['batchIndex'] : 0,
            'invoice_operation' => $raw['invoiceOperation'],
            'invoice_category' => $raw['invoiceCategory'],
            'invoice_issue_date' => $raw['invoiceIssueDate'],
            'supplier_tax_number' => $raw['supplierTaxNumber'],
            'supplier_name' => $raw['supplierName'],
            'customer_tax_number' => $raw['customerTaxNumber'],
            'customer_name' => $raw['customerName'],
            'payment_method' => $raw['paymentMethod'],
            'payment_date' => $raw['paymentDate'],
            'invoice_appearance' => $raw['invoiceAppearance'],
            'source' => $raw['source'],
            'invoice_delivery_date' => $raw['invoiceDeliveryDate'],
            'currency' => $raw['currency'],
            'invoice_net_amount' => $raw['invoiceNetAmount'],
            'invoice_net_amount_huf' => $raw['invoiceNetAmountHUF'],
            'invoice_vat_amount' => $raw['invoiceVatAmount'],
            'invoice_vat_amount_huf' => $raw['invoiceVatAmountHUF'],
            'transaction_id' => $raw['transactionId'],
            'transaction_index' => $raw['index'] !== '' ? (int) $raw['index'] : null,
            'original_invoice_number' => $raw['originalInvoiceNumber'],
            'modification_index' => $raw['modificationIndex'] !== '' ? (int) $raw['modificationIndex'] : null,
            'ins_date' => $this->sqlDateTime($raw['insDate']),
            'completeness_indicator' => strtolower($raw['completenessIndicator']) === 'true' ? 1 : 0,
            'raw_digest' => $rawJson ?: '{}',
            'digest_hash' => hash('sha256', $rawJson ?: '{}'),
        );
    }

    private function upsertDigest(array $data): array
    {
        global $conf;
        $entity = (int) $conf->entity;
        $supplierTaxNumber = trim((string) ($data['supplier_tax_number'] ?? ''));

        $sql = 'SELECT rowid, digest_hash, data_fetched, invoice_net_amount, invoice_vat_amount FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$entity;
        $sql .= " AND invoice_direction = '".$this->db->escape($data['invoice_direction'])."'";
        $sql .= " AND supplier_tax_number = '".$this->db->escape($supplierTaxNumber)."'";
        $sql .= " AND invoice_number = '".$this->db->escape($data['invoice_number'])."'";
        $sql .= ' AND batch_index = '.((int) $data['batch_index']);
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }

        $existing = $this->db->fetch_object($resql);
        $this->db->free($resql);

        // A pre-migration row whose supplier tax could not be recovered is still
        // safer to claim than to duplicate, but only when that legacy identity is
        // unique. Once claimed, future lookups use the full supplier-scoped key.
        if (!$existing && $supplierTaxNumber !== '') {
            $legacySql = 'SELECT rowid, digest_hash, data_fetched, invoice_net_amount, invoice_vat_amount';
            $legacySql .= ' FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
            $legacySql .= ' WHERE entity = '.$entity;
            $legacySql .= " AND invoice_direction = '".$this->db->escape($data['invoice_direction'])."'";
            $legacySql .= " AND (supplier_tax_number IS NULL OR TRIM(supplier_tax_number) = '')";
            $legacySql .= " AND invoice_number = '".$this->db->escape($data['invoice_number'])."'";
            $legacySql .= ' AND batch_index = '.((int) $data['batch_index']);
            $legacySql .= ' ORDER BY rowid LIMIT 2';
            $legacyRes = $this->db->query($legacySql);
            if (!$legacyRes) {
                throw new Exception($this->db->lasterror());
            }
            $legacyRows = array();
            while ($legacyObj = $this->db->fetch_object($legacyRes)) {
                $legacyRows[] = $legacyObj;
            }
            $this->db->free($legacyRes);
            if (count($legacyRows) === 1) {
                $existing = $legacyRows[0];
            }
        }

        $inserted = !$existing;
        $changed = $inserted || $existing->digest_hash !== $data['digest_hash'];
        $dataFetched = $existing ? (bool) $existing->data_fetched : false;
        $amountsMissing = $inserted
            ? ($data['invoice_net_amount'] === '' || $data['invoice_vat_amount'] === '')
            : ($existing->invoice_net_amount === null || $existing->invoice_vat_amount === null);

        if ($inserted) {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'navinvoice_invoice ('
                .'entity, invoice_direction, supplier_tax_number, invoice_number, batch_index, datec, last_sync) VALUES ('
                .$entity.", '".$this->db->escape($data['invoice_direction'])."', '".$this->db->escape($supplierTaxNumber)."', '"
                .$this->db->escape($data['invoice_number'])."', ".((int) $data['batch_index']).", '"
                .$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
            if (!$this->db->query($sql)) {
                throw new Exception($this->db->lasterror());
            }
            $rowid = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'navinvoice_invoice');
        } else {
            $rowid = (int) $existing->rowid;
        }

        $set = array();
        foreach (array(
            'invoice_operation', 'invoice_category', 'supplier_name', 'customer_tax_number', 'customer_name',
            'payment_method', 'invoice_appearance', 'source', 'currency', 'transaction_id', 'original_invoice_number', 'raw_digest', 'digest_hash'
        ) as $key) {
            $set[] = $key." = '".$this->db->escape((string) $data[$key])."'";
        }
        // Supplier tax is identity. Keep it explicit as well as in the lookup key.
        $set[] = "supplier_tax_number = '".$this->db->escape($supplierTaxNumber)."'";
        foreach (array('invoice_issue_date', 'payment_date', 'invoice_delivery_date', 'ins_date') as $key) {
            $set[] = $key.' = '.($data[$key] !== '' && $data[$key] !== null ? "'".$this->db->escape((string) $data[$key])."'" : 'NULL');
        }
        foreach (array('invoice_net_amount', 'invoice_net_amount_huf', 'invoice_vat_amount', 'invoice_vat_amount_huf') as $key) {
            if ($data[$key] !== '') {
                $set[] = $key." = '".$this->db->escape((string) $data[$key])."'";
            }
        }
        $set[] = 'transaction_index = '.($data['transaction_index'] !== null ? (int) $data['transaction_index'] : 'NULL');
        $set[] = 'modification_index = '.($data['modification_index'] !== null ? (int) $data['modification_index'] : 'NULL');
        $set[] = 'completeness_indicator = '.((int) $data['completeness_indicator']);
        $set[] = "last_sync = '".$this->db->idate(dol_now())."'";

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice SET '.implode(', ', $set).' WHERE rowid = '.$rowid;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }

        return array(
            'rowid' => $rowid,
            'inserted' => $inserted,
            'changed' => $changed,
            'data_fetched' => $dataFetched,
            'amounts_missing' => $amountsMissing,
        );
    }

    private function storeInvoiceData(int $rowid, string $xml): void
    {
        $amounts = $this->extractInvoiceAmounts($xml);
        $set = array(
            "invoice_data = '".$this->db->escape($xml)."'",
            'data_fetched = 1',
            "data_hash = '".hash('sha256', $xml)."'",
        );
        foreach ($amounts as $column => $value) {
            if ($value !== null) {
                $set[] = $column." = COALESCE(".$column.", '".$this->db->escape($value)."')";
            }
        }

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice SET '.implode(', ', $set).' WHERE rowid = '.$rowid;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }
    }

    private function enrichStoredInvoiceAmounts(int $rowid): void
    {
        $sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice WHERE rowid = '.$rowid;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj || trim((string) $obj->invoice_data) === '') {
            return;
        }

        $amounts = $this->extractInvoiceAmounts((string) $obj->invoice_data);
        $set = array();
        foreach ($amounts as $column => $value) {
            if ($value !== null) {
                $set[] = $column." = COALESCE(".$column.", '".$this->db->escape($value)."')";
            }
        }
        if (!$set) {
            return;
        }

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice SET '.implode(', ', $set).' WHERE rowid = '.$rowid;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }
    }

    /**
     * Recover mirror totals from the canonical parser when queryInvoiceDigest
     * omitted them. No second XML interpretation lives in the sync layer.
     *
     * @return array<string,string|null>
     */
    private function extractInvoiceAmounts(string $xml): array
    {
        $result = array(
            'invoice_net_amount' => null,
            'invoice_net_amount_huf' => null,
            'invoice_vat_amount' => null,
            'invoice_vat_amount_huf' => null,
        );
        try {
            $parsed = $this->parser->parse($xml);
        } catch (Throwable $e) {
            dol_syslog(__METHOD__.': cannot parse stored NAV XML: '.$e->getMessage(), LOG_WARNING);
            return $result;
        }
        $totals = is_array($parsed['totals'] ?? null) ? $parsed['totals'] : array();
        foreach (array(
            'invoice_net_amount' => 'net',
            'invoice_net_amount_huf' => 'net_huf',
            'invoice_vat_amount' => 'vat',
            'invoice_vat_amount_huf' => 'vat_huf',
        ) as $column => $key) {
            $value = $totals[$key] ?? null;
            if ($value !== null && $value !== '' && is_numeric($value)) {
                $result[$column] = (string) $value;
            }
        }
        return $result;
    }

    /** @param callable|null $callback */
    private function notifyProgress(?callable $callback, array $state): void
    {
        if ($callback === null) {
            return;
        }
        try {
            $callback($state);
        } catch (Throwable $e) {
            dol_syslog(__METHOD__.': progress callback failed: '.$e->getMessage(), LOG_WARNING);
        }
    }

    private function sqlDateTime(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            return $date->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }
}
