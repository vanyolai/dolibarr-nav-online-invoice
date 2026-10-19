<?php

require_once __DIR__.'/../class/navapi.class.php';

function fail_api_response_test(string $message): void
{
    fwrite(STDERR, "FAIL: ".$message."\n");
    exit(1);
}

/**
 * @param string $expectedMessageFragment Empty for a successful response.
 */
function assert_nav_protocol_result(string $xml, string $expectedMessageFragment): void
{
    $response = simplexml_load_string($xml);
    if (!$response instanceof SimpleXMLElement) {
        fail_api_response_test('Invalid response XML in test fixture.');
    }

    // The response validator is network-free; no configured Dolibarr runtime
    // or real NAV credentials are required.
    $api = (new ReflectionClass('NavInvoiceApi'))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod('NavInvoiceApi', 'assertSuccessfulResult');
    $method->setAccessible(true);

    try {
        $method->invoke($api, $response, 'queryTaxpayer', 'TESTREQUEST123');
    } catch (Exception $e) {
        if ($expectedMessageFragment === '') {
            fail_api_response_test('NAV success response was rejected: '.$e->getMessage());
        }
        if (strpos($e->getMessage(), $expectedMessageFragment) === false
            || strpos($e->getMessage(), 'TESTREQUEST123') === false) {
            fail_api_response_test('Missing protocol error detail/requestId: '.$e->getMessage());
        }
        return;
    }

    if ($expectedMessageFragment !== '') {
        fail_api_response_test('Unsuccessful NAV response was accepted: '.$expectedMessageFragment);
    }
}

assert_nav_protocol_result(
    '<QueryTaxpayerResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
        .'<result><funcCode>OK</funcCode></result></QueryTaxpayerResponse>',
    ''
);

// Some NAV protocol failures are returned as HTTP 200 without detailed
// technicalValidationMessages. Such responses must not be accepted as success.
assert_nav_protocol_result(
    '<GeneralErrorResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
        .'<result><funcCode>ERROR</funcCode><errorCode>INVALID_REQUEST</errorCode>'
        .'<message>Invalid request</message></result></GeneralErrorResponse>',
    'INVALID_REQUEST'
);

assert_nav_protocol_result(
    '<QueryTaxpayerResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
        .'<result><funcCode>UNEXPECTED</funcCode></result></QueryTaxpayerResponse>',
    'UNEXPECTED'
);

// Prefixes and the common namespace differ across NAV response types.
assert_nav_protocol_result(
    '<QueryTaxpayerResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api"'
        .' xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common">'
        .'<common:result><common:funcCode>ERROR</common:funcCode>'
        .'<common:errorCode>AUTHENTICATION</common:errorCode></common:result>'
        .'</QueryTaxpayerResponse>',
    'AUTHENTICATION'
);

assert_nav_protocol_result(
    '<QueryTaxpayerResponse xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
        .'<taxpayerValidity>true</taxpayerValidity></QueryTaxpayerResponse>',
    'missing funcCode'
);

fwrite(STDOUT, "NAV API protocol response regression tests passed\n");
