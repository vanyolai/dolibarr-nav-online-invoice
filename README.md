# Dolibarr NAV Online Invoice

External Dolibarr module for synchronizing inbound and outbound invoices from the Hungarian NAV Online Invoice API v3 and importing supported NAV invoices into Dolibarr as drafts.

> **Development status:** experimental. NAV acquisition is read-only, while supported NAV invoices can now be imported manually into Dolibarr as draft customer or supplier invoices. Automatic validation, accounting and payment actions are intentionally not performed.

## Target

- Dolibarr 23.x
- PHP 8.1+
- NAV Online Invoice API v3
- MariaDB / MySQL for the initial development milestone

## Current features

- NAV test and production environments
- NAV technical-user authentication
- Connection test with `queryTaxpayer`
- Outbound (`OUTBOUND`) and inbound (`INBOUND`) invoice discovery with `queryInvoiceDigest`
- Manual synchronization of inbound, outbound or both directions
- Scheduled synchronization of both directions
- Automatic pagination
- Automatic splitting of long history imports into at most 35-day NAV query windows
- Complete invoice retrieval with `queryInvoiceData`
- Base64 decoding and optional gzip decompression of invoice payloads
- Unified local mirror table with invoice direction, digest and full-data SHA-256 hashes
- Idempotent upsert by Dolibarr entity, direction, invoice number and batch index
- Automatic migration of the initial outbound-only mirror schema
- Detailed parsed invoice view with parties, addresses, totals and invoice lines
- Read-only Dolibarr partner matching and partner-enrichment preview
- Guarded manual import into Dolibarr draft customer (`Facture`) and supplier (`FactureFournisseur`) invoices
- Duplicate detection and NAV mirror-to-Dolibarr linkage
- Hungarian and English UI strings

## Repository layout

The repository root is the Dolibarr module root. It is intended to be installed directly as:

```text
htdocs/custom/navinvoice/
├── admin/
├── class/
├── core/
├── langs/
├── sql/
├── detail.php
├── import.php
└── index.php
```

## Installation

For a Git-managed Dolibarr checkout, using this repository as a submodule is recommended:

```bash
cd /path/to/dolibarr
git submodule add -b feature/nav-sync-foundation \
  https://github.com/vanyolai/dolibarr-nav-online-invoice.git \
  htdocs/custom/navinvoice
```

While the module is still under development, the submodule tracks `feature/nav-sync-foundation`. After the first stable release, production should track `main` or a release tag instead.

A direct clone into `htdocs/custom/navinvoice` also works, but a submodule lets the parent Dolibarr repository pin the exact module commit used in production.

Then enable **NAV Online Invoice** in Dolibarr's module setup.

For an existing installation, no manual SQL migration is required. On first module-page access or synchronization the module ensures the current mirror schema is present.

For automatic synchronization, Dolibarr Scheduled Jobs must also be operational.

## NAV configuration

Open the module setup page and configure:

- environment (`test` or `production`)
- technical-user login
- technical-user password
- taxpayer number (first 8 digits)
- technical-user signing key
- scheduled sync switch
- synchronization lookback window
- whether complete invoice XML should be downloaded

Use **Save and test NAV connection** before running invoice synchronization.

The password and signing key use Dolibarr constant names ending in `_PASSWORD` and `_KEY`; Dolibarr 23 therefore treats them as sensitive constants and encrypts their stored values. The module decrypts them only when preparing authenticated NAV API requests.

## Synchronization and import model

NAV acquisition is deliberately separated from Dolibarr invoice creation:

```text
                         NAV Online Invoice
                                |
                 +--------------+--------------+
                 |                             |
          OUTBOUND digest                INBOUND digest
                 |                             |
                 +--------------+--------------+
                                |
                    local NAV invoice mirror
                                |
                   +------------+------------+
                   |                         |
            queryInvoiceData          change detection
                   |
             complete invoice XML
                   |
              parser + checks
                   |
          partner matching / preview
                   |
             manual draft import
                   |
       Facture / FactureFournisseur
```

The mirror table stores `fk_facture` for customer invoices and `fk_facture_fourn` for supplier invoices after a successful import/link.

The first live importer is intentionally conservative. It currently accepts only invoices that satisfy all of the following:

- NAV operation is `CREATE`
- invoice category is `NORMAL`
- complete NAV XML is available
- an existing Dolibarr partner is matched strongly by tax number or name+address
- invoice currency equals the Dolibarr base currency
- invoice lines contain supported percentage/zero VAT data
- NAV line totals reconcile with header totals
- no duplicate Dolibarr invoice is found

Imported lines are currently created as free-text invoice lines (`fk_product = 0`). Product/supplier-product matching is a planned later milestone.

Existing Dolibarr invoices and partner data are never silently overwritten by the synchronization process.

## Planned direction

Next milestones include:

- controlled partner creation and NAV-based partner-data completion
- current taxpayer master-data lookup with `queryTaxpayer`
- product and supplier-product matching for inbound invoice lines
- supplier price maintenance from invoices
- supplier-order matching / reconstruction workflows
- support for foreign-currency and special VAT cases
- controlled handling of modification and storno invoices
- explicit validation workflow for imported outbound drafts while preserving the original NAV invoice number
- bank-transaction reconciliation in a separate integration layer

For inbound invoices the external partner is the supplier; for outbound invoices it is the customer. Private-person customer identity is intentionally unavailable through NAV Online Invoice v3 and cannot be reconstructed from NAV data alone.

## Development branch

Current implementation is developed on:

```text
feature/nav-sync-foundation
```

The `main` branch is intentionally kept stable until the foundation and draft-import workflow have been tested against real NAV data and a Dolibarr 23 instance.
