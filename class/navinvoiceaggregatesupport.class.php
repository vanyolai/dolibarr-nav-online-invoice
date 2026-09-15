<?php

/**
 * Enrich an import preview with the NAV data that is specific to AGGREGATE
 * invoices. The generic parser intentionally stays version-neutral; this helper
 * reads only the aggregate fields we need for a deterministic Dolibarr import.
 */
class NavInvoiceAggregateSupport
{
    /**
     * @param array<string,mixed> $preview
     * @return array<string,mixed>
     */
    public function enrich(array $preview, string $xml): array
    {
        if (strtoupper(trim((string) ($preview['category'] ?? ''))) !== 'AGGREGATE') {
            return $preview;
        }

        $blockers = array_values(array_diff(
            array_map('strval', $preview['blockers'] ?? array()),
            array('category_unsupported')
        ));
        $warnings = array_values(array_unique(array_map('strval', $preview['warnings'] ?? array())));

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$document instanceof SimpleXMLElement) {
            $blockers[] = 'aggregate_xml_invalid';
            return $this->finish($preview, $blockers, $warnings);
        }

        $invoiceNodes = $document->xpath(
            '//*[local-name()="invoiceMain"]/*[local-name()="invoice"]'
            .' | //*[local-name()="invoiceMain"]/*[local-name()="batchInvoice"]/*[local-name()="invoice"]'
        );
        if (!$invoiceNodes) {
            $blockers[] = 'aggregate_xml_invalid';
            return $this->finish($preview, $blockers, $warnings);
        }

        $invoice = $invoiceNodes[0];
        $head = $this->node($invoice, './*[local-name()="invoiceHead"]');
        $detail = $head ? $this->node($head, './*[local-name()="invoiceDetail"]') : null;
        $accountingDeliveryDate = $detail ? $this->text($detail, './*[local-name()="invoiceAccountingDeliveryDate"]') : '';
        $headerDeliveryDate = trim((string) ($preview['header']['delivery_date'] ?? ''));

        // Keep NAV's accounting-delivery date available for audit/reporting, but
        // do not reinterpret it as Dolibarr's point-of-tax date. The importer has
        // historically mapped invoiceDeliveryDate to date_pointoftax, and an
        // aggregate invoice's header delivery date remains the latest line-level
        // delivery date by NAV semantics.
        $preview['header']['accounting_delivery_date'] = $accountingDeliveryDate;
        $preview['header']['point_of_tax_date'] = $headerDeliveryDate;

        if ($accountingDeliveryDate !== '' && !$this->validDate($accountingDeliveryDate)) {
            $blockers[] = 'aggregate_accounting_delivery_date_invalid';
        }

        $aggregateByLineNumber = array();
        $aggregateByIndex = array();
        $lineNodes = $invoice->xpath('./*[local-name()="invoiceLines"]/*[local-name()="line"]');
        foreach ($lineNodes ?: array() as $index => $lineNode) {
            $lineNumber = $this->text($lineNode, './*[local-name()="lineNumber"]');
            $aggregateNode = $this->node($lineNode, './*[local-name()="aggregateInvoiceLineData"]');
            $data = array(
                'delivery_date' => $aggregateNode ? $this->text($aggregateNode, './*[local-name()="lineDeliveryDate"]') : '',
                'exchange_rate' => $aggregateNode ? $this->text($aggregateNode, './*[local-name()="lineExchangeRate"]') : '',
            );
            $aggregateByIndex[(int) $index] = $data;
            if ($lineNumber !== '') {
                $aggregateByLineNumber[$lineNumber] = $data;
            }
        }

        $maxDeliveryDate = '';
        foreach (($preview['lines'] ?? array()) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineNumber = trim((string) ($line['number'] ?? ''));
            $data = $lineNumber !== '' && isset($aggregateByLineNumber[$lineNumber])
                ? $aggregateByLineNumber[$lineNumber]
                : ($aggregateByIndex[(int) $index] ?? array('delivery_date' => '', 'exchange_rate' => ''));

            $deliveryDate = trim((string) ($data['delivery_date'] ?? ''));
            $exchangeRate = trim((string) ($data['exchange_rate'] ?? ''));
            $preview['lines'][$index]['aggregate_delivery_date'] = $deliveryDate;
            $preview['lines'][$index]['aggregate_exchange_rate'] = $exchangeRate;

            if (!$this->validDate($deliveryDate)) {
                $blockers[] = 'aggregate_line_delivery_date_missing';
            } elseif ($maxDeliveryDate === '' || $deliveryDate > $maxDeliveryDate) {
                $maxDeliveryDate = $deliveryDate;
            }

            // lineExchangeRate is optional in the NAV 3.0 XSD. Validate it only
            // when present; zero is also a schema-valid ExchangeRateType value.
            if ($exchangeRate !== '' && (!is_numeric($exchangeRate) || (float) $exchangeRate < 0)) {
                $blockers[] = 'aggregate_line_exchange_rate_invalid';
            }
        }

        // NAV aggregate semantics require the invoice-level delivery date to be
        // the latest line delivery date. This check makes sure we do not silently
        // flatten a malformed aggregate invoice into a single-date Dolibarr bill.
        if ($maxDeliveryDate !== ''
            && (!$this->validDate($headerDeliveryDate) || $headerDeliveryDate !== $maxDeliveryDate)) {
            $blockers[] = 'aggregate_delivery_date_mismatch';
        }

        return $this->finish($preview, $blockers, $warnings);
    }

    /** @param array<string,mixed> $preview @param string[] $blockers @param string[] $warnings */
    private function finish(array $preview, array $blockers, array $warnings): array
    {
        $preview['blockers'] = array_values(array_unique(array_map('strval', $blockers)));
        $preview['warnings'] = array_values(array_unique(array_map('strval', $warnings)));
        $preview['state'] = $preview['blockers'] ? 'blocked' : ($preview['warnings'] ? 'review' : 'ready');
        return $preview;
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

    private function validDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
