<?php

require_once __DIR__.'/../class/navamountpolicy.class.php';

function fail_huf_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

function expect_huf_match(array $actual, array $expected, bool $shouldMatch, string $message): void
{
    $matched = NavAmountPolicy::invoiceTotalsMatch($actual, $expected, 'HUF', 'NORMAL');
    if ($matched !== $shouldMatch) {
        fail_huf_test($message.' actual='.json_encode($actual).' expected='.json_encode($expected));
    }
}

// Original Overgate case: NAV exposes fractional HUF VAT while Dolibarr stores
// the payable invoice totals at whole-forint precision.
expect_huf_match(
    array('net' => 262380.0, 'vat' => 70843.0, 'gross' => 333223.0),
    array('net' => 262380.0, 'vat' => 70842.6, 'gross' => 333223.0),
    true,
    '2026/04269 legitimate HUF rounding must be accepted'
);

// Real bulk-import cases. The policy must accept only currency-equivalent
// results, not arbitrary +/- 1 or 2 HUF differences.
expect_huf_match(
    array('net' => 7308.0, 'vat' => 1973.0, 'gross' => 9281.0),
    array('net' => 7307.09, 'vat' => 1972.91, 'gross' => 9280.0),
    false,
    'SZ27959/2026 wrong rounded net/gross must be rejected before reconciliation'
);
expect_huf_match(
    array('net' => 7307.0, 'vat' => 1973.0, 'gross' => 9280.0),
    array('net' => 7307.09, 'vat' => 1972.91, 'gross' => 9280.0),
    true,
    'SZ27959/2026 reconciled totals must be accepted'
);

expect_huf_match(
    array('net' => 3842.0, 'vat' => 1037.0, 'gross' => 4879.0),
    array('net' => 3840.0, 'vat' => 1037.0, 'gross' => 4877.0),
    false,
    '1226/04534A two-forint net/gross error must never be hidden'
);
expect_huf_match(
    array('net' => 3840.0, 'vat' => 1037.0, 'gross' => 4877.0),
    array('net' => 3840.0, 'vat' => 1037.0, 'gross' => 4877.0),
    true,
    '1226/04534A authoritative totals must match after line reconciliation'
);

expect_huf_match(
    array('net' => 433.0, 'vat' => 117.0, 'gross' => 550.0),
    array('net' => 433.07, 'vat' => 116.93, 'gross' => 550.0),
    true,
    '2026/1145085/PR1 sub-forint components with identical HUF totals must be accepted'
);

expect_huf_match(
    array('net' => 2512.0, 'vat' => 678.0, 'gross' => 3190.0),
    array('net' => 2512.0, 'vat' => 679.0, 'gross' => 3191.0),
    false,
    '1226/04593A one-forint VAT/gross error must be reconciled, not tolerated'
);
expect_huf_match(
    array('net' => 2512.0, 'vat' => 679.0, 'gross' => 3191.0),
    array('net' => 2512.0, 'vat' => 679.0, 'gross' => 3191.0),
    true,
    '1226/04593A authoritative totals must match after reconciliation'
);

$lines = array(
    array('net' => 239700.0, 'vat' => 64719.0, 'gross' => 304419.0),
    array('net' => 22680.0, 'vat' => 6123.6, 'gross' => 28803.6),
    array('net' => 0.0, 'vat' => 0.0, 'gross' => 0.0),
);
if (!NavAmountPolicy::lineTotalsMatchHeader(
    $lines,
    array('net' => 262380.0, 'vat' => 70842.6, 'gross' => 333223.0),
    'HUF',
    'NORMAL'
)) {
    fail_huf_test('preview and importer must share the same HUF summary policy');
}

fwrite(STDOUT, "HUF import regression tests passed\n");
