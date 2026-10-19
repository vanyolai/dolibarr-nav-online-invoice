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

Implemented on `main`:
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

### Consolidation candidate — 2026-10-19

Two valuable development lines were found outside `main`:

1. `feature/outbound-foundation`
   - outbound NAV compliance preflight foundation;
   - Facture -> NAV InvoiceData mapping documentation;
   - separate production and sandbox NAV connection profiles;
   - live reads explicitly pinned to the production profile;
   - outbound regression coverage.

2. `refactor/final-audit`
   - shared NAV amount/currency policy;
   - supplier-import reconciliation through Dolibarr line APIs;
   - authoritative NAV line/header consistency validation;
   - HUF fractional/cumulative rounding fixes;
   - importer reconciliation regression coverage;
   - use of Dolibarr string helpers in matching/import code.

They became separate because `refactor/final-audit` was started later from an older local base (`d31535d1bedf`) instead of the then-current `main` / outbound line. This was branch-base drift, not an architectural requirement.

They are functionally complementary. Their production code paths do not overlap; the only direct file conflict was the GitHub Actions workflow, where each branch added a different regression suite.

The consolidation branch:

```text
integration/nav-consolidation-20261019
```

contains a three-parent merge of current `main`, `feature/outbound-foundation` and `refactor/final-audit`. The CI workflow deliberately runs **both** new regression suites.

Do not delete the two source branches until the consolidation PR passes CI, is reviewed/accepted and merged to `main`. After that merge both source branches become historical ancestors and should be deleted.

## Architectural rules

- NAV mirror data and Dolibarr business objects are separate sources with explicit linking.
- Historical invoice-party data must not be treated as current taxpayer master data.
- Third-party creation re-queries current taxpayer data immediately before creation.
- Every existing/candidate/ambiguous partner match blocks duplicate creation.
- Non-CREATE operations require an authoritative, ordered chain and linked source invoice.
- Imported records are drafts.
- Preserve exact NAV source wording/data for diagnostics even if display normalization is applied.
- Test/sandbox NAV profiles must never silently become the source for live acquisition.
- Amount reconciliation must follow explicit currency/precision policy and native Dolibarr line APIs rather than ad-hoc rounding.

## Roadmap

### Immediate gate — consolidate existing mature work
- run PHP 8.1/8.3 lint on the combined tree;
- run parser, purchase-policy, sync, HUF-import, outbound-preflight and importer-reconciliation regressions together;
- review the combined NAV profile configuration and importer amount policy;
- merge the consolidation PR only if those checks remain green;
- then delete the two superseded development branches.

### Next development blocks
1. Controlled assignment/creation of missing products and supplier-product references.
2. Supplier price maintenance and supplier-order matching/reconstruction.
3. Additional foreign-currency and special-VAT cases.
4. Explicit validation workflow for imported outbound drafts while preserving the original NAV invoice number.
5. Bank-transaction reconciliation remains a separate integration layer (BankSync), not a second NAV-owned banking subsystem.

Before stable release:
- validate MODIFY/STORNO against real multi-step NAV chains;
- test mixed-sign adjustments and supplier credit notes;
- test outbound compliance against a representative NAV test/sandbox workflow before any submission capability is considered;
- complete Dolibarr 23 integration acceptance.

## Branch policy

`main` is the subtree source. Large work belongs on focused feature branches and must be reviewed/tested before merge to `main`.

Always branch from freshly fetched current `main`:

```bash
git fetch origin --prune
git switch main
git pull --ff-only
git switch -c feature/<topic>
```

Do not start new work from an old local branch merely because it contains related code. That stale-base pattern is what produced the parallel outbound/final-audit histories.

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
