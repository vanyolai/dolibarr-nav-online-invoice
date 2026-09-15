<?php

/**
 * Validate and expose the already-parsed NAV semantics specific to AGGREGATE
 * invoices. Raw XML parsing belongs exclusively to NavInvoiceParser.
 */
class NavInvoiceAggregateSupport
{
    /**
     * @param array<string,mixed> $preview
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public function enrich(array $preview, array $parsed): array
    {
        if (strtoupper(trim((string) ($preview['category'] ?? ''))) !== 'AGGREGATE') {
            return $preview;
        }

        // NavInvoiceImportPreview remains usable on its own for the original
        // NORMAL/SIMPLIFIED scope. The operation preview adds AGGREGATE support
        // here and owns the category-specific validation below.
        $blockers = array_values(array_diff(
            array_map('strval', $preview['blockers'] ?? array()),
            array('category_unsupported')
        ));
        $warnings = array_values(array_unique(array_map('strval', $preview['warnings'] ?? array())));

        $detail = is_array($parsed['detail'] ?? null) ? $parsed['detail'] : array();
        $accountingDeliveryDate = trim((string) ($detail['accounting_delivery_date'] ?? ''));
        $headerDeliveryDate = trim((string) ($preview['header']['delivery_date'] ?? ''));

        // Preserve the accounting delivery date as source/audit data. Dolibarr's
        // point-of-tax date continues to follow invoiceDeliveryDate.
        $preview['header']['accounting_delivery_date'] = $accountingDeliveryDate;
        $preview['header']['point_of_tax_date'] = $headerDeliveryDate;

        if ($accountingDeliveryDate !== '' && !$this->validDate($accountingDeliveryDate)) {
            $blockers[] = 'aggregate_accounting_delivery_date_invalid';
        }

        $parsedLines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : array();
        $parsedByNumber = array();
        foreach ($parsedLines as $index => $parsedLine) {
            if (!is_array($parsedLine)) {
                continue;
            }
            $number = trim((string) ($parsedLine['number'] ?? ''));
            if ($number !== '') {
                $parsedByNumber[$number] = $parsedLine;
            }
        }

        $maxDeliveryDate = '';
        foreach (($preview['lines'] ?? array()) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineNumber = trim((string) ($line['number'] ?? ''));
            $sourceLine = $lineNumber !== '' && isset($parsedByNumber[$lineNumber])
                ? $parsedByNumber[$lineNumber]
                : ($parsedLines[(int) $index] ?? array());
            $aggregate = is_array($sourceLine['aggregate'] ?? null) ? $sourceLine['aggregate'] : array();

            $deliveryDate = trim((string) ($aggregate['delivery_date'] ?? ''));
            $exchangeRate = $aggregate['exchange_rate'] ?? null;
            $exchangeRateText = $exchangeRate === null ? '' : trim((string) $exchangeRate);

            $preview['lines'][$index]['aggregate_delivery_date'] = $deliveryDate;
            $preview['lines'][$index]['aggregate_exchange_rate'] = $exchangeRateText;

            if (!$this->validDate($deliveryDate)) {
                $blockers[] = 'aggregate_line_delivery_date_missing';
            } elseif ($maxDeliveryDate === '' || $deliveryDate > $maxDeliveryDate) {
                $maxDeliveryDate = $deliveryDate;
            }

            // lineExchangeRate is optional in the NAV XSD. Validate only when present.
            if ($exchangeRateText !== '' && (!is_numeric($exchangeRateText) || (float) $exchangeRateText < 0)) {
                $blockers[] = 'aggregate_line_exchange_rate_invalid';
            }
        }

        // For an aggregate invoice invoiceDeliveryDate must equal the latest
        // lineDeliveryDate. Do not silently flatten malformed source data.
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

    private function validDate(string $date): bool
    {
        if ($date === '') {
            return false;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
    }
}
