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
     * Display the native point-of-tax date on supplier invoices.
     *
     * Dolibarr 23 stores facture_fourn.date_pointoftax but the standard
     * supplier invoice card does not render it. Keep the presentation tied to
     * the native INVOICE_POINTOFTAX_DATE feature flag.
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
