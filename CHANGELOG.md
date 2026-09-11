# Changelog

## 0.7.2 - development

- Map NAV `invoiceDeliveryDate` to Dolibarr's native `date_pointoftax` field on customer and supplier invoice imports.
- Preserve a missing NAV payment due date as missing instead of inventing an invoice-date fallback.
- Add a supplier-invoice point-of-tax compatibility display for stock Dolibarr 23 and suppress it when the core already supports the field natively.

## 0.7.1 - development

- Block invoice import when the matched Dolibarr third party has no country assigned, because Dolibarr needs the country for correct VAT handling on invoice lines.
- Block import when the Dolibarr partner country conflicts with the country code reported in the NAV invoice, requiring manual review instead of guessing VAT treatment.
- Keep NAV partner enrichment read-only; invoice import does not silently mutate third-party master data.

## 0.7.0 - development

- Support `SIMPLIFIED` NAV invoices whose source amounts are expressed as gross values with VAT content instead of normal net/VAT amounts.
- Convert supported NAV VAT-content values to Dolibarr VAT rates, including the Hungarian 5%, 18% and 27% rates, while treating NAV gross as the authoritative amount.
- Derive Dolibarr net unit prices for simplified invoices from authoritative gross amounts rather than misinterpreting NAV `unitPrice` as net.
- Validate simplified imports against the NAV gross total; net and VAT remain explicitly derived values because NAV VAT content is rounded.
- Label simplified invoice unit prices as gross and show the VAT-content-to-rate conversion in invoice details and import preview.
- Move the module entry from its own top-level menu into Dolibarr's Billing / Payment left menu.
- Preserve NAV module constants when temporarily disabling the module so menu registration can be refreshed safely.

## 0.6.2 - development

- Clear NAV mirror links automatically when linked Dolibarr customer or supplier invoices are deleted.
- Self-heal stale `fk_facture` / `fk_facture_fourn` links when the NAV list or import preview detects that the referenced Dolibarr invoice no longer exists.
- Allow a previously imported-and-deleted draft to be imported again without manual database cleanup.

## 0.6.1 - development

- Map NAV invoice units to active Dolibarr `llx_c_units` entries when `PRODUCT_USE_UNITS` is enabled.
- Prefer stable Dolibarr unit codes over localized labels; use controlled aliases only as a fallback.
- Leave unresolved or ambiguous units unassigned rather than guessing.
- Show NAV and resolved Dolibarr units in the import preview.

## 0.6.0 - development

- Add guarded manual import of NAV invoices into Dolibarr as draft customer or supplier invoices.
- Import parsed NAV invoice lines as free-text Dolibarr invoice lines; product matching is intentionally deferred.
- Require strong partner matching, full invoice XML, CREATE operation, NORMAL category and base-currency invoices for the first live importer.
- Block unsupported VAT-content/special-VAT cases and invoices whose line totals do not reconcile with NAV header totals.
- Preserve authoritative NAV line net totals by deriving the Dolibarr unit price from line net amount and quantity when required.
- Detect duplicates using NAV external keys and direction-specific Dolibarr invoice references.
- Store NAV source metadata in the invoice private note and link the created Dolibarr invoice back to the NAV mirror record.
- Create drafts only; no automatic validation, booking or payment operation is performed.

## 0.5.0 - development

- Add read-only partner enrichment preview based on matched NAV invoice party data.
- Suggest filling missing tax number, street address, postal code, town, country and customer/supplier role without modifying Dolibarr yet.
- Preserve existing non-empty Dolibarr values and surface differences instead of overwriting them.
- Keep bank-account data in a separate review category because partners may have multiple or changing bank accounts.
- Only mark enrichment as safe when the partner match is based on tax number or strong name/address evidence.

## 0.4.0 - development

- Add read-only NAV-to-Dolibarr third-party matching on invoice details.
- Match Hungarian partners primarily by the first 8 digits of the tax number stored in Dolibarr VAT ID.
- Fall back to normalized company-name matching, reinforced by postal code and town when available.
- Reject name-based matches when Dolibarr already contains a different tax number.
- Distinguish exact tax, strong name/address, probable name, fuzzy candidate, ambiguous and missing matches.
- Indicate when a matched Dolibarr third party has no tax number and NAV data is strong enough to suggest filling it later.

## 0.3.0 - development

- Parse stored NAV InvoiceData XML into structured invoice header, party, totals and line data.
- Add a detailed invoice view with clickable invoice numbers from the mirror list.
- Display supplier/customer data, dates, payment information, totals and invoice lines.
- Support normal and simplified invoice amount structures.
- Add human-readable NAV enum labels and derived line gross display where NAV omits the gross line amount.
- Add raw NAV XML inspection and reliable parsing of namespaced simple/detailed addresses.

## 0.2.0 - development

- Add NAV `INBOUND` invoice discovery and full XML retrieval.
- Manual synchronization can target inbound, outbound or both directions.
- Scheduled synchronization refreshes both directions.
- Store invoice direction in the NAV mirror and include it in record uniqueness.
- Add supplier tax-number indexing and reserved `fk_facture_fourn` linkage for future Dolibarr supplier-invoice mapping.
- Existing mirror tables are migrated automatically on first module-page access or synchronization; existing records are classified as `OUTBOUND`.
- Unified list now shows the relevant external partner for each direction.

## 0.1.0 - development

- Initial NAV Online Invoice read-only synchronization foundation.
- Repository root is now the Dolibarr module root, suitable for direct installation or Git submodule use at `htdocs/custom/navinvoice`.