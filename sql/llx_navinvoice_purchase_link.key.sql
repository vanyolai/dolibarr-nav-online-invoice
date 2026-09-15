ALTER TABLE llx_navinvoice_purchase_link ADD UNIQUE INDEX uk_navinvoice_purchase_pair (entity, fk_navinvoice_invoice, fk_commande_fourn);
ALTER TABLE llx_navinvoice_purchase_link ADD INDEX idx_navinvoice_purchase_mirror (entity, fk_navinvoice_invoice);
ALTER TABLE llx_navinvoice_purchase_link ADD INDEX idx_navinvoice_purchase_order (entity, fk_commande_fourn);
