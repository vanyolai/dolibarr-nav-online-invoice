# Changelog

## 0.2.0 - development

- Add NAV `INBOUND` invoice discovery and full XML retrieval.
- Manual synchronization can target inbound, outbound or both directions.
- Scheduled synchronization refreshes both directions.
- Store invoice direction in the NAV mirror and include it in record uniqueness.
- Add supplier tax-number indexing and reserved `fk_facture_fourn` linkage for future Dolibarr supplier-invoice mapping.
- Existing mirror tables are migrated automatically on first module-page access or synchronization; existing records are classified as `OUTBOUND`.
- Unified list now shows the relevant external partner for each direction.

## 0.1.0 - development

- Initial NAV Online Invoice read-only synchronization foundation.
- Repository root is now the Dolibarr module root, suitable for direct installation or Git submodule use at `htdocs/custom/navinvoice`.
