<?php

define('MAIN_DB_PREFIX', 'llx_');
define('LOG_INFO', 1);
define('LOG_ERR', 2);

function dol_include_once($path): void {}
function dol_syslog($message, $level = 0): void {}
function getDolGlobalInt($key, $default = 0) { return $key === 'MAIN_MAX_DECIMALS_TOT' ? 0 : $default; }
function price2num($value, $rounding = '', $option = 0)
{
    if ($rounding === 'MT') return round((float) $value, 0);
    return (float) $value;
}
function dol_strtoupper($value) { return strtoupper($value); }

class User {}

class FactureFournisseur
{
    public $id = 1;
    public $thirdparty;
    public $total_ht = 0.0;
    public $total_tva = 0.0;
    public $total_ttc = 0.0;
    public $multicurrency_total_ht = 0.0;
    public $multicurrency_total_tva = 0.0;
    public $multicurrency_total_ttc = 0.0;
    public $note_private = '';
    public $lastRounding = '';
    public $setValueCalls = array();

    public function __construct()
    {
        $this->thirdparty = new stdClass();
    }

    public function update_price($exclspec = 0, $roundingadjust = 'auto', $nodatabaseupdate = 0, $seller = null)
    {
        $this->lastRounding = $roundingadjust;
        $this->total_ht = 0.0;
        $this->total_tva = 0.0;
        $this->total_ttc = 0.0;
        foreach (SupplierInvoiceLine::$rows as $row) {
            $this->total_ht += (float) $row['total_ht'];
            $this->total_tva += (float) $row['total_tva'];
            $this->total_ttc += (float) $row['total_ttc'];
        }
        return 1;
    }

    public function update($user = null, $notrigger = 0)
    {
        return 1;
    }

    public function setValueFrom($field, $value, $table = '', $id = null, $format = '', $idField = '', $user = null, $trigger = '')
    {
        $this->{$field} = (float) $value;
        $this->setValueCalls[$field] = (float) $value;
        return 1;
    }

    public function fetch($id)
    {
        return 1;
    }
}

class SupplierInvoiceLine
{
    public static $rows = array();
    public static $updates = array();

    public $db;
    public $id;
    public $qty;
    public $remise_percent;
    public $subprice;
    public $pu_ht;
    public $subprice_ttc;
    public $pu_ttc;
    public $total_ht;
    public $total_tva;
    public $total_ttc;
    public $multicurrency_subprice;
    public $multicurrency_total_ht;
    public $multicurrency_total_tva;
    public $multicurrency_total_ttc;
    public $error = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function fetch($id)
    {
        if (!isset(self::$rows[$id])) return 0;
        $this->id = $id;
        foreach (self::$rows[$id] as $key => $value) $this->{$key} = $value;
        return 1;
    }

    public function update($notrigger = 0)
    {
        self::$updates[] = array('id' => $this->id, 'notrigger' => $notrigger);
        self::$rows[$this->id] = array(
            'qty' => $this->qty,
            'remise_percent' => $this->remise_percent,
            'subprice' => $this->subprice,
            'subprice_ttc' => $this->subprice_ttc,
            'total_ht' => $this->total_ht,
            'total_tva' => $this->total_tva,
            'total_ttc' => $this->total_ttc,
            'multicurrency_subprice' => $this->multicurrency_subprice,
            'multicurrency_total_ht' => $this->multicurrency_total_ht,
            'multicurrency_total_tva' => $this->multicurrency_total_tva,
            'multicurrency_total_ttc' => $this->multicurrency_total_ttc,
        );
        return 1;
    }
}

class FakeResult
{
    public $rows;
    public $offset = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
}

class FakeDb
{
    public $queries = array();

    public function query($sql)
    {
        $this->queries[] = $sql;
        if (strpos($sql, 'SELECT rowid FROM llx_facture_fourn_det') === 0) {
            return new FakeResult(array((object) array('rowid' => 10), (object) array('rowid' => 11)));
        }
        return true;
    }

    public function fetch_object($result)
    {
        if (!($result instanceof FakeResult) || !isset($result->rows[$result->offset])) return false;
        return $result->rows[$result->offset++];
    }

    public function free($result): void {}
    public function lasterror(): string { return ''; }
}

require_once __DIR__.'/../class/navamountpolicy.class.php';
require_once __DIR__.'/../class/navinvoiceimporter.class.php';

function fail_importer_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

function set_private_property($object, string $name, $value): void
{
    $property = new ReflectionProperty(get_class($object), $name);
    $property->setAccessible(true);
    $property->setValue($object, $value);
}

function invoke_private($object, string $name, array $args)
{
    $method = new ReflectionMethod(get_class($object), $name);
    $method->setAccessible(true);
    return $method->invokeArgs($object, $args);
}

$db = new FakeDb();
$reflection = new ReflectionClass('NavInvoiceImporter');
$importer = $reflection->newInstanceWithoutConstructor();
set_private_property($importer, 'db', $db);
set_private_property($importer, 'baseCurrency', 'HUF');

$preview = array(
    'header' => array('currency' => 'HUF'),
    'category' => 'NORMAL',
    'totals' => array('net' => 2512.0, 'vat' => 679.0, 'gross' => 3191.0),
    'lines' => array(
        array('quantity' => 1.0, 'discount_percent' => 0.0, 'net' => 1255.6, 'vat' => 339.1, 'gross' => 1594.7),
        array('quantity' => 1.0, 'discount_percent' => 0.0, 'net' => 1256.3, 'vat' => 339.4, 'gross' => 1595.7),
    ),
);

SupplierInvoiceLine::$rows = array(
    10 => array(
        'qty' => 1.0, 'remise_percent' => 0.0, 'subprice' => 1256.0, 'subprice_ttc' => 1595.0,
        'total_ht' => 1256.0, 'total_tva' => 339.0, 'total_ttc' => 1595.0,
        'multicurrency_subprice' => 1256.0, 'multicurrency_total_ht' => 1256.0,
        'multicurrency_total_tva' => 339.0, 'multicurrency_total_ttc' => 1595.0,
    ),
    11 => array(
        'qty' => 1.0, 'remise_percent' => 0.0, 'subprice' => 1256.0, 'subprice_ttc' => 1596.0,
        'total_ht' => 1256.0, 'total_tva' => 339.0, 'total_ttc' => 1596.0,
        'multicurrency_subprice' => 1256.0, 'multicurrency_total_ht' => 1256.0,
        'multicurrency_total_tva' => 339.0, 'multicurrency_total_ttc' => 1596.0,
    ),
);

$invoice = new FactureFournisseur();

if (invoke_private($importer, 'supplierLinesMatchNav', array($invoice, $preview)) !== true) {
    fail_importer_test('individually rounded HUF supplier lines must match NAV at document precision');
}

invoke_private($importer, 'preserveSupplierNavSummary', array($invoice, $preview, new User()));

if ($invoice->lastRounding !== 'none') {
    fail_importer_test('stored NAV lines must first be summed with update_price mode none');
}
if ((float) $invoice->total_ht !== 2512.0 || (float) $invoice->total_tva !== 679.0 || (float) $invoice->total_ttc !== 3191.0) {
    fail_importer_test('NAV header totals must survive cumulative HUF line rounding');
}
if (strpos($invoice->note_private, 'nav_summary_reconciled=1') === false) {
    fail_importer_test('summary reconciliation must be auditable on the invoice');
}
foreach (array('multicurrency_total_ht', 'multicurrency_total_tva', 'multicurrency_total_ttc') as $field) {
    if (!isset($invoice->setValueCalls[$field])) {
        fail_importer_test('parallel base-currency totals must remain coherent: '.$field);
    }
}
foreach ($db->queries as $sql) {
    if (strpos($sql, 'UPDATE llx_facture_fourn_det') !== false) {
        fail_importer_test('importer must not directly update Dolibarr core supplier-line tables');
    }
}

fwrite(STDOUT, "Importer reconciliation regression tests passed\n");
