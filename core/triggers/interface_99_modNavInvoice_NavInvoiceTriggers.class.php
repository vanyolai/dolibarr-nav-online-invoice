<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Keep NAV invoice mirror links in sync with Dolibarr invoice deletion.
 */
class InterfaceNavInvoiceTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'technic';
        $this->description = 'NAV Online Invoice link maintenance triggers';
        $this->version = self::VERSIONS['dev'];
        $this->picto = 'file-invoice';
    }

    /**
     * @param string $action
     * @param CommonObject $object
     * @param User $user
     * @param Translate $langs
     * @param Conf $conf
     * @return int
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        if (empty($conf->navinvoice) || empty($conf->navinvoice->enabled)) {
            return 0;
        }
        if ($action !== 'BILL_DELETE' && $action !== 'BILL_SUPPLIER_DELETE') {
            return 0;
        }

        dol_include_once('/navinvoice/class/navinvoicelinkmanager.class.php');

        $entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
        $manager = new NavInvoiceLinkManager($this->db, $entity);
        $direction = $action === 'BILL_SUPPLIER_DELETE' ? 'INBOUND' : 'OUTBOUND';
        $ok = $manager->unlinkDeletedInvoice($direction, (int) $object->id);

        // Do not prevent a Dolibarr invoice deletion if link cleanup fails.
        // The NAV pages also self-heal stale links on the next access.
        return $ok ? 1 : 0;
    }
}
