# Dolibarr NAV Online Invoice

External Dolibarr module for synchronizing inbound and outbound invoices from the Hungarian NAV Online Invoice API v3.

> **Development status:** experimental / read-only NAV mirror. The current milestone downloads NAV invoice metadata and, optionally, complete invoice XML. It does **not** create or overwrite Dolibarr customer or supplier invoices yet.

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

For an existing 0.1.x installation, no manual SQL migration is required. On first module-page access or synchronization the module adds the invoice-direction and supplier-invoice-link fields and treats existing rows as `OUTBOUND`.

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

## Synchronization model

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
```

The mirror table reserves:

- `fk_facture` for future linkage to Dolibarr customer invoices (`Facture`)
- `fk_facture_fourn` for future linkage to Dolibarr supplier invoices (`FactureFournisseur`)

Existing Dolibarr invoices should be linked and checked rather than silently overwritten.

## Next milestone

The next phase will map NAV invoice XML to Dolibarr business objects and classify each NAV record before any write is allowed:

- existing matching Dolibarr invoice
- existing but different invoice
- NAV-only invoice eligible for import
- modification invoice
- cancellation/storno invoice
- ambiguous or unsupported invoice requiring manual review

For inbound invoices the external partner is the supplier; for outbound invoices it is the customer. Private-person customer identity is intentionally unavailable through NAV Online Invoice v3 and cannot be reconstructed from NAV data alone.

## Development branch

Initial implementation is developed on:

```text
feature/nav-sync-foundation
```

The `main` branch is intentionally kept stable until the foundation has been tested against real NAV data and a Dolibarr 23 instance.
