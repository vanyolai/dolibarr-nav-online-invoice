<?php

/**
 * Resolve NAV invoice units to active Dolibarr c_units entries.
 *
 * The resolver intentionally prefers stable dictionary codes over translated
 * labels. Custom NAV OWN units may be matched against an exact Dolibarr code,
 * short label or literal label, but ambiguous matches are never selected.
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

        // NAV standard unit enum -> stable Dolibarr dictionary code.
        // LINEAR_METER is dimensionally the same core Dolibarr unit as METER.
        $codeMap = array(
            'PIECE' => 'P',
            'KILOGRAM' => 'KG',
            'TON' => 'T',
            'KWH' => 'KWH',
            'DAY' => 'D',
            'HOUR' => 'H',
            'MINUTE' => 'MI',
            'MONTH' => 'MO',
            'LITER' => 'L',
            'KILOMETER' => 'KM',
            'CUBIC_METER' => 'M3',
            'METER' => 'M',
            'LINEAR_METER' => 'M',
        );

        if ($navUnit !== 'OWN' && isset($codeMap[$navUnit])) {
            $match = $this->findUniqueByCode($codeMap[$navUnit]);
            if ($match !== null) {
                return $this->resolvedResult($result, $match, 'code');
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
            if (isset($symbolMap[$navUnit])) {
                $match = $this->findUniqueByShortLabel($symbolMap[$navUnit][0], $symbolMap[$navUnit][1]);
                if ($match !== null) {
                    return $this->resolvedResult($result, $match, 'symbol');
                }
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
    private function findUniqueByCode(string $code): ?array
    {
        $matches = array();
        foreach ($this->loadUnits() as $unit) {
            if (strcasecmp((string) $unit['code'], $code) === 0) {
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
            if ($unitType !== '' && strcasecmp((string) $unit['unit_type'], $unitType) !== 0) {
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
