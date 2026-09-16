<?php

/**
 * Pure purchasing rules shared by the NAV purchase workbench and regression
 * tests. No database access and no inference from price to physical units.
 */
class NavPurchasePricePolicy
{
    /**
     * Select the Dolibarr supplier-price tier applicable to a purchased quantity.
     *
     * A tier's quantity is its MOQ threshold. The applicable tier is the highest
     * threshold not exceeding the absolute purchased quantity. Packaging and
     * price values are deliberately ignored.
     *
     * @param array<int,array<string,mixed>> $prices
     * @return array{price:array<string,mixed>,applicable:bool}|null
     */
    public static function selectTier(array $prices, float $quantity): ?array
    {
        if (!$prices) {
            return null;
        }

        $quantity = abs($quantity);
        $applicable = array();
        foreach ($prices as $price) {
            if (!is_array($price)) {
                continue;
            }
            if (self::tierApplies($price, $quantity)) {
                $applicable[] = $price;
            }
        }

        if ($applicable) {
            usort($applicable, static function (array $a, array $b): int {
                $quantityCompare = ((float) ($b['quantity'] ?? 0)) <=> ((float) ($a['quantity'] ?? 0));
                if ($quantityCompare !== 0) {
                    return $quantityCompare;
                }
                return ((int) ($b['rowid'] ?? 0)) <=> ((int) ($a['rowid'] ?? 0));
            });
            return array('price' => $applicable[0], 'applicable' => true);
        }

        $fallback = array_values(array_filter($prices, 'is_array'));
        if (!$fallback) {
            return null;
        }
        usort($fallback, static function (array $a, array $b): int {
            $quantityCompare = ((float) ($a['quantity'] ?? 0)) <=> ((float) ($b['quantity'] ?? 0));
            if ($quantityCompare !== 0) {
                return $quantityCompare;
            }
            return ((int) ($b['rowid'] ?? 0)) <=> ((int) ($a['rowid'] ?? 0));
        });
        return array('price' => $fallback[0], 'applicable' => false);
    }

    /** @param array<string,mixed> $price */
    public static function tierApplies(array $price, float $quantity): bool
    {
        $minimum = max(0.0, (float) ($price['quantity'] ?? 0));
        return $minimum <= abs($quantity) + 0.000001;
    }

    public static function effectivePricesEqual(float $left, float $right): bool
    {
        $tolerance = max(0.01, max(abs($left), abs($right)) * 0.00001);
        return abs($left - $right) <= $tolerance;
    }

    public static function orderingMultipleSatisfied(float $quantity, float $packaging): bool
    {
        if ($packaging <= 0.0 || abs($quantity) <= 0.000000001) {
            return true;
        }
        $multiple = abs($quantity) / $packaging;
        return abs($multiple - round($multiple)) <= 0.000001;
    }
}
