<?php

/**
 * Parse NAV Online Invoice 3.0 InvoiceData XML into a stable, read-only
 * structure that can later be reused for Dolibarr invoice imports.
 */
class NavInvoiceParser
{
    /**
     * @return array<string, mixed>
     */
    public function parse(string $xml): array
    {
        libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        if (!$document instanceof SimpleXMLElement) {
            $detail = !empty($errors) ? trim($errors[0]->message) : 'unknown XML error';
            throw new Exception('Stored NAV invoice XML is invalid: '.$detail);
        }

        $invoiceNodes = $document->xpath(
            '//*[local-name()="invoiceMain"]/*[local-name()="invoice"]'
            .' | //*[local-name()="invoiceMain"]/*[local-name()="batchInvoice"]/*[local-name()="invoice"]'
        );
        if (!$invoiceNodes) {
            throw new Exception('Stored NAV XML does not contain an invoice payload.');
        }

        $invoice = $invoiceNodes[0];
        $head = $this->node($invoice, './*[local-name()="invoiceHead"]');
        $detail = $head ? $this->node($head, './*[local-name()="invoiceDetail"]') : null;
        $supplierInfo = $head ? $this->node($head, './*[local-name()="supplierInfo"]') : null;
        $customerInfo = $head ? $this->node($head, './*[local-name()="customerInfo"]') : null;
        $reference = $this->node($invoice, './*[local-name()="invoiceReference"]');

        $currency = $detail ? $this->text($detail, './*[local-name()="currencyCode"]') : '';
        $totals = $this->parseTotals($invoice);

        $lines = array();
        $lineNodes = $invoice->xpath('./*[local-name()="invoiceLines"]/*[local-name()="line"]');
        foreach ($lineNodes ?: array() as $line) {
            $lines[] = $this->parseLine($line);
        }

        return array(
            'invoice_number' => $this->text($document, './*[local-name()="invoiceNumber"]'),
            'invoice_issue_date' => $this->text($document, './*[local-name()="invoiceIssueDate"]'),
            'completeness_indicator' => $this->boolText($document, './*[local-name()="completenessIndicator"]'),
            'batch_index' => $this->text($document, './*[local-name()="invoiceMain"]/*[local-name()="batchInvoice"]/*[local-name()="batchIndex"]'),
            'supplier' => $this->parseSupplier($supplierInfo),
            'customer' => $this->parseCustomer($customerInfo),
            'detail' => array(
                'category' => $detail ? $this->text($detail, './*[local-name()="invoiceCategory"]') : '',
                'delivery_date' => $detail ? $this->text($detail, './*[local-name()="invoiceDeliveryDate"]') : '',
                'delivery_period_start' => $detail ? $this->text($detail, './*[local-name()="invoiceDeliveryPeriodStart"]') : '',
                'delivery_period_end' => $detail ? $this->text($detail, './*[local-name()="invoiceDeliveryPeriodEnd"]') : '',
                'payment_date' => $detail ? $this->text($detail, './*[local-name()="paymentDate"]') : '',
                'payment_method' => $detail ? $this->text($detail, './*[local-name()="paymentMethod"]') : '',
                'currency' => $currency,
                'exchange_rate' => $detail ? $this->text($detail, './*[local-name()="exchangeRate"]') : '',
                'invoice_appearance' => $detail ? $this->text($detail, './*[local-name()="invoiceAppearance"]') : '',
                'cash_accounting' => $detail ? $this->boolText($detail, './*[local-name()="cashAccountingIndicator"]') : null,
                'periodical_settlement' => $detail ? $this->boolText($detail, './*[local-name()="periodicalSettlement"]') : null,
                'small_business_indicator' => $detail ? $this->boolText($detail, './*[local-name()="smallBusinessIndicator"]') : null,
            ),
            'reference' => array(
                'original_invoice_number' => $reference ? $this->text($reference, './*[local-name()="originalInvoiceNumber"]') : '',
                'modification_index' => $reference ? $this->text($reference, './*[local-name()="modificationIndex"]') : '',
                'modify_without_master' => $reference ? $this->boolText($reference, './*[local-name()="modifyWithoutMaster"]') : null,
            ),
            'totals' => $totals,
            'lines' => $lines,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseSupplier(?SimpleXMLElement $node): array
    {
        if ($node === null) {
            return $this->emptyParty();
        }

        return array(
            'name' => $this->text($node, './*[local-name()="supplierName"]'),
            'tax_number' => $this->text($node, './*[local-name()="supplierTaxNumber"]/*[local-name()="taxpayerId"]'),
            'vat_code' => $this->text($node, './*[local-name()="supplierTaxNumber"]/*[local-name()="vatCode"]'),
            'county_code' => $this->text($node, './*[local-name()="supplierTaxNumber"]/*[local-name()="countyCode"]'),
            'group_member_tax_number' => $this->text($node, './*[local-name()="groupMemberTaxNumber"]/*[local-name()="taxpayerId"]'),
            'community_vat_number' => '',
            'third_state_tax_id' => '',
            'bank_account' => $this->text($node, './*[local-name()="supplierBankAccountNumber"]'),
            'address' => $this->parseAddress($this->node($node, './*[local-name()="supplierAddress"]')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseCustomer(?SimpleXMLElement $node): array
    {
        if ($node === null) {
            return $this->emptyParty();
        }

        return array(
            'name' => $this->text($node, './*[local-name()="customerName"]'),
            'vat_status' => $this->text($node, './*[local-name()="customerVatStatus"]'),
            'tax_number' => $this->text($node, './*[local-name()="customerVatData"]/*[local-name()="customerTaxNumber"]/*[local-name()="taxpayerId"]'),
            'vat_code' => $this->text($node, './*[local-name()="customerVatData"]/*[local-name()="customerTaxNumber"]/*[local-name()="vatCode"]'),
            'county_code' => $this->text($node, './*[local-name()="customerVatData"]/*[local-name()="customerTaxNumber"]/*[local-name()="countyCode"]'),
            'group_member_tax_number' => $this->text($node, './*[local-name()="customerVatData"]/*[local-name()="communityVatNumber"]'),
            'community_vat_number' => $this->text($node, './*[local-name()="customerVatData"]/*[local-name()="communityVatNumber"]'),
            'third_state_tax_id' => $this->text($node, './*[local-name()="customerVatData"]/*[local-name()="thirdStateTaxId"]'),
            'bank_account' => $this->text($node, './*[local-name()="customerBankAccountNumber"]'),
            'address' => $this->parseAddress($this->node($node, './*[local-name()="customerAddress"]')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyParty(): array
    {
        return array(
            'name' => '',
            'vat_status' => '',
            'tax_number' => '',
            'vat_code' => '',
            'county_code' => '',
            'group_member_tax_number' => '',
            'community_vat_number' => '',
            'third_state_tax_id' => '',
            'bank_account' => '',
            'address' => $this->emptyAddress(),
        );
    }

    /**
     * @return array<string, string>
     */
    private function parseAddress(?SimpleXMLElement $address): array
    {
        if ($address === null) {
            return $this->emptyAddress();
        }

        $detailed = $this->node($address, './*[local-name()="detailedAddress"]');
        $simple = $this->node($address, './*[local-name()="simpleAddress"]');
        $source = $detailed !== null ? $detailed : $simple;
        if ($source === null) {
            return $this->emptyAddress();
        }

        $result = $this->emptyAddress();
        foreach (array(
            'countryCode' => 'country_code',
            'region' => 'region',
            'postalCode' => 'postal_code',
            'city' => 'city',
            'streetName' => 'street_name',
            'publicPlaceCategory' => 'public_place_category',
            'number' => 'number',
            'building' => 'building',
            'staircase' => 'staircase',
            'floor' => 'floor',
            'door' => 'door',
            'lotNumber' => 'lot_number',
            'additionalAddressDetail' => 'additional_detail',
        ) as $xmlName => $key) {
            $result[$key] = $this->text($source, './*[local-name()="'.$xmlName.'"]');
        }

        $parts = array();
        $city = trim($result['postal_code'].' '.$result['city']);
        if ($city !== '') {
            $parts[] = $city;
        }
        $street = trim($result['street_name'].' '.$result['public_place_category'].' '.$result['number']);
        if ($street !== '') {
            $parts[] = $street;
        }
        foreach (array('building', 'staircase', 'floor', 'door', 'lot_number', 'additional_detail') as $key) {
            if ($result[$key] !== '') {
                $parts[] = $result[$key];
            }
        }
        $result['formatted'] = implode(', ', $parts);

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function emptyAddress(): array
    {
        return array(
            'country_code' => '',
            'region' => '',
            'postal_code' => '',
            'city' => '',
            'street_name' => '',
            'public_place_category' => '',
            'number' => '',
            'building' => '',
            'staircase' => '',
            'floor' => '',
            'door' => '',
            'lot_number' => '',
            'additional_detail' => '',
            'formatted' => '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseTotals(SimpleXMLElement $invoice): array
    {
        $result = array(
            'net' => null,
            'net_huf' => null,
            'vat' => null,
            'vat_huf' => null,
            'gross' => null,
            'gross_huf' => null,
            'gross_derived' => false,
            'gross_huf_derived' => false,
        );

        $summary = $this->node($invoice, './*[local-name()="invoiceSummary"]');
        if (!$summary) {
            return $result;
        }

        $normal = $this->node($summary, './*[local-name()="summaryNormal"]');
        if ($normal) {
            $result['net'] = $this->nullableText($normal, './*[local-name()="invoiceNetAmount"]');
            $result['net_huf'] = $this->nullableText($normal, './*[local-name()="invoiceNetAmountHUF"]');
            $result['vat'] = $this->nullableText($normal, './*[local-name()="invoiceVatAmount"]');
            $result['vat_huf'] = $this->nullableText($normal, './*[local-name()="invoiceVatAmountHUF"]');
        }

        $grossData = $this->node($summary, './*[local-name()="summaryGrossData"]');
        if ($grossData) {
            $result['gross'] = $this->nullableText($grossData, './*[local-name()="invoiceGrossAmount"]');
            $result['gross_huf'] = $this->nullableText($grossData, './*[local-name()="invoiceGrossAmountHUF"]');
        }

        if ($normal) {
            // summaryGrossData is optional in NAV InvoiceData. For a normal
            // invoice, net and VAT from summaryNormal are authoritative and
            // make the gross amount deterministic when it is omitted.
            if ($result['gross'] === null && $result['net'] !== null && $result['vat'] !== null) {
                $result['gross'] = $this->decimal((float) $result['net'] + (float) $result['vat']);
                $result['gross_derived'] = true;
            }
            if ($result['gross_huf'] === null && $result['net_huf'] !== null && $result['vat_huf'] !== null) {
                $result['gross_huf'] = $this->decimal((float) $result['net_huf'] + (float) $result['vat_huf']);
                $result['gross_huf_derived'] = true;
            }
            return $result;
        }

        if ($result['gross'] === null) {
            return $result;
        }

        $simplified = $summary->xpath('./*[local-name()="summarySimplified"]');
        if (!$simplified) {
            return $result;
        }

        $gross = 0.0;
        $grossHuf = 0.0;
        $vat = 0.0;
        $vatHuf = 0.0;
        $hasGross = false;
        $hasGrossHuf = false;
        $derivable = true;

        foreach ($simplified as $item) {
            $itemGross = $this->nullableText($item, './*[local-name()="vatContentGrossAmount"]');
            if ($itemGross === null) {
                $derivable = false;
                break;
            }
            $g = (float) $itemGross;
            $gross += $g;
            $hasGross = true;

            $itemGrossHuf = $this->nullableText($item, './*[local-name()="vatContentGrossAmountHUF"]');
            if ($itemGrossHuf !== null) {
                $gh = (float) $itemGrossHuf;
                $grossHuf += $gh;
                $hasGrossHuf = true;
            } else {
                $gh = null;
            }

            $content = $this->nullableText($item, './*[local-name()="vatRate"]/*[local-name()="vatContent"]');
            if ($content !== null) {
                $rate = (float) $content;
                $vat += $g * $rate;
                if ($gh !== null) {
                    $vatHuf += $gh * $rate;
                }
            } elseif (!$this->isZeroVatRate($item, './*[local-name()="vatRate"]')) {
                $derivable = false;
                break;
            }
        }

        if ($derivable && $hasGross) {
            $result['gross'] = $this->decimal($gross);
            $result['vat'] = $this->decimal($vat);
            $result['net'] = $this->decimal($gross - $vat);
            if ($hasGrossHuf) {
                $result['gross_huf'] = $this->decimal($grossHuf);
                $result['vat_huf'] = $this->decimal($vatHuf);
                $result['net_huf'] = $this->decimal($grossHuf - $vatHuf);
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseLine(SimpleXMLElement $line): array
    {
        $normal = $this->node($line, './*[local-name()="lineAmountsNormal"]');
        $simplified = $this->node($line, './*[local-name()="lineAmountsSimplified"]');
        $amounts = array(
            'net' => null,
            'net_huf' => null,
            'vat' => null,
            'vat_huf' => null,
            'gross' => null,
            'gross_huf' => null,
            'vat_derived' => false,
            'vat_huf_derived' => false,
            'gross_derived' => false,
            'gross_huf_derived' => false,
        );
        $vat = array('kind' => '', 'value' => '', 'label' => '');

        if ($normal) {
            $amounts['net'] = $this->nullableText($normal, './*[local-name()="lineNetAmountData"]/*[local-name()="lineNetAmount"]');
            $amounts['net_huf'] = $this->nullableText($normal, './*[local-name()="lineNetAmountData"]/*[local-name()="lineNetAmountHUF"]');
            $amounts['vat'] = $this->nullableText($normal, './*[local-name()="lineVatData"]/*[local-name()="lineVatAmount"]');
            $amounts['vat_huf'] = $this->nullableText($normal, './*[local-name()="lineVatData"]/*[local-name()="lineVatAmountHUF"]');
            $amounts['gross'] = $this->nullableText($normal, './*[local-name()="lineGrossAmountData"]/*[local-name()="lineGrossAmountNormal"]');
            $amounts['gross_huf'] = $this->nullableText($normal, './*[local-name()="lineGrossAmountData"]/*[local-name()="lineGrossAmountNormalHUF"]');
            $vat = $this->parseVatRate($this->node($normal, './*[local-name()="lineVatRate"]'), false);

            // NAV may omit lineVatData and lineGrossAmountData even though line
            // net and VAT rate are present. Keep the source net authoritative,
            // and derive only the mathematically deterministic missing values.
            if ($amounts['net'] !== null) {
                if ($amounts['vat'] === null && $vat['kind'] === 'percentage') {
                    $amounts['vat'] = $this->decimal((float) $amounts['net'] * (float) $vat['value']);
                    $amounts['vat_derived'] = true;
                } elseif ($amounts['vat'] === null && $vat['kind'] === 'zero') {
                    $amounts['vat'] = '0';
                    $amounts['vat_derived'] = true;
                }
                if ($amounts['gross'] === null && $amounts['vat'] !== null) {
                    $amounts['gross'] = $this->decimal((float) $amounts['net'] + (float) $amounts['vat']);
                    $amounts['gross_derived'] = true;
                }
            }
            if ($amounts['net_huf'] !== null) {
                if ($amounts['vat_huf'] === null && $vat['kind'] === 'percentage') {
                    $amounts['vat_huf'] = $this->decimal((float) $amounts['net_huf'] * (float) $vat['value']);
                    $amounts['vat_huf_derived'] = true;
                } elseif ($amounts['vat_huf'] === null && $vat['kind'] === 'zero') {
                    $amounts['vat_huf'] = '0';
                    $amounts['vat_huf_derived'] = true;
                }
                if ($amounts['gross_huf'] === null && $amounts['vat_huf'] !== null) {
                    $amounts['gross_huf'] = $this->decimal((float) $amounts['net_huf'] + (float) $amounts['vat_huf']);
                    $amounts['gross_huf_derived'] = true;
                }
            }
        } elseif ($simplified) {
            $amounts['gross'] = $this->nullableText($simplified, './*[local-name()="lineGrossAmountSimplified"]');
            $amounts['gross_huf'] = $this->nullableText($simplified, './*[local-name()="lineGrossAmountSimplifiedHUF"]');
            $vatNode = $this->node($simplified, './*[local-name()="lineVatRate"]');
            $vat = $this->parseVatRate($vatNode, true);
            if ($amounts['gross'] !== null) {
                if ($vat['kind'] === 'content') {
                    $vatAmount = (float) $amounts['gross'] * (float) $vat['value'];
                    $amounts['vat'] = $this->decimal($vatAmount);
                    $amounts['net'] = $this->decimal((float) $amounts['gross'] - $vatAmount);
                    $amounts['vat_derived'] = true;
                } elseif ($vat['kind'] === 'zero') {
                    $amounts['vat'] = '0';
                    $amounts['net'] = $amounts['gross'];
                    $amounts['vat_derived'] = true;
                }
            }
            if ($amounts['gross_huf'] !== null) {
                if ($vat['kind'] === 'content') {
                    $vatAmountHuf = (float) $amounts['gross_huf'] * (float) $vat['value'];
                    $amounts['vat_huf'] = $this->decimal($vatAmountHuf);
                    $amounts['net_huf'] = $this->decimal((float) $amounts['gross_huf'] - $vatAmountHuf);
                    $amounts['vat_huf_derived'] = true;
                } elseif ($vat['kind'] === 'zero') {
                    $amounts['vat_huf'] = '0';
                    $amounts['net_huf'] = $amounts['gross_huf'];
                    $amounts['vat_huf_derived'] = true;
                }
            }
        }

        $codes = array();
        $codeNodes = $line->xpath('./*[local-name()="productCodes"]/*[local-name()="productCode"]');
        foreach ($codeNodes ?: array() as $code) {
            $category = $this->text($code, './*[local-name()="productCodeCategory"]');
            $value = $this->text($code, './*[local-name()="productCodeValue"]');
            $ownValue = $this->text($code, './*[local-name()="productCodeOwnValue"]');
            if ($category !== '' || $value !== '' || $ownValue !== '') {
                $codes[] = array('category' => $category, 'value' => $value !== '' ? $value : $ownValue);
            }
        }

        $itemNumbers = array();
        $itemNumberNodes = $line->xpath('./*[local-name()="conventionalLineInfo"]/*[local-name()="itemNumbers"]/*[local-name()="itemNumber"]');
        foreach ($itemNumberNodes ?: array() as $itemNumberNode) {
            $itemNumber = trim((string) $itemNumberNode);
            if ($itemNumber !== '' && !in_array($itemNumber, $itemNumbers, true)) {
                $itemNumbers[] = $itemNumber;
            }
        }

        return array(
            'number' => $this->text($line, './*[local-name()="lineNumber"]'),
            'description' => $this->text($line, './*[local-name()="lineDescription"]'),
            'nature' => $this->text($line, './*[local-name()="lineNatureIndicator"]'),
            'expression' => $this->boolText($line, './*[local-name()="lineExpressionIndicator"]'),
            'quantity' => $this->nullableText($line, './*[local-name()="quantity"]'),
            'unit' => $this->text($line, './*[local-name()="unitOfMeasure"]'),
            'unit_own' => $this->text($line, './*[local-name()="unitOfMeasureOwn"]'),
            'unit_price' => $this->nullableText($line, './*[local-name()="unitPrice"]'),
            'unit_price_huf' => $this->nullableText($line, './*[local-name()="unitPriceHUF"]'),
            'vat' => $vat,
            'amounts' => $amounts,
            'product_codes' => $codes,
            'item_numbers' => $itemNumbers,
            'modification' => array(
                'reference' => $this->text($line, './*[local-name()="lineModificationReference"]/*[local-name()="lineNumberReference"]'),
                'operation' => $this->text($line, './*[local-name()="lineModificationReference"]/*[local-name()="lineOperation"]'),
            ),
        );
    }

    /**
     * @return array{kind:string,value:string,label:string}
     */
    private function parseVatRate(?SimpleXMLElement $vatRate, bool $simplified): array
    {
        if (!$vatRate) {
            return array('kind' => '', 'value' => '', 'label' => '');
        }

        $percentage = $this->nullableText($vatRate, './*[local-name()="vatPercentage"]');
        if ($percentage !== null) {
            return array(
                'kind' => 'percentage',
                'value' => $percentage,
                'label' => $this->percent((float) $percentage),
            );
        }

        $content = $this->nullableText($vatRate, './*[local-name()="vatContent"]');
        if ($content !== null) {
            return array(
                'kind' => 'content',
                'value' => $content,
                'label' => ($simplified ? 'VAT content ' : '').$this->percent((float) $content),
            );
        }

        foreach (array(
            'vatExemption' => 'VAT_EXEMPTION',
            'vatOutOfScope' => 'OUT_OF_SCOPE',
            'vatDomesticReverseCharge' => 'DOMESTIC_REVERSE_CHARGE',
        ) as $element => $label) {
            $node = $this->node($vatRate, './*[local-name()="'.$element.'"]');
            if ($node) {
                $case = $this->text($node, './*[local-name()="case"]');
                $reason = $this->text($node, './*[local-name()="reason"]');
                $text = trim($case.($reason !== '' ? ' - '.$reason : ''));
                return array('kind' => 'zero', 'value' => '0', 'label' => $text !== '' ? $text : $label);
            }
        }

        $noVatCharge = $this->text($vatRate, './*[local-name()="noVatCharge"]');
        if (strtolower($noVatCharge) === 'true') {
            return array('kind' => 'zero', 'value' => '0', 'label' => 'NO_VAT_CHARGE');
        }

        $margin = $this->text($vatRate, './*[local-name()="marginSchemeIndicator"]');
        if ($margin !== '') {
            return array('kind' => 'special', 'value' => $margin, 'label' => $margin);
        }

        $mismatch = $this->node($vatRate, './*[local-name()="vatAmountMismatch"]');
        if ($mismatch) {
            $rate = $this->text($mismatch, './*[local-name()="vatRate"]');
            $case = $this->text($mismatch, './*[local-name()="case"]');
            return array('kind' => 'special', 'value' => $rate, 'label' => trim('VAT_AMOUNT_MISMATCH '.$case.' '.$rate));
        }

        return array('kind' => '', 'value' => '', 'label' => '');
    }

    private function isZeroVatRate(SimpleXMLElement $node, string $path): bool
    {
        $rate = $this->node($node, $path);
        if (!$rate) {
            return false;
        }
        foreach (array('vatExemption', 'vatOutOfScope', 'vatDomesticReverseCharge') as $name) {
            if ($this->node($rate, './*[local-name()="'.$name.'"]')) {
                return true;
            }
        }
        return strtolower($this->text($rate, './*[local-name()="noVatCharge"]')) === 'true';
    }

    private function node(SimpleXMLElement $node, string $xpath): ?SimpleXMLElement
    {
        $nodes = $node->xpath($xpath);
        return $nodes ? $nodes[0] : null;
    }

    private function text(SimpleXMLElement $node, string $xpath): string
    {
        $value = $this->nullableText($node, $xpath);
        return $value ?? '';
    }

    private function nullableText(SimpleXMLElement $node, string $xpath): ?string
    {
        $nodes = $node->xpath($xpath);
        if (!$nodes) {
            return null;
        }
        $value = trim((string) $nodes[0]);
        return $value === '' ? null : $value;
    }

    private function boolText(SimpleXMLElement $node, string $xpath): ?bool
    {
        $value = $this->nullableText($node, $xpath);
        if ($value === null) {
            return null;
        }
        return strtolower($value) === 'true' || $value === '1';
    }

    private function percent(float $value): string
    {
        $number = rtrim(rtrim(number_format($value * 100, 4, '.', ''), '0'), '.');
        return $number.'%';
    }

    private function decimal(float $value): string
    {
        $number = number_format(round($value, 8), 8, '.', '');
        $number = rtrim(rtrim($number, '0'), '.');
        return $number === '-0' || $number === '' ? '0' : $number;
    }
}
