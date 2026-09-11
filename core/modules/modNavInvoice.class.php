<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modNavInvoice extends DolibarrModules
{
    public function __construct($db)
    {
        global $conf;

        $this->db = $db;
        $this->numero = 581000;
        $this->rights_class = 'navinvoice';
        $this->family = 'interface';
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = 'ModuleNavInvoiceDesc';
        $this->descriptionlong = 'ModuleNavInvoiceDesc';
        $this->editor_name = 'vanyolai';
        $this->editor_url = 'https://github.com/vanyolai/dolibarr-nav-online-invoice';
        $this->version = '0.7.2';
        $this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
        $this->picto = 'file-invoice';

        $this->module_parts = array(
            'triggers' => 1,
            'login' => 0,
            'substitutions' => 0,
            'menus' => 0,
            'tpl' => 0,
            'models' => 0,
            'css' => array(),
            'js' => array(),
            'hooks' => array('invoicesuppliercard'),
            'moduleforexternal' => 0,
        );

        $this->dirs = array('/navinvoice/temp');
        $this->config_page_url = array('setup.php@navinvoice');
        $this->hidden = false;
        $this->depends = array('modFacture');
        $this->requiredby = array();
        $this->conflictwith = array();
        $this->langfiles = array('navinvoice@navinvoice');
        $this->phpmin = array(8, 1);
        $this->need_dolibarr_version = array(23, 0);
        $this->need_javascript_ajax = 0;

        // Keep NAV connection settings when the module is temporarily disabled.
        // This also makes a disable/enable cycle safe when refreshing menu entries.
        $this->const = array(
            0 => array('NAVINVOICE_ENVIRONMENT', 'chaine', 'test', 'NAV API environment', 0, 'current', 0),
            1 => array('NAVINVOICE_SYNC_ENABLED', 'yesno', '0', 'Enable scheduled synchronization', 0, 'current', 0),
            2 => array('NAVINVOICE_SYNC_LOOKBACK_DAYS', 'chaine', '7', 'Days to re-check on scheduled sync', 0, 'current', 0),
            3 => array('NAVINVOICE_FETCH_FULL_DATA', 'yesno', '1', 'Download complete invoice XML', 0, 'current', 0),
            4 => array('NAVINVOICE_SOFTWARE_ID', 'chaine', 'DOLIBARRNAVSYNC001', 'NAV software identifier', 0, 'current', 0),
        );

        if (!isModEnabled('navinvoice')) {
            $conf->navinvoice = new stdClass();
            $conf->navinvoice->enabled = 0;
        }

        $this->tabs = array();
        $this->dictionaries = array();
        $this->boxes = array();

        $this->cronjobs = array(
            0 => array(
                'label' => 'NavInvoiceScheduledSync',
                'jobtype' => 'method',
                'class' => '/navinvoice/class/navinvoicesync.class.php',
                'objectname' => 'NavInvoiceSync',
                'method' => 'runScheduledSync',
                'parameters' => '',
                'comment' => 'NavInvoiceScheduledSyncDesc',
                'frequency' => 1,
                'unitfrequency' => 3600,
                'status' => 0,
                'test' => 'isModEnabled("navinvoice") && getDolGlobalInt("NAVINVOICE_SYNC_ENABLED")',
                'priority' => 50,
            ),
        );

        $this->rights = array();
        $r = 0;
        $this->rights[$r][0] = 581001;
        $this->rights[$r][1] = 'Read NAV invoice synchronization data';
        $this->rights[$r][4] = 'invoice';
        $this->rights[$r][5] = 'read';
        $r++;
        $this->rights[$r][0] = 581002;
        $this->rights[$r][1] = 'Run NAV invoice synchronization';
        $this->rights[$r][4] = 'invoice';
        $this->rights[$r][5] = 'sync';
        $r++;
        $this->rights[$r][0] = 581003;
        $this->rights[$r][1] = 'Import NAV invoices as Dolibarr drafts';
        $this->rights[$r][4] = 'invoice';
        $this->rights[$r][5] = 'import';

        // NAV Online Invoice belongs to Dolibarr's Billing / Payment area.
        // Use the stable core mainmenu code "billing" instead of creating a
        // separate top-level application menu.
        $this->menu = array();
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu' => 'fk_mainmenu=billing',
            'type' => 'left',
            'titre' => 'NavOnlineInvoice',
            'prefix' => img_picto('', $this->picto, 'class="pictofixedwidth valignmiddle paddingright"'),
            'mainmenu' => 'billing',
            'leftmenu' => 'navinvoice',
            'url' => '/navinvoice/index.php?mainmenu=billing&leftmenu=navinvoice',
            'langs' => 'navinvoice@navinvoice',
            'position' => 250,
            'enabled' => "isModEnabled('navinvoice')",
            'perms' => '$user->hasRight("navinvoice", "invoice", "read")',
            'target' => '',
            'user' => 0,
        );
    }

    public function init($options = '')
    {
        $result = $this->_load_tables('/navinvoice/sql/');
        if ($result < 0) {
            return -1;
        }

        $this->remove($options);
        $sql = array();
        return $this->_init($sql, $options);
    }

    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
