<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Copy deterministic payment semantics from the source NAV invoice to
 * supplier-order drafts reconstructed by the NAV purchase workbench.
 */
class InterfaceNavPurchaseTerms extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->name = preg_replace('/^Interface/i', '', get_class($this));
        $this->family = 'technic';
        $this->description = 'NAV purchase-workbench payment terms propagation';
        $this->version = self::VERSIONS['dev'];
        $this->picto = 'supplier_order';
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
        if ($action !== 'ORDER_SUPPLIER_CREATE') {
            return 0;
        }
        if (empty($conf->navinvoice) || empty($conf->navinvoice->enabled)) {
            return 0;
        }
        if (!is_object($object) || empty($object->id) || (string) ($object->element ?? '') !== 'order_supplier') {
            return 0;
        }

        // Only touch orders explicitly reconstructed by NavPurchaseWorkbench.
        $note = (string) ($object->note_private ?? '');
        if (!preg_match('/(?:^|\n)mirror_rowid=(\d+)(?:\n|$)/', $note, $matches)) {
            return 0;
        }
        $mirrorId = (int) $matches[1];
        if ($mirrorId <= 0) {
            return 0;
        }

        $entity = !empty($object->entity) ? (int) $object->entity : (int) $conf->entity;
        $xml = $this->loadSourceXml($mirrorId, $entity);
        if ($xml === '') {
            dol_syslog('NavInvoice purchase terms: source XML not available for mirror '.$mirrorId, LOG_WARNING);
            return 0;
        }

        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        if (!$document instanceof SimpleXMLElement) {
            dol_syslog('NavInvoice purchase terms: invalid source XML for mirror '.$mirrorId, LOG_WARNING);
            return 0;
        }

        $paymentMethod = $this->xmlText($document, '//*[local-name()="invoiceDetail"]/*[local-name()="paymentMethod"]');
        $dueDate = $this->xmlText($document, '//*[local-name()="invoiceDetail"]/*[local-name()="paymentDate"]');
        $invoiceDate = $this->xmlText($document, '/*[local-name()="InvoiceData"]/*[local-name()="invoiceIssueDate"]');
        $deliveryDate = $this->xmlText($document, '//*[local-name()="invoiceDetail"]/*[local-name()="invoiceDeliveryDate"]');
        $accountingDeliveryDate = $this->xmlText($document, '//*[local-name()="invoiceDetail"]/*[local-name()="invoiceAccountingDeliveryDate"]');

        $changed = false;

        $modeId = $this->paymentModeId($paymentMethod);
        if ($modeId > 0) {
            $result = $object->setPaymentMethods($modeId);
            if ($result > 0) {
                $changed = true;
            } else {
                dol_syslog('NavInvoice purchase terms: could not set payment method on supplier order '.$object->id, LOG_WARNING);
            }
        }

        $termId = $this->paymentTermId($dueDate, array($accountingDeliveryDate, $deliveryDate, $invoiceDate), $entity);
        if ($termId > 0) {
            $result = $object->setPaymentTerms($termId);
            if ($result > 0) {
                $changed = true;
            } else {
                dol_syslog('NavInvoice purchase terms: could not set payment term on supplier order '.$object->id, LOG_WARNING);
            }
        } elseif ($dueDate !== '') {
            dol_syslog(
                'NavInvoice purchase terms: no exact simple Dolibarr payment term matches NAV due date '.$dueDate.' on supplier order '.$object->id,
                LOG_INFO
            );
        }

        return $changed ? 1 : 0;
    }

    private function loadSourceXml(int $mirrorId, int $entity): string
    {
        $sql = 'SELECT invoice_data FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' WHERE rowid = '.$mirrorId.' AND entity = '.$entity.' AND invoice_direction = \'INBOUND\' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            return '';
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (string) ($obj->invoice_data ?? '') : '';
    }

    private function paymentModeId(string $navMethod): int
    {
        $map = array(
            'CASH' => 'LIQ',
            'TRANSFER' => 'VIR',
            'CARD' => 'CB',
        );
        $code = $map[strtoupper(trim($navMethod))] ?? '';
        if ($code === '') {
            return 0;
        }

        $sql = 'SELECT id FROM '.MAIN_DB_PREFIX.'c_paiement';
        $sql .= " WHERE code = '".$this->db->escape($code)."' LIMIT 1";
        $resql = $this->db->query($sql);
        if (!$resql) {
            return 0;
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        return $obj ? (int) $obj->id : 0;
    }

    /**
     * Resolve an exact simple Dolibarr payment term from the NAV due date.
     *
     * NAV gives the actual due date, not a payment-term code. We therefore only
     * infer straight N-day terms when one of the invoice's authoritative base
     * dates produces an exact number of days. Accounting/delivery date is tried
     * before invoice issue date; this maps e.g. 2026-09-14 -> 2026-10-14 to the
     * native 30-day term even when the invoice itself was issued on 2026-09-15.
     * End-of-month / nth-of-month terms are deliberately not guessed.
     *
     * @param string $dueDate
     * @param string[] $baseDates
     */
    private function paymentTermId(string $dueDate, array $baseDates, int $entity): int
    {
        $due = $this->parseDate($dueDate);
        if ($due === null) {
            return 0;
        }

        $seenDays = array();
        foreach ($baseDates as $baseDate) {
            $base = $this->parseDate((string) $baseDate);
            if ($base === null || $base > $due) {
                continue;
            }
            $days = (int) $base->diff($due)->days;
            if (isset($seenDays[$days])) {
                continue;
            }
            $seenDays[$days] = true;

            $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_payment_term';
            $sql .= ' WHERE active = 1';
            $sql .= ' AND entity IN (0, '.$entity.')';
            $sql .= ' AND COALESCE(type_cdr, 0) = 0';
            $sql .= ' AND COALESCE(decalage, 0) = 0';
            $sql .= ' AND COALESCE(nbjour, 0) = '.$days;
            $sql .= ' ORDER BY CASE WHEN entity = '.$entity.' THEN 0 ELSE 1 END, sortorder, rowid LIMIT 1';
            $resql = $this->db->query($sql);
            if (!$resql) {
                continue;
            }
            $obj = $this->db->fetch_object($resql);
            $this->db->free($resql);
            if ($obj) {
                return (int) $obj->rowid;
            }
        }

        return 0;
    }

    private function parseDate(string $date): ?DateTimeImmutable
    {
        $date = trim($date);
        if ($date === '') {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return null;
        }
        return $parsed;
    }

    private function xmlText(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        if (!$nodes) {
            return '';
        }
        return trim((string) $nodes[0]);
    }
}
