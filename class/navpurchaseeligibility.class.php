<?php

/**
 * Shared purchase-workbench eligibility rules for inbound NAV invoices.
 */
class NavPurchaseEligibility
{
    /**
     * A purchase order may only be reconstructed from an inbound CREATE invoice.
     * Pure advance invoices are accounting/payment documents and are excluded.
     *
     * A missing parsed payload does not add an extra eligibility restriction;
     * the normal detail/import validation remains responsible for malformed XML.
     *
     * @param array<string,mixed>|null $parsed
     */
    public static function isEligible(string $direction, string $operation, ?array $parsed): bool
    {
        if (strtoupper(trim($direction)) !== 'INBOUND' || strtoupper(trim($operation)) !== 'CREATE') {
            return false;
        }
        if ($parsed === null) {
            return true;
        }

        $hasMonetaryLine = false;
        $advanceOnly = true;
        foreach (($parsed['lines'] ?? array()) as $line) {
            if (!is_array($line)) {
                continue;
            }
            $monetary = abs((float) ($line['amounts']['net'] ?? 0)) > 0.0000001
                || abs((float) ($line['amounts']['vat'] ?? 0)) > 0.0000001
                || abs((float) ($line['amounts']['gross'] ?? 0)) > 0.0000001;
            if (!$monetary) {
                continue;
            }
            $hasMonetaryLine = true;
            if (($line['advance'] ?? null) !== true) {
                $advanceOnly = false;
                break;
            }
        }

        return !($hasMonetaryLine && $advanceOnly);
    }
}
