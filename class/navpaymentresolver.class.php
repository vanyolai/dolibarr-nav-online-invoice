<?php

/**
 * Resolve NAV payment semantics to Dolibarr dictionary identifiers.
 *
 * The resolver is deliberately deterministic. Payment methods are mapped from
 * NAV enum values to stable Dolibarr payment codes. Payment terms are selected
 * only when a simple active Dolibarr N-day term exactly reproduces the NAV due
 * date from one of the supplied accounting base dates.
 */
class NavPaymentResolver
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

    public function paymentModeId(string $navMethod): int
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
     * @param string[] $baseDates Dates that can legally explain the NAV due date,
     *                            ordered from most to least specific.
     */
    public function paymentTermId(string $dueDate, array $baseDates): int
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
            $sql .= ' AND entity IN (0, '.$this->entity.')';
            $sql .= ' AND COALESCE(type_cdr, 0) = 0';
            $sql .= ' AND COALESCE(decalage, 0) = 0';
            $sql .= ' AND COALESCE(nbjour, 0) = '.$days;
            $sql .= ' ORDER BY CASE WHEN entity = '.$this->entity.' THEN 0 ELSE 1 END, sortorder, rowid LIMIT 1';
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
        return $parsed && $parsed->format('Y-m-d') === $date ? $parsed : null;
    }
}
