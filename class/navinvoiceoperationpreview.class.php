<?php

dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navinvoiceoperationpolicy.class.php');

/**
 * Extend the existing accounting/data preview with NAV operation semantics.
 *
 * The legacy preview remains responsible for partner, VAT, currency, line and
 * duplicate checks. This wrapper replaces its blanket non-CREATE blocker with
 * the stricter relation/chain/mapping policy.
 */
class NavInvoiceOperationPreview
{
    /** @var NavInvoiceImportPreview */
    private $basePreview;

    /** @var NavInvoiceOperationPolicy */
    private $operationPolicy;

    public function __construct($db, int $entity, string $baseCurrency = 'HUF')
    {
        $this->basePreview = new NavInvoiceImportPreview($db, $entity, $baseCurrency);
        $this->operationPolicy = new NavInvoiceOperationPolicy($db, $entity);
    }

    /**
     * @param array<string,mixed> $parsed
     * @param object $record
     * @param array<string,mixed>|null $partnerMatch
     * @return array<string,mixed>
     */
    public function build(array $parsed, $record, ?array $partnerMatch): array
    {
        $preview = $this->basePreview->build($parsed, $record, $partnerMatch);
        $policy = $this->operationPolicy->evaluate($parsed, $record);
        $preview['operation_policy'] = $policy;

        $operation = strtoupper((string) ($preview['operation'] ?? 'CREATE'));
        if ($operation === 'CREATE') {
            return $preview;
        }

        $blockers = array_values(array_diff(
            array_map('strval', $preview['blockers'] ?? array()),
            array('operation_relation')
        ));
        foreach (($policy['blockers'] ?? array()) as $blocker) {
            $blockers[] = (string) $blocker;
        }

        $warnings = array_values(array_map('strval', $preview['warnings'] ?? array()));
        foreach (($policy['warnings'] ?? array()) as $warning) {
            $warnings[] = (string) $warning;
        }

        $preview['blockers'] = array_values(array_unique($blockers));
        $preview['warnings'] = array_values(array_unique($warnings));
        $preview['state'] = $preview['blockers'] ? 'blocked' : ($preview['warnings'] ? 'review' : 'ready');
        return $preview;
    }
}
