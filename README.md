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
- Current taxpayer master-data lookup with `queryTaxpayer`
- Outbound (`OUTBOUND`) and inbound (`INBOUND`) invoice discovery with `queryInvoiceDigest`
- Manual synchronization of inbound, outbound or both directions
- Scheduled synchronization of both directions
- Automatic pagination
- Automatic splitting of long history imports into at most 35-day NAV query windows
- Complete invoice retrieval with `queryInvoiceData`
- Authoritative modification/storno-chain lookup with `queryInvoiceChainDigest`
- Base64 decoding and optional gzip decompression of invoice payloads
- Unified local mirror table with invoice direction, digest and full-data SHA-256 hashes
- Idempotent upsert by Dolibarr entity, direction, invoice number and batch index
- Automatic migration of the initial outbound-only mirror schema
- Detailed parsed invoice view with parties, addresses, totals and invoice lines
- Conservative Dolibarr partner matching and safe partner-data enrichment
- Historical invoice-party vs current NAV taxpayer comparison
- Controlled creation of missing Dolibarr third parties from fresh NAV master data
- Guarded manual import into Dolibarr draft customer (`Facture`) and supplier (`FactureFournisseur`) invoices
- Controlled MODIFY/STORNO draft import when the NAV relation chain has a deterministic Dolibarr mapping
- Exact product matching for invoice lines using supplier + supplier item reference on inbound invoices and Dolibarr product reference on outbound invoices
- Native `fk_product` linkage on imported invoice lines when the product match is deterministic; unresolved lines remain free-text
- Batch preflight/import for inbound supplier invoices, including dedicated partner-resolution state
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
             +-----+-------------------+
             |                         |
          CREATE               MODIFY / STORNO
             |                         |
             |              queryInvoiceChainDigest
             |                         |
             +------------+------------+
                          |
                 manual draft import
                          |
              Facture / FactureFournisseur
```

The mirror table stores `fk_facture` for customer invoices and `fk_facture_fourn` for supplier invoices after a successful import/link.

The importer is intentionally conservative. It requires complete NAV XML, a strong partner match, supported currency/VAT data and duplicate checks before a Dolibarr draft is created. Simplified invoices are supported where their VAT-content values can be mapped deterministically.

For non-`CREATE` operations the module additionally requires the original NAV invoice to be known and linked to Dolibarr, earlier modification indexes to be imported first, and the local relation chain to match the authoritative NAV chain. Negative `MODIFY` and `STORNO` operations map to source-linked Dolibarr credit notes; positive `MODIFY` operations map to source-linked standard adjustment drafts. Zero-value/non-financial modifications and `modifyWithoutMaster` cases remain blocked until a representation can be made without inventing accounting events.

Existing Dolibarr invoices and partner data are never silently overwritten by the synchronization process. All invoice imports create new drafts only.

Product matching is conservative and non-blocking. A deterministic match is linked through the native Dolibarr `fk_product` field while the NAV description, quantity, unit price and supplier reference remain authoritative for the imported invoice line. Unresolved or ambiguous items stay as free-text lines until product master data is resolved.

## Development roadmap

The completed 0.8.0 development block covers current NAV taxpayer master data, controlled partner creation/resolution and guarded CREATE/MODIFY/STORNO import foundations.

The next development blocks are:

1. controlled assignment/creation of missing products and supplier-product references from unmatched NAV invoice lines;
2. supplier price maintenance from invoices and supplier-order matching/reconstruction workflows;
3. support for additional foreign-currency and special VAT cases;
4. explicit validation workflow for imported outbound drafts while preserving the original NAV invoice number;
5. bank-transaction reconciliation in a separate integration layer.

Before a stable release, the MODIFY/STORNO mapping still needs validation against real NAV invoice chains and a Dolibarr 23 test installation, especially multi-step modifications, mixed-sign adjustments and supplier credit notes.

For inbound invoices the external partner is the supplier; for outbound invoices it is the customer. Private-person customer identity is intentionally unavailable through NAV Online Invoice v3 and cannot be reconstructed from NAV data alone.

## Branch policy

`main` is the integration branch consumed by the Dolibarr subtree. Larger development blocks should be implemented on focused feature branches, reviewed/tested there, and merged into `main` before the Dolibarr repository pulls the subtree update.

Historical branches such as `feature/nav-sync-foundation`, `feature/taxpayer-partner-workflow` and `refactor/final-audit` are retained only as development/audit reference points. New development must branch from current `main`, and Dolibarr subtree integration must pull from `main` unless a temporary test of an explicit feature branch is intentionally requested.
