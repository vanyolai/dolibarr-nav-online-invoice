<?php

require_once __DIR__.'/../class/navinvoicecompliance.class.php';

function fail_preflight_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

function expect_preflight(bool $expected, array $result, string $message): void
{
    if (($result['ok'] ?? null) !== $expected) {
        fail_preflight_test($message.' blockers='.implode(',', $result['blockers'] ?? array()));
    }
}

function expect_blocker(string $blocker, array $result, string $message): void
{
    if (!in_array($blocker, $result['blockers'] ?? array(), true)) {
        fail_preflight_test($message.' blockers='.implode(',', $result['blockers'] ?? array()));
    }
}

function base_snapshot(): array
{
    return array(
        'invoice' => array(
            'issue_date' => '2026-09-22',
            'delivery_date' => '2026-09-22',
            'due_date' => '2026-09-30',
            'currency' => 'HUF',
            'payment_method' => 'TRANSFER',
            'periodical_settlement' => false,
            'period_start' => '',
            'period_end' => '',
            'cash_accounting' => false,
            'total_net' => 10000,
            'total_vat' => 2700,
            'total_gross' => 12700,
        ),
        'supplier' => array(
            'tax_number' => '12345678-2-19',
            'name' => 'Example Kft.',
            'address' => array(
                'country_code' => 'HU',
                'postal_code' => '8230',
                'city' => 'Balatonfüred',
                'line' => 'Példa utca 1.',
            ),
        ),
        'customer' => array(
            'vat_status' => 'DOMESTIC',
            'tax_number' => '87654321-2-42',
            'name' => 'Customer Kft.',
            'address' => array(
                'country_code' => 'HU',
                'postal_code' => '8200',
                'city' => 'Veszprém',
                'line' => 'Minta tér 2.',
            ),
        ),
        'lines' => array(
            array(
                'description' => 'Monthly service',
                'quantity' => 1,
                'unit' => 'PIECE',
                'unit_price' => 10000,
                'net' => 10000,
                'vat_rate' => 27,
                'vat_classification' => 'PERCENTAGE',
                'vat' => 2700,
                'gross' => 12700,
                'nature' => 'SERVICE',
            ),
        ),
    );
}

$preflight = new NavInvoiceCompliancePreflight();

$valid = $preflight->evaluate(base_snapshot());
expect_preflight(true, $valid, 'ordinary domestic HUF invoice should pass');

$zero = base_snapshot();
$zero['invoice']['total_vat'] = 0;
$zero['invoice']['total_gross'] = 10000;
$zero['lines'][0]['vat_rate'] = 0;
$zero['lines'][0]['vat'] = 0;
$zero['lines'][0]['gross'] = 10000;
$zero['lines'][0]['vat_classification'] = '';
$zeroResult = $preflight->evaluate($zero);
expect_preflight(false, $zeroResult, 'unclassified zero VAT must be blocked');
expect_blocker('line_1_zero_vat_requires_explicit_classification', $zeroResult, 'zero VAT blocker missing');

$domesticNoTax = base_snapshot();
$domesticNoTax['customer']['tax_number'] = '';
$domesticResult = $preflight->evaluate($domesticNoTax);
expect_preflight(false, $domesticResult, 'domestic VAT customer without tax number must be blocked');
expect_blocker('domestic_customer_tax_number_missing_or_invalid', $domesticResult, 'domestic tax blocker missing');

$periodical = base_snapshot();
$periodical['invoice']['periodical_settlement'] = true;
$periodical['invoice']['period_start'] = '';
$periodical['invoice']['period_end'] = '';
$periodResult = $preflight->evaluate($periodical);
expect_preflight(false, $periodResult, 'periodical invoice without period bounds must be blocked');
expect_blocker('periodical_period_start_missing_or_invalid', $periodResult, 'period start blocker missing');
expect_blocker('periodical_period_end_missing_or_invalid', $periodResult, 'period end blocker missing');

$private = base_snapshot();
$private['customer'] = array(
    'vat_status' => 'PRIVATE_PERSON',
    'tax_number' => '',
    'name' => '',
    'address' => array(),
);
$privateResult = $preflight->evaluate($private);
expect_preflight(true, $privateResult, 'private-person branch must not require business identity fields');

$foreign = base_snapshot();
$foreign['invoice']['currency'] = 'EUR';
$foreignResult = $preflight->evaluate($foreign);
expect_preflight(false, $foreignResult, 'foreign currency must be blocked in HUF MVP');
expect_blocker('invoice_currency_not_supported_mvp', $foreignResult, 'foreign currency blocker missing');

$badTotals = base_snapshot();
$badTotals['invoice']['total_gross'] = 12701;
$badTotalsResult = $preflight->evaluate($badTotals);
expect_preflight(false, $badTotalsResult, 'header and line totals must reconcile');
expect_blocker('invoice_total_gross_does_not_reconcile', $badTotalsResult, 'gross reconciliation blocker missing');

fwrite(STDOUT, "Outbound compliance preflight regression tests passed\n");
