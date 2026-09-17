from pathlib import Path

# This script is intentionally a one-shot branch migration. It is run by the
# final-audit workflow and commits only the resulting source/test changes.

p = Path('index.php')
text = p.read_text()
old = """$sync = new NavInvoiceSync($db);
$linkManager = new NavInvoiceLinkManager($db, (int) $conf->entity);
try {
    $sync->ensureSchema();
} catch (Throwable $e) {
    setEventMessages($langs->trans('SchemaMigrationFailed').': '.$e->getMessage(), null, 'errors');
}

"""
new = """$sync = new NavInvoiceSync($db);
$linkManager = new NavInvoiceLinkManager($db, (int) $conf->entity);

"""
if text.count(old) != 1:
    raise SystemExit('stale index ensureSchema block not found exactly once')
text = text.replace(old, new, 1)
p.write_text(text)

p = Path('class/navapi.class.php')
text = p.read_text()
old = """        if ($supplierTaxNumber !== null && trim($supplierTaxNumber) !== '') {
            $normalizedSupplierTaxNumber = $this->normalizeTaxNumber($supplierTaxNumber);
            if (strlen($normalizedSupplierTaxNumber) !== 8) {
                throw new Exception('NAV supplier tax number must contain the first 8 digits of the Hungarian tax number.');
            }
            $body .= '<supplierTaxNumber>'.$this->xml($normalizedSupplierTaxNumber).'</supplierTaxNumber>';
        }
"""
new = """        if ($direction === 'INBOUND' && $supplierTaxNumber !== null && trim($supplierTaxNumber) !== '') {
            $normalizedSupplierTaxNumber = $this->normalizeTaxNumber($supplierTaxNumber);
            if (strlen($normalizedSupplierTaxNumber) !== 8) {
                throw new Exception('NAV supplier tax number must contain the first 8 digits of the Hungarian tax number.');
            }
            $body .= '<supplierTaxNumber>'.$this->xml($normalizedSupplierTaxNumber).'</supplierTaxNumber>';
        }
"""
if text.count(old) != 1:
    raise SystemExit('queryInvoiceData supplier filter block not found exactly once')
text = text.replace(old, new, 1)
p.write_text(text)

p = Path('class/navinvoicesync.class.php')
text = p.read_text()
old = """                    $supplierTaxNumber = trim((string) ($data['supplier_tax_number'] ?? ''));
                    $xml = $api->queryInvoiceData(
                        $data['invoice_number'],
                        (int) $data['batch_index'],
                        $direction,
                        $supplierTaxNumber !== '' ? $supplierTaxNumber : null
                    );
"""
new = """                    $supplierTaxNumber = trim((string) ($data['supplier_tax_number'] ?? ''));
                    $xml = $api->queryInvoiceData(
                        $data['invoice_number'],
                        (int) $data['batch_index'],
                        $direction,
                        $direction === 'INBOUND' && $supplierTaxNumber !== '' ? $supplierTaxNumber : null
                    );
"""
if text.count(old) != 1:
    raise SystemExit('sync queryInvoiceData call not found exactly once')
text = text.replace(old, new, 1)
p.write_text(text)

Path('tests/sync_regression.php').write_text(r'''<?php

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
''')
