<?php

/**
 * Apply safe, missing partner master data proposed by NavPartnerEnrichmentPreview.
 *
 * This class deliberately does not overwrite conflicting non-empty values and
 * does not touch bank accounts or customer/supplier roles. Those changes need
 * separate business decisions and may have additional Dolibarr side effects.
 */
class NavPartnerEnricher
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
     * @param array<string,mixed> $enrichment Fresh result from NavPartnerEnrichmentPreview::build().
     * @param User $user Current Dolibarr user.
     * @return array<int,string> Names of fields that were applied.
     */
    public function apply(array $enrichment, User $user): array
    {
        if (empty($enrichment['available']) || empty($enrichment['strong_match'])) {
            throw new Exception('Partner enrichment requires a strong, unambiguous partner match.');
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

        $applied = array();
        foreach (($enrichment['items'] ?? array()) as $item) {
            if (empty($item['safe']) || (string) ($item['status'] ?? '') !== 'missing') {
                continue;
            }

            $field = (string) ($item['field'] ?? '');
            $value = (string) ($item['proposed'] ?? '');
            switch ($field) {
                case 'tva_intra':
                    // The preview only marks this safe when the value is absent,
                    // or when an 8-digit Hungarian taxpayer core can be completed
                    // by the NAV full tax number for the same taxpayer.
                    $societe->tva_intra = $value;
                    $applied[] = $field;
                    break;

                case 'address':
                    if (trim((string) $societe->address) === '') {
                        $societe->address = $value;
                        $applied[] = $field;
                    }
                    break;

                case 'zip':
                    if (trim((string) $societe->zip) === '') {
                        $societe->zip = $value;
                        $applied[] = $field;
                    }
                    break;

                case 'town':
                    if (trim((string) $societe->town) === '') {
                        $societe->town = $value;
                        $applied[] = $field;
                    }
                    break;

                case 'fk_pays':
                    $countryId = (int) ($item['proposed_id'] ?? 0);
                    if (empty($societe->country_id) && $countryId > 0) {
                        $societe->country_id = $countryId;
                        $applied[] = $field;
                    }
                    break;
            }
        }

        if (!$applied) {
            return array();
        }

        // Use the Dolibarr business object instead of direct SQL so standard
        // validation and COMPANY_MODIFY triggers remain in effect.
        $result = $societe->update($partnerId, $user, 1, 0, 0, 'update', 1);
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

        return array_values(array_unique($applied));
    }
}
