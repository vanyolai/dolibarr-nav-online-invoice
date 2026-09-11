<?php

/**
 * Minimal read-only client for NAV Online Invoice API v3.
 */
class NavInvoiceApi
{
    private string $login;
    private string $password;
    private string $taxNumber;
    private string $signingKey;
    private string $environment;
    private string $softwareId;
    private string $softwareVersion;

    public function __construct()
    {
        $this->login = trim((string) getDolGlobalString('NAVINVOICE_LOGIN'));
        $this->password = $this->readSecret('NAVINVOICE_PASSWORD');
        $this->taxNumber = $this->normalizeTaxNumber((string) getDolGlobalString('NAVINVOICE_TAX_NUMBER'));
        $this->signingKey = trim($this->readSecret('NAVINVOICE_SIGNING_KEY'));
        $this->environment = getDolGlobalString('NAVINVOICE_ENVIRONMENT', 'test') === 'production' ? 'production' : 'test';
        $this->softwareId = trim((string) getDolGlobalString('NAVINVOICE_SOFTWARE_ID', 'DOLIBARRNAVSYNC001'));
        $this->softwareVersion = '0.3.0';
    }

    public function isConfigured(): bool
    {
        return $this->login !== '' && $this->password !== '' && strlen($this->taxNumber) === 8 && $this->signingKey !== '';
    }

    public function queryTaxpayer(?string $taxNumber = null): SimpleXMLElement
    {
        $taxNumber = $this->normalizeTaxNumber($taxNumber ?: $this->taxNumber);
        if (strlen($taxNumber) !== 8) {
            throw new Exception('NAV taxpayer number must contain the first 8 digits of the Hungarian tax number.');
        }

        $body = '<taxNumber>'.$this->xml($taxNumber).'</taxNumber>';
        return $this->request('queryTaxpayer', 'QueryTaxpayerRequest', $body);
    }

    public function queryInvoiceDigest(string $dateFrom, string $dateTo, int $page = 1, string $direction = 'OUTBOUND'): SimpleXMLElement
    {
        $this->assertDate($dateFrom);
        $this->assertDate($dateTo);
        $direction = $this->normalizeDirection($direction);
        if ($page < 1) {
            throw new Exception('NAV page number must be positive.');
        }

        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);
        if ($to < $from) {
            throw new Exception('NAV dateTo cannot be earlier than dateFrom.');
        }
        if ((int) $from->diff($to)->format('%a') > 34) {
            throw new Exception('NAV queryInvoiceDigest date interval cannot exceed 35 calendar days.');
        }

        $body = '<page>'.$page.'</page>'
            .'<invoiceDirection>'.$direction.'</invoiceDirection>'
            .'<invoiceQueryParams><mandatoryQueryParams><invoiceIssueDate>'
            .'<dateFrom>'.$this->xml($dateFrom).'</dateFrom>'
            .'<dateTo>'.$this->xml($dateTo).'</dateTo>'
            .'</invoiceIssueDate></mandatoryQueryParams></invoiceQueryParams>';

        return $this->request('queryInvoiceDigest', 'QueryInvoiceDigestRequest', $body);
    }

    public function queryInvoiceData(string $invoiceNumber, int $batchIndex = 0, string $direction = 'OUTBOUND'): string
    {
        if ($invoiceNumber === '') {
            throw new Exception('Invoice number is required.');
        }
        $direction = $this->normalizeDirection($direction);

        $body = '<invoiceNumberQuery>'
            .'<invoiceNumber>'.$this->xml($invoiceNumber).'</invoiceNumber>'
            .'<invoiceDirection>'.$direction.'</invoiceDirection>';
        if ($batchIndex > 0) {
            $body .= '<batchIndex>'.$batchIndex.'</batchIndex>';
        }
        $body .= '</invoiceNumberQuery>';

        $response = $this->request('queryInvoiceData', 'QueryInvoiceDataRequest', $body);
        $dataNodes = $response->xpath('//*[local-name()="invoiceDataResult"]');
        if (!$dataNodes) {
            throw new Exception('NAV queryInvoiceData returned no invoiceDataResult.');
        }

        $result = $dataNodes[0];
        $encodedNodes = $result->xpath('./*[local-name()="invoiceData"]');
        if (!$encodedNodes) {
            throw new Exception('NAV queryInvoiceData returned no invoiceData payload.');
        }

        $decoded = base64_decode((string) $encodedNodes[0], true);
        if ($decoded === false) {
            throw new Exception('NAV invoiceData Base64 decoding failed.');
        }

        $compressedNodes = $result->xpath('./*[local-name()="compressedContentIndicator"]');
        $compressed = $compressedNodes && strtolower((string) $compressedNodes[0]) === 'true';
        if ($compressed) {
            $uncompressed = gzdecode($decoded);
            if ($uncompressed === false) {
                throw new Exception('NAV invoiceData gzip decompression failed.');
            }
            $decoded = $uncompressed;
        }

        return $decoded;
    }

    private function request(string $endpoint, string $rootElement, string $body): SimpleXMLElement
    {
        if (!$this->isConfigured()) {
            throw new Exception('NAV Online Invoice credentials are incomplete.');
        }
        if (!function_exists('curl_init')) {
            throw new Exception('PHP cURL extension is required.');
        }
        if (!in_array('sha3-512', hash_algos(), true)) {
            throw new Exception('PHP hash extension with SHA3-512 support is required.');
        }

        $requestId = $this->createRequestId();
        $utc = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $timestampXml = $utc->format('Y-m-d\\TH:i:s.v\\Z');
        $timestampSignature = $utc->format('YmdHis');
        $passwordHash = strtoupper(hash('sha512', $this->password));
        $signature = strtoupper(hash('sha3-512', $requestId.$timestampSignature.$this->signingKey));

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            .'<'.$rootElement.' xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            .'<common:header>'
            .'<common:requestId>'.$requestId.'</common:requestId>'
            .'<common:timestamp>'.$timestampXml.'</common:timestamp>'
            .'<common:requestVersion>3.0</common:requestVersion>'
            .'<common:headerVersion>1.0</common:headerVersion>'
            .'</common:header>'
            .'<common:user>'
            .'<common:login>'.$this->xml($this->login).'</common:login>'
            .'<common:passwordHash cryptoType="SHA-512">'.$passwordHash.'</common:passwordHash>'
            .'<common:taxNumber>'.$this->xml($this->taxNumber).'</common:taxNumber>'
            .'<common:requestSignature cryptoType="SHA3-512">'.$signature.'</common:requestSignature>'
            .'</common:user>'
            .$this->softwareXml()
            .$body
            .'</'.$rootElement.'>';

        $url = $this->baseUrl().'/'.$endpoint;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => array('Content-Type: application/xml; charset=UTF-8', 'Accept: application/xml'),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ));

        $raw = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($raw === false || $curlError !== '') {
            throw new Exception('NAV HTTP request failed: '.$curlError);
        }

        libxml_use_internal_errors(true);
        $response = simplexml_load_string($raw);

        if ($httpCode < 200 || $httpCode >= 300) {
            $detail = $response instanceof SimpleXMLElement ? $this->extractApiError($response) : '';
            $message = 'NAV HTTP request returned status '.$httpCode;
            if ($detail !== '') {
                $message .= ': '.$detail;
            }
            if ($httpCode === 401) {
                $message .= ' (check that the technical user and keys belong to the selected NAV '.($this->environment === 'production' ? 'production' : 'test').' environment)';
            }
            throw new Exception($message.'.');
        }

        if ($response === false) {
            $errors = libxml_get_errors();
            libxml_clear_errors();
            throw new Exception('NAV returned invalid XML'.(!empty($errors) ? ': '.trim($errors[0]->message) : '.'));
        }

        $detail = $this->extractApiError($response);
        if ($detail !== '') {
            throw new Exception($detail);
        }

        return $response;
    }

    private function extractApiError(SimpleXMLElement $response): string
    {
        $funcNodes = $response->xpath('//*[local-name()="result"]/*[local-name()="funcCode"]');
        if (!$funcNodes || (string) $funcNodes[0] === 'OK') {
            return '';
        }

        $errorCodeNodes = $response->xpath('//*[local-name()="result"]/*[local-name()="errorCode"]');
        $messageNodes = $response->xpath('//*[local-name()="result"]/*[local-name()="message"]');
        $errorCode = $errorCodeNodes ? trim((string) $errorCodeNodes[0]) : 'UNKNOWN';
        $message = $messageNodes ? trim((string) $messageNodes[0]) : 'NAV API request failed';

        return $errorCode.': '.$message;
    }

    private function readSecret(string $name): string
    {
        $value = (string) getDolGlobalString($name);
        if ($value === '') {
            return '';
        }

        if (preg_match('/^dolcrypt:/i', $value)) {
            require_once DOL_DOCUMENT_ROOT.'/core/lib/security.lib.php';
            $value = (string) dolDecrypt($value);
        }

        return $value;
    }

    private function softwareXml(): string
    {
        return '<software>'
            .'<softwareId>'.$this->xml($this->softwareId).'</softwareId>'
            .'<softwareName>Dolibarr NAV Online Invoice</softwareName>'
            .'<softwareOperation>LOCAL_SOFTWARE</softwareOperation>'
            .'<softwareMainVersion>'.$this->xml($this->softwareVersion).'</softwareMainVersion>'
            .'<softwareDevName>vanyolai</softwareDevName>'
            .'<softwareDevContact>https://github.com/vanyolai/dolibarr-nav-online-invoice</softwareDevContact>'
            .'<softwareDevCountryCode>HU</softwareDevCountryCode>'
            .'</software>';
    }

    private function baseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3'
            : 'https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3';
    }

    private function createRequestId(): string
    {
        return 'RID'.gmdate('YmdHis').strtoupper(bin2hex(random_bytes(5)));
    }

    private function normalizeTaxNumber(string $taxNumber): string
    {
        $digits = preg_replace('/\\D+/', '', $taxNumber);
        return substr((string) $digits, 0, 8);
    }

    private function normalizeDirection(string $direction): string
    {
        $direction = strtoupper(trim($direction));
        if (!in_array($direction, array('OUTBOUND', 'INBOUND'), true)) {
            throw new Exception('NAV invoice direction must be OUTBOUND or INBOUND.');
        }
        return $direction;
    }

    private function assertDate(string $date): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new Exception('Invalid date format, expected YYYY-MM-DD.');
        }
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
