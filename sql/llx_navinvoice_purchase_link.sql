CREATE TABLE llx_navinvoice_purchase_link (
    rowid integer AUTO_INCREMENT PRIMARY KEY,
    entity integer NOT NULL,
    fk_navinvoice_invoice integer NOT NULL,
    fk_commande_fourn integer NOT NULL,
    datec datetime NOT NULL,
    tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
