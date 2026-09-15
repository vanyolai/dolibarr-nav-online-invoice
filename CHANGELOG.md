# Changelog

## 0.8.0 - development

- Document git subtree as the preferred integration model; this repository is the authoritative module source and Dolibarr consumes it under `htdocs/custom/navinvoice`.
- Promote `queryTaxpayer` into a reusable current taxpayer master-data service with namespace-independent parsing, tax-number normalization and HQ/site/branch address handling.
- Normalize NAV taxpayer master-data fields that arrive entirely in uppercase for display and Dolibarr master-data creation, while preserving the exact raw NAV wording for diagnostics/audit.
- Add a dedicated partner-resolution page that compares historical invoice party data with current NAV taxpayer master data.
- Add controlled third-party creation from freshly queried NAV data, with duplicate-candidate blocking, country mapping and a second match check immediately before creation.
- Create suppliers/customers with only the role implied by invoice direction and return to invoice import after successful creation.
- Keep invoice party values out of partner writes; invoice data is comparison/history only, while creation values come from the fresh NAV master-data response.
- Add a dedicated `partner_required` batch state when partner absence is the only remaining import blocker, with direct routing into the NAV taxpayer/partner resolution workflow.
- Add a NAV relation resolver for CREATE/MODIFY/STORNO chains, including original mirror lookup, modification-index sequencing, `modifyWithoutMaster` handling and original Dolibarr invoice linkage.
- Add `queryInvoiceChainDigest` support and compare the complete paginated authoritative NAV chain with the local mirror before permitting a non-CREATE import.
- Add a relation-review page that exposes the local and authoritative chain state before import.
- Add targeted authoritative chain synchronization so missing older master/modification invoices can be downloaded into the local mirror without manually widening date-based synchronization windows.
- Treat optional fields omitted by `queryInvoiceChainDigest` as unknown rather than mismatches, while still blocking genuine values that conflict with the full locally mirrored InvoiceData.
- Add guarded MODIFY/STORNO draft import when the relation is deterministic: negative MODIFY and STORNO map to Dolibarr credit notes; positive MODIFY maps to a standard adjustment; all are linked to the original Dolibarr invoice.
- Keep zero-value/non-financial MODIFY operations blocked instead of inventing a financial document, and block `modifyWithoutMaster` until a safe Dolibarr policy exists.
- Require earlier modification indexes to be imported first and block source-partner mismatches, authoritative-chain mismatches and unsupported Dolibarr negative-line configurations.
- Apply the same non-CREATE operation policy to single-invoice and batch preflight/import paths.
- Revamp the module dashboard: move last-sync information out of every list row, add compact synchronization/status panels, and derive the suggested synchronization range from the most recent mirror refresh with the configured lookback overlap.
- Add direction filtering, an "items needing attention" view, configurable page size and pagination to the NAV invoice list while keeping list filters independent from synchronization direction.
- Revamp batch import with compact filters/status counters, direct relation-resolution links, and clear indication of prerequisites pulled in from outside the selected date range.
- Automatically include required older master invoices and prior modifications in batch preflight when a selected MODIFY/STORNO depends on them, even when they fall outside the requested date range.
- Allow chain members blocked only by not-yet-imported prerequisites to be selected together; batch execution processes the selected chain in dependency order and re-runs preflight before each import.
- Show Dolibarr import/link status and direct Import Preview / Relation Review actions on the individual NAV invoice detail page.
- Add deterministic product matching for invoice lines: inbound lines use supplier + supplier item reference; outbound lines use the Dolibarr product reference.
- Link exact product matches through Dolibarr's native `fk_product` field while preserving NAV invoice-line descriptions, quantities, prices and supplier references; unresolved or ambiguous lines remain free-text.
- Remove the experimental generic NAV line-metadata/GTIN display after real invoices showed that it added little value beyond the already available supplier item reference.
- Remove the redundant linked-invoice action button from NAV invoice details when the status row already provides the Dolibarr invoice link.
- Add Hungarian and English UI strings for taxpayer, partner, relation, product matching and non-CREATE import workflows.

## 0.7.2 - development

- Map NAV `invoiceDeliveryDate` to Dolibarr's native `date_pointoftax` field on customer and supplier invoice imports.
- Preserve a missing NAV payment due date as missing instead of inventing an invoice-date fallback.
- Add a supplier-invoice point-of-tax compatibility display for stock Dolibarr 23 and suppress it when the core already supports the field natively.

## 0.7.1 - development

- Block invoice import when the matched Dolibarr third party has no country assigned, because Dolibarr needs the country for correct VAT handling on invoice lines.
- Block import when the Dolibarr partner country conflicts with the country code reported by NAV, requiring manual review instead of guessing VAT treatment.
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
