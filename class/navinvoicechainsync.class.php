<?php

dol_include_once('/navinvoice/class/navapi.class.php');
dol_include_once('/navinvoice/class/navinvoicechain.class.php');
dol_include_once('/navinvoice/class/navinvoiceparser.class.php');

/**
 * Targeted synchronization for one authoritative NAV invoice chain.
 *
 * Used when a MODIFY/STORNO refers to an older master invoice (or an earlier
 * modification) missing from the local mirror. Mirror identity is issuer scoped:
 * invoice numbers are not assumed to be globally unique across suppliers.
 */
class NavInvoiceChainSyncService
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var NavInvoiceApi */
    private $api;

    /** @var NavInvoiceChainService */
    private $chainService;

    /** @var NavInvoiceParser */
    private $parser;

    public function __construct($db, int $entity, ?NavInvoiceApi $api = null)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->api = $api ?: new NavInvoiceApi();
        $this->chainService = new NavInvoiceChainService($this->api);
        $this->parser = new NavInvoiceParser();
    }

    /**
     * @param object $record NAV mirror record
     * @param array<string,mixed>|null $parsed parsed InvoiceData for $record
     * @return array<string,mixed>
     */
    public function syncFromRecord($record, ?array $parsed = null): array
    {
        $direction = strtoupper(trim((string) ($record->invoice_direction ?? 'OUTBOUND')));
        if (!in_array($direction, array('INBOUND', 'OUTBOUND'), true)) {
            throw new InvalidArgumentException('NAV invoice direction must be INBOUND or OUTBOUND.');
        }

        $operation = strtoupper(trim((string) ($record->invoice_operation ?? 'CREATE')));
        $currentInvoiceNumber = trim((string) ($record->invoice_number ?? ''));
        if ($currentInvoiceNumber === '') {
            throw new InvalidArgumentException('NAV invoice number is missing.');
        }

        $reference = is_array($parsed['reference'] ?? null) ? $parsed['reference'] : array();
        $rootInvoiceNumber = $operation === 'CREATE'
            ? $currentInvoiceNumber
            : trim((string) ($reference['original_invoice_number'] ?? $record->original_invoice_number ?? ''));
        if ($rootInvoiceNumber === '') {
            throw new Exception('The NAV modification does not identify its original invoice.');
        }

        $supplier = is_array($parsed['supplier'] ?? null) ? $parsed['supplier'] : array();
        $supplierTaxNumber = trim((string) ($supplier['tax_number'] ?? $record->supplier_tax_number ?? ''));

        $chain = $this->chainService->fetch(
            $rootInvoiceNumber,
            $direction,
            $direction === 'INBOUND' && $supplierTaxNumber !== '' ? $supplierTaxNumber : null
        );
        $elements = is_array($chain['elements'] ?? null) ? $chain['elements'] : array();
        if (!$elements) {
            throw new Exception('NAV returned an empty authoritative invoice chain.');
        }

        // Fetch and parse everything before touching the database. If NAV cannot
        // provide one member we leave the mirror unchanged instead of storing a
        // half-resolved chain.
        $prepared = array();
        foreach ($elements as $element) {
            $invoiceNumber = trim((string) ($element['invoice_number'] ?? ''));
            if ($invoiceNumber === '') {
                throw new Exception('The authoritative NAV chain contains an invoice without an invoice number.');
            }
            $batchIndex = (int) ($element['batch_index'] ?? 0);
            $elementSupplierTax = trim((string) ($element['supplier_tax_number'] ?? ''));
            if ($elementSupplierTax === '') {
                $elementSupplierTax = $supplierTaxNumber;
            }

            $xml = $this->api->queryInvoiceData(
                $invoiceNumber,
                $batchIndex,
                $direction,
                $direction === 'INBOUND' && $elementSupplierTax !== '' ? $elementSupplierTax : null
            );
            $invoice = $this->parser->parse($xml);
            $prepared[] = $this->buildMirrorData($element, $invoice, $xml, $direction);
        }

        $stats = array(
            'root_invoice_number' => $rootInvoiceNumber,
            'chain_count' => count($prepared),
            'inserted' => 0,
            'updated' => 0,
            'downloaded' => count($prepared),
        );

        $this->db->begin();
        try {
            foreach ($prepared as $data) {
                $inserted = $this->upsert($data);
                if ($inserted) {
                    $stats['inserted']++;
                } else {
                    $stats['updated']++;
                }
            }
            $this->db->commit();
        } catch (Throwable $e) {
            $this->db->rollback();
            throw $e;
        }

        return $stats;
    }

    /**
     * @param array<string,mixed> $element
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function buildMirrorData(array $element, array $invoice, string $xml, string $direction): array
    {
        $supplier = is_array($invoice['supplier'] ?? null) ? $invoice['supplier'] : array();
        $customer = is_array($invoice['customer'] ?? null) ? $invoice['customer'] : array();
        $detail = is_array($invoice['detail'] ?? null) ? $invoice['detail'] : array();
        $reference = is_array($invoice['reference'] ?? null) ? $invoice['reference'] : array();
        $totals = is_array($invoice['totals'] ?? null) ? $invoice['totals'] : array();

        $raw = json_encode($element, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return array(
            'invoice_direction' => $direction,
            'invoice_number' => trim((string) ($invoice['invoice_number'] ?? $element['invoice_number'] ?? '')),
            'batch_index' => (int) ($invoice['batch_index'] ?? $element['batch_index'] ?? 0),
            'invoice_operation' => strtoupper(trim((string) ($element['invoice_operation'] ?? 'CREATE'))),
            'invoice_category' => trim((string) ($detail['category'] ?? $element['invoice_category'] ?? '')),
            'invoice_issue_date' => trim((string) ($invoice['invoice_issue_date'] ?? $element['invoice_issue_date'] ?? '')),
            'supplier_tax_number' => trim((string) ($supplier['tax_number'] ?? $element['supplier_tax_number'] ?? '')),
            'supplier_name' => trim((string) ($supplier['name'] ?? '')),
            'customer_tax_number' => trim((string) ($customer['tax_number'] ?? $element['customer_tax_number'] ?? '')),
            'customer_name' => trim((string) ($customer['name'] ?? '')),
            'payment_method' => trim((string) ($detail['payment_method'] ?? '')),
            'payment_date' => trim((string) ($detail['payment_date'] ?? '')),
            'invoice_appearance' => trim((string) ($detail['invoice_appearance'] ?? '')),
            'source' => '',
            'invoice_delivery_date' => trim((string) ($detail['delivery_date'] ?? '')),
            'currency' => trim((string) ($detail['currency'] ?? '')),
            'invoice_net_amount' => $this->decimalOrNull($totals['net'] ?? null),
            'invoice_net_amount_huf' => $this->decimalOrNull($totals['net_huf'] ?? null),
            'invoice_vat_amount' => $this->decimalOrNull($totals['vat'] ?? null),
            'invoice_vat_amount_huf' => $this->decimalOrNull($totals['vat_huf'] ?? null),
            'transaction_id' => trim((string) ($element['transaction_id'] ?? '')),
            'transaction_index' => $this->intOrNull($element['transaction_index'] ?? null),
            'original_invoice_number' => trim((string) ($reference['original_invoice_number'] ?? $element['original_invoice_number'] ?? '')),
            'modification_index' => $this->intOrNull($reference['modification_index'] ?? $element['modification_index'] ?? null),
            'completeness_indicator' => !empty($invoice['completeness_indicator']) ? 1 : 0,
            'raw_digest' => $raw ?: '{}',
            'invoice_data' => $xml,
            'data_hash' => hash('sha256', $xml),
        );
    }

    /** @param array<string,mixed> $data */
    private function upsert(array $data): bool
    {
        $supplierTaxNumber = trim((string) ($data['supplier_tax_number'] ?? ''));
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= " AND invoice_direction = '".$this->db->escape((string) $data['invoice_direction'])."'";
        $sql .= " AND supplier_tax_number = '".$this->db->escape($supplierTaxNumber)."'";
        $sql .= " AND invoice_number = '".$this->db->escape((string) $data['invoice_number'])."'";
        $sql .= ' AND batch_index = '.((int) $data['batch_index']);
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $existing = $this->db->fetch_object($resql);
        $this->db->free($resql);

        $inserted = !$existing;
        if ($inserted) {
            $sql = 'INSERT INTO '.MAIN_DB_PREFIX.'navinvoice_invoice (';
            $sql .= 'entity, invoice_direction, supplier_tax_number, invoice_number, batch_index, datec, last_sync';
            $sql .= ') VALUES (';
            $sql .= $this->entity;
            $sql .= ", '".$this->db->escape((string) $data['invoice_direction'])."'";
            $sql .= ", '".$this->db->escape($supplierTaxNumber)."'";
            $sql .= ", '".$this->db->escape((string) $data['invoice_number'])."'";
            $sql .= ', '.((int) $data['batch_index']);
            $sql .= ", '".$this->db->idate(dol_now())."'";
            $sql .= ", '".$this->db->idate(dol_now())."')";
            if (!$this->db->query($sql)) {
                throw new Exception($this->db->lasterror());
            }
            $rowid = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'navinvoice_invoice');
        } else {
            $rowid = (int) $existing->rowid;
        }

        $set = array();
        foreach (array(
            'invoice_operation', 'invoice_category', 'supplier_name',
            'customer_tax_number', 'customer_name', 'payment_method', 'invoice_appearance',
            'source', 'currency', 'transaction_id', 'original_invoice_number'
        ) as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($inserted || $value !== '') {
                $set[] = $key." = '".$this->db->escape($value)."'";
            }
        }
        $set[] = "supplier_tax_number = '".$this->db->escape($supplierTaxNumber)."'";
        foreach (array('invoice_issue_date', 'payment_date', 'invoice_delivery_date') as $key) {
            $value = trim((string) ($data[$key] ?? ''));
            if ($inserted || $value !== '') {
                $set[] = $key.' = '.($value !== '' ? "'".$this->db->escape($value)."'" : 'NULL');
            }
        }
        foreach (array('invoice_net_amount', 'invoice_net_amount_huf', 'invoice_vat_amount', 'invoice_vat_amount_huf') as $key) {
            $value = $data[$key] ?? null;
            if ($inserted || $value !== null) {
                $set[] = $key.' = '.($value !== null ? "'".$this->db->escape((string) $value)."'" : 'NULL');
            }
        }
        if ($inserted || $data['transaction_index'] !== null) {
            $set[] = 'transaction_index = '.($data['transaction_index'] !== null ? (int) $data['transaction_index'] : 'NULL');
        }
        if ($inserted || $data['modification_index'] !== null) {
            $set[] = 'modification_index = '.($data['modification_index'] !== null ? (int) $data['modification_index'] : 'NULL');
        }
        $set[] = 'completeness_indicator = '.((int) ($data['completeness_indicator'] ?? 0));
        if ($inserted) {
            $set[] = "raw_digest = '".$this->db->escape((string) $data['raw_digest'])."'";
            $set[] = 'digest_hash = NULL';
        }
        $set[] = "invoice_data = '".$this->db->escape((string) $data['invoice_data'])."'";
        $set[] = 'data_fetched = 1';
        $set[] = "data_hash = '".$this->db->escape((string) $data['data_hash'])."'";
        $set[] = "last_sync = '".$this->db->idate(dol_now())."'";

        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice SET '.implode(', ', $set).' WHERE rowid = '.$rowid;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }

        return $inserted;
    }

    /** @param mixed $value */
    private function intOrNull($value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    /** @param mixed $value */
    private function decimalOrNull($value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (string) $value;
    }
}
