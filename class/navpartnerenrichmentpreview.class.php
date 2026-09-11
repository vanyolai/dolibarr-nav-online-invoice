<?php

/**
 * Build a read-only preview of partner data that NAV invoice data could add
 * to an already matched Dolibarr third party.
 *
 * Existing non-empty Dolibarr values are never proposed for automatic
 * replacement. Differences are surfaced for review only.
 */
class NavPartnerEnrichmentPreview
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
     * @param array<string,mixed> $party Parsed NAV supplier/customer.
     * @param array<string,mixed>|null $partnerMatch Result from NavPartnerMatcher.
     * @param string $expectedRole supplier|customer
     * @return array<string,mixed>
     */
    public function build(array $party, ?array $partnerMatch, string $expectedRole): array
    {
        $matched = is_array($partnerMatch['match'] ?? null) ? $partnerMatch['match'] : null;
        if ($matched === null || empty($matched['id'])) {
            return array('available' => false, 'strong_match' => false, 'partner' => null, 'items' => array());
        }

        $partner = $this->fetchPartner((int) $matched['id']);
        if ($partner === null) {
            return array('available' => false, 'strong_match' => false, 'partner' => null, 'items' => array());
        }

        $matchStatus = (string) ($partnerMatch['status'] ?? 'none');
        $strongMatch = in_array($matchStatus, array('tax', 'name_address'), true);
        $items = array();

        $navTax = $this->fullTaxNumber($party);
        $this->compareTaxNumber($items, (string) $partner['tva_intra'], $navTax, $strongMatch);

        $address = is_array($party['address'] ?? null) ? $party['address'] : array();
        $navStreet = $this->streetAddress($address);
        $this->compareField($items, 'address', 'Address', (string) $partner['address'], $navStreet, $strongMatch);
        $this->compareField($items, 'zip', 'Zip', (string) $partner['zip'], (string) ($address['postal_code'] ?? ''), $strongMatch);
        $this->compareField($items, 'town', 'Town', (string) $partner['town'], (string) ($address['city'] ?? ''), $strongMatch);

        $navCountryCode = strtoupper(trim((string) ($address['country_code'] ?? '')));
        if ($navCountryCode !== '') {
            $country = $this->findCountry($navCountryCode);
            if ($country !== null) {
                $currentCountry = (string) ($partner['country_code'] ?? '');
                if (empty($partner['fk_pays'])) {
                    $items[] = array(
                        'field' => 'fk_pays',
                        'label' => 'Country',
                        'current' => '',
                        'proposed' => (string) $country['label'].' ('.$navCountryCode.')',
                        'proposed_id' => (int) $country['rowid'],
                        'status' => 'missing',
                        'safe' => $strongMatch,
                    );
                } elseif ($currentCountry !== '' && strtoupper($currentCountry) !== $navCountryCode) {
                    $items[] = array(
                        'field' => 'fk_pays',
                        'label' => 'Country',
                        'current' => (string) ($partner['country_label'] ?: $currentCountry),
                        'proposed' => (string) $country['label'].' ('.$navCountryCode.')',
                        'proposed_id' => (int) $country['rowid'],
                        'status' => 'different',
                        'safe' => false,
                    );
                }
            }
        }

        if ($expectedRole === 'supplier' && empty($partner['fournisseur'])) {
            $items[] = array(
                'field' => 'fournisseur',
                'label' => 'SupplierRole',
                'current' => '0',
                'proposed' => '1',
                'status' => 'role_missing',
                'safe' => $strongMatch,
            );
        } elseif ($expectedRole === 'customer') {
            $client = (int) $partner['client'];
            if ($client === 0 || $client === 2) {
                $items[] = array(
                    'field' => 'client',
                    'label' => 'CustomerRole',
                    'current' => (string) $client,
                    'proposed' => (string) ($client === 2 ? 3 : 1),
                    'status' => 'role_missing',
                    'safe' => $strongMatch,
                );
            }
        }

        $bankAccount = trim((string) ($party['bank_account'] ?? ''));
        if ($bankAccount !== '') {
            $items[] = array(
                'field' => 'bank_account',
                'label' => 'BankAccount',
                'current' => '',
                'proposed' => $bankAccount,
                'status' => 'separate_review',
                'safe' => false,
            );
        }

        return array(
            'available' => true,
            'strong_match' => $strongMatch,
            'match_status' => $matchStatus,
            'partner' => $partner,
            'items' => $items,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function fetchPartner(int $id): ?array
    {
        $sql = 'SELECT s.rowid, s.nom, s.tva_intra, s.address, s.zip, s.town, s.fk_pays, s.client, s.fournisseur,';
        $sql .= ' c.code AS country_code, c.label AS country_label';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'societe AS s';
        $sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_country AS c ON c.rowid = s.fk_pays';
        $sql .= ' WHERE s.rowid = '.$id.' AND s.entity = '.$this->entity;

        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }

        return array(
            'id' => (int) $obj->rowid,
            'name' => (string) $obj->nom,
            'tva_intra' => (string) $obj->tva_intra,
            'address' => (string) $obj->address,
            'zip' => (string) $obj->zip,
            'town' => (string) $obj->town,
            'fk_pays' => (int) $obj->fk_pays,
            'country_code' => (string) $obj->country_code,
            'country_label' => (string) $obj->country_label,
            'client' => (int) $obj->client,
            'fournisseur' => (int) $obj->fournisseur,
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findCountry(string $code): ?array
    {
        $sql = 'SELECT rowid, code, label FROM '.MAIN_DB_PREFIX.'c_country';
        $sql .= " WHERE UPPER(code) = '".$this->db->escape($code)."'";
        $sql .= ' LIMIT 1';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }
        $obj = $this->db->fetch_object($resql);
        $this->db->free($resql);
        if (!$obj) {
            return null;
        }
        return array('rowid' => (int) $obj->rowid, 'code' => (string) $obj->code, 'label' => (string) $obj->label);
    }

    /**
     * Compare Hungarian tax numbers by taxpayer core first.
     *
     * The first 8 digits identify the taxpayer. A stored 8-digit value and a
     * full NAV tax number therefore describe the same taxpayer and the latter
     * may safely complete the former after a strong partner match. If both
     * sides contain suffix digits and those differ, keep the discrepancy for
     * review because an invoice can contain historical tax-status data.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function compareTaxNumber(array &$items, string $current, string $proposed, bool $strongMatch): void
    {
        $current = trim($current);
        $proposed = trim($proposed);
        if ($proposed === '') {
            return;
        }

        if ($current === '') {
            $items[] = array(
                'field' => 'tva_intra',
                'label' => 'TaxNumber',
                'current' => '',
                'proposed' => $proposed,
                'status' => 'missing',
                'safe' => $strongMatch,
            );
            return;
        }

        $currentDigits = (string) preg_replace('/\D+/', '', $current);
        $proposedDigits = (string) preg_replace('/\D+/', '', $proposed);
        $currentCore = strlen($currentDigits) >= 8 ? substr($currentDigits, 0, 8) : '';
        $proposedCore = strlen($proposedDigits) >= 8 ? substr($proposedDigits, 0, 8) : '';

        if ($currentCore !== '' && $proposedCore !== '' && $currentCore === $proposedCore) {
            // Exact value or Dolibarr already contains at least as much tax data.
            if ($currentDigits === $proposedDigits || strlen($currentDigits) > 8 && strlen($proposedDigits) === 8) {
                return;
            }

            // Dolibarr only stores the taxpayer core; NAV has the complete form.
            if (strlen($currentDigits) === 8 && strlen($proposedDigits) > 8) {
                $items[] = array(
                    'field' => 'tva_intra',
                    'label' => 'TaxNumber',
                    'current' => $current,
                    'proposed' => $proposed,
                    'status' => 'missing',
                    'safe' => $strongMatch,
                );
                return;
            }

            // Same taxpayer core but different suffix: keep it visible for review.
            $items[] = array(
                'field' => 'tva_intra',
                'label' => 'TaxNumber',
                'current' => $current,
                'proposed' => $proposed,
                'status' => 'different',
                'safe' => false,
            );
            return;
        }

        if ($this->normalize($current) !== $this->normalize($proposed)) {
            $items[] = array(
                'field' => 'tva_intra',
                'label' => 'TaxNumber',
                'current' => $current,
                'proposed' => $proposed,
                'status' => 'different',
                'safe' => false,
            );
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function compareField(array &$items, string $field, string $label, string $current, string $proposed, bool $strongMatch): void
    {
        $current = trim($current);
        $proposed = trim($proposed);
        if ($proposed === '') {
            return;
        }

        if ($current === '') {
            $items[] = array(
                'field' => $field,
                'label' => $label,
                'current' => '',
                'proposed' => $proposed,
                'status' => 'missing',
                'safe' => $strongMatch,
            );
            return;
        }

        if ($this->normalize($current) !== $this->normalize($proposed)) {
            $items[] = array(
                'field' => $field,
                'label' => $label,
                'current' => $current,
                'proposed' => $proposed,
                'status' => 'different',
                'safe' => false,
            );
        }
    }

    /** @param array<string,mixed> $party */
    private function fullTaxNumber(array $party): string
    {
        $tax = trim((string) ($party['tax_number'] ?? ''));
        if ($tax === '') {
            return '';
        }
        $vatCode = trim((string) ($party['vat_code'] ?? ''));
        $countyCode = trim((string) ($party['county_code'] ?? ''));
        if ($vatCode !== '' && $countyCode !== '') {
            return $tax.'-'.$vatCode.'-'.$countyCode;
        }
        return $tax;
    }

    /** @param array<string,mixed> $address */
    private function streetAddress(array $address): string
    {
        $street = trim(implode(' ', array_filter(array(
            trim((string) ($address['street_name'] ?? '')),
            trim((string) ($address['public_place_category'] ?? '')),
            trim((string) ($address['number'] ?? '')),
        ), static function (string $value): bool {
            return $value !== '';
        })));

        $extras = array();
        foreach (array('building', 'staircase', 'floor', 'door', 'lot_number', 'additional_detail') as $key) {
            $value = trim((string) ($address[$key] ?? ''));
            if ($value !== '') {
                $extras[] = $value;
            }
        }

        if ($street === '') {
            return implode(', ', $extras);
        }
        return trim($street.($extras ? ', '.implode(', ', $extras) : ''));
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }
}
