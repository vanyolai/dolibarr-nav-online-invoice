<?php

dol_include_once('/navinvoice/class/navapi.class.php');

/**
 * Query and normalize current Hungarian taxpayer master data from NAV.
 *
 * Invoice XML contains historical party data valid for the invoice. This
 * service deliberately keeps current taxpayer master data separate so master
 * data creation/update decisions do not accidentally treat an old invoice
 * address as the current registered address.
 */
class NavTaxpayerService
{
    /** @var NavInvoiceApi */
    private $api;

    public function __construct(?NavInvoiceApi $api = null)
    {
        $this->api = $api ?: new NavInvoiceApi();
    }

    /**
     * @return array<string,mixed>
     */
    public function lookup(string $taxNumber): array
    {
        $core = $this->normalizeTaxNumber($taxNumber);
        if ($core === '') {
            throw new InvalidArgumentException('A Hungarian taxpayer number with at least 8 digits is required.');
        }

        $response = $this->api->queryTaxpayer($core);
        return $this->parse($response, $core);
    }

    /**
     * Convert taxpayer master data to the same party shape used by the invoice
     * parser and partner matcher.
     *
     * @param array<string,mixed> $master
     * @return array<string,mixed>
     */
    public function toParty(array $master): array
    {
        $address = is_array($master['primary_address'] ?? null) ? $master['primary_address'] : array();
        return array(
            'name' => (string) ($master['name'] ?? ''),
            'tax_number' => (string) ($master['tax_number'] ?? ''),
            'vat_code' => (string) ($master['vat_code'] ?? ''),
            'county_code' => (string) ($master['county_code'] ?? ''),
            'address' => $address,
            'bank_account' => '',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function parse(SimpleXMLElement $response, string $requestedCore): array
    {
        $validity = $this->xpathValue($response, '//*[local-name()="taxpayerValidity"]');
        $valid = strtolower($validity) === 'true' || $validity === '1';
        $infoDate = $this->xpathValue($response, '//*[local-name()="infoDate"]');

        $dataNodes = $response->xpath('//*[local-name()="taxpayerData"]');
        if (!$dataNodes) {
            return array(
                'requested_tax_number' => $requestedCore,
                'valid' => $valid,
                'info_date' => $infoDate,
                'name' => '',
                'short_name' => '',
                'tax_number' => $requestedCore,
                'vat_code' => '',
                'county_code' => '',
                'full_tax_number' => $requestedCore,
                'incorporation' => '',
                'vat_group_membership' => '',
                'addresses' => array(),
                'primary_address' => null,
            );
        }

        $data = $dataNodes[0];
        $taxDetailNodes = $data->xpath('./*[local-name()="taxNumberDetail"]');
        $taxDetail = $taxDetailNodes ? $taxDetailNodes[0] : null;
        $taxpayerId = $taxDetail ? $this->xpathValue($taxDetail, './*[local-name()="taxpayerId"]') : '';
        $taxpayerId = $this->normalizeTaxNumber($taxpayerId) ?: $requestedCore;
        $vatCode = $taxDetail ? $this->xpathValue($taxDetail, './*[local-name()="vatCode"]') : '';
        $countyCode = $taxDetail ? $this->xpathValue($taxDetail, './*[local-name()="countyCode"]') : '';

        $addresses = array();
        $addressItems = $data->xpath('.//*[local-name()="taxpayerAddressItem"]');
        foreach ($addressItems ?: array() as $item) {
            $addresses[] = $this->parseAddressItem($item);
        }

        $primary = null;
        foreach ($addresses as $address) {
            if (strtoupper((string) ($address['type'] ?? '')) === 'HQ') {
                $primary = $address;
                break;
            }
        }
        if ($primary === null && $addresses) {
            $primary = $addresses[0];
        }

        $fullTaxNumber = $taxpayerId;
        if ($vatCode !== '' && $countyCode !== '') {
            $fullTaxNumber .= '-'.$vatCode.'-'.$countyCode;
        }

        return array(
            'requested_tax_number' => $requestedCore,
            'valid' => $valid,
            'info_date' => $infoDate,
            'name' => $this->xpathValue($data, './*[local-name()="taxpayerName"]'),
            'short_name' => $this->xpathValue($data, './*[local-name()="taxpayerShortName"]'),
            'tax_number' => $taxpayerId,
            'vat_code' => $vatCode,
            'county_code' => $countyCode,
            'full_tax_number' => $fullTaxNumber,
            'incorporation' => $this->xpathValue($data, './*[local-name()="incorporation"]'),
            'vat_group_membership' => $this->xpathValue($data, './*[local-name()="vatGroupMembership"]'),
            'addresses' => $addresses,
            'primary_address' => $primary,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function parseAddressItem(SimpleXMLElement $item): array
    {
        $addressNodes = $item->xpath('./*[local-name()="taxpayerAddress"]');
        $address = $addressNodes ? $addressNodes[0] : $item;

        $result = array(
            'type' => $this->xpathValue($item, './*[local-name()="taxpayerAddressType"]'),
            'country_code' => $this->xpathValue($address, './/*[local-name()="countryCode"]'),
            'postal_code' => $this->xpathValue($address, './/*[local-name()="postalCode"]'),
            'city' => $this->xpathValue($address, './/*[local-name()="city"]'),
            'street_name' => $this->xpathValue($address, './/*[local-name()="streetName"]'),
            'public_place_category' => $this->xpathValue($address, './/*[local-name()="publicPlaceCategory"]'),
            'number' => $this->xpathValue($address, './/*[local-name()="number"]'),
            'building' => $this->xpathValue($address, './/*[local-name()="building"]'),
            'staircase' => $this->xpathValue($address, './/*[local-name()="staircase"]'),
            'floor' => $this->xpathValue($address, './/*[local-name()="floor"]'),
            'door' => $this->xpathValue($address, './/*[local-name()="door"]'),
            'lot_number' => $this->xpathValue($address, './/*[local-name()="lotNumber"]'),
            'additional_detail' => $this->xpathValue($address, './/*[local-name()="additionalAddressDetail"]'),
        );
        $result['formatted'] = $this->formatAddress($result);
        return $result;
    }

    /** @param array<string,mixed> $address */
    private function formatAddress(array $address): string
    {
        $street = trim(implode(' ', array_filter(array(
            trim((string) ($address['street_name'] ?? '')),
            trim((string) ($address['public_place_category'] ?? '')),
            trim((string) ($address['number'] ?? '')),
        ), static function (string $value): bool {
            return $value !== '';
        })));

        $extras = array();
        foreach (array('building', 'staircase', 'floor', 'door', 'lot_number') as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value !== '') {
                $extras[] = $value;
            }
        }
        $additional = trim((string) ($address['additional_detail'] ?? ''));
        if ($street === '' && $additional !== '') {
            $street = $additional;
        } elseif ($additional !== '') {
            $extras[] = $additional;
        }

        $locality = trim(implode(' ', array_filter(array(
            trim((string) ($address['postal_code'] ?? '')),
            trim((string) ($address['city'] ?? '')),
        ), static function (string $value): bool {
            return $value !== '';
        })));

        return trim(implode(', ', array_filter(array($locality, trim($street.($extras ? ', '.implode(', ', $extras) : ''))), static function (string $value): bool {
            return $value !== '';
        })));
    }

    private function normalizeTaxNumber(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || strlen($digits) < 8) {
            return '';
        }
        return substr($digits, 0, 8);
    }

    private function xpathValue(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        return $nodes ? trim((string) $nodes[0]) : '';
    }
}
