<?php

/**
 * Currency-aware comparison policy for authoritative NAV invoice amounts.
 *
 * NAV may expose sub-unit amounts even when Dolibarr is configured to display
 * and store document totals at the currency's normal precision. Comparisons
 * therefore happen at currency precision instead of using an arbitrary
 * absolute tolerance.
 */
class NavAmountPolicy
{
    /**
     * @param array<string,mixed> $actual
     * @param array<string,mixed> $expected
     */
    public static function invoiceTotalsMatch(array $actual, array $expected, string $currency, string $category): bool
    {
        $category = strtoupper(trim($category));
        if ($category === 'SIMPLIFIED') {
            return self::amountMatches(
                (float) ($actual['gross'] ?? 0),
                (float) ($expected['gross'] ?? 0),
                $currency
            );
        }

        return self::amountMatches((float) ($actual['net'] ?? 0), (float) ($expected['net'] ?? 0), $currency)
            && self::amountMatches((float) ($actual['vat'] ?? 0), (float) ($expected['vat'] ?? 0), $currency)
            && self::amountMatches((float) ($actual['gross'] ?? 0), (float) ($expected['gross'] ?? 0), $currency);
    }

    /**
     * @param array<int,array<string,mixed>> $lines
     * @param array<string,mixed> $totals
     */
    public static function lineTotalsMatchHeader(array $lines, array $totals, string $currency, string $category): bool
    {
        $category = strtoupper(trim($category));
        if ($category === 'SIMPLIFIED') {
            $gross = 0.0;
            foreach ($lines as $line) {
                if (($line['gross'] ?? null) === null || ($line['gross'] ?? '') === '') {
                    return false;
                }
                $gross += (float) $line['gross'];
            }
            return self::amountMatches($gross, (float) ($totals['gross'] ?? 0), $currency);
        }

        $net = 0.0;
        $vat = 0.0;
        foreach ($lines as $line) {
            if (($line['net'] ?? null) === null || ($line['net'] ?? '') === ''
                || ($line['vat'] ?? null) === null || ($line['vat'] ?? '') === '') {
                return false;
            }
            $net += (float) $line['net'];
            $vat += (float) $line['vat'];
        }

        return self::invoiceTotalsMatch(
            array('net' => $net, 'vat' => $vat, 'gross' => $net + $vat),
            $totals,
            $currency,
            $category
        );
    }

    public static function amountMatches(float $actual, float $expected, string $currency): bool
    {
        $decimals = self::currencyDecimals($currency);
        return round($actual, $decimals) == round($expected, $decimals);
    }

    private static function currencyDecimals(string $currency): int
    {
        return in_array(strtoupper(trim($currency)), array('HUF', 'JPY'), true) ? 0 : 2;
    }
}
