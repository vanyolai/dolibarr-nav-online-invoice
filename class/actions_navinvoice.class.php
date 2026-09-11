<?php

/**
 * Hooks for NAV Online Invoice integration.
 */
class ActionsNavInvoice
{
    /** @var string */
    public $resprints = '';

    /** @var array<string,mixed> */
    public $results = array();

    /** @var string */
    public $error = '';

    /** @var array<int,string> */
    public $errors = array();

    /**
     * Compatibility fallback for supplier invoice point-of-tax display.
     *
     * Stock Dolibarr 23 stores facture_fourn.date_pointoftax in the schema but
     * does not load/render it on supplier invoice cards. Patched/newer cores do
     * so natively; in that case $object->date_pointoftax is populated and this
     * hook deliberately renders nothing to avoid a duplicate row.
     *
     * @param array<string,mixed> $parameters Hook parameters.
     * @param object $object Current Dolibarr object.
     * @param string $action Current action.
     * @param HookManager $hookmanager Hook manager.
     * @return int 0 to continue standard processing.
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $db, $langs;

        $this->resprints = '';

        if (!getDolGlobalString('INVOICE_POINTOFTAX_DATE')) {
            return 0;
        }
        if (!is_object($object) || (string) ($object->element ?? '') !== 'invoice_supplier' || empty($object->id)) {
            return 0;
        }

        // Patched/newer Dolibarr cores load and render this field natively.
        if (!empty($object->date_pointoftax)) {
            return 0;
        }

        $sql = 'SELECT date_pointoftax FROM '.MAIN_DB_PREFIX.'facture_fourn';
        $sql .= ' WHERE rowid = '.((int) $object->id);
        $resql = $db->query($sql);
        if (!$resql) {
            return 0;
        }

        $row = $db->fetch_object($resql);
        $db->free($resql);
        if (!$row || empty($row->date_pointoftax)) {
            return 0;
        }

        $langs->loadLangs(array('navinvoice@navinvoice'));
        $date = $db->jdate($row->date_pointoftax);
        $this->resprints = '<tr><td>'.$langs->trans('InvoiceDeliveryDate').'</td><td>'.dol_print_date($date, 'day').'</td></tr>';

        return 0;
    }
}
