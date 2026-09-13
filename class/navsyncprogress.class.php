<?php

/**
 * Persist manual NAV synchronization progress so the UI can poll it while the
 * long-running synchronization request is still active.
 */
class NavSyncProgress
{
    /** @var DoliDB */
    private $db;
    private int $entity;
    private int $userId;

    public function __construct($db, int $entity, int $userId)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->userId = $userId;
    }

    public function ensureSchema(): void
    {
        $table = MAIN_DB_PREFIX.'navinvoice_sync_run';
        $sql = 'CREATE TABLE IF NOT EXISTS '.$table.' ('
            .'rowid integer AUTO_INCREMENT PRIMARY KEY,'
            .'entity integer NOT NULL,'
            .'run_key varchar(64) NOT NULL,'
            .'fk_user integer NOT NULL DEFAULT 0,'
            .'status varchar(16) NOT NULL DEFAULT \'running\','
            .'stage varchar(32) NOT NULL DEFAULT \'starting\','
            .'date_from date NULL,'
            .'date_to date NULL,'
            .'current_direction varchar(8) NULL,'
            .'chunk_from date NULL,'
            .'chunk_to date NULL,'
            .'page integer NULL,'
            .'available_page integer NULL,'
            .'current_invoice varchar(100) NULL,'
            .'seen integer NOT NULL DEFAULT 0,'
            .'inserted integer NOT NULL DEFAULT 0,'
            .'updated integer NOT NULL DEFAULT 0,'
            .'unchanged integer NOT NULL DEFAULT 0,'
            .'downloaded integer NOT NULL DEFAULT 0,'
            .'message text NULL,'
            .'datec datetime NOT NULL,'
            .'tms timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
            .'UNIQUE KEY uk_navinvoice_sync_run (entity, run_key),'
            .'KEY idx_navinvoice_sync_run_user (entity, fk_user, status),'
            .'KEY idx_navinvoice_sync_run_tms (tms)'
            .') ENGINE=innodb';
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }
    }

    public function start(string $runKey, string $dateFrom, string $dateTo): void
    {
        $this->validateRunKey($runKey);
        $this->ensureSchema();
        $table = MAIN_DB_PREFIX.'navinvoice_sync_run';
        $now = $this->db->idate(dol_now());
        $sql = 'INSERT INTO '.$table.' (entity, run_key, fk_user, status, stage, date_from, date_to, datec) VALUES ('
            .$this->entity.", '".$this->db->escape($runKey)."', ".$this->userId.", 'running', 'starting', '"
            .$this->db->escape($dateFrom)."', '".$this->db->escape($dateTo)."', '".$now."')"
            .' ON DUPLICATE KEY UPDATE fk_user = VALUES(fk_user), status = \'running\', stage = \'starting\','
            .' date_from = VALUES(date_from), date_to = VALUES(date_to), datec = VALUES(datec), current_direction = NULL, chunk_from = NULL,'
            .' chunk_to = NULL, page = NULL, available_page = NULL, current_invoice = NULL, seen = 0, inserted = 0,'
            .' updated = 0, unchanged = 0, downloaded = 0, message = NULL';
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }
        $this->cleanup();
    }

    /** @param array<string,mixed> $state */
    public function update(string $runKey, array $state): void
    {
        $this->validateRunKey($runKey);
        $allowed = array(
            'status' => 'string', 'stage' => 'string', 'current_direction' => 'string',
            'chunk_from' => 'string', 'chunk_to' => 'string', 'page' => 'int', 'available_page' => 'int',
            'current_invoice' => 'string', 'seen' => 'int', 'inserted' => 'int', 'updated' => 'int',
            'unchanged' => 'int', 'downloaded' => 'int', 'message' => 'string',
        );
        $set = array();
        foreach ($allowed as $field => $type) {
            if (!array_key_exists($field, $state)) {
                continue;
            }
            $value = $state[$field];
            if ($value === null || $value === '') {
                $set[] = $field.' = NULL';
            } elseif ($type === 'int') {
                $set[] = $field.' = '.((int) $value);
            } else {
                $set[] = $field." = '".$this->db->escape((string) $value)."'";
            }
        }
        if (!$set) {
            return;
        }
        $sql = 'UPDATE '.MAIN_DB_PREFIX.'navinvoice_sync_run SET '.implode(', ', $set)
            .' WHERE entity = '.$this->entity
            ." AND run_key = '".$this->db->escape($runKey)."'"
            .' AND fk_user = '.$this->userId;
        if (!$this->db->query($sql)) {
            throw new Exception($this->db->lasterror());
        }
    }

    /** @return array<string,mixed>|null */
    public function get(string $runKey): ?array
    {
        $this->validateRunKey($runKey);
        $this->ensureSchema();
        $sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_sync_run'
            .' WHERE entity = '.$this->entity
            ." AND run_key = '".$this->db->escape($runKey)."'"
            .' AND fk_user = '.$this->userId
            .' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }
        return array(
            'status' => (string) $obj->status,
            'stage' => (string) $obj->stage,
            'date_from' => (string) $obj->date_from,
            'date_to' => (string) $obj->date_to,
            'current_direction' => (string) $obj->current_direction,
            'chunk_from' => (string) $obj->chunk_from,
            'chunk_to' => (string) $obj->chunk_to,
            'page' => $obj->page !== null ? (int) $obj->page : null,
            'available_page' => $obj->available_page !== null ? (int) $obj->available_page : null,
            'current_invoice' => (string) $obj->current_invoice,
            'seen' => (int) $obj->seen,
            'inserted' => (int) $obj->inserted,
            'updated' => (int) $obj->updated,
            'unchanged' => (int) $obj->unchanged,
            'downloaded' => (int) $obj->downloaded,
            'message' => (string) $obj->message,
            'datec' => (string) $obj->datec,
            'updated_at' => (string) $obj->tms,
        );
    }

    private function cleanup(): void
    {
        $cutoff = $this->db->idate(dol_now() - 7 * 86400);
        $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."navinvoice_sync_run WHERE tms < '".$cutoff."'");
    }

    private function validateRunKey(string $runKey): void
    {
        if (!preg_match('/^[A-Fa-f0-9]{24,64}$/', $runKey)) {
            throw new InvalidArgumentException('Invalid NAV sync run key.');
        }
    }
}
