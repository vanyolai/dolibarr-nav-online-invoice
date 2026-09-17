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
    private int $minIntervalMs;

    public function __construct()
    {
        $this->login = trim((string) getDolGlobalString('NAVINVOICE_LOGIN'));
        $this->password = $this->readSecret('NAVINVOICE_PASSWORD');
        $this->taxNumber = $this->normalizeTaxNumber((string) getDolGlobalString('NAVINVOICE_TAX_NUMBER'));
        $this->signingKey = trim($this->readSecret('NAVINVOICE_SIGNING_KEY'));
        $this->environment = getDolGlobalString('NAVINVOICE_ENVIRONMENT', 'test') === 'production' ? 'production' : 'test';
        $this->softwareId = trim((string) getDolGlobalString('NAVINVOICE_SOFTWARE_ID', 'DOLIBARRNAVSYNC001'));
        $this->softwareVersion = '0.8.0';
        // Keep a small serialized gap between requests. Full invoice payloads
        // are queried one-by-one by the NAV API, so a large fixed delay makes
        // historical inbound synchronization unnecessarily slow. The value can
        // still be overridden through a Dolibarr constant without code changes.
        $this->minIntervalMs = max(0, min(5000, getDolGlobalInt('NAVINVOICE_API_MIN_INTERVAL_MS', 300)));
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

    /**
     * Query the authoritative NAV relation chain for an invoice.
     *
     * NAV accepts an optional taxpayer number in this request. The module omits
     * it by default because the authenticated taxpayer and invoice direction are
     * normally sufficient, but callers may provide it when disambiguation is
     * needed.
     */
    public function queryInvoiceChainDigest(
        string $invoiceNumber,
        string $direction = 'OUTBOUND',
        int $page = 1,
        ?string $taxNumber = null
    ): SimpleXMLElement {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new Exception('Invoice number is required for NAV invoice-chain query.');
        }
        $direction = $this->normalizeDirection($direction);
        if ($page < 1) {
            throw new Exception('NAV page number must be positive.');
        }

        $body = '<page>'.$page.'</page>'
            .'<invoiceChainQuery>'
            .'<invoiceNumber>'.$this->xml($invoiceNumber).'</invoiceNumber>'
            .'<invoiceDirection>'.$direction.'</invoiceDirection>';

        if ($taxNumber !== null && trim($taxNumber) !== '') {
            $normalizedTaxNumber = $this->normalizeTaxNumber($taxNumber);
            if (strlen($normalizedTaxNumber) !== 8) {
                throw new Exception('NAV invoice-chain taxpayer number must contain the first 8 digits of the Hungarian tax number.');
            }
            $body .= '<taxNumber>'.$this->xml($normalizedTaxNumber).'</taxNumber>';
        }

        $body .= '</invoiceChainQuery>';

        return $this->request('queryInvoiceChainDigest', 'QueryInvoiceChainDigestRequest', $body);
    }

    public function queryInvoiceData(
        string $invoiceNumber,
        int $batchIndex = 0,
        string $direction = 'OUTBOUND',
        ?string $supplierTaxNumber = null
    ): string {
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
        if ($direction === 'INBOUND' && $supplierTaxNumber !== null && trim($supplierTaxNumber) !== '') {
            $normalizedSupplierTaxNumber = $this->normalizeTaxNumber($supplierTaxNumber);
            if (strlen($normalizedSupplierTaxNumber) !== 8) {
                throw new Exception('NAV supplier tax number must contain the first 8 digits of the Hungarian tax number.');
            }
            $body .= '<supplierTaxNumber>'.$this->xml($normalizedSupplierTaxNumber).'</supplierTaxNumber>';
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
            .'<software>'
            .'<softwareId>'.$this->xml($this->softwareId).'</softwareId>'
            .'<softwareName>Dolibarr NAV Online Invoice</softwareName>'
            .'<softwareOperation>ONLINE_SERVICE</softwareOperation>'
            .'<softwareMainVersion>'.$this->xml($this->softwareVersion).'</softwareMainVersion>'
            .'<softwareDevName>vanyolai</softwareDevName>'
            .'<softwareDevContact>https://github.com/vanyolai/dolibarr-nav-online-invoice</softwareDevContact>'
            .'<softwareDevCountryCode>HU</softwareDevCountryCode>'
            .'<softwareDevTaxNumber>'.$this->xml($this->taxNumber).'</softwareDevTaxNumber>'
            .'</software>'
            .$body
            .'</'.$rootElement.'>';

        $this->paceRequests();

        $url = $this->baseUrl().'/'.$endpoint;
        $ch = curl_init($url);
        curl_setopt_array($ch, array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $xml,
            CURLOPT_HTTPHEADER => array('Content-Type: application/xml', 'Accept: application/xml'),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ));
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $raw === '') {
            throw new Exception('NAV API '.$endpoint.' request failed'.($curlError !== '' ? ': '.$curlError : '.'));
        }

        $response = @simplexml_load_string($raw);
        if ($response === false) {
            throw new Exception('NAV API '.$endpoint.' returned invalid XML (HTTP '.$status.').');
        }

        $technicalMessages = $response->xpath('//*[local-name()="technicalValidationMessages"]/*[local-name()="validationResultCode" and normalize-space(.) != "OK"]/..');
        $businessMessages = $response->xpath('//*[local-name()="businessValidationMessages"]/*[local-name()="validationResultCode" and normalize-space(.) != "OK"]/..');
        $messages = array_merge($technicalMessages ?: array(), $businessMessages ?: array());
        if ($messages) {
            $parts = array();
            foreach ($messages as $message) {
                $code = $this->xpathValue($message, './*[local-name()="validationErrorCode"]');
                $text = $this->xpathValue($message, './*[local-name()="message"]');
                $parts[] = trim($code.($text !== '' ? ': '.$text : ''));
            }
            throw new Exception('NAV API '.$endpoint.' validation error: '.implode('; ', array_filter($parts)).' [requestId '.$requestId.']');
        }

        if ($status < 200 || $status >= 300) {
            $errorCode = $this->xpathValue($response, '//*[local-name()="result"]/*[local-name()="errorCode"]');
            $errorMessage = $this->xpathValue($response, '//*[local-name()="result"]/*[local-name()="message"]');
            if ($errorCode === '') {
                $errorCode = $this->xpathValue($response, '//*[local-name()="errorCode"]');
            }
            if ($errorMessage === '') {
                $errorMessage = $this->xpathValue($response, '//*[local-name()="message"]');
            }
            $detail = trim($errorCode.($errorMessage !== '' ? ': '.$errorMessage : ''));
            $hint = $status === 401 ? ' Check that the selected test/production environment matches the technical user.' : '';
            throw new Exception(
                'NAV API '.$endpoint.' HTTP '.$status
                .($detail !== '' ? ' - '.$detail : '')
                .' [requestId '.$requestId.'].'
                .$hint
            );
        }

        return $response;
    }

    /**
     * Serialize requests from this Dolibarr instance and keep a minimum
     * interval between their start times. This protects manual sync, scheduled
     * sync, taxpayer lookups and relation checks from collectively bursting the
     * NAV API from the same application host.
     */
    private function paceRequests(): void
    {
        if ($this->minIntervalMs <= 0) {
            return;
        }

        $directory = defined('DOL_DATA_ROOT') ? rtrim((string) DOL_DATA_ROOT, '/').'/navinvoice' : sys_get_temp_dir();
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }
        $path = rtrim($directory, '/').'/navapi-rate.lock';
        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }
            rewind($handle);
            $previous = (float) trim((string) stream_get_contents($handle));
            $now = microtime(true);
            $minimumSeconds = $this->minIntervalMs / 1000;
            if ($previous > 0) {
                $wait = $minimumSeconds - ($now - $previous);
                if ($wait > 0) {
                    usleep((int) ceil($wait * 1000000));
                }
            }

            $started = microtime(true);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, sprintf('%.6F', $started));
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }
    }

    private function baseUrl(): string
    {
        return $this->environment === 'production'
            ? 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3'
            : 'https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3';
    }

    private function normalizeTaxNumber(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
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
            throw new Exception('Invalid date, expected YYYY-MM-DD: '.$date);
        }
    }

    private function createRequestId(): string
    {
        return 'DOL'.strtoupper(substr(bin2hex(random_bytes(15)), 0, 27));
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xpathValue(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        return $nodes ? trim((string) $nodes[0]) : '';
    }

    private function readSecret(string $name): string
    {
        $value = trim((string) getDolGlobalString($name));
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, 'dolcrypt:') && function_exists('dolDecrypt')) {
            $decrypted = dolDecrypt($value);
            if ($decrypted !== false && $decrypted !== null) {
                return trim((string) $decrypted);
            }
        }

        return $value;
    }
}
