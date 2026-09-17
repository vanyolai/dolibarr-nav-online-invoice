from pathlib import Path
import re

# This script is intentionally a one-shot branch migration. It is run by the
# final-audit workflow and commits only the resulting source/test changes.

# Runtime schema migration belongs to module activation only. The final audit
# renamed ensureSchema() to migrateLegacySchema(), so remove the stale UI caller
# rather than reintroducing runtime DDL.
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

# NAV queryInvoiceData permits supplierTaxNumber only for INBOUND queries. Keep
# the rule in the API boundary as an invariant, so a future caller cannot emit
# the invalid OUTBOUND + supplierTaxNumber request that caused HTTP 400.
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

# Also make the sync caller explicit. A failed XML request happens after digest
# upsert, leaving data_fetched=0; that state is deliberately retried on the next
# sync by the existing (!$upsert['data_fetched']) condition.
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

# Source-level guard for the cross-layer invariants. This complements the
# parser/purchase behavioral regressions without requiring live NAV credentials.
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
$assert(strpos($sync, "public function migrateLegacySchema(): void") !== false, 'activation-only legacy migration must remain available');
$assert(strpos($sync, "!$upsert['data_fetched']") !== false, 'rows whose XML download failed must be retried on a later sync');
$assert(strpos($sync, "$direction === 'INBOUND' && $supplierTaxNumber !== '' ? $supplierTaxNumber : null") !== false, 'sync must pass supplierTaxNumber only for INBOUND XML queries');
$assert(strpos($api, "$direction === 'INBOUND' && $supplierTaxNumber !== null") !== false, 'API boundary must reject supplierTaxNumber emission for OUTBOUND queries');

if ($failures) {
    fwrite(STDERR, "NAV sync regression failures:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo "NAV sync regression tests passed.\n";
''')
