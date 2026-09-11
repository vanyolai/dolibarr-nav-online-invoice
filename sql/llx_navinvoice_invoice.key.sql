ALTER TABLE llx_navinvoice_invoice ADD UNIQUE INDEX uk_navinvoice_invoice (entity, invoice_direction, invoice_number, batch_index);
ALTER TABLE llx_navinvoice_invoice ADD INDEX idx_navinvoice_issue_date (entity, invoice_issue_date);
ALTER TABLE llx_navinvoice_invoice ADD INDEX idx_navinvoice_customer_tax (entity, customer_tax_number);
ALTER TABLE llx_navinvoice_invoice ADD INDEX idx_navinvoice_supplier_tax (entity, supplier_tax_number);
ALTER TABLE llx_navinvoice_invoice ADD INDEX idx_navinvoice_fk_facture (fk_facture);
ALTER TABLE llx_navinvoice_invoice ADD INDEX idx_navinvoice_fk_facture_fourn (fk_facture_fourn);
