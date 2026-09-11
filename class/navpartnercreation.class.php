<?php

dol_include_once('/navinvoice/class/navtaxpayer.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');

/**
 * Build and execute controlled Dolibarr third-party creation from fresh NAV
 * taxpayer master data.
 *
 * The invoice party is used only to identify the taxpayer and to show
 * historical/current differences. Values written to Dolibarr come from a
 * fresh queryTaxpayer response, never from POST fields or stale page data.
 */
class NavPartnerCreationService
{
    /** @var DoliDB */
    private $db;

    /** @var int */
    private $entity;

    /** @var NavTaxpayerService */
    private $taxpayerService;

    /** @var NavPartnerMatcher */
    private $matcher;

    public function __construct($db, int $entity, ?NavTaxpayerService $taxpayerService = null)
    {
        $this->db = $db;
        $this->entity = $entity;
        $this->taxpayerService = $taxpayerService ?: new NavTaxpayerService();
        $this->matcher = new NavPartnerMatcher($db, $entity);
    }

    /**
     * @param array<string,mixed> $invoiceParty Parsed NAV invoice party.
     * @param string $expectedRole supplier|customer
     * @return array<string,mixed>
     */
    public function build(array $invoiceParty, string $expectedRole): array
    {
        $expectedRole = $this->normalizeRole($expectedRole);
        $taxCore = $this->normalizeTaxNumber((string) ($invoiceParty['tax_number'] ?? ''));
        if ($taxCore === '') {
            return $this->unavailable('tax_number_missing', $expectedRole);
        }

        $master = $this->taxpayerService->lookup($taxCore);
        $blockers = array();
        if (empty($master['valid'])) {
            $blockers[] = 'taxpayer_invalid';
        }
        if (trim((string) ($master['name'] ?? '')) === '') {
            $blockers[] = 'taxpayer_data_missing';
        }

        $masterParty = $this->taxpayerService->toParty($master);
        $match = $this->matcher->match($masterParty, $expectedRole);
        $matchStatus = (string) ($match['status'] ?? 'none');
        if ($matchStatus !== 'none') {
            $blockers[] = $matchStatus === 'ambiguous' ? 'partner_ambiguous' : 'partner_candidate_exists';
        }

        $address = is_array($master['primary_address'] ?? null) ? $master['primary_address'] : null;
        $country = null;
        if ($address === null) {
            $blockers[] = 'primary_address_missing';
        } else {
            $countryCode = strtoupper(trim((string) ($address['country_code'] ?? '')));
            if ($countryCode === '') {
                $blockers[] = 'country_missing';
            } else {
                $country = $this->findCountry($countryCode);
                if ($country === null) {
                    $blockers[] = 'country_unknown';
                }
            }
        }

        return array(
            'available' => true,
            'expected_role' => $expectedRole,
            'tax_number' => $taxCore,
            'master' => $master,
            'master_party' => $masterParty,
            'match' => $match,
            'country' => $country,
            'differences' => $this->compareInvoiceToMaster($invoiceParty, $masterParty),
            'blockers' => array_values(array_unique($blockers)),
            'can_create' => !$blockers,
        );
    }

    /**
     * Perform creation after rebuilding the preview from live NAV and current
     * Dolibarr state. No master-data values are accepted from the browser.
     *
     * @param array<string,mixed> $invoiceParty
     * @param string $expectedRole supplier|customer
     * @param User $user
     * @return array<string,mixed>
     */
    public function create(array $invoiceParty, string $expectedRole, User $user): array
    {
        $preview = $this->build($invoiceParty, $expectedRole);
        if (empty($preview['can_create'])) {
            $blockers = array_map('strval', $preview['blockers'] ?? array());
            throw new Exception('NAV third-party creation is blocked'.($blockers ? ': '.implode(', ', $blockers) : '.'));
        }

        $master = is_array($preview['master'] ?? null) ? $preview['master'] : array();
        $address = is_array($master['primary_address'] ?? null) ? $master['primary_address'] : array();
        $country = is_array($preview['country'] ?? null) ? $preview['country'] : null;
        if ($country === null || empty($country['rowid'])) {
            throw new Exception('NAV third-party creation has no resolved Dolibarr country.');
        }

        // Re-run matching immediately before create to reduce the chance of a
        // duplicate if another request created the company after the preview.
        $freshMatch = $this->matcher->match($this->taxpayerService->toParty($master), (string) $preview['expected_role']);
        if ((string) ($freshMatch['status'] ?? 'none') !== 'none') {
            throw new Exception('A matching or potentially duplicate Dolibarr third party now exists.');
        }

        require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
        $societe = new Societe($this->db);
        $fullName = trim((string) ($master['name'] ?? ''));
        $shortName = trim((string) ($master['short_name'] ?? ''));
        $societe->name = $this->limit($shortName !== '' ? $shortName : $fullName, 128);
        if ($societe->name === '') {
            throw new Exception('NAV taxpayer master data contains no usable company name.');
        }
        if ($fullName !== '' && $this->normalize($fullName) !== $this->normalize($societe->name)) {
            $societe->name_alias = $this->limit($fullName, 128);
        }

        $societe->entity = $this->entity;
        $societe->tva_intra = trim((string) ($master['full_tax_number'] ?? $master['tax_number'] ?? ''));
        $societe->address = $this->limit($this->streetAddress($address), 255);
        $societe->zip = $this->limit(trim((string) ($address['postal_code'] ?? '')), 25);
        $societe->town = $this->limit(trim((string) ($address['city'] ?? '')), 50);
        $societe->country_id = (int) $country['rowid'];
        $societe->status = 1;
        $societe->client = (string) $preview['expected_role'] === 'customer' ? 1 : 0;
        $societe->fournisseur = (string) $preview['expected_role'] === 'supplier' ? 1 : 0;

        // Societe::create() owns its transaction and calls verify()/update() and
        // COMPANY_CREATE itself. Request automatic customer/supplier code
        // generation so installations with mandatory third-party codes work the
        // same way as the standard Dolibarr creation workflow.
        if ($societe->client) {
            $societe->code_client = 'auto';
        }
        if ($societe->fournisseur) {
            $societe->code_fournisseur = 'auto';
        }

        $id = $societe->create($user);
        if ($id <= 0) {
            $errors = array();
            if (!empty($societe->error)) {
                $errors[] = (string) $societe->error;
            }
            if (!empty($societe->errors) && is_array($societe->errors)) {
                $errors = array_merge($errors, array_map('strval', $societe->errors));
            }
            throw new Exception('Dolibarr third-party creation failed'.($errors ? ': '.implode('; ', $errors) : '.'));
        }

        return array(
            'id' => (int) $id,
            'name' => (string) $societe->name,
            'tax_number' => (string) $societe->tva_intra,
            'url' => DOL_URL_ROOT.'/societe/card.php?socid='.(int) $id,
            'preview' => $preview,
        );
    }

    /**
     * @param array<string,mixed> $invoiceParty
     * @param array<string,mixed> $masterParty
     * @return array<int,array<string,mixed>>
     */
    private function compareInvoiceToMaster(array $invoiceParty, array $masterParty): array
    {
        $invoiceAddress = is_array($invoiceParty['address'] ?? null) ? $invoiceParty['address'] : array();
        $masterAddress = is_array($masterParty['address'] ?? null) ? $masterParty['address'] : array();
        $pairs = array(
            'name' => array((string) ($invoiceParty['name'] ?? ''), (string) ($masterParty['name'] ?? '')),
            'tax_number' => array($this->fullTaxNumber($invoiceParty), $this->fullTaxNumber($masterParty)),
            'address' => array($this->streetAddress($invoiceAddress), $this->streetAddress($masterAddress)),
            'zip' => array((string) ($invoiceAddress['postal_code'] ?? ''), (string) ($masterAddress['postal_code'] ?? '')),
            'town' => array((string) ($invoiceAddress['city'] ?? ''), (string) ($masterAddress['city'] ?? '')),
            'country' => array((string) ($invoiceAddress['country_code'] ?? ''), (string) ($masterAddress['country_code'] ?? '')),
        );

        $items = array();
        foreach ($pairs as $field => $values) {
            $invoice = trim((string) $values[0]);
            $current = trim((string) $values[1]);
            if ($invoice === '' && $current === '') {
                continue;
            }
            $items[] = array(
                'field' => $field,
                'invoice' => $invoice,
                'current' => $current,
                'same' => $this->normalize($invoice) === $this->normalize($current),
            );
        }
        return $items;
    }

    /** @return array<string,mixed>|null */
    private function findCountry(string $code): ?array
    {
        $sql = 'SELECT rowid, code, label FROM '.MAIN_DB_PREFIX.'c_country';
        $sql .= " WHERE UPPER(code) = '".$this->db->escape(strtoupper($code))."'";
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

    /** @param array<string,mixed> $party */
    private function fullTaxNumber(array $party): string
    {
        $tax = trim((string) ($party['tax_number'] ?? ''));
        if ($tax === '') {
            return '';
        }
        $vatCode = trim((string) ($party['vat_code'] ?? ''));
        $countyCode = trim((string) ($party['county_code'] ?? ''));
        return $vatCode !== '' && $countyCode !== '' ? $tax.'-'.$vatCode.'-'.$countyCode : $tax;
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

        return trim($street.($extras ? ', '.implode(', ', $extras) : ''));
    }

    private function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        if (!in_array($role, array('supplier', 'customer'), true)) {
            throw new InvalidArgumentException('Partner role must be supplier or customer.');
        }
        return $role;
    }

    private function normalizeTaxNumber(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value);
        if ($digits === null || strlen($digits) < 8) {
            return '';
        }
        return substr($digits, 0, 8);
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = strtr($value, array(
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        ));
        $value = preg_replace('/\s+/u', ' ', $value);
        return trim((string) $value);
    }

    private function limit(string $value, int $length): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $length, 'UTF-8') : substr($value, 0, $length);
    }

    /** @return array<string,mixed> */
    private function unavailable(string $reason, string $role): array
    {
        return array(
            'available' => false,
            'expected_role' => $role,
            'tax_number' => '',
            'master' => null,
            'master_party' => null,
            'match' => null,
            'country' => null,
            'differences' => array(),
            'blockers' => array($reason),
            'can_create' => false,
        );
    }
}
