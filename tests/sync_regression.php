<?php

$root = dirname(__DIR__);
$index = file_get_contents($root.'/index.php');
$sync = file_get_contents($root.'/class/navinvoicesync.class.php');
$api = file_get_contents($root.'/class/navapi.class.php');

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(strpos($index, 'ensureSchema(') === false, 'index.php must not run schema migration at request time');
$assert(strpos($sync, 'public function ensureSchema') === false, 'NavInvoiceSync must not expose the removed runtime schema migrator');
$assert(strpos($sync, 'public function migrateLegacySchema(): void') !== false, 'activation-only legacy migration must remain available');
$assert(strpos($sync, '!$upsert[\'data_fetched\']') !== false, 'rows whose XML download failed must be retried on a later sync');
$assert(strpos($sync, '$direction === \'INBOUND\' && $supplierTaxNumber !== \'\' ? $supplierTaxNumber : null') !== false, 'sync must pass supplierTaxNumber only for INBOUND XML queries');
$assert(strpos($api, '$direction === \'INBOUND\' && $supplierTaxNumber !== null') !== false, 'API boundary must suppress supplierTaxNumber for OUTBOUND queries');

if ($failures) {
    fwrite(STDERR, "NAV sync regression failures:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "NAV sync regression tests passed.\n";
