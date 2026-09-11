<?php

dol_include_once('/navinvoice/class/navinvoicerelation.class.php');
dol_include_once('/navinvoice/class/navinvoicechain.class.php');

/**
 * Decide whether a NAV invoice operation has a deterministic Dolibarr mapping.
 *
 * This is deliberately stricter than the NAV data model. NAV may accept
 * legally valid edge cases which still lack a safe accounting representation
 * in Dolibarr. Such cases remain blocked until an explicit policy exists.
 */
class NavInvoiceOperationPolicy
{
    /** @var NavInvoiceRelationResolver */
    private $relationResolver;

    /** @var NavInvoiceChainService */
    private $chainService;

    public function __construct($db, int $entity, ?NavInvoiceChainService $chainService = null)
    {
        $this->relationResolver = new NavInvoiceRelationResolver($db, $entity);
        $this->chainService = $chainService ?: new NavInvoiceChainService();
    }

    /**
     * @param array<string,mixed> $parsed
     * @param object $record
     * @return array<string,mixed>
     */
    public function evaluate(array $parsed, $record): array
    {
        $operation = strtoupper(trim((string) ($record->invoice_operation ?? 'CREATE')));
        $direction = strtoupper(trim((string) ($record->invoice_direction ?? 'OUTBOUND')));
        $invoiceNumber = trim((string) ($parsed['invoice_number'] ?? $record->invoice_number ?? ''));
        $result = array(
            'operation' => $operation,
            'direction' => $direction,
            'mapping' => $operation === 'CREATE' ? 'standard' : '',
            'source_invoice_id' => 0,
            'relation' => null,
            'authoritative_chain' => null,
            'authoritative_chain_error' => '',
            'chain_comparison' => null,
            'blockers' => array(),
            'warnings' => array(),
            'ready' => $operation === 'CREATE',
        );

        if ($operation === 'CREATE') {
            return $result;
        }
        if (!in_array($operation, array('MODIFY', 'STORNO'), true)) {
            $result['blockers'][] = 'operation_unsupported';
            return $this->finish($result);
        }

        $relation = $this->relationResolver->resolve($record, $parsed);
        $result['relation'] = $relation;
        foreach (($relation['blockers'] ?? array()) as $blocker) {
            $result['blockers'][] = 'relation_'.$blocker;
        }
        foreach (($relation['warnings'] ?? array()) as $warning) {
            $result['warnings'][] = 'relation_'.$warning;
        }
        $result['source_invoice_id'] = (int) ($relation['original_dolibarr_invoice_id'] ?? 0);

        try {
            // Inbound invoice numbers are issuer-local and may collide across
            // suppliers. NAV's optional taxNumber query field disambiguates the
            // authoritative chain, so use the supplier's 8-digit taxpayer ID
            // whenever the InvoiceData contains it.
            $chainTaxNumber = null;
            if ($direction === 'INBOUND') {
                $supplierTaxNumber = trim((string) ($parsed['supplier']['tax_number'] ?? ''));
                if ($supplierTaxNumber !== '') {
                    $chainTaxNumber = $supplierTaxNumber;
                }
            }

            $navChain = $this->chainService->fetch($invoiceNumber, $direction, $chainTaxNumber);
            $result['authoritative_chain'] = $navChain;
            $comparison = $this->chainService->compareToLocal(
                is_array($navChain['elements'] ?? null) ? $navChain['elements'] : array(),
                is_array($relation['chain'] ?? null) ? $relation['chain'] : array()
            );
            $result['chain_comparison'] = $comparison;
            if (empty($comparison['complete'])) {
                $result['blockers'][] = 'authoritative_chain_mismatch';
            }
            $currentElement = $this->findCurrentElement(
                is_array($navChain['elements'] ?? null) ? $navChain['elements'] : array(),
                $invoiceNumber,
                $record->batch_index ?? null
            );
            if ($currentElement === null) {
                $result['blockers'][] = 'current_chain_member_missing';
            } elseif (strtoupper((string) ($currentElement['invoice_operation'] ?? '')) !== $operation) {
                $result['blockers'][] = 'current_chain_operation_mismatch';
            }
        } catch (Throwable $e) {
            $result['blockers'][] = 'authoritative_chain_unavailable';
            $result['authoritative_chain_error'] = $e->getMessage();
        }

        $gross = $this->numeric($parsed['totals']['gross'] ?? null);
        if ($gross === null) {
            $result['blockers'][] = 'operation_total_missing';
            return $this->finish($result);
        }

        $epsilon = 0.005;
        if ($operation === 'STORNO') {
            if ($gross >= -$epsilon) {
                $result['blockers'][] = 'storno_not_negative';
            } else {
                $result['mapping'] = 'credit_note';
            }
        } else {
            if ($gross < -$epsilon) {
                $result['mapping'] = 'credit_note';
            } elseif ($gross > $epsilon) {
                $result['mapping'] = 'standard_adjustment';
            } else {
                // A zero-value MODIFY can still be legally meaningful (for
                // example correcting descriptive data), but creating a fake
                // financial invoice would misrepresent the accounting event.
                $result['blockers'][] = 'non_financial_modification';
            }
        }

        if ($result['mapping'] === 'credit_note') {
            $net = $this->numeric($parsed['totals']['net'] ?? null);
            if ($net !== null && $net > $epsilon) {
                // Dolibarr validates credit notes by HT sign, not TTC sign.
                $result['blockers'][] = 'credit_note_net_positive';
            }
        }

        $mixedSigns = $this->hasMixedLineSigns($parsed);
        if ($mixedSigns) {
            $result['warnings'][] = 'mixed_sign_modification';
            if ($result['mapping'] === 'standard_adjustment' && !getDolGlobalString('FACTURE_ENABLE_NEGATIVE_LINES')) {
                $result['blockers'][] = 'standard_adjustment_negative_lines_disabled';
            }
        }
        if ($result['source_invoice_id'] <= 0) {
            $result['blockers'][] = 'source_invoice_missing';
        }

        return $this->finish($result);
    }

    /** @param array<int,array<string,mixed>> $elements @param mixed $batchIndex */
    private function findCurrentElement(array $elements, string $invoiceNumber, $batchIndex): ?array
    {
        $wantedBatch = $batchIndex === null || $batchIndex === '' ? 0 : (int) $batchIndex;
        foreach ($elements as $element) {
            if ((string) ($element['invoice_number'] ?? '') !== $invoiceNumber) {
                continue;
            }
            $elementBatch = $element['batch_index'] === null || $element['batch_index'] === '' ? 0 : (int) $element['batch_index'];
            if ($elementBatch === $wantedBatch) {
                return $element;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $parsed */
    private function hasMixedLineSigns(array $parsed): bool
    {
        $positive = false;
        $negative = false;
        foreach (($parsed['lines'] ?? array()) as $line) {
            $amount = $this->numeric($line['amounts']['gross'] ?? $line['amounts']['net'] ?? null);
            if ($amount === null || abs($amount) < 0.005) {
                continue;
            }
            if ($amount > 0) {
                $positive = true;
            } else {
                $negative = true;
            }
        }
        return $positive && $negative;
    }

    /** @param mixed $value */
    private function numeric($value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private function finish(array $result): array
    {
        $result['blockers'] = array_values(array_unique(array_map('strval', $result['blockers'] ?? array())));
        $result['warnings'] = array_values(array_unique(array_map('strval', $result['warnings'] ?? array())));
        $result['ready'] = !$result['blockers'] && (string) ($result['mapping'] ?? '') !== '';
        return $result;
    }
}
