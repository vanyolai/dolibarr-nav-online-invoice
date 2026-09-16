<?php

require_once __DIR__.'/../class/navpurchasepricepolicy.class.php';

function fail_purchase_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

function expect_purchase_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail_purchase_test($message.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
    }
}

function expect_purchase_true(bool $actual, string $message): void
{
    if (!$actual) {
        fail_purchase_test($message);
    }
}

$prices = array(
    array('rowid' => 10, 'quantity' => 1, 'packaging' => 500, 'unitprice' => 999),
    array('rowid' => 20, 'quantity' => 50, 'packaging' => 50, 'unitprice' => 82.4),
    array('rowid' => 30, 'quantity' => 100, 'packaging' => 1, 'unitprice' => 1),
);

$selection = NavPurchasePricePolicy::selectTier($prices, 50);
expect_purchase_same(20, (int) $selection['price']['rowid'], 'highest applicable MOQ must win independently of price and packaging');
expect_purchase_same(true, $selection['applicable'], '50-unit tier must be applicable at quantity 50');

$selection = NavPurchasePricePolicy::selectTier($prices, 75);
expect_purchase_same(20, (int) $selection['price']['rowid'], 'quantity 75 must still use MOQ 50 tier');

$selection = NavPurchasePricePolicy::selectTier(array(
    array('rowid' => 40, 'quantity' => 50, 'unitprice' => 100),
    array('rowid' => 50, 'quantity' => 100, 'unitprice' => 90),
), 25);
expect_purchase_same(40, (int) $selection['price']['rowid'], 'below all tiers the smallest MOQ is context-only fallback');
expect_purchase_same(false, $selection['applicable'], 'fallback tier must not be marked applicable');

expect_purchase_true(
    NavPurchasePricePolicy::effectivePricesEqual(245.0 * 0.8, 196.0),
    '245 less 20 percent and flat 196 must be economically equal'
);
expect_purchase_true(
    !NavPurchasePricePolicy::effectivePricesEqual(196.0, 196.2),
    'materially different effective prices must not compare equal'
);

expect_purchase_true(
    NavPurchasePricePolicy::orderingMultipleSatisfied(50, 50),
    '50 units satisfies an order multiple of 50'
);
expect_purchase_true(
    !NavPurchasePricePolicy::orderingMultipleSatisfied(75, 50),
    '75 units does not satisfy an order multiple of 50'
);
expect_purchase_true(
    NavPurchasePricePolicy::orderingMultipleSatisfied(75, 0),
    'missing packaging must not block quantity semantics'
);

expect_purchase_true(
    NavPurchasePricePolicy::tierApplies(array('quantity' => 50), 50),
    'MOQ 50 must apply at quantity 50'
);
expect_purchase_true(
    !NavPurchasePricePolicy::tierApplies(array('quantity' => 50), 49),
    'MOQ 50 must not apply below its threshold'
);

fwrite(STDOUT, "Purchase policy regression tests passed\n");
