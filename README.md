# Dolibarr NAV Online Invoice

External Dolibarr module for synchronizing inbound and outbound invoices from the Hungarian NAV Online Invoice API v3 and importing supported NAV invoices into Dolibarr as drafts.

> **Development status:** experimental. NAV acquisition is read-only, while supported NAV invoices can be imported manually into Dolibarr as draft customer or supplier invoices. Automatic validation, accounting and payment actions are intentionally not performed.

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
- Conservative Dolibarr partner matching and safe partner-data enrichment
- Guarded manual import into Dolibarr draft customer (`Facture`) and supplier (`FactureFournisseur`) invoices
- Batch preflight/import for inbound supplier invoices
- Duplicate detection and NAV mirror-to-Dolibarr linkage
- Hungarian and English UI strings

## Repository layout

This repository is the authoritative source of the NAV module. The repository root is the Dolibarr module root and is intended to live at:

```text
htdocs/custom/navinvoice/
├── admin/
├── class/
├── core/
├── langs/
├── sql/
├── batch.php
├── detail.php
├── import.php
└── index.php
```

## Integration into a Dolibarr repository

The recommended integration for a Git-managed Dolibarr checkout is **git subtree**. Development happens in this repository first; the Dolibarr repository consumes released or tested module commits under `htdocs/custom/navinvoice`.

Initial import:

```bash
cd /path/to/dolibarr
git subtree add \
  --prefix=htdocs/custom/navinvoice \
  https://github.com/vanyolai/dolibarr-nav-online-invoice.git \
  main \
  --squash
```

Update an existing subtree:

```bash
cd /path/to/dolibarr
git subtree pull \
  --prefix=htdocs/custom/navinvoice \
  https://github.com/vanyolai/dolibarr-nav-online-invoice.git \
  main \
  --squash
```

A named Git remote can be used instead of the repository URL, for example `navinvoice`. Changes should normally be committed to this module repository first and then pulled into Dolibarr. If an emergency change is made inside the Dolibarr subtree, `git subtree push` can be used to publish it back, but keeping this repository authoritative avoids divergent histories.

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

The importer is intentionally conservative. At the current development stage it requires complete NAV XML, a strong existing partner match, supported currency/VAT data and duplicate checks before a Dolibarr draft is created. Simplified invoices are supported where their VAT-content values can be mapped deterministically. Product matching is intentionally separate from the accounting-safe invoice import path.

Existing Dolibarr invoices and partner data are never silently overwritten by the synchronization process.

## Development roadmap

The next development blocks are:

1. promote `queryTaxpayer` from a connection test to an explicit current taxpayer master-data service;
2. compare historical invoice-party data with current NAV taxpayer master data;
3. controlled creation of missing Dolibarr third parties from fresh NAV taxpayer data;
4. batch-import states/actions for invoices whose partner must be created or resolved;
5. controlled import of non-`CREATE` NAV operations, including modification/storno chains and their relationship to the original invoice;
6. product and supplier-product matching for inbound invoice lines;
7. supplier price maintenance from invoices and supplier-order matching/reconstruction workflows;
8. support for additional foreign-currency and special VAT cases;
9. explicit validation workflow for imported outbound drafts while preserving the original NAV invoice number;
10. bank-transaction reconciliation in a separate integration layer.

For inbound invoices the external partner is the supplier; for outbound invoices it is the customer. Private-person customer identity is intentionally unavailable through NAV Online Invoice v3 and cannot be reconstructed from NAV data alone.

## Branch policy

`main` is the integration branch consumed by the Dolibarr subtree. Larger development blocks should be implemented on focused feature branches, reviewed/tested there, and merged into `main` before the Dolibarr repository pulls the subtree update.
