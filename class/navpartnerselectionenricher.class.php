<?php

/**
 * Apply explicitly selected partner master-data changes from a fresh
 * NavPartnerEnrichmentPreview result.
 *
 * The browser submits field names only. Values are always taken from the fresh
 * server-side preview so a modified request cannot inject arbitrary partner
 * data. Bank accounts are intentionally excluded because Dolibarr manages them
 * as separate objects.
 */
class NavPartnerSelectionEnricher
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    public function __construct($db, int $entity)
    {
        $this->db = $db;
        $this->entity = $entity;
    }

    /**
     * Add selection metadata used by the UI and by apply().
     *
     * @param array<string,mixed> $enrichment
     * @return array<int,array<string,mixed>>
     */
    public function selectableItems(array $enrichment): array
    {
        $strongMatch = !empty($enrichment['strong_match']);
        $result = array();
        foreach (($enrichment['items'] ?? array()) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $meta = $this->selectionMeta($item, $strongMatch);
            $item['selectable'] = $meta['selectable'];
            $item['default_selected'] = $meta['default_selected'];
            $result[] = $item;
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $enrichment Fresh preview.
     * @param string[] $selectedFields Field names explicitly selected by user.
     * @param User $user Current Dolibarr user.
     * @return string[] Applied field names.
     */
    public function apply(array $enrichment, array $selectedFields, User $user): array
    {
        if (empty($enrichment['available']) || empty($enrichment['strong_match'])) {
            throw new Exception('Partner enrichment requires a strong, unambiguous partner match.');
        }

        $selected = array_values(array_unique(array_filter(array_map(static function ($value): string {
            return trim((string) $value);
        }, $selectedFields), static function (string $value): bool {
            return $value !== '';
        })));
        if (!$selected) {
            return array();
        }

        $partner = is_array($enrichment['partner'] ?? null) ? $enrichment['partner'] : null;
        $partnerId = (int) ($partner['id'] ?? 0);
        if ($partnerId <= 0) {
            throw new Exception('Partner enrichment has no valid Dolibarr third-party id.');
        }

        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
        $societe = new Societe($this->db);
        if ($societe->fetch($partnerId) <= 0) {
            throw new Exception('The matched Dolibarr third party could not be loaded.');
        }
        if (isset($societe->entity) && (int) $societe->entity !== $this->entity) {
            throw new Exception('The matched Dolibarr third party belongs to another entity.');
        }

        $itemsByField = array();
        foreach ($this->selectableItems($enrichment) as $item) {
            $field = (string) ($item['field'] ?? '');
            if ($field !== '' && !empty($item['selectable'])) {
                $itemsByField[$field] = $item;
            }
        }

        $applied = array();
        foreach ($selected as $field) {
            if (!isset($itemsByField[$field])) {
                continue;
            }
            $item = $itemsByField[$field];
            $value = (string) ($item['proposed'] ?? '');

            switch ($field) {
                case 'tva_intra':
                    $societe->tva_intra = $value;
                    $applied[] = $field;
                    break;

                case 'address':
                    $societe->address = $value;
                    $applied[] = $field;
                    break;

                case 'zip':
                    $societe->zip = $value;
                    $applied[] = $field;
                    break;

                case 'town':
                    $societe->town = $value;
                    $applied[] = $field;
                    break;

                case 'fk_pays':
                    $countryId = (int) ($item['proposed_id'] ?? 0);
                    if ($countryId > 0) {
                        $societe->country_id = $countryId;
                        $applied[] = $field;
                    }
                    break;

                case 'fournisseur':
                    $societe->fournisseur = 1;
                    $applied[] = $field;
                    break;

                case 'client':
                    $client = (int) $value;
                    if (in_array($client, array(1, 3), true)) {
                        $societe->client = $client;
                        $applied[] = $field;
                    }
                    break;
            }
        }

        $applied = array_values(array_unique($applied));
        if (!$applied) {
            return array();
        }

        // Use Dolibarr's business object so validation and COMPANY_MODIFY
        // triggers are preserved. Enabling a customer/supplier role therefore
        // also respects the site's code/accounting validation rules.
        $allowClientCode = in_array('client', $applied, true) ? 1 : 0;
        $allowSupplierCode = in_array('fournisseur', $applied, true) ? 1 : 0;
        $result = $societe->update($partnerId, $user, 1, $allowClientCode, $allowSupplierCode, 'update', 1);
        if ($result < 0) {
            $parts = array();
            if (!empty($societe->error)) {
                $parts[] = (string) $societe->error;
            }
            if (!empty($societe->errors) && is_array($societe->errors)) {
                $parts = array_merge($parts, array_map('strval', $societe->errors));
            }
            throw new Exception('Dolibarr third-party update failed'.($parts ? ': '.implode('; ', $parts) : '.'));
        }

        return $applied;
    }

    /**
     * @param array<string,mixed> $item
     * @return array{selectable:bool,default_selected:bool}
     */
    private function selectionMeta(array $item, bool $strongMatch): array
    {
        if (!$strongMatch) {
            return array('selectable' => false, 'default_selected' => false);
        }

        $field = (string) ($item['field'] ?? '');
        $status = (string) ($item['status'] ?? '');

        // Bank accounts are separate Dolibarr objects and remain review-only.
        if ($field === 'bank_account' || $status === 'separate_review') {
            return array('selectable' => false, 'default_selected' => false);
        }

        // A different taxpayer core is a partner-identification problem, not a
        // value that should ever be overwritten from this convenience UI.
        if ($field === 'tva_intra' && $status === 'different'
            && !$this->sameHungarianTaxpayerCore((string) ($item['current'] ?? ''), (string) ($item['proposed'] ?? ''))) {
            return array('selectable' => false, 'default_selected' => false);
        }

        $allowed = array('tva_intra', 'address', 'zip', 'town', 'fk_pays', 'fournisseur', 'client');
        if (!in_array($field, $allowed, true)) {
            return array('selectable' => false, 'default_selected' => false);
        }

        // Preserve the old conservative defaults. Roles and conflicting
        // existing values require an explicit user choice.
        $safeDefaultFields = array('tva_intra', 'address', 'zip', 'town', 'fk_pays');
        $defaultSelected = $status === 'missing'
            && !empty($item['safe'])
            && in_array($field, $safeDefaultFields, true);

        return array('selectable' => true, 'default_selected' => $defaultSelected);
    }

    private function sameHungarianTaxpayerCore(string $left, string $right): bool
    {
        $leftDigits = (string) preg_replace('/\D+/', '', $left);
        $rightDigits = (string) preg_replace('/\D+/', '', $right);
        if (strlen($leftDigits) < 8 || strlen($rightDigits) < 8) {
            return false;
        }
        return substr($leftDigits, 0, 8) === substr($rightDigits, 0, 8);
    }
}
