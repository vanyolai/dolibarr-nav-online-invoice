<?php

// Regression coverage for HUF invoice-total reconciliation. This test keeps the
// real Overgate 2026/04269 arithmetic executable without requiring Dolibarr.

function fail_huf_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

function huf_totals_match(float $actualNet, float $actualVat, float $actualGross, float $navNet, float $navVat, float $navGross): bool
{
    $epsilon = 0.00001;
    return abs($actualNet - $navNet) <= $epsilon
        && abs($actualVat - $navVat) < 1.0
        && abs($actualGross - $navGross) <= $epsilon;
}

if (!huf_totals_match(262380.0, 70843.0, 333223.0, 262380.0, 70842.6, 333223.0)) {
    fail_huf_test('2026/04269 legitimate fractional-forint VAT rounding must be accepted');
}
if (huf_totals_match(262380.0, 70844.0, 333224.0, 262380.0, 70842.6, 333223.0)) {
    fail_huf_test('a changed authoritative gross must not be hidden by HUF VAT tolerance');
}
if (huf_totals_match(262381.0, 70842.0, 333223.0, 262380.0, 70842.6, 333223.0)) {
    fail_huf_test('a changed authoritative net must not be hidden by HUF VAT tolerance');
}

fwrite(STDOUT, "HUF import regression tests passed\n");
