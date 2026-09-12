<?php

/**
 * Extract optional NAV Online Invoice processing metadata without changing the
 * canonical invoice parser/import model. The result is intentionally read-only
 * and can be reused by diagnostics, product matching and order reconciliation.
 */
class NavInvoiceMetadataExtractor
{
    /**
     * @return array{invoice:array<string,mixed>,lines:array<int,array<string,mixed>>}
     */
    public function extract(string $xml): array
    {
        $previousLibxmlState = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlState);

        if (!$document instanceof SimpleXMLElement) {
            $detail = !empty($errors) ? trim($errors[0]->message) : 'unknown XML error';
            throw new Exception('Stored NAV invoice XML is invalid: '.$detail);
        }

        $invoiceNodes = $document->xpath(
            '//*[local-name()="invoiceMain"]/*[local-name()="invoice"]'
            .' | //*[local-name()="invoiceMain"]/*[local-name()="batchInvoice"]/*[local-name()="invoice"]'
        );
        if (!$invoiceNodes) {
            throw new Exception('Stored NAV XML does not contain an invoice payload.');
        }

        $invoice = $invoiceNodes[0];
        $detail = $this->node($invoice, './*[local-name()="invoiceHead"]/*[local-name()="invoiceDetail"]');
        $invoiceMetadata = array(
            'conventional' => $detail ? $this->parseConventional($this->node($detail, './*[local-name()="conventionalInvoiceInfo"]')) : array(),
            'additional_data' => $detail ? $this->parseAdditional($detail, 'additionalInvoiceData') : array(),
        );

        $lines = array();
        $lineNodes = $invoice->xpath('./*[local-name()="invoiceLines"]/*[local-name()="line"]');
        foreach ($lineNodes ?: array() as $line) {
            $lines[] = $this->parseLine($line);
        }

        return array('invoice' => $invoiceMetadata, 'lines' => $lines);
    }

    /** @return array<string,mixed> */
    private function parseLine(SimpleXMLElement $line): array
    {
        $productCodes = array();
        $codeNodes = $line->xpath('./*[local-name()="productCodes"]/*[local-name()="productCode"]');
        foreach ($codeNodes ?: array() as $code) {
            $category = $this->text($code, './*[local-name()="productCodeCategory"]');
            $value = $this->text($code, './*[local-name()="productCodeValue"]');
            $ownValue = $this->text($code, './*[local-name()="productCodeOwnValue"]');
            $value = $value !== '' ? $value : $ownValue;
            if ($category !== '' || $value !== '') {
                $productCodes[] = array('category' => $category, 'value' => $value);
            }
        }

        $conventional = $this->parseConventional($this->node($line, './*[local-name()="conventionalLineInfo"]'));
        $additional = $this->parseAdditional($line, 'additionalLineData');

        return array(
            'number' => $this->text($line, './*[local-name()="lineNumber"]'),
            'description' => $this->text($line, './*[local-name()="lineDescription"]'),
            'product_codes' => $productCodes,
            'conventional' => $conventional,
            'additional_data' => $additional,
            'barcode_candidates' => $this->barcodeCandidates($productCodes, $conventional, $additional),
        );
    }

    /**
     * NAV ConventionalInvoiceInfoType is shared between invoice and line level.
     * @return array<string,array<int,string>>
     */
    private function parseConventional(?SimpleXMLElement $node): array
    {
        if (!$node) {
            return array();
        }

        $paths = array(
            'order_numbers' => array('orderNumbers', 'orderNumber'),
            'delivery_notes' => array('deliveryNotes', 'deliveryNote'),
            'shipping_dates' => array('shippingDates', 'shippingDate'),
            'contract_numbers' => array('contractNumbers', 'contractNumber'),
            'supplier_company_codes' => array('supplierCompanyCodes', 'supplierCompanyCode'),
            'customer_company_codes' => array('customerCompanyCodes', 'customerCompanyCode'),
            'dealer_codes' => array('dealerCodes', 'dealerCode'),
            'cost_centers' => array('costCenters', 'costCenter'),
            'project_numbers' => array('projectNumbers', 'projectNumber'),
            'general_ledger_account_numbers' => array('generalLedgerAccountNumbers', 'generalLedgerAccountNumber'),
            'gln_supplier' => array('glnNumbersSupplier', 'glnNumber'),
            'gln_customer' => array('glnNumbersCustomer', 'glnNumber'),
            'material_numbers' => array('materialNumbers', 'materialNumber'),
            'item_numbers' => array('itemNumbers', 'itemNumber'),
            'ekaer_ids' => array('ekaerIds', 'ekaerId'),
        );

        $result = array();
        foreach ($paths as $key => $parts) {
            $values = $this->texts(
                $node,
                './*[local-name()="'.$parts[0].'"]/*[local-name()="'.$parts[1].'"]'
            );
            if ($values) {
                $result[$key] = $values;
            }
        }

        return $result;
    }

    /** @return array<int,array{name:string,description:string,value:string}> */
    private function parseAdditional(SimpleXMLElement $parent, string $element): array
    {
        $result = array();
        $nodes = $parent->xpath('./*[local-name()="'.$element.'"]');
        foreach ($nodes ?: array() as $data) {
            $name = $this->text($data, './*[local-name()="dataName"]');
            $description = $this->text($data, './*[local-name()="dataDescription"]');
            $value = $this->text($data, './*[local-name()="dataValue"]');
            if ($name !== '' || $description !== '' || $value !== '') {
                $result[] = array(
                    'name' => $name,
                    'description' => $description,
                    'value' => $value,
                );
            }
        }
        return $result;
    }

    /**
     * Detect only checksum-valid numeric GTIN candidates. This is deliberately
     * a candidate signal, not an automatic Dolibarr barcode assignment: NAV has
     * no dedicated BARCODE product-code category and supplier-defined data may
     * use the same numeric shape for unrelated identifiers.
     *
     * @param array<int,array{category:string,value:string}> $productCodes
     * @param array<string,array<int,string>> $conventional
     * @param array<int,array{name:string,description:string,value:string}> $additional
     * @return array<int,array{value:string,sources:array<int,string>}>
     */
    private function barcodeCandidates(array $productCodes, array $conventional, array $additional): array
    {
        $candidates = array();
        $add = function (string $value, string $source) use (&$candidates): void {
            $normalized = preg_replace('/\s+/', '', trim($value));
            if (!is_string($normalized) || !$this->isValidGtin($normalized)) {
                return;
            }
            if (!isset($candidates[$normalized])) {
                $candidates[$normalized] = array('value' => $normalized, 'sources' => array());
            }
            if (!in_array($source, $candidates[$normalized]['sources'], true)) {
                $candidates[$normalized]['sources'][] = $source;
            }
        };

        foreach ($productCodes as $code) {
            $category = strtoupper(trim((string) ($code['category'] ?? '')));
            $value = trim((string) ($code['value'] ?? ''));
            if ($value !== '') {
                $add($value, 'product_code:'.$category);
            }
        }
        foreach (array('item_numbers', 'material_numbers') as $key) {
            foreach ($conventional[$key] ?? array() as $value) {
                $add((string) $value, 'conventional:'.$key);
            }
        }
        foreach ($additional as $data) {
            $value = trim((string) ($data['value'] ?? ''));
            if ($value !== '') {
                $sourceName = trim((string) ($data['name'] ?? ''));
                $add($value, 'additional_data'.($sourceName !== '' ? ':'.$sourceName : ''));
            }
        }

        return array_values($candidates);
    }

    private function isValidGtin(string $value): bool
    {
        if (!preg_match('/^\d{8}$|^\d{12}$|^\d{13}$|^\d{14}$/', $value)) {
            return false;
        }

        $sum = 0;
        $length = strlen($value);
        for ($i = $length - 2, $position = 1; $i >= 0; $i--, $position++) {
            $digit = (int) $value[$i];
            $sum += $digit * (($position % 2) === 1 ? 3 : 1);
        }
        $expected = (10 - ($sum % 10)) % 10;
        return $expected === (int) $value[$length - 1];
    }

    /** @return array<int,string> */
    private function texts(SimpleXMLElement $node, string $xpath): array
    {
        $result = array();
        $nodes = $node->xpath($xpath);
        foreach ($nodes ?: array() as $valueNode) {
            $value = trim((string) $valueNode);
            if ($value !== '' && !in_array($value, $result, true)) {
                $result[] = $value;
            }
        }
        return $result;
    }

    private function node(SimpleXMLElement $node, string $xpath): ?SimpleXMLElement
    {
        $nodes = $node->xpath($xpath);
        return $nodes ? $nodes[0] : null;
    }

    private function text(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        if (!$nodes) {
            return '';
        }
        return trim((string) $nodes[0]);
    }
}
