# Development Guide — NAV Online Invoice

> Last reviewed: 2026-10-19

## Repository role

```text
canonical source: vanyolai/dolibarr-nav-online-invoice
source branch:    main
consumer prefix:  htdocs/custom/navinvoice
subtree mode:     squashed
```

Current integrated version: **0.9.0**.

## Safety boundary

NAV synchronization and Dolibarr accounting mutation are deliberately separated.

The module may acquire and mirror NAV data automatically, but supported invoices are imported **manually as drafts**. It must not silently validate, account or pay them.

Existing Dolibarr invoices and partner data must never be overwritten by synchronization.

## Current implementation state

Implemented:
- NAV test/production authentication;
- inbound/outbound digest synchronization;
- pagination and 35-day window splitting;
- full invoice XML retrieval/decompression;
- local idempotent mirror;
- taxpayer master-data lookup;
- historical invoice-party vs current taxpayer comparison;
- controlled third-party creation;
- guarded draft customer/supplier invoice import;
- deterministic product matching where possible;
- batch preflight/import;
- authoritative modification/storno chain lookup;
- guarded CREATE/MODIFY/STORNO mapping;
- HU/EN strings.

Unsupported/blocked cases remain blocked rather than guessed, including non-deterministic chain/accounting semantics.

## Architectural rules

- NAV mirror data and Dolibarr business objects are separate sources with explicit linking.
- Historical invoice-party data must not be treated as current taxpayer master data.
- Third-party creation re-queries current taxpayer data immediately before creation.
- Every existing/candidate/ambiguous partner match blocks duplicate creation.
- Non-CREATE operations require an authoritative, ordered chain and linked source invoice.
- Imported records are drafts.
- Preserve exact NAV source wording/data for diagnostics even if display normalization is applied.

## Roadmap

1. Controlled assignment/creation of missing products and supplier-product references.
2. Supplier price maintenance and supplier-order matching/reconstruction.
3. Additional foreign-currency and special-VAT cases.
4. Explicit validation workflow for imported outbound drafts while preserving the original NAV invoice number.
5. Bank-transaction reconciliation remains a separate integration layer (BankSync), not a second NAV-owned banking subsystem.

Before stable release:
- validate MODIFY/STORNO against real multi-step NAV chains;
- test mixed-sign adjustments and supplier credit notes;
- complete Dolibarr 23 integration acceptance.

## Branch policy

`main` is the subtree source. Large work belongs on focused feature branches and must be reviewed/tested before merge to `main`.

Historical feature branches are references, not automatic integration sources.

## Subtree update

```bash
git subtree pull \
  --prefix=htdocs/custom/navinvoice \
  https://github.com/vanyolai/dolibarr-nav-online-invoice.git \
  main \
  --squash \
  -m "Update NAV Online Invoice subtree"
```
