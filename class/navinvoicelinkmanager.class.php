<?php

/**
 * Keep NAV mirror links to Dolibarr invoices consistent.
 */
class NavInvoiceLinkManager
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * Return the linked Dolibarr invoice id if it still exists.
     * A stale mirror link is cleared automatically.
     *
     * @param object $record NAV mirror record containing rowid and link fields.
     */
    public function resolve($record, string $direction): int
    {
        $direction = strtoupper($direction);
        $inbound = $direction === 'INBOUND';
        $field = $inbound ? 'fk_facture_fourn' : 'fk_facture';
        $table = $inbound ? 'facture_fourn' : 'facture';
        $linkedId = (int) ($record->{$field} ?? 0);

        if ($linkedId <= 0) {
            return 0;
        }

        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.$table;
        $sql .= ' WHERE rowid = '.$linkedId;
        $sql .= ' AND entity = '.$this->entity;
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Failed to verify linked Dolibarr invoice: '.$this->db->lasterror());
        }
        $exists = (bool) $this->db->fetch_object($resql);
        $this->db->free($resql);

        if ($exists) {
            return $linkedId;
        }

        // The invoice was deleted outside the NAV module. Repair the mirror link
        // only if it still points to the id we have just verified as missing.
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' SET '.$field.' = NULL';
        $sql .= ' WHERE rowid = '.((int) ($record->rowid ?? 0));
        $sql .= ' AND entity = '.$this->entity;
        $sql .= ' AND '.$field.' = '.$linkedId;
        if (!$this->db->query($sql)) {
            throw new Exception('Failed to clear stale NAV invoice link: '.$this->db->lasterror());
        }

        $record->{$field} = null;
        dol_syslog('NavInvoiceLinkManager cleared stale '.$field.'='.$linkedId.' for NAV mirror row '.((int) ($record->rowid ?? 0)), LOG_INFO);
        return 0;
    }

    /**
     * Clear mirror links when a Dolibarr invoice is deleted.
     */
    public function unlinkDeletedInvoice(string $direction, int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return true;
        }

        $field = strtoupper($direction) === 'INBOUND' ? 'fk_facture_fourn' : 'fk_facture';
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_invoice';
        $sql .= ' SET '.$field.' = NULL';
        $sql .= ' WHERE entity = '.$this->entity;
        $sql .= ' AND '.$field.' = '.$invoiceId;

        if (!$this->db->query($sql)) {
            dol_syslog('NavInvoiceLinkManager failed to unlink deleted invoice '.$invoiceId.': '.$this->db->lasterror(), LOG_ERR);
            return false;
        }

        return true;
    }
}
