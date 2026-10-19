# Dolibarr 23 → NAV Online Számla 3.0 mezőtérkép

**M1 / outbound foundation – v0.1**

- Branch: `feature/outbound-foundation`
- Dolibarr target: 23.x
- NAV schema: Online Invoice 3.0, `invoiceData.xsd`
- Initial production scope: normal Hungarian HUF customer invoices
- Schema reference verified against the official `nav-gov-hu/Online-Invoice` repository on 2026-09-22.

This document defines the mapping contract used by the outbound builder and by the local compliance preflight. It deliberately separates:

1. **Dolibarr source data** – what already exists in `Facture`, `FactureLigne`, `Societe`, `$mysoc` and dictionaries;
2. **normalized outbound snapshot** – stable internal values independent of XML serialization;
3. **NAV InvoiceData 3.0** – the final XML representation.

The first implementation is conservative. Missing or ambiguous information is blocked rather than inferred when the inference could change tax meaning.

## 1. Root structure

| NAV field | Requirement | Normalized source | Dolibarr source / rule | M1 policy |
|---|---|---|---|---|
| `InvoiceData/invoiceNumber` | required after validation | `invoice.number` | final `Facture.ref` | not required by draft preflight; required by builder/post-validation |
| `invoiceIssueDate` | required | `invoice.issue_date` | `Facture.datef` | BLOCK if missing/invalid |
| `completenessIndicator` | required | constant | false for ordinary Dolibarr PDF invoices | builder constant `false` |
| `invoiceMain/invoice` | required for ordinary invoice | operation context | normal CREATE initially | M1 supports CREATE only |

The pre-validation check must not require the final invoice number because Dolibarr assigns the permanent reference during `Facture::validate()`.

## 2. Supplier / issuer

| NAV field | Requirement | Normalized source | Dolibarr source | M1 policy |
|---|---|---|---|---|
| `supplierTaxNumber/taxpayerId` | required | `supplier.tax_number` | company tax number from Dolibarr company configuration | BLOCK if no usable 8-digit core |
| `supplierTaxNumber/vatCode` | required | parsed full HU tax number | company tax number | BLOCK if final builder cannot derive |
| `supplierTaxNumber/countyCode` | required | parsed full HU tax number | company tax number | BLOCK if final builder cannot derive |
| `supplierName` | required | `supplier.name` | `$mysoc->name` | BLOCK if empty |
| `supplierAddress` | required | structured supplier address | `$mysoc` address/country fields | BLOCK if unusable |
| `communityVatNumber` | conditional | `supplier.community_vat_number` | company VAT data | optional in domestic MVP |
| `supplierBankAccountNumber` | optional | selected invoice bank account | `Facture.fk_account` / bank account | builder later |
| `individualExemption` | conditional | explicit company tax policy | module/config data | never infer from a 0% line |

The supplier identity is installation master data, not copied from free-text invoice notes.

## 3. Customer

NAV 3.0 requires an explicit `customerVatStatus` value: `DOMESTIC`, `OTHER` or `PRIVATE_PERSON`. Missing tax number alone is **not enough** to classify a customer as a private person.

| NAV field | Requirement | Normalized source | Dolibarr source | M1 policy |
|---|---|---|---|---|
| `customerVatStatus` | required when customerInfo is sent | `customer.vat_status` | deterministic resolver / explicit classification | BLOCK if unresolved |
| `customerVatData/customerTaxNumber` | DOMESTIC | `customer.tax_number` | `Societe.tva_intra` | BLOCK for DOMESTIC without usable tax number |
| `customerVatData/communityVatNumber` | conditional | community VAT no. | partner VAT data | later scope |
| `customerVatData/thirdStateTaxId` | conditional | third-state tax id | partner data | later scope |
| `customerName` | conditional | `customer.name` | `Societe.name` | required by our DOMESTIC/OTHER MVP |
| `customerAddress` | conditional | structured address | `Societe.address/zip/town/country` | required by our DOMESTIC/OTHER MVP |

For `PRIVATE_PERSON`, identity data must be treated separately from ordinary business-customer reporting. The builder must not simply reuse the DOMESTIC branch.

## 4. Invoice detail

| NAV field | Requirement | Normalized source | Dolibarr source / rule | M1 policy |
|---|---|---|---|---|
| `invoiceCategory` | required | `invoice.category` | `NORMAL` initially | constant `NORMAL` |
| `invoiceDeliveryDate` | required | `invoice.delivery_date` | `Facture.date_pointoftax`, otherwise issue date for non-periodic invoice | periodic invoice requires explicit calculated value |
| `invoiceDeliveryPeriodStart` | conditional | `invoice.period_start` | HU recurring metadata/extrafield | required when periodical |
| `invoiceDeliveryPeriodEnd` | conditional | `invoice.period_end` | HU recurring metadata/extrafield | required when periodical |
| `invoiceAccountingDeliveryDate` | optional | accounting date | future explicit rule | not emitted in first builder unless configured |
| `periodicalSettlement` | optional | `invoice.periodical_settlement` | explicit HU metadata | never infer only from line dates |
| `currencyCode` | required | `invoice.currency` | `Facture.multicurrency_code` or base currency | MVP BLOCK unless HUF |
| `exchangeRate` | required | `invoice.exchange_rate` | HUF = 1 | M1 HUF constant 1 |
| `paymentMethod` | optional | `invoice.payment_method` | resolved from `fk_mode_reglement` | WARN if unresolved in M1 |
| `paymentDate` | optional | `invoice.due_date` | `Facture.date_lim_reglement` | WARN if absent |
| `cashAccountingIndicator` | conditional | `invoice.cash_accounting` | explicit company/invoice policy | never infer from payment date |
| `invoiceAppearance` | required | `invoice.appearance` | electronic PDF workflow | initially `ELECTRONIC` once issued-artifact policy is active |

### Periodical settlement

The normalized snapshot must contain all of:

- `periodical_settlement = true`
- `period_start`
- `period_end`
- explicitly calculated `delivery_date`

The date calculation itself belongs in `HuPeriodicalSettlementCalculator`; the preflight only checks that the result is complete and internally consistent.

## 5. Invoice lines

Dolibarr 23 invoice lines expose the required financial primitives directly:

- `description/desc`
- `qty`
- `subprice`
- `remise_percent`
- `tva_tx`
- `vat_src_code`
- `total_ht`
- `total_tva`
- `total_ttc`
- `fk_product`, `product_type`
- `fk_unit`
- `date_start`, `date_end`

| NAV field | Normalized source | Dolibarr source / rule | M1 policy |
|---|---|---|---|
| `lineNumber` | sequential 1..N | line order / `rang` | generated deterministically |
| `lineExpressionIndicator` | `line.expression` | true for normal quantity lines | M1 true |
| `lineNatureIndicator` | `line.nature` | `product_type`: 0 PRODUCT, 1 SERVICE | deterministic |
| `lineDescription` | `line.description` | line label/description | BLOCK if blank |
| `quantity` | `line.quantity` | `qty` | BLOCK if non-numeric/zero for expression line |
| `unitOfMeasure` | normalized NAV unit | `fk_unit` + Dolibarr unit dictionary | standard mapping where deterministic |
| `unitOfMeasureOwn` | literal unit | Dolibarr unit label/symbol | used when NAV unit = OWN |
| `unitPrice` | `line.unit_price` | `subprice` after Dolibarr line semantics | numeric required |
| `lineDiscountData` | explicit discount | `remise_percent` | builder later; must reconcile with totals |
| `lineNetAmount` | `line.net` | `total_ht` | required |
| `lineNetAmountHUF` | same in HUF | `total_ht` | identical in HUF MVP |
| `lineVatRate` | normalized VAT discriminator | see VAT section | required |
| `lineVatAmount` | `line.vat` | `total_tva` | required for normal taxable lines |
| `lineVatAmountHUF` | same in HUF | `total_tva` | identical in HUF MVP |
| `lineGrossAmountNormal` | `line.gross` | `total_ttc` | emitted for deterministic reconciliation |

## 6. VAT mapping

### 6.1 Positive Hungarian VAT rates

For the initial domestic scope:

| Dolibarr `tva_tx` | NAV representation |
|---:|---|
| 27 | `vatPercentage = 0.27` |
| 18 | `vatPercentage = 0.18` |
| 5 | `vatPercentage = 0.05` |

These are deterministic and may be generated directly after numeric reconciliation.

### 6.2 Zero / special VAT

A Dolibarr numeric rate of `0` is ambiguous. NAV `VatRateType` can represent, among other cases:

- `vatPercentage`
- `vatExemption`
- `vatOutOfScope`
- `vatDomesticReverseCharge`
- `marginSchemeIndicator`
- `vatAmountMismatch`
- `noVatCharge`

Therefore:

> **M1 rule:** `tva_tx = 0` without an explicit outbound VAT classification is a BLOCK condition.

The builder must never guess tax meaning from the numeric rate alone. A later milestone will define the exact Dolibarr UI/extrafield/dictionary used to select exemption/reason codes.

## 7. Summary

For NORMAL invoices NAV requires `summaryNormal` with VAT-rate groups plus invoice net/VAT totals; gross totals may also be sent.

| NAV field | Dolibarr source |
|---|---|
| `summaryByVatRate/*` | deterministic aggregation of normalized lines by NAV VAT discriminator |
| `invoiceNetAmount` | `Facture.total_ht` |
| `invoiceNetAmountHUF` | same for HUF |
| `invoiceVatAmount` | `Facture.total_tva` |
| `invoiceVatAmountHUF` | same for HUF |
| `summaryGrossData/invoiceGrossAmount` | `Facture.total_ttc` |
| `summaryGrossData/invoiceGrossAmountHUF` | same for HUF |

The builder must aggregate from the same normalized line snapshot that passed preflight and compare the result against the invoice header totals.

## 8. Modification / cancellation

NAV `InvoiceReferenceType` contains:

- `originalInvoiceNumber`
- `modifyWithoutMaster`
- `modificationIndex`

The existing inbound relation-chain implementation is the reference for outbound chain integrity. M1 does **not** enable MODIFY/STORNO sending yet; it only reserves these normalized fields.

## 9. Normalized snapshot contract v0.1

The first `NavInvoiceCompliancePreflight` consumes a pure array so it is testable without booting Dolibarr:

```php
array(
    'invoice' => array(
        'issue_date' => '2026-09-22',
        'delivery_date' => '2026-09-22',
        'due_date' => '2026-09-30',
        'currency' => 'HUF',
        'payment_method' => 'TRANSFER',
        'periodical_settlement' => false,
        'period_start' => '',
        'period_end' => '',
        'cash_accounting' => false,
        'total_net' => 10000,
        'total_vat' => 2700,
        'total_gross' => 12700,
    ),
    'supplier' => array(
        'tax_number' => '12345678-2-19',
        'name' => 'Example Kft.',
        'address' => array(
            'country_code' => 'HU',
            'postal_code' => '8230',
            'city' => 'Balatonfüred',
            'line' => 'Példa utca 1.'
        ),
    ),
    'customer' => array(
        'vat_status' => 'DOMESTIC',
        'tax_number' => '87654321-2-42',
        'name' => 'Customer Kft.',
        'address' => array(...),
    ),
    'lines' => array(
        array(
            'description' => 'Monthly service',
            'quantity' => 1,
            'unit' => 'PIECE',
            'unit_price' => 10000,
            'net' => 10000,
            'vat_rate' => 27,
            'vat_classification' => 'PERCENTAGE',
            'vat' => 2700,
            'gross' => 12700,
            'nature' => 'SERVICE',
        ),
    ),
);
```

A later Dolibarr adapter will be responsible for building this snapshot from `Facture`, `FactureLigne`, the customer `Societe`, `$mysoc`, payment/unit dictionaries and HU-specific extrafields.

## 10. Preflight BLOCK/WARN policy v0.1

### BLOCK

- invalid/missing invoice issue date;
- non-HUF currency in the first MVP;
- missing supplier tax number/name/address;
- unresolved customer VAT status;
- DOMESTIC customer without tax number;
- missing business-customer identity/address;
- no invoice lines;
- line description/quantity/financial amounts missing;
- unsupported positive VAT rate in MVP;
- zero VAT without explicit tax classification;
- periodical invoice without period start/end or explicit delivery date;
- period end before period start;
- invoice totals not reconciling with line totals.

### WARN

- non-periodic invoice uses issue date as delivery-date fallback;
- payment method unavailable;
- payment due date unavailable;
- optional NAV fields not yet mapped in M1.

Warnings do not authorize an unsupported tax case; every ambiguous tax meaning remains BLOCK.

## 11. Implementation boundary

M1 creates only:

1. this mapping contract;
2. the pure `NavInvoiceCompliancePreflight`;
3. regression tests.

It does not yet:

- allocate invoice numbers;
- call NAV;
- write an outbox record;
- serialize final InvoiceData XML;
- submit CREATE/MODIFY/STORNO.

Those are subsequent outbound milestones.

## References

- Official NAV Online Invoice repository: `nav-gov-hu/Online-Invoice`
- `src/schemas/nav/gov/hu/OSA/invoiceData.xsd`
- 23/2014. (VI. 30.) NGM rendelet, especially invoicing-software and online-reporting requirements
- 2007. évi CXXVII. törvény (Áfa tv.), including invoice content and periodical-settlement rules
- Dolibarr 23: `Facture`, `FactureLigne`, `Societe`
