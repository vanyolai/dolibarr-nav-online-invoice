# NAV Online Invoice 0.9.0 — Dolibarr 23 integration acceptance

**Status:** PENDING manual integration acceptance. Passing GitHub Actions does not certify the live NAV environment, Dolibarr database behavior or accounting correctness.

**Baseline:** consolidated `main` commit `695666564d23fec2192ea95342a6fdaae000a4fe` (2026-10-19). Apply later fixes through reviewed PRs before recording the candidate commit for acceptance.

## Scope and safeguards

- Target a **non-production Dolibarr 23** installation with representative, sanitized or specifically authorized NAV data. Back up the database, document root and module configuration before testing any import or activation.
- Run tests with an appropriately permissioned user; use distinct NAV test and production technical users. **Never send an invoice** during this validation; the 0.9.0 API client implements read-only queries, not `tokenExchange → manageInvoice → queryTransactionStatus`.
- Production NAV acquisition must use `NavInvoiceApi::production()`, regardless of which profile was most recently tested in the setup page.
- Never paste credentials, signing keys, exchange keys or unredacted invoice/customer XML into issues or test reports.
- Record the Dolibarr 23 commit, module commit, PHP version, database version and active module flags with the results below.

## 1. Automated quality gate

- [ ] `.github/workflows/php-lint.yml` passes for PHP 8.1 and PHP 8.3 on the **candidate commit**.
- [ ] Parser, purchase-policy, sync, NAV API response, HUF import, outbound preflight and importer-reconciliation regressions all pass.
- [ ] An HTTP 200 NAV response with `result/funcCode=ERROR`, an unexpected code or a missing code fails with diagnostic request ID (regression fixture only; no real NAV call necessary).
- [ ] There is no unexpected change to the module's Dolibarr 23 minimum version, MariaDB table keys, scheduled job configuration or PHP extensions.

## 2. Module upgrade and schema

- [ ] Record and back up the existing `llx_navinvoice_invoice` and `llx_navinvoice_purchase_link` data, including existing Dolibarr invoice links.
- [ ] Initialize/reinitialize the module using Dolibarr module administration in a test installation. Legacy schema migration is **activation-only** (`modNavInvoice::init`), not a side effect of opening a page or running synchronization.
- [ ] Verify the mirror table, unique index (`entity, direction, invoice number, batch index`) and both link fields (`fk_facture`, `fk_facture_fourn`); verify legacy rows and hashes remain accessible.
- [ ] Confirm that a second initialization does not duplicate rows or destroy links.

## 3. NAV connection-profile isolation

- [ ] Configure the **production** technical user and validate a read-only `queryTaxpayer` request using the production test button.
- [ ] Configure the separate **test/sandbox** profile, including its exchange key, and validate `queryTaxpayer` using the sandbox test button.
- [ ] Confirm that changing the test profile cannot alter production credentials, endpoint or scheduled acquisition.
- [ ] Test a deliberately invalid credential **only in the isolated sandbox profile**, verify a visible NAV error and confirm no successful synchronization is recorded.
- [ ] Confirm that the "Save and test" action proves only the **read-only connection**; it does **not** exercise the exchange key or simulate outbound reporting.

## 4. Read-only acquisition and idempotency

- [ ] Synchronize inbound, outbound and both directions over a limited date range in the test Dolibarr installation, using authorized production NAV reads.
- [ ] Check paging, greater-than-35-day window splitting, digest/full-XML consistency, SHA-256 content hashes and a retry after an interrupted XML fetch.
- [ ] Repeat the same sync period: no additional mirror rows and no changed/overwritten Dolibarr business objects.
- [ ] Confirm the scheduled job (when enabled) queries only NAV production and does not create or validate Dolibarr invoices.

## 5. Import and amount reconciliation

- [ ] With `NAVINVOICE_AUTO_VALIDATE_INBOUND=0`, import a supported normal HUF supplier invoice and customer invoice **manually**. Both must remain drafts, with correct mirror links and native line data.
- [ ] Recheck representative HUF invoices exhibiting fractional, cumulative or line-level rounding. Compare NAV XML net/VAT/gross values with Dolibarr invoice totals at the configured document precision; a genuine whole-forint discrepancy must remain blocked.
- [ ] Verify `invoiceDeliveryDate` / point-of-tax, optional due date, unit resolution, exact product matching, supplier item reference and free-text fallback.
- [ ] Verify duplicate import prevention, deletion-link cleanup and reimport of a deliberately removed test draft.
- [ ] Only on the test Dolibarr instance, if the opt-in feature is needed: enable `NAVINVOICE_AUTO_VALIDATE_INBOUND` and verify that **only** manually imported inbound READY supplier invoices can validate, subject to permissions and stock/warehouse safeguards. A non-READY invoice and every outbound customer invoice must remain unvalidated.
- [ ] Switch the option back to the intended production value after testing.

## 6. MODIFY / STORNO and purchasing

- [ ] Fetch an authoritative multi-step chain with `queryInvoiceChainDigest`; verify earlier linked invoices are required and processed in order.
- [ ] Test a supported negative MODIFY, positive MODIFY and STORNO with source-invoice linkage. Check native supplier credit-note signs and header/line totals against NAV.
- [ ] Verify mixed-sign or non-financial modifications without a deterministic accounting representation remain **blocked**; do not bypass blockers to make a test pass.
- [ ] Exercise a missing-partner resolution path and a deterministic product/supplier-reference match; ensure the original NAV wording remains available for audit.
- [ ] If the optional purchase workbench is enabled, verify only explicitly requested supplier-price/product/order changes occur; NAV sync must not change purchasing master data.

## 7. Outbound foundation (no NAV submission)

- [ ] Run normalized HUF customer-invoice preflight fixtures. Supported positive VAT rates (5%, 18%, 27%) pass; ambiguous zero VAT, unsupported currency, missing business-customer VAT identity and incomplete periodical-settlement metadata block.
- [ ] Confirm that neither preflight nor the current Dolibarr trigger creates a NAV outbound transaction or attempts to call `manageInvoice`.
- [ ] Record separately that real `tokenExchange`, outbound XML generation/XSD validation, `manageInvoice`, status polling and transactional outbox are **future milestones**, not features accepted under 0.9.0.

## Evidence and release decision

| Field | Value |
|---|---|
| Test date / tester | PENDING |
| Dolibarr 23 commit | PENDING |
| NAV module candidate commit | PENDING |
| PHP / DB versions | PENDING |
| Production/test profile isolation | PENDING |
| NAV sync + idempotency | PENDING |
| Real HUF import reconciliation | PENDING |
| MODIFY/STORNO chain cases | PENDING |
| Optional inbound validation workflow | PENDING / NOT APPLICABLE |
| CI pipeline URL | PENDING |
| Go / No-go / blockers | **NO-GO until mandatory manual checks pass** |

Only after documenting these results and resolving blockers should the reviewed module `main` be pulled into the Dolibarr `htdocs/custom/navinvoice` git subtree. The import/mirror checks are not a certification of the future outbound Hungarian invoicing workflow.
