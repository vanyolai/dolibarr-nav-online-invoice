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
 * restore NAV supplier-line semantics after Dolibarr creates imported drafts.
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
            return $this->restoreNavSupplierLines($object, $conf);
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
     * Restore the source NAV line representation on supplier invoices created
     * by this module.
     *
     * Dolibarr 23 forces all supplier-credit-note rows to positive quantities
     * and negative unit prices. It also cannot infer NAV lineDiscountData when
     * the importer initially supplies an effective unit price. Both behaviours
     * lose source semantics even when the financial totals remain correct.
     *
     * For NAV-created drafts this trigger therefore restores:
     * - the proper mixed-sign representation;
     * - the original NAV unitPrice when lineDiscountData reproduces the
     *   authoritative lineNetAmount;
     * - the corresponding native Dolibarr remise_percent value;
     * - the authoritative NAV line totals.
     *
     * If the discount metadata does not mathematically reproduce the NAV line
     * total, the safe fallback remains the effective unit price and zero
     * Dolibarr discount. No guess is stored.
     *
     * @param CommonObject $object
     * @param Conf $conf
     * @return int
     */
    private function restoreNavSupplierLines($object, Conf $conf): int
    {
        if (!is_object($object) || empty($object->id)) {
            return 0;
        }

        $refExt = trim((string) ($object->ref_ext ?? ''));
        if (!preg_match('/^NAV\|INBOUND\|(.+)\|(\d+)$/', $refExt, $matches)) {
            return 0;
        }

        $invoiceNumber = (string) $matches[1];
        $batchIndex = (int) $matches[2];
        $entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
        $creditNote = (int) ($object->type ?? 0) === 2;

        $sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE entity = '.$entity;
        $sql .= " AND invoice_direction = 'INBOUND'";
        $sql .= " AND invoice_number = '".$this->db->escape($invoiceNumber)."'";
        $sql .= ' AND batch_index = '.$batchIndex;
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return $this->triggerError('Could not load NAV source XML for supplier line restoration: '.$this->db->lasterror());
        }
        $mirror = $this->db->fetch_object($resql);
        $this->db->free($resql);
        $xml = is_object($mirror) ? (string) ($mirror->invoice_data ?? '') : '';
        if ($xml === '') {
            return $this->triggerError('NAV source XML is missing for supplier line restoration.');
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$document instanceof SimpleXMLElement) {
            return $this->triggerError('Stored NAV XML is invalid during supplier line restoration.');
        }

        $navLines = $document->xpath('//*[local-name()="invoiceLines"]/*[local-name()="line"]');
        if (!$navLines) {
            return $this->triggerError('Stored NAV XML contains no invoice lines during supplier line restoration.');
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
                'NAV/Dolibarr supplier line count differs during source restoration: NAV '
                .count($navLines).' vs Dolibarr '.count($lineIds).'.'
            );
        }

        foreach ($navLines as $index => $navLine) {
            $quantityText = $this->xmlText($navLine, './*[local-name()="quantity"]');
            $netText = $this->xmlText($navLine, './*[local-name()="lineAmountsNormal"]/*[local-name()="lineNetAmountData"]/*[local-name()="lineNetAmount"]');
            if ($quantityText === '' || $netText === '' || !is_numeric($quantityText) || !is_numeric($netText)) {
                // Descriptive/non-expression and simplified lines are left to
                // the regular importer mapping; there is no normal net amount
                // here from which to validate a NAV discount representation.
                continue;
            }

            $sourceQuantity = (float) $quantityText;
            $quantity = $creditNote ? abs($sourceQuantity) : $sourceQuantity;
            $net = (float) $netText;
            if (abs($quantity) <= 0.000000001) {
                continue;
            }

            $sourceUnitPriceText = $this->xmlText($navLine, './*[local-name()="unitPrice"]');
            $sourceUnitPrice = $sourceUnitPriceText !== '' && is_numeric($sourceUnitPriceText)
                ? (float) $sourceUnitPriceText
                : null;

            $discountPercent = 0.0;
            if ($sourceUnitPrice !== null) {
                // Credit notes in Dolibarr use positive quantities; keep the
                // source unit-price magnitude but let the NAV financial effect
                // determine the price sign. Standard invoices keep source signs.
                $unitPrice = $creditNote
                    ? ($net < -0.0000001 ? -abs($sourceUnitPrice) : abs($sourceUnitPrice))
                    : $sourceUnitPrice;
                $extended = $quantity * $unitPrice;

                if (!$this->amountsClose($extended, $net)) {
                    $discountPercent = $this->validatedDiscountPercent($navLine, $extended, $net);
                    if ($discountPercent === null) {
                        // NAV lineNetAmount is authoritative. Only flatten to an
                        // effective price when lineDiscountData cannot explain
                        // the difference deterministically.
                        $unitPrice = $net / $quantity;
                        $discountPercent = 0.0;
                    }
                }
            } else {
                $unitPrice = $net / $quantity;
            }

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
            $sql .= ', remise_percent = '.$this->sqlNumber((float) $discountPercent);
            $sql .= ', total_ht = '.$this->sqlNumber($net);
            $sql .= ', tva = '.$this->sqlNumber($vatAmount);
            $sql .= ', total_ttc = '.$this->sqlNumber($gross);
            $sql .= ' WHERE rowid = '.$lineIds[(int) $index];
            if (!$this->db->query($sql)) {
                return $this->triggerError('Could not restore NAV supplier line semantics: '.$this->db->lasterror());
            }
        }

        dol_syslog(
            'NavInvoice trigger restored NAV supplier line prices, discounts and signs on invoice '.((int) $object->id),
            LOG_INFO
        );
        return 1;
    }

    /**
     * Return a native Dolibarr discount percentage only when the NAV discount
     * data mathematically reproduces the authoritative line net amount.
     *
     * @return float|null
     */
    private function validatedDiscountPercent(SimpleXMLElement $navLine, float $extended, float $net): ?float
    {
        if (abs($extended) <= 0.000000001) {
            return null;
        }

        $rateText = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountRate"]');
        if ($rateText !== '' && is_numeric($rateText)) {
            $rate = abs((float) $rateText);
            if ($rate <= 1.0) {
                $percent = $rate * 100.0;
                if ($percent <= 100.0 && $this->amountsClose($extended * (1.0 - $rate), $net)) {
                    return $percent;
                }
            }
        }

        $valueText = $this->xmlText($navLine, './*[local-name()="lineDiscountData"]/*[local-name()="discountValue"]');
        if ($valueText !== '' && is_numeric($valueText)) {
            $discountValue = abs((float) $valueText);
            $percent = 100.0 * $discountValue / abs($extended);
            if ($percent >= 0.0 && $percent <= 100.0
                && $this->amountsClose($extended * (1.0 - ($percent / 100.0)), $net)) {
                return $percent;
            }
        }

        return null;
    }

    private function amountsClose(float $left, float $right): bool
    {
        return abs($left - $right) <= 0.01;
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
