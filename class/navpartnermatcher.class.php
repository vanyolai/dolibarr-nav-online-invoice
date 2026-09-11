<?php

/**
 * Read-only matcher between a NAV invoice party and Dolibarr third parties.
 *
 * Matching rules are intentionally conservative: a different stored tax
 * number always disqualifies a name-based match. No Dolibarr data is changed
 * by this class.
 */
class NavPartnerMatcher
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
     * @param array<string,mixed> $party Parsed NAV supplier/customer data.
     * @param string $expectedRole supplier|customer
     * @return array<string,mixed>
     */
    public function match(array $party, string $expectedRole): array
    {
        $navTax = $this->normalizeTaxNumber((string) ($party['tax_number'] ?? ''));
        $navName = $this->normalizeCompanyName((string) ($party['name'] ?? ''));
        $navZip = $this->normalizeText((string) ($party['address']['postal_code'] ?? ''));
        $navTown = $this->normalizeText((string) ($party['address']['city'] ?? ''));

        if ($navTax === '' && $navName === '') {
            return $this->emptyResult('unavailable');
        }

        $sql = 'SELECT rowid, nom, tva_intra, address, zip, town, client, fournisseur, status';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'societe';
        $sql .= ' WHERE entity = '.$this->entity;
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception($this->db->lasterror());
        }

        $candidates = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $candidateTax = $this->normalizeTaxNumber((string) $obj->tva_intra);

            // A known but different tax number is stronger evidence than a similar name.
            if ($navTax !== '' && $candidateTax !== '' && $navTax !== $candidateTax) {
                continue;
            }

            $candidateName = $this->normalizeCompanyName((string) $obj->nom);
            $score = 0;
            $reasons = array();
            $nameSimilarity = null;
            $exactName = false;
            $locationMatch = false;

            if ($navTax !== '' && $candidateTax !== '' && $navTax === $candidateTax) {
                $score = 100;
                $reasons[] = 'tax_number';
            } elseif ($navName !== '' && $candidateName !== '') {
                if ($navName === $candidateName) {
                    $score = 82;
                    $exactName = true;
                    $nameSimilarity = 100.0;
                    $reasons[] = 'name_exact';
                } elseif (strlen($navName) >= 5 && strlen($candidateName) >= 5) {
                    similar_text($navName, $candidateName, $nameSimilarity);
                    if ($nameSimilarity >= 94.0) {
                        $score = 74;
                        $reasons[] = 'name_similar';
                    } elseif ($nameSimilarity >= 88.0) {
                        $score = 64;
                        $reasons[] = 'name_similar';
                    } else {
                        continue;
                    }
                } else {
                    continue;
                }
            } else {
                continue;
            }

            if ($score < 100) {
                $candidateZip = $this->normalizeText((string) $obj->zip);
                $candidateTown = $this->normalizeText((string) $obj->town);

                if ($navZip !== '' && $candidateZip !== '' && $navZip === $candidateZip) {
                    $score += 8;
                    $locationMatch = true;
                    $reasons[] = 'zip';
                }
                if ($navTown !== '' && $candidateTown !== '' && $navTown === $candidateTown) {
                    $score += 7;
                    $locationMatch = true;
                    $reasons[] = 'town';
                }

                if ($expectedRole === 'supplier' && !empty($obj->fournisseur)) {
                    $score += 3;
                    $reasons[] = 'role';
                } elseif ($expectedRole === 'customer' && in_array((int) $obj->client, array(1, 3), true)) {
                    $score += 3;
                    $reasons[] = 'role';
                }
            }

            $candidates[] = array(
                'id' => (int) $obj->rowid,
                'name' => (string) $obj->nom,
                'tax_number' => (string) $obj->tva_intra,
                'address' => (string) $obj->address,
                'zip' => (string) $obj->zip,
                'town' => (string) $obj->town,
                'client' => (int) $obj->client,
                'supplier' => (int) $obj->fournisseur,
                'status' => (int) $obj->status,
                'score' => min(100, $score),
                'reasons' => $reasons,
                'name_similarity' => $nameSimilarity,
                'exact_name' => $exactName,
                'location_match' => $locationMatch,
                'tax_number_missing' => $candidateTax === '',
            );
        }
        $this->db->free($resql);

        usort($candidates, static function (array $a, array $b): int {
            if ($a['score'] === $b['score']) {
                return strcmp($a['name'], $b['name']);
            }
            return $b['score'] <=> $a['score'];
        });

        if (!$candidates) {
            return $this->emptyResult('none');
        }

        $top = $candidates[0];
        $secondScore = isset($candidates[1]) ? (int) $candidates[1]['score'] : -1;
        $gap = (int) $top['score'] - $secondScore;

        if (in_array('tax_number', $top['reasons'], true)) {
            $sameTaxCount = 0;
            foreach ($candidates as $candidate) {
                if (in_array('tax_number', $candidate['reasons'], true)) {
                    $sameTaxCount++;
                }
            }
            if ($sameTaxCount > 1) {
                return $this->result('ambiguous', null, array_slice($candidates, 0, 5), $navTax);
            }
            return $this->result('tax', $top, $candidates, $navTax);
        }

        if ($secondScore >= 0 && $gap < 5) {
            return $this->result('ambiguous', null, array_slice($candidates, 0, 5), $navTax);
        }

        if ((int) $top['score'] >= 90 && $top['exact_name'] && $top['location_match']) {
            return $this->result('name_address', $top, $candidates, $navTax);
        }

        if ((int) $top['score'] >= 82 && $top['exact_name']) {
            return $this->result('name', $top, $candidates, $navTax);
        }

        if ((int) $top['score'] >= 72) {
            return $this->result('candidate', $top, $candidates, $navTax);
        }

        return $this->emptyResult('none', array_slice($candidates, 0, 5));
    }

    /**
     * @param array<string,mixed>|null $match
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function result(string $status, ?array $match, array $candidates, string $navTax): array
    {
        $canFillTax = false;
        if ($match !== null && $navTax !== '' && !empty($match['tax_number_missing'])) {
            // Tax completion is only suggested for an exact normalized name that is
            // also reinforced by NAV address data. The UI remains read-only for now.
            $canFillTax = !empty($match['exact_name']) && !empty($match['location_match']);
        }

        return array(
            'status' => $status,
            'match' => $match,
            'candidates' => array_slice($candidates, 0, 5),
            'nav_tax_number' => $navTax,
            'can_fill_tax_number' => $canFillTax,
        );
    }

    /**
     * @param array<int,array<string,mixed>> $candidates
     * @return array<string,mixed>
     */
    private function emptyResult(string $status, array $candidates = array()): array
    {
        return array(
            'status' => $status,
            'match' => null,
            'candidates' => $candidates,
            'nav_tax_number' => '',
            'can_fill_tax_number' => false,
        );
    }

    private function normalizeTaxNumber(string $value): string
    {
        $digits = preg_replace('/[^0-9]+/', '', strtoupper($value));
        if ($digits === null || strlen($digits) < 8) {
            return '';
        }
        return substr($digits, 0, 8);
    }

    private function normalizeCompanyName(string $value): string
    {
        $value = $this->normalizeText($value);
        if ($value === '') {
            return '';
        }

        // Legal-form differences should not prevent matching the same company.
        $value = preg_replace('/\b(korlatolt felelossegu tarsasag|zartkoruen mukodo reszvenytarsasag|nyilvanosan mukodo reszvenytarsasag|beteti tarsasag|kozkereseti tarsasag|egyeni vallalkozo)\b/u', ' ', $value);
        $value = preg_replace('/\b(kft|zrt|nyrt|bt|kkt|ev)\b/u', ' ', (string) $value);
        $value = preg_replace('/[^a-z0-9]+/', '', (string) $value);

        return (string) $value;
    }

    private function normalizeText(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        $value = strtr($value, array(
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ö' => 'o', 'ő' => 'o',
            'ú' => 'u', 'ü' => 'u', 'ű' => 'u',
        ));
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
        $value = preg_replace('/\s+/u', ' ', (string) $value);

        return trim((string) $value);
    }
}
