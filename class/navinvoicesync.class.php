<?php

require_once __DIR__.'/navapi.class.php';

class NavInvoiceSync
{
    /** @var DoliDB */
    private $db;

    public string $error = '';
    public array $errors = array();

    public function __construct($db)
    {
        $this->db = $db;
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

    public function syncPeriod(string $dateFrom, string $dateTo, bool $fetchFullData = true, string $direction = 'BOTH'): array
    {
        $this->ensureSchema();

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
        );

        foreach ($directions as $currentDirection) {
            $chunkStart = $start;
            while ($chunkStart <= $end) {
                $chunkEnd = $chunkStart->modify('+34 days');
                if ($chunkEnd > $end) {
                    $chunkEnd = $end;
                }
                $stats['chunks']++;
                $this->syncChunk(
                    $api,
                    $chunkStart->format('Y-m-d'),
                    $chunkEnd->format('Y-m-d'),
                    $fetchFullData,
                    $currentDirection,
                    $stats
                );
                $chunkStart = $chunkEnd->modify('+1 day');
            }
        }

        return $stats;
    }

    public function ensureSchema(): void
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

            $resql = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = 'uk_navinvoice_invoice'");
            if (!$resql) {
                throw new Exception($this->db->lasterror());
            }
            $hasOldUnique = (bool) $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($hasOldUnique && !$this->db->query("ALTER TABLE ".$table." DROP INDEX uk_navinvoice_invoice")) {
                throw new Exception($this->db->lasterror());
            }
            if (!$this->db->query("ALTER TABLE ".$table." ADD UNIQUE INDEX uk_navinvoice_invoice (entity, invoice_direction, invoice_number, batch_index)")) {
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

        $resql = $this->db->query("SHOW INDEX FROM ".$table." WHERE Key_name = 'idx_navinvoice_supplier_tax'");
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $hasSupplierTaxIndex = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$hasSupplierTaxIndex) {
            if (!$this->db->query("ALTER TABLE ".$table." ADD INDEX idx_navinvoice_supplier_tax (entity, supplier_tax_number)")) {
                throw new Exception($this->db->lasterror());
            }
        }
    }

    private function syncChunk(NavInvoiceApi $api, string $from, string $to, bool $fetchFullData, string $direction, array &$stats): void
    {
        $page = 1;
        $availablePage = 1;

        do {
            $response = $api->queryInvoiceDigest($from, $to, $page, $direction);
            $pageNodes = $response->xpath('//*[local-name()="invoiceDigestResult"]/*[local-name()="availablePage"]');
            $availablePage = $pageNodes ? max(1, (int) $pageNodes[0]) : 1;
            $digests = $response->xpath('//*[local-name()="invoiceDigestResult"]/*[local-name()="invoiceDigest"]');

            foreach ($digests ?: array() as $digest) {
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
                    $xml = $api->queryInvoiceData($data['invoice_number'], (int) $data['batch_index'], $direction);
                    $this->storeInvoiceData((int) $upsert['rowid'], $xml);
                    $stats['downloaded']++;
                }
            }
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

        $sql = 'SELECT rowid, digest_hash, data_fetched FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$entity;
        $sql .= " AND invoice_direction = '".$this->db->escape($data['invoice_direction'])."'";
        $sql .= " AND invoice_number = '".$this->db->escape($data['invoice_number'])."'";
        $sql .= ' AND batch_index = '.((int) $data['batch_index']);
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }

        $existing = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $inserted = !$existing;
        $changed = $inserted || $existing->digest_hash !== $data['digest_hash'];
        $dataFetched = $existing ? (bool) $existing->data_fetched : false;

        if ($inserted) {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'navinvoice_invoice ('
                .'entity, invoice_direction, invoice_number, batch_index, datec, last_sync) VALUES ('
                .$entity.", '".$this->db->escape($data['invoice_direction'])."', '".$this->db->escape($data['invoice_number'])."', ".((int) $data['batch_index']).", '".$this->db->idate(dol_now())."', '".$this->db->idate(dol_now())."')";
            if (!$this->db->query($sql)) {
                throw new Exception($this->db->lasterror());
            }
            $rowid = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'navinvoice_invoice');
        } else {
            $rowid = (int) $existing->rowid;
        }

        $set = array();
        foreach (array(
            'invoice_operation', 'invoice_category', 'supplier_tax_number', 'supplier_name', 'customer_tax_number', 'customer_name',
            'payment_method', 'invoice_appearance', 'source', 'currency', 'transaction_id', 'original_invoice_number', 'raw_digest', 'digest_hash'
        ) as $key) {
            $set[] = $key." = '".$this->db->escape((string) $data[$key])."'";
        }
        foreach (array('invoice_issue_date', 'payment_date', 'invoice_delivery_date', 'ins_date') as $key) {
            $set[] = $key.' = '.($data[$key] !== '' && $data[$key] !== null ? "'".$this->db->escape((string) $data[$key])."'" : 'NULL');
        }
        foreach (array('invoice_net_amount', 'invoice_net_amount_huf', 'invoice_vat_amount', 'invoice_vat_amount_huf') as $key) {
            $set[] = $key.' = '.($data[$key] !== '' ? "'".$this->db->escape((string) $data[$key])."'" : 'NULL');
        }
        $set[] = 'transaction_index = '.($data['transaction_index'] !== null ? (int) $data['transaction_index'] : 'NULL');
        $set[] = 'modification_index = '.($data['modification_index'] !== null ? (int) $data['modification_index'] : 'NULL');
        $set[] = 'completeness_indicator = '.((int) $data['completeness_indicator']);
        $set[] = "last_sync = '".$this->db->idate(dol_now())."'";

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice SET '.implode(', ', $set).' WHERE rowid = '.$rowid;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }

        return array('rowid' => $rowid, 'inserted' => $inserted, 'changed' => $changed, 'data_fetched' => $dataFetched);
    }

    private function storeInvoiceData(int $rowid, string $xml): void
    {
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice SET ';
        $sql .= "invoice_data = '".$this->db->escape($xml)."', data_fetched = 1, data_hash = '".hash('sha256', $xml)."'";
        $sql .= ' WHERE rowid = '.$rowid;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
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
