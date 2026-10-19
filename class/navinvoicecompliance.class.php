<?php

/**
 * Pure, network-free outbound invoice compliance preflight.
 *
 * The class intentionally consumes a normalized snapshot instead of Dolibarr
 * objects. A later adapter maps Facture/FactureLigne/Societe/$mysoc into this
 * contract. Keeping this layer pure makes it safe to execute before invoice
 * validation and easy to regression-test without a Dolibarr bootstrap.
 */
class NavInvoiceCompliancePreflight
{
    /** @var array<int,float> */
    private const MVP_PERCENTAGE_RATES = array(5.0, 18.0, 27.0);

    /** @var array<int,string> */
    private const CUSTOMER_VAT_STATUSES = array('DOMESTIC', 'OTHER', 'PRIVATE_PERSON');

    /**
     * @param array<string,mixed> $snapshot
     * @return array{ok:bool,blockers:array<int,string>,warnings:array<int,string>}
     */
    public function evaluate(array $snapshot): array
    {
        $blockers = array();
        $warnings = array();

        $invoice = $this->section($snapshot, 'invoice');
        $supplier = $this->section($snapshot, 'supplier');
        $customer = $this->section($snapshot, 'customer');
        $lines = isset($snapshot['lines']) && is_array($snapshot['lines']) ? $snapshot['lines'] : array();

        $issueDate = trim((string) ($invoice['issue_date'] ?? ''));
        if (!$this->validDate($issueDate)) {
            $blockers[] = 'invoice_issue_date_missing_or_invalid';
        }

        $currency = strtoupper(trim((string) ($invoice['currency'] ?? '')));
        if ($currency === '') {
            $blockers[] = 'invoice_currency_missing';
        } elseif ($currency !== 'HUF') {
            $blockers[] = 'invoice_currency_not_supported_mvp';
        }

        $deliveryDate = trim((string) ($invoice['delivery_date'] ?? ''));
        $periodical = !empty($invoice['periodical_settlement']);
        if ($deliveryDate !== '' && !$this->validDate($deliveryDate)) {
            $blockers[] = 'invoice_delivery_date_invalid';
        } elseif ($deliveryDate === '') {
            if ($periodical) {
                $blockers[] = 'periodical_delivery_date_missing';
            } elseif ($this->validDate($issueDate)) {
                $warnings[] = 'delivery_date_falls_back_to_issue_date';
            }
        }

        $dueDate = trim((string) ($invoice['due_date'] ?? ''));
        if ($dueDate === '') {
            $warnings[] = 'payment_due_date_missing';
        } elseif (!$this->validDate($dueDate)) {
            $blockers[] = 'payment_due_date_invalid';
        }

        if (trim((string) ($invoice['payment_method'] ?? '')) === '') {
            $warnings[] = 'payment_method_missing';
        }

        if ($periodical) {
            $periodStart = trim((string) ($invoice['period_start'] ?? ''));
            $periodEnd = trim((string) ($invoice['period_end'] ?? ''));
            if (!$this->validDate($periodStart)) {
                $blockers[] = 'periodical_period_start_missing_or_invalid';
            }
            if (!$this->validDate($periodEnd)) {
                $blockers[] = 'periodical_period_end_missing_or_invalid';
            }
            if ($this->validDate($periodStart) && $this->validDate($periodEnd) && $periodEnd < $periodStart) {
                $blockers[] = 'periodical_period_end_before_start';
            }
        }

        $this->validateSupplier($supplier, $blockers);
        $this->validateCustomer($customer, $blockers);

        if (!$lines) {
            $blockers[] = 'invoice_lines_missing';
        } else {
            foreach ($lines as $index => $line) {
                if (!is_array($line)) {
                    $blockers[] = 'line_'.($index + 1).'_invalid';
                    continue;
                }
                $this->validateLine($line, $index + 1, $blockers);
            }
        }

        if ($lines) {
            $this->validateTotals($invoice, $lines, $blockers);
        }

        $blockers = array_values(array_unique($blockers));
        $warnings = array_values(array_unique($warnings));

        return array(
            'ok' => !$blockers,
            'blockers' => $blockers,
            'warnings' => $warnings,
        );
    }

    /**
     * @param array<string,mixed> $supplier
     * @param array<int,string> $blockers
     */
    private function validateSupplier(array $supplier, array &$blockers): void
    {
        if ($this->taxCore((string) ($supplier['tax_number'] ?? '')) === '') {
            $blockers[] = 'supplier_tax_number_missing_or_invalid';
        }
        if (trim((string) ($supplier['name'] ?? '')) === '') {
            $blockers[] = 'supplier_name_missing';
        }
        if (!$this->usableAddress($this->section($supplier, 'address'))) {
            $blockers[] = 'supplier_address_missing';
        }
    }

    /**
     * @param array<string,mixed> $customer
     * @param array<int,string> $blockers
     */
    private function validateCustomer(array $customer, array &$blockers): void
    {
        $status = strtoupper(trim((string) ($customer['vat_status'] ?? '')));
        if (!in_array($status, self::CUSTOMER_VAT_STATUSES, true)) {
            $blockers[] = 'customer_vat_status_missing_or_invalid';
            return;
        }

        if ($status === 'DOMESTIC' && $this->taxCore((string) ($customer['tax_number'] ?? '')) === '') {
            $blockers[] = 'domestic_customer_tax_number_missing_or_invalid';
        }

        // Private-person NAV reporting has different identity semantics. Do not
        // force the normal business-customer name/address branch here.
        if ($status !== 'PRIVATE_PERSON') {
            if (trim((string) ($customer['name'] ?? '')) === '') {
                $blockers[] = 'customer_name_missing';
            }
            if (!$this->usableAddress($this->section($customer, 'address'))) {
                $blockers[] = 'customer_address_missing';
            }
        }
    }

    /**
     * @param array<string,mixed> $line
     * @param array<int,string> $blockers
     */
    private function validateLine(array $line, int $number, array &$blockers): void
    {
        $prefix = 'line_'.$number.'_';

        if (trim((string) ($line['description'] ?? '')) === '') {
            $blockers[] = $prefix.'description_missing';
        }

        if (!$this->numericNonZero($line['quantity'] ?? null)) {
            $blockers[] = $prefix.'quantity_missing_or_zero';
        }
        if (!isset($line['unit_price']) || !is_numeric($line['unit_price'])) {
            $blockers[] = $prefix.'unit_price_missing';
        }

        foreach (array('net', 'vat', 'gross') as $field) {
            if (!array_key_exists($field, $line) || !is_numeric($line[$field])) {
                $blockers[] = $prefix.$field.'_missing';
            }
        }

        $nature = strtoupper(trim((string) ($line['nature'] ?? '')));
        if (!in_array($nature, array('PRODUCT', 'SERVICE', 'OTHER'), true)) {
            $blockers[] = $prefix.'nature_missing_or_invalid';
        }

        if (!array_key_exists('vat_rate', $line) || !is_numeric($line['vat_rate'])) {
            $blockers[] = $prefix.'vat_rate_missing';
            return;
        }

        $vatRate = (float) $line['vat_rate'];
        $classification = strtoupper(trim((string) ($line['vat_classification'] ?? '')));

        if ($vatRate > 0.0) {
            if (!$this->floatInArray($vatRate, self::MVP_PERCENTAGE_RATES)) {
                $blockers[] = $prefix.'vat_rate_not_supported_mvp';
            }
            if ($classification !== '' && $classification !== 'PERCENTAGE') {
                $blockers[] = $prefix.'vat_classification_conflicts_with_percentage';
            }
        } elseif (abs($vatRate) < 0.000001) {
            if ($classification === '' || $classification === 'PERCENTAGE') {
                $blockers[] = $prefix.'zero_vat_requires_explicit_classification';
            }
        } else {
            $blockers[] = $prefix.'negative_vat_rate_invalid';
        }
    }

    /**
     * @param array<string,mixed> $invoice
     * @param array<int,mixed> $lines
     * @param array<int,string> $blockers
     */
    private function validateTotals(array $invoice, array $lines, array &$blockers): void
    {
        $expected = array(
            'total_net' => 'net',
            'total_vat' => 'vat',
            'total_gross' => 'gross',
        );

        foreach ($expected as $headerField => $lineField) {
            if (!array_key_exists($headerField, $invoice) || !is_numeric($invoice[$headerField])) {
                $blockers[] = 'invoice_'.$headerField.'_missing';
                continue;
            }

            $sum = 0.0;
            $complete = true;
            foreach ($lines as $line) {
                if (!is_array($line) || !array_key_exists($lineField, $line) || !is_numeric($line[$lineField])) {
                    $complete = false;
                    break;
                }
                $sum += (float) $line[$lineField];
            }
            if ($complete && abs($sum - (float) $invoice[$headerField]) > 0.01) {
                $blockers[] = 'invoice_'.$headerField.'_does_not_reconcile';
            }
        }
    }

    /** @param array<string,mixed> $source @return array<string,mixed> */
    private function section(array $source, string $key): array
    {
        return isset($source[$key]) && is_array($source[$key]) ? $source[$key] : array();
    }

    /** @param array<string,mixed> $address */
    private function usableAddress(array $address): bool
    {
        return trim((string) ($address['country_code'] ?? '')) !== ''
            && trim((string) ($address['postal_code'] ?? '')) !== ''
            && trim((string) ($address['city'] ?? '')) !== ''
            && trim((string) ($address['line'] ?? '')) !== '';
    }

    private function taxCore(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        return $digits !== null && strlen($digits) >= 8 ? substr($digits, 0, 8) : '';
    }

    private function validDate(string $value): bool
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return false;
        }
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year);
    }

    /** @param mixed $value */
    private function numericNonZero($value): bool
    {
        return is_numeric($value) && abs((float) $value) > 0.0000001;
    }

    /** @param array<int,float> $haystack */
    private function floatInArray(float $needle, array $haystack): bool
    {
        foreach ($haystack as $value) {
            if (abs($needle - $value) < 0.000001) {
                return true;
            }
        }
        return false;
    }
}
