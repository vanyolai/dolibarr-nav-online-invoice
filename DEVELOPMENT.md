# Development Guide — NAV Online Invoice

> Last reviewed: 2026-10-19

## Repository role

```text
canonical source: vanyolai/dolibarr-nav-online-invoice
source branch:    main
consumer prefix:  htdocs/custom/navinvoice
subtree mode:     squashed
```

Current integrated Dolibarr snapshot version: **0.9.0**.

## Safety boundary

NAV synchronization and Dolibarr accounting mutation are deliberately separated.

The module may acquire and mirror NAV data automatically, but supported invoices are imported **manually and created as drafts first**. If an administrator explicitly enables `NAVINVOICE_AUTO_VALIDATE_INBOUND`, a manually imported READY supplier invoice may subsequently be validated through Dolibarr's native API after permission and stock-safety checks. Synchronization itself must never trigger validation. Customer invoice validation, accounting and payment are not automated.

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
- outbound NAV compliance preflight foundation;
- Facture -> NAV InvoiceData mapping baseline;
- separate production and sandbox NAV connection profiles;
- live NAV reads pinned explicitly to the production profile;
- shared NAV amount/currency policy;
- supplier-import reconciliation through Dolibarr line APIs;
- authoritative NAV line/header consistency validation;
- HUF fractional and cumulative rounding hardening;
- outbound-preflight and importer-reconciliation regression coverage;
- NAV API protocol-status rejection and network-free regression coverage;
- HU/EN strings.

Unsupported/blocked cases remain blocked rather than guessed, including non-deterministic chain/accounting semantics.

## Branch consolidation — completed 2026-10-19

Two valuable development lines had evolved outside `main`:

1. `feature/outbound-foundation`
   - outbound NAV compliance preflight;
   - InvoiceData mapping documentation;
   - separate production/sandbox NAV connection profiles;
   - outbound regression coverage.

2. `refactor/final-audit`
   - shared amount/currency policy;
   - supplier-import reconciliation;
   - NAV line/header consistency validation;
   - HUF rounding fixes;
   - importer reconciliation regression coverage.

The second branch was started later from an older local base (`d31535d1bedf`) instead of the then-current `main` / outbound line. The split was therefore **branch-base drift**, not an architectural requirement.

The two lines were complementary. Their production code paths did not overlap; the only direct file conflict was `.github/workflows/php-lint.yml`, where each branch added a different regression suite.

They were consolidated through PR #4 using `integration/nav-consolidation-20261019`, preserving both histories in the merge ancestry. The combined workflow runs both:
- `tests/outbound_preflight_regression.php`;
- `tests/importer_reconciliation_regression.php`.

Both PHP 8.1 and PHP 8.3 CI matrices passed on the consolidated tree before merge.

The source branches `feature/outbound-foundation`, `refactor/final-audit` and the temporary integration branch are now historical ancestors of `main` and may be deleted.

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

### Immediate validation after consolidation
- validate the merged NAV profile configuration against the real test/sandbox environment;
- validate importer amount/rounding behavior against representative real invoices;
- validate MODIFY/STORNO against real multi-step NAV chains;
- test mixed-sign adjustments and supplier credit notes;
- only after acceptance pull the updated `main` subtree into the Dolibarr consumer.

### Next development blocks
1. Controlled assignment/creation of missing products and supplier-product references.
2. Supplier price maintenance and supplier-order matching/reconstruction.
3. Additional foreign-currency and special-VAT cases.
4. Explicit validation workflow for imported outbound drafts while preserving the original NAV invoice number.
5. Bank-transaction reconciliation remains a separate integration layer (BankSync), not a second NAV-owned banking subsystem.

Before stable release:
- keep unsupported accounting semantics blocked rather than guessed;
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
