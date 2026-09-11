# Dolibarr NAV Online Invoice

External Dolibarr module for synchronizing outbound invoices from the Hungarian NAV Online Invoice API v3.

> **Development status:** experimental / read-only NAV mirror. The current milestone downloads NAV invoice metadata and, optionally, complete invoice XML. It does **not** create or overwrite Dolibarr customer invoices yet.

## Target

- Dolibarr 23.x
- PHP 8.1+
- NAV Online Invoice API v3
- MariaDB / MySQL for the initial development milestone

## Current features

- NAV test and production environments
- NAV technical-user authentication
- Connection test with `queryTaxpayer`
- Outbound invoice discovery with `queryInvoiceDigest`
- Automatic pagination
- Automatic splitting of long history imports into at most 35-day NAV query windows
- Complete invoice retrieval with `queryInvoiceData`
- Base64 decoding and optional gzip decompression of invoice payloads
- Local mirror table with digest and full-data SHA-256 hashes
- Idempotent upsert by Dolibarr entity, invoice number and batch index
- Manual date-range synchronization
- Optional hourly Scheduled Job
- Hungarian and English UI strings

## Installation for development

Copy or mount the `navinvoice` directory into the Dolibarr custom modules directory:

```text
htdocs/custom/navinvoice
```

Then enable **NAV Online Invoice** in Dolibarr's module setup.

The module depends on the Dolibarr customer invoice module. For automatic synchronization, Dolibarr Scheduled Jobs must also be operational.

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

Start with the NAV test environment whenever possible. Use **Test NAV connection** before running invoice synchronization.

The password and signing key use Dolibarr constant names ending in `_PASSWORD` and `_KEY`; Dolibarr 23 therefore treats them as sensitive constants and encrypts their stored values.

## Synchronization model

The first milestone deliberately separates NAV acquisition from Dolibarr invoice creation:

```text
NAV Online Invoice
       |
       +-- queryInvoiceDigest (OUTBOUND)
       |       |
       |       +-- local digest mirror / change detection
       |
       +-- queryInvoiceData
               |
               +-- decoded complete invoice XML
```

The mirror table reserves `fk_facture` for the later safe linkage to Dolibarr `Facture` records.

## Next milestone

The next phase will map NAV invoice XML to Dolibarr business objects and classify each NAV record before any write is allowed:

- already exists in Dolibarr and matches
- exists but differs
- NAV-only invoice, eligible for import
- modification invoice
- cancellation/storno invoice
- ambiguous or unsupported invoice requiring manual review

Existing Dolibarr invoices should be linked and checked rather than silently overwritten.

## Development branch

Initial implementation is developed on:

```text
feature/nav-sync-foundation
```

The `main` branch is intentionally kept stable until the foundation has been tested against a real NAV technical user and a Dolibarr 23 instance.
