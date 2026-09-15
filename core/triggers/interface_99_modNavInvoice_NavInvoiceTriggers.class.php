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
 * Keep NAV invoice mirror links in sync with Dolibarr invoice deletion and
 * repair NAV mixed-sign supplier credit-note rows after Dolibarr 23 creates
 * them with its legacy all-negative credit-note convention.
 */
class InterfaceNavInvoiceTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'technic';
        $this->description = 'NAV Online Invoice link maintenance and import integrity triggers';
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

        if ($action === 'BILL_SUPPLIER_CREATE') {
            return $this->repairNavSupplierCreditNoteLines($object, $conf);
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

    /**
     * Dolibarr 23 normalizes every supplier-credit-note row to a positive
     * quantity and negative unit price. That destroys valid NAV MODIFY
     * documents which contain both negative and positive financial rows.
     *
     * The NAV importer deliberately places the source record key into ref_ext,
     * so only invoices created by this module are touched here. The source XML
     * remains authoritative: quantity and line net amount are used to restore
     * the same effective unit price that the import preview calculated. This
     * keeps preview and imported draft identical, including NAV lines whose
     * source unitPrice differs from lineNetAmount/quantity because of a NAV
     * lineDiscountData discount.
     *
     * @param CommonObject $object
     * @param Conf $conf
     * @return int
     */
    private function repairNavSupplierCreditNoteLines($object, Conf $conf): int
    {
        if (!is_object($object) || empty($object->id) || (int) ($object->type ?? 0) !== 2) {
            return 0;
        }

        $refExt = trim((string) ($object->ref_ext ?? ''));
        if (!preg_match('/^NAV\|INBOUND\|(.+)\|(\d+)$/', $refExt, $matches)) {
            return 0;
        }

        $invoiceNumber = (string) $matches[1];
        $batchIndex = (int) $matches[2];
        $entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;

        $sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$entity;
        $sql .= " AND invoice_direction = 'INBOUND'";
        $sql .= " AND invoice_number = '".$this->db->escape($invoiceNumber)."'";
        $sql .= ' AND batch_index = '.$batchIndex;
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->triggerError('Could not load NAV source XML for supplier credit-note line repair: '.$this->db->lasterror());
        }
        $mirror = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $xml = is_object($mirror) ? (string) ($mirror->invoice_data ?? '') : '';
        if ($xml === '') {
            return $this->triggerError('NAV source XML is missing for supplier credit-note line repair.');
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$document instanceof SimpleXMLElement) {
            return $this->triggerError('Stored NAV XML is invalid during supplier credit-note line repair.');
        }

        $navLines = $document->xpath('//*[local-name()="invoiceLines"]/*[local-name()="line"]');
        if (!$navLines) {
            return $this->triggerError('Stored NAV XML contains no invoice lines during supplier credit-note line repair.');
        }

        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'facture_fourn_det';
        $sql .= ' WHERE fk_facture_fourn = '.((int) $object->id);
        $sql .= ' ORDER BY rang, rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->triggerError('Could not load created supplier invoice lines: '.$this->db->lasterror());
        }
        $lineIds = array();
        while ($row = $this->db->fetch_object($resql)) {
            $lineIds[] = (int) $row->rowid;
        }
        $this->db->free($resql);

        if (count($lineIds) !== count($navLines)) {
            return $this->triggerError(
                'NAV/Dolibarr supplier credit-note line count differs during sign repair: NAV '
                .count($navLines).' vs Dolibarr '.count($lineIds).'.'
            );
        }

        foreach ($navLines as $index => $navLine) {
            $quantityText = $this->xmlText($navLine, './*[local-name()="quantity"]');
            $netText = $this->xmlText($navLine, './*[local-name()="lineAmountsNormal"]/*[local-name()="lineNetAmountData"]/*[local-name()="lineNetAmount"]');
            if ($quantityText === '' || $netText === '' || !is_numeric($quantityText) || !is_numeric($netText)) {
                // Descriptive/non-expression lines are left untouched. They do
                // not carry a financial sign that needs restoring.
                continue;
            }

            $quantity = abs((float) $quantityText);
            $net = (float) $netText;
            if ($quantity <= 0.000000001 || abs($net) <= 0.000000001) {
                continue;
            }

            // lineNetAmount is the authoritative financial amount. Using the
            // effective price reproduces the import-preview behaviour and also
            // naturally incorporates lineDiscountData without inventing a
            // percentage discount that was not present in the source XML.
            $unitPrice = $net / $quantity;

            $vatAmountText = $this->xmlText($navLine, './*[local-name()="lineAmountsNormal"]/*[local-name()="lineVatData"]/*[local-name()="lineVatAmount"]');
            $grossText = $this->xmlText($navLine, './*[local-name()="lineAmountsNormal"]/*[local-name()="lineGrossAmountData"]/*[local-name()="lineGrossAmountNormal"]');
            $vatPercentageText = $this->xmlText($navLine, './*[local-name()="lineAmountsNormal"]/*[local-name()="lineVatRate"]/*[local-name()="vatPercentage"]');
            $vatAmount = $vatAmountText !== '' && is_numeric($vatAmountText)
                ? (float) $vatAmountText
                : ($vatPercentageText !== '' && is_numeric($vatPercentageText) ? $net * (float) $vatPercentageText : 0.0);
            $gross = $grossText !== '' && is_numeric($grossText) ? (float) $grossText : $net + $vatAmount;
            $vatRate = $vatPercentageText !== '' && is_numeric($vatPercentageText) ? (float) $vatPercentageText * 100.0 : 0.0;
            $unitPriceTtc = $unitPrice * (1.0 + ($vatRate / 100.0));

            $sql = 'UPDATE '.MAIN_DB_PREFIX.'facture_fourn_det SET';
            $sql .= ' qty = '.$this->sqlNumber($quantity);
            $sql .= ', pu_ht = '.$this->sqlNumber($unitPrice);
            $sql .= ', pu_ttc = '.$this->sqlNumber($unitPriceTtc);
            $sql .= ', remise_percent = 0';
            $sql .= ', total_ht = '.$this->sqlNumber($net);
            $sql .= ', tva = '.$this->sqlNumber($vatAmount);
            $sql .= ', total_ttc = '.$this->sqlNumber($gross);
            $sql .= ' WHERE rowid = '.$lineIds[(int) $index];
            if (!$this->db->query($sql)) {
                return $this->triggerError('Could not restore NAV supplier credit-note line signs: '.$this->db->lasterror());
            }
        }

        dol_syslog(
            'NavInvoice trigger restored NAV mixed-sign supplier credit-note rows on invoice '.((int) $object->id),
            LOG_INFO
        );
        return 1;
    }

    private function xmlText(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        if (!$nodes) {
            return '';
        }
        return trim((string) $nodes[0]);
    }

    private function sqlNumber(float $value): string
    {
        $number = number_format($value, 12, '.', '');
        $number = rtrim(rtrim($number, '0'), '.');
        return $number === '' || $number === '-0' ? '0' : $number;
    }

    private function triggerError(string $message): int
    {
        $this->error = $message;
        dol_syslog('NavInvoice trigger error: '.$message, LOG_ERR);
        return -1;
    }
}
