<?php

dol_include_once('/navinvoice/class/navapi.class.php');

/**
 * Read and normalize the authoritative NAV invoice chain.
 *
 * The local mirror is intentionally not used here. queryInvoiceChainDigest is
 * the source of truth for which CREATE/MODIFY/STORNO members NAV currently
 * considers part of a chain; callers may compare this result with locally
 * mirrored records afterwards.
 */
class NavInvoiceChainService
{
    /** @var NavInvoiceApi */
    private $api;

    public function __construct(?NavInvoiceApi $api = null)
    {
        $this->api = $api ?: new NavInvoiceApi();
    }

    /**
     * Fetch all available pages of a NAV invoice chain.
     *
     * @return array<string,mixed>
     */
    public function fetch(string $invoiceNumber, string $direction, ?string $taxNumber = null): array
    {
        $invoiceNumber = trim($invoiceNumber);
        if ($invoiceNumber === '') {
            throw new InvalidArgumentException('Invoice number is required for NAV invoice-chain lookup.');
        }

        $page = 1;
        $availablePage = 1;
        $elements = array();
        do {
            $response = $this->api->queryInvoiceChainDigest($invoiceNumber, $direction, $page, $taxNumber);
            $parsed = $this->parsePage($response);
            $availablePage = max(1, (int) $parsed['available_page']);
            foreach ($parsed['elements'] as $element) {
                $elements[] = $element;
            }
            $page++;

            // Defensive ceiling: the NAV response page count is authoritative,
            // but do not permit a malformed response to create an unbounded loop.
            if ($page > 1000) {
                throw new Exception('NAV invoice-chain pagination exceeded the safety limit.');
            }
        } while ($page <= $availablePage);

        $elements = $this->deduplicateAndSort($elements);
        return array(
            'query_invoice_number' => $invoiceNumber,
            'direction' => strtoupper(trim($direction)),
            'available_page' => $availablePage,
            'pages_fetched' => $availablePage,
            'elements' => $elements,
            'count' => count($elements),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function parsePage(SimpleXMLElement $response): array
    {
        $resultNodes = $response->xpath('//*[local-name()="invoiceChainDigestResult"]');
        if (!$resultNodes) {
            throw new Exception('NAV queryInvoiceChainDigest returned no invoiceChainDigestResult.');
        }
        $result = $resultNodes[0];
        $currentPage = (int) $this->xpathValue($result, './*[local-name()="currentPage"]');
        $availablePage = (int) $this->xpathValue($result, './*[local-name()="availablePage"]');
        if ($currentPage <= 0) {
            $currentPage = 1;
        }
        if ($availablePage <= 0) {
            $availablePage = $currentPage;
        }

        $elements = array();
        $nodes = $result->xpath('./*[local-name()="invoiceChainElement"]');
        foreach ($nodes ?: array() as $node) {
            $digestNodes = $node->xpath('./*[local-name()="invoiceChainDigest"]');
            if (!$digestNodes) {
                continue;
            }
            $digest = $digestNodes[0];
            $referenceNodes = $node->xpath('./*[local-name()="invoiceReferenceData"]');
            $reference = $referenceNodes ? $referenceNodes[0] : null;

            $batchIndex = $this->nullableInt($this->xpathValue($digest, './*[local-name()="batchIndex"]'));
            $transactionIndex = $this->nullableInt($this->xpathValue($digest, './*[local-name()="index"]'));
            $modificationIndex = $reference
                ? $this->nullableInt($this->xpathValue($reference, './*[local-name()="modificationIndex"]'))
                : null;
            $modifyWithoutMaster = $reference
                ? $this->nullableBool($this->xpathValue($reference, './*[local-name()="modifyWithoutMaster"]'))
                : null;

            $elements[] = array(
                'invoice_number' => $this->xpathValue($digest, './*[local-name()="invoiceNumber"]'),
                'batch_index' => $batchIndex,
                'invoice_operation' => strtoupper($this->xpathValue($digest, './*[local-name()="invoiceOperation"]')),
                'invoice_category' => $this->xpathValue($digest, './*[local-name()="invoiceCategory"]'),
                'invoice_issue_date' => $this->xpathValue($digest, './*[local-name()="invoiceIssueDate"]'),
                'supplier_tax_number' => $this->xpathValue($digest, './*[local-name()="supplierTaxNumber"]'),
                'customer_tax_number' => $this->xpathValue($digest, './*[local-name()="customerTaxNumber"]'),
                'transaction_id' => $this->xpathValue($digest, './*[local-name()="transactionId"]'),
                'transaction_index' => $transactionIndex,
                'original_invoice_number' => $reference ? $this->xpathValue($reference, './*[local-name()="originalInvoiceNumber"]') : '',
                'modification_index' => $modificationIndex,
                'modify_without_master' => $modifyWithoutMaster,
            );
        }

        return array(
            'current_page' => $currentPage,
            'available_page' => $availablePage,
            'elements' => $elements,
        );
    }

    /**
     * Compare normalized NAV chain elements with normalized local mirror items.
     *
     * @param array<int,array<string,mixed>> $authoritative
     * @param array<int,array<string,mixed>> $local
     * @return array<string,mixed>
     */
    public function compareToLocal(array $authoritative, array $local): array
    {
        $navMap = array();
        foreach ($authoritative as $item) {
            $navMap[$this->identity($item)] = $item;
        }
        $localMap = array();
        foreach ($local as $item) {
            $normalized = array(
                'invoice_number' => (string) ($item['invoice_number'] ?? ''),
                'batch_index' => $item['batch_index'] ?? null,
                'invoice_operation' => strtoupper((string) ($item['operation'] ?? $item['invoice_operation'] ?? '')),
                'modification_index' => $item['modification_index'] ?? null,
            );
            $localMap[$this->identity($normalized)] = $item;
        }

        $missingLocal = array();
        foreach ($navMap as $key => $item) {
            if (!isset($localMap[$key])) {
                $missingLocal[] = $item;
            }
        }
        $localOnly = array();
        foreach ($localMap as $key => $item) {
            if (!isset($navMap[$key])) {
                $localOnly[] = $item;
            }
        }

        $mismatches = array();
        foreach ($navMap as $key => $navItem) {
            if (!isset($localMap[$key])) {
                continue;
            }
            $localItem = $localMap[$key];
            $localOperation = strtoupper((string) ($localItem['operation'] ?? $localItem['invoice_operation'] ?? ''));
            $localModificationIndex = $localItem['modification_index'] ?? null;
            if ($localOperation !== strtoupper((string) ($navItem['invoice_operation'] ?? ''))
                || $this->nullableIntValue($localModificationIndex) !== $this->nullableIntValue($navItem['modification_index'] ?? null)) {
                $mismatches[] = array('nav' => $navItem, 'local' => $localItem);
            }
        }

        return array(
            'complete' => !$missingLocal && !$localOnly && !$mismatches,
            'missing_local' => $missingLocal,
            'local_only' => $localOnly,
            'mismatches' => $mismatches,
        );
    }

    /** @param array<int,array<string,mixed>> $items @return array<int,array<string,mixed>> */
    private function deduplicateAndSort(array $items): array
    {
        $unique = array();
        foreach ($items as $item) {
            $unique[$this->identity($item)] = $item;
        }
        $items = array_values($unique);
        usort($items, static function (array $a, array $b): int {
            $aOperation = strtoupper((string) ($a['invoice_operation'] ?? ''));
            $bOperation = strtoupper((string) ($b['invoice_operation'] ?? ''));
            if (($aOperation === 'CREATE') !== ($bOperation === 'CREATE')) {
                return $aOperation === 'CREATE' ? -1 : 1;
            }
            $aMod = (int) ($a['modification_index'] ?? 0);
            $bMod = (int) ($b['modification_index'] ?? 0);
            if ($aMod !== $bMod) {
                return $aMod <=> $bMod;
            }
            $dateCompare = strcmp((string) ($a['invoice_issue_date'] ?? ''), (string) ($b['invoice_issue_date'] ?? ''));
            if ($dateCompare !== 0) {
                return $dateCompare;
            }
            return strcmp((string) ($a['invoice_number'] ?? ''), (string) ($b['invoice_number'] ?? ''));
        });
        return $items;
    }

    /** @param array<string,mixed> $item */
    private function identity(array $item): string
    {
        $invoiceNumber = trim((string) ($item['invoice_number'] ?? ''));
        $batchIndex = $this->nullableIntValue($item['batch_index'] ?? null);
        return $invoiceNumber.'#'.($batchIndex === null ? '0' : (string) $batchIndex);
    }

    private function nullableInt(string $value): ?int
    {
        $value = trim($value);
        return $value === '' ? null : (int) $value;
    }

    /** @param mixed $value */
    private function nullableIntValue($value): ?int
    {
        return $value === '' || $value === null ? null : (int) $value;
    }

    private function nullableBool(string $value): ?bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return null;
        }
        if ($value === 'true' || $value === '1') {
            return true;
        }
        if ($value === 'false' || $value === '0') {
            return false;
        }
        return null;
    }

    private function xpathValue(SimpleXMLElement $node, string $xpath): string
    {
        $nodes = $node->xpath($xpath);
        return $nodes ? trim((string) $nodes[0]) : '';
    }
}
