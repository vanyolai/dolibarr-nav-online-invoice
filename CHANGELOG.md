# Changelog

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
