<?php

/**
 * Resolve NAV invoice units to active Dolibarr c_units entries.
 *
 * The resolver intentionally prefers stable dictionary codes over translated
 * labels. Custom NAV OWN units may be matched against known business aliases
 * or an exact Dolibarr code, short label or literal label, but ambiguous
 * matches are never selected.
 */
class NavUnitResolver
{
    /** @var DoliDB */
    private $db;

    /** @var bool */
    private $enabled;

    /** @var array<int,array<string,mixed>>|null */
    private $units = null;

    public function __construct($db)
    {
        $this->db = $db;
        $this->enabled = (bool) getDolGlobalInt('PRODUCT_USE_UNITS');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @param array<string,mixed> $line Parsed NAV invoice line.
     * @return array<string,mixed>
     */
    public function resolve(array $line): array
    {
        $navUnit = strtoupper(trim((string) ($line['unit'] ?? '')));
        $ownUnit = trim((string) ($line['unit_own'] ?? ''));
        $source = $ownUnit !== '' ? $ownUnit : $navUnit;

        $result = array(
            'enabled' => $this->enabled,
            'source' => $source,
            'nav_unit' => $navUnit,
            'own_unit' => $ownUnit,
            'id' => 0,
            'code' => '',
            'short_label' => '',
            'label' => '',
            'status' => $this->enabled ? 'unresolved' : 'disabled',
        );

        if (!$this->enabled || $source === '') {
            if ($this->enabled && $source === '') {
                $result['status'] = 'empty';
            }
            return $result;
        }

        // NAV standard unit enum -> stable Dolibarr dictionary code and type.
        // Constraining by unit_type avoids rejecting a canonical dictionary
        // entry merely because an installation contains a custom unit reusing
        // the same code in another dimension. LINEAR_METER is dimensionally
        // the same core Dolibarr unit as METER.
        $codeMap = array(
            'PIECE' => array('P', 'qty'),
            'KILOGRAM' => array('KG', 'weight'),
            'TON' => array('T', 'weight'),
            'KWH' => array('KWH', ''),
            'DAY' => array('D', 'time'),
            'HOUR' => array('H', 'time'),
            'MINUTE' => array('MI', 'time'),
            'MONTH' => array('MO', 'time'),
            'LITER' => array('L', 'volume'),
            'KILOMETER' => array('KM', 'distance'),
            'CUBIC_METER' => array('M3', 'volume'),
            'METER' => array('M', 'size'),
            'LINEAR_METER' => array('M', 'size'),
        );

        // Some issuers legally send unitOfMeasure=OWN but put one of NAV's
        // standard enum names into unitOfMeasureOwn (for example
        // "LINEAR_METER "). Treat such values as aliases of the standard enum,
        // otherwise literal matching would look for a Dolibarr unit actually
        // named LINEAR_METER instead of mapping it to M / size.
        $mappedNavUnit = $navUnit;
        if ($navUnit === 'OWN' && $ownUnit !== '') {
            $ownEnum = strtoupper(trim($ownUnit));
            if (isset($codeMap[$ownEnum])) {
                $mappedNavUnit = $ownEnum;
            }
        }

        if (isset($codeMap[$mappedNavUnit])) {
            $match = $this->findUniqueByCode($codeMap[$mappedNavUnit][0], $codeMap[$mappedNavUnit][1]);
            if ($match !== null) {
                return $this->resolvedResult($result, $match, $navUnit === 'OWN' ? 'own_standard_code' : 'code');
            }

            // Some installations use a custom code while retaining the
            // international short symbol. Only use symbols that are language
            // independent and constrain by unit type where useful.
            $symbolMap = array(
                'KILOGRAM' => array('kg', 'weight'),
                'TON' => array('t', 'weight'),
                'KWH' => array('kwh', ''),
                'DAY' => array('d', 'time'),
                'HOUR' => array('h', 'time'),
                'MINUTE' => array('min', 'time'),
                'LITER' => array('l', 'volume'),
                'KILOMETER' => array('km', 'distance'),
                'CUBIC_METER' => array('m3', 'volume'),
                'METER' => array('m', 'size'),
                'LINEAR_METER' => array('m', 'size'),
            );
            if (isset($symbolMap[$mappedNavUnit])) {
                $match = $this->findUniqueByShortLabel($symbolMap[$mappedNavUnit][0], $symbolMap[$mappedNavUnit][1]);
                if ($match !== null) {
                    return $this->resolvedResult($result, $match, $navUnit === 'OWN' ? 'own_standard_symbol' : 'symbol');
                }
            }
        }

        // Hungarian and common invoice software frequently uses OWN even for
        // conventional units (for example OWN/m or OWN/db). Resolve these
        // known aliases to the installation's stable Dolibarr unit code before
        // generic literal matching. This also disambiguates "m": in the active
        // dictionary it means M/size (meter), while MO/time may also expose
        // "m" as its short label.
        if ($navUnit === 'OWN' && $ownUnit !== '') {
            $match = $this->findOwnAlias($ownUnit);
            if ($match !== null) {
                return $this->resolvedResult($result, $match, 'own_alias');
            }
        }

        // OWN, PACK, CARTON and any future NAV unit can only be matched safely
        // when the invoice value uniquely equals a configured Dolibarr code,
        // short label or literal label. We do not translate labels here because
        // that would make matching depend on the UI language.
        $needle = $ownUnit !== '' ? $ownUnit : $source;
        $matches = array();
        foreach ($this->loadUnits() as $unit) {
            foreach (array('code', 'short_label', 'label') as $field) {
                if ($this->normalize((string) $unit[$field]) === $this->normalize($needle)) {
                    $matches[(int) $unit['id']] = $unit;
                    break;
                }
            }
        }

        if (count($matches) === 1) {
            return $this->resolvedResult($result, reset($matches), 'literal');
        }
        if (count($matches) > 1) {
            $result['status'] = 'ambiguous';
        }

        return $result;
    }

    /**
     * Resolve common OWN values against the stable codes used by the current
     * Dolibarr unit dictionary. Alias keys are normalized exactly like source
     * values, so m³ and m3 are equivalent and dots/whitespace are ignored.
     *
     * The deliberately wrong-looking MI short label "piece" seen in some
     * dictionaries is not treated as a minute alias; semantic aliases always
     * target the canonical code and unit type instead of trusting that label.
     *
     * @return array<string,mixed>|null
     */
    private function findOwnAlias(string $value): ?array
    {
        $aliases = array(
            'km' => array('KM', 'distance'),
            't' => array('T', 'weight'),
            'tonna' => array('T', 'weight'),
            'kg' => array('KG', 'weight'),
            'g' => array('G', 'weight'),
            'mg' => array('MG', 'weight'),
            'm' => array('M', 'size'),
            'dm' => array('DM', 'size'),
            'cm' => array('CM', 'size'),
            'mm' => array('MM', 'size'),
            'm3' => array('M3', 'volume'),
            'l' => array('L', 'volume'),
            'liter' => array('L', 'volume'),
            'litre' => array('L', 'volume'),
            'db' => array('P', 'qty'),
            'darab' => array('P', 'qty'),
            'piece' => array('P', 'qty'),
            'pc' => array('P', 'qty'),
            'pcs' => array('P', 'qty'),
            'klt' => array('SET', 'qty'),
            'készlet' => array('SET', 'qty'),
            'keszlet' => array('SET', 'qty'),
            'set' => array('SET', 'qty'),
            's' => array('S', 'time'),
            'másodperc' => array('S', 'time'),
            'masodperc' => array('S', 'time'),
            'min' => array('MI', 'time'),
            'mn' => array('MI', 'time'),
            'perc' => array('MI', 'time'),
            'h' => array('H', 'time'),
            'óra' => array('H', 'time'),
            'ora' => array('H', 'time'),
            'd' => array('D', 'time'),
            'nap' => array('D', 'time'),
            'w' => array('W', 'time'),
            'hét' => array('W', 'time'),
            'het' => array('W', 'time'),
            'mo' => array('MO', 'time'),
            'hó' => array('MO', 'time'),
            'ho' => array('MO', 'time'),
            'hónap' => array('MO', 'time'),
            'honap' => array('MO', 'time'),
            'y' => array('Y', 'time'),
            'év' => array('Y', 'time'),
            'ev' => array('Y', 'time'),
        );

        $needle = $this->normalize($value);
        if (!isset($aliases[$needle])) {
            return null;
        }

        return $this->findUniqueByCode($aliases[$needle][0], $aliases[$needle][1]);
    }

    /** @return array<int,array<string,mixed>> */
    private function loadUnits(): array
    {
        if ($this->units !== null) {
            return $this->units;
        }

        $this->units = array();
        $sql = 'SELECT rowid, code, label, short_label, unit_type, scale';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'c_units';
        $sql .= ' WHERE active = 1';
        $sql .= ' ORDER BY sortorder, rowid';
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Unable to read Dolibarr unit dictionary: '.$this->db->lasterror());
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $this->units[] = array(
                'id' => (int) $obj->rowid,
                'code' => (string) $obj->code,
                'label' => (string) $obj->label,
                'short_label' => (string) $obj->short_label,
                'unit_type' => (string) $obj->unit_type,
                'scale' => $obj->scale,
            );
        }
        $this->db->free($resql);

        return $this->units;
    }

    /** @return array<string,mixed>|null */
    private function findUniqueByCode(string $code, string $unitType = ''): ?array
    {
        $matches = array();
        foreach ($this->loadUnits() as $unit) {
            if ($unitType !== '' && strcasecmp(trim((string) $unit['unit_type']), trim($unitType)) !== 0) {
                continue;
            }
            if (strcasecmp(trim((string) $unit['code']), trim($code)) === 0) {
                $matches[] = $unit;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string,mixed>|null */
    private function findUniqueByShortLabel(string $symbol, string $unitType = ''): ?array
    {
        $matches = array();
        foreach ($this->loadUnits() as $unit) {
            if ($unitType !== '' && strcasecmp(trim((string) $unit['unit_type']), trim($unitType)) !== 0) {
                continue;
            }
            if ($this->normalize((string) $unit['short_label']) === $this->normalize($symbol)) {
                $matches[] = $unit;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string,mixed> $base
     * @param array<string,mixed> $unit
     * @return array<string,mixed>
     */
    private function resolvedResult(array $base, array $unit, string $method): array
    {
        $base['id'] = (int) $unit['id'];
        $base['code'] = (string) $unit['code'];
        $base['short_label'] = (string) $unit['short_label'];
        $base['label'] = (string) $unit['label'];
        $base['status'] = 'resolved';
        $base['method'] = $method;
        return $base;
    }

    private function normalize(string $value): string
    {
        $value = trim($value);
        if (function_exists('mb_strtolower')) {
            $value = mb_strtolower($value, 'UTF-8');
        } else {
            $value = strtolower($value);
        }
        $value = str_replace(array('³', '²'), array('3', '2'), $value);
        $value = preg_replace('/[\s\.]+/u', '', $value) ?? $value;
        return $value;
    }
}
