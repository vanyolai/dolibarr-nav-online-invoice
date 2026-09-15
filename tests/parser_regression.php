<?php

require_once __DIR__.'/../class/navinvoiceparser.class.php';

function fail_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

function expect_same($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        fail_test($message.' expected='.var_export($expected, true).' actual='.var_export($actual, true));
    }
}

$xml = <<<'XML'
<?xml version="1.0"?>
<InvoiceData xmlns="http://schemas.nav.gov.hu/OSA/3.0/data" xmlns:ns2="http://schemas.nav.gov.hu/OSA/3.0/base">
  <invoiceNumber>TEST-1</invoiceNumber>
  <invoiceIssueDate>2026-09-15</invoiceIssueDate>
  <invoiceMain><invoice>
    <invoiceHead>
      <supplierInfo>
        <supplierTaxNumber><ns2:taxpayerId>10508671</ns2:taxpayerId><ns2:vatCode>2</ns2:vatCode><ns2:countyCode>44</ns2:countyCode></supplierTaxNumber>
        <communityVatNumber>HU10508671</communityVatNumber>
        <supplierName>Supplier</supplierName>
      </supplierInfo>
      <customerInfo>
        <customerVatStatus>DOMESTIC</customerVatStatus>
        <customerVatData><customerTaxNumber><ns2:taxpayerId>25571350</ns2:taxpayerId><ns2:vatCode>2</ns2:vatCode><ns2:countyCode>19</ns2:countyCode></customerTaxNumber></customerVatData>
        <customerName>Customer</customerName>
      </customerInfo>
      <invoiceDetail>
        <invoiceCategory>AGGREGATE</invoiceCategory>
        <invoiceDeliveryDate>2026-09-14</invoiceDeliveryDate>
        <invoiceAccountingDeliveryDate>2026-09-14</invoiceAccountingDeliveryDate>
        <currencyCode>HUF</currencyCode><exchangeRate>1</exchangeRate>
        <paymentMethod>TRANSFER</paymentMethod><paymentDate>2026-10-15</paymentDate>
      </invoiceDetail>
    </invoiceHead>
    <invoiceLines>
      <line>
        <lineNumber>1</lineNumber>
        <advanceData><advanceIndicator>true</advanceIndicator></advanceData>
        <lineExpressionIndicator>true</lineExpressionIndicator>
        <lineDescription>Discounted aggregate line</lineDescription>
        <quantity>50</quantity><unitOfMeasure>OWN</unitOfMeasure><unitOfMeasureOwn>LINEAR_METER </unitOfMeasureOwn><unitPrice>91</unitPrice>
        <lineDiscountData><discountDescription>Kedvezmény</discountDescription><discountValue>1365</discountValue><discountRate>0.3</discountRate></lineDiscountData>
        <lineAmountsNormal>
          <lineNetAmountData><lineNetAmount>3185</lineNetAmount><lineNetAmountHUF>3185</lineNetAmountHUF></lineNetAmountData>
          <lineVatRate><vatPercentage>0.27</vatPercentage></lineVatRate>
          <lineVatData><lineVatAmount>859.95</lineVatAmount><lineVatAmountHUF>859.95</lineVatAmountHUF></lineVatData>
          <lineGrossAmountData><lineGrossAmountNormal>4044.95</lineGrossAmountNormal><lineGrossAmountNormalHUF>4044.95</lineGrossAmountNormalHUF></lineGrossAmountData>
        </lineAmountsNormal>
        <aggregateInvoiceLineData><lineExchangeRate>1</lineExchangeRate><lineDeliveryDate>2026-09-14</lineDeliveryDate></aggregateInvoiceLineData>
        <additionalLineData><dataName>ID</dataName><dataDescription>internal</dataDescription><dataValue>123</dataValue></additionalLineData>
      </line>
    </invoiceLines>
    <invoiceSummary><summaryNormal><invoiceNetAmount>3185</invoiceNetAmount><invoiceNetAmountHUF>3185</invoiceNetAmountHUF><invoiceVatAmount>859.95</invoiceVatAmount><invoiceVatAmountHUF>859.95</invoiceVatAmountHUF></summaryNormal><summaryGrossData><invoiceGrossAmount>4044.95</invoiceGrossAmount><invoiceGrossAmountHUF>4044.95</invoiceGrossAmountHUF></summaryGrossData></invoiceSummary>
  </invoice></invoiceMain>
</InvoiceData>
XML;

$parsed = (new NavInvoiceParser())->parse($xml);
expect_same('25571350', $parsed['customer']['tax_number'], 'nested customer taxpayer id');
expect_same('2', $parsed['customer']['vat_code'], 'nested customer VAT code');
expect_same('19', $parsed['customer']['county_code'], 'nested customer county code');
expect_same('HU10508671', $parsed['supplier']['community_vat_number'], 'supplier community VAT');
expect_same('2026-09-14', $parsed['detail']['accounting_delivery_date'], 'accounting delivery date');
expect_same(true, $parsed['lines'][0]['advance'], 'nested advance indicator');
expect_same('1365', $parsed['lines'][0]['discount']['value'], 'discount value');
expect_same('0.3', $parsed['lines'][0]['discount']['rate'], 'discount rate');
expect_same('2026-09-14', $parsed['lines'][0]['aggregate']['delivery_date'], 'aggregate line delivery date');
expect_same('1', $parsed['lines'][0]['aggregate']['exchange_rate'], 'aggregate line exchange rate');
expect_same('123', $parsed['lines'][0]['additional_data'][0]['value'], 'additional line data');
expect_same('3185', $parsed['totals']['net'], 'normal invoice net total');
expect_same('4044.95', $parsed['totals']['gross'], 'normal invoice gross total');

fwrite(STDOUT, "Parser regression tests passed\n");
