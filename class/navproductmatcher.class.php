<?php

/**
 * Resolve a NAV invoice line to an existing Dolibarr product/service.
 *
 * Matching is intentionally conservative:
 * - inbound: supplier + NAV issuer OWN/item number -> supplier product ref
 * - outbound: NAV issuer OWN/item number -> Dolibarr product ref
 *
 * Descriptions are deliberately not used for automatic matching.
 */
class NavProductMatcher
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
     * @param array<string,mixed> $line Mapped import-preview line.
     * @return array<string,mixed>
     */
    public function matchLine(string $direction, int $partnerId, array $line): array
    {
        $direction = strtoupper(trim($direction));
        $expectedType = (int) ($line['product_type'] ?? 0);

        if ($direction === 'INBOUND') {
            $reference = trim((string) ($line['supplier_ref'] ?? ''));
            if ($reference === '') {
                return $this->emptyResult('no_reference', 'supplier_ref', '');
            }
            if ($partnerId <= 0) {
                return $this->emptyResult('partner_required', 'supplier_ref', $reference);
            }
            return $this->matchSupplierReference($partnerId, $reference, $expectedType);
        }

        if ($direction === 'OUTBOUND') {
            $reference = $this->ownReference($line);
            if ($reference === '') {
                return $this->emptyResult('no_reference', 'product_ref', '');
            }
            return $this->matchProductReference($reference, $expectedType);
        }

        return $this->emptyResult('unsupported_direction', '', '');
    }

    /** @return array<string,mixed> */
    private function matchSupplierReference(int $supplierId, string $reference, int $expectedType): array
    {
        $sql = 'SELECT p.rowid, p.ref, p.label, p.fk_product_type, p.tobuy, p.tosell,';
        $sql .= ' MIN(pfp.rowid) as supplier_price_id';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product_fournisseur_price as pfp';
        $sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = pfp.fk_product';
        $sql .= ' WHERE pfp.entity IN ('.getEntity('productsupplierprice').')';
        $sql .= ' AND p.entity IN ('.getEntity('product').')';
        $sql .= ' AND pfp.fk_soc = '.$supplierId;
        $sql .= " AND LOWER(TRIM(pfp.ref_fourn)) = LOWER('".$this->db->escape($reference)."')";
        $sql .= ' GROUP BY p.rowid, p.ref, p.label, p.fk_product_type, p.tobuy, p.tosell';
        $sql .= ' ORDER BY p.rowid';

        return $this->finishQuery($sql, 'supplier_ref', $reference, $expectedType, true);
    }

    /** @return array<string,mixed> */
    private function matchProductReference(string $reference, int $expectedType): array
    {
        $sql = 'SELECT p.rowid, p.ref, p.label, p.fk_product_type, p.tobuy, p.tosell,';
        $sql .= ' NULL as supplier_price_id';
        $sql .= ' FROM '.MAIN_DB_PREFIX.'product as p';
        $sql .= ' WHERE p.entity IN ('.getEntity('product').')';
        $sql .= " AND LOWER(TRIM(p.ref)) = LOWER('".$this->db->escape($reference)."')";
        $sql .= ' ORDER BY p.rowid';

        return $this->finishQuery($sql, 'product_ref', $reference, $expectedType, false);
    }

    /**
     * @return array<string,mixed>
     */
    private function finishQuery(string $sql, string $matchType, string $reference, int $expectedType, bool $purchase): array
    {
        $resql = $this->db->query($sql);
        if (!$resql) {
            throw new Exception('Dolibarr product matching failed: '.$this->db->lasterror());
        }

        $products = array();
        while ($obj = $this->db->fetch_object($resql)) {
            $productId = (int) $obj->rowid;
            $products[$productId] = array(
                'id' => $productId,
                'ref' => (string) $obj->ref,
                'label' => (string) $obj->label,
                'product_type' => (int) $obj->fk_product_type,
                'tobuy' => (int) $obj->tobuy,
                'tosell' => (int) $obj->tosell,
                'supplier_price_id' => isset($obj->supplier_price_id) ? (int) $obj->supplier_price_id : 0,
            );
        }
        $this->db->free($resql);

        $candidates = array_values($products);
        if (!$candidates) {
            return $this->emptyResult('none', $matchType, $reference);
        }
        if (count($candidates) > 1) {
            return array(
                'status' => 'ambiguous',
                'match_type' => $matchType,
                'reference' => $reference,
                'auto_link' => false,
                'product' => null,
                'candidates' => array_slice($candidates, 0, 10),
            );
        }

        $product = $candidates[0];
        if ((int) $product['product_type'] !== $expectedType) {
            return array(
                'status' => 'type_mismatch',
                'match_type' => $matchType,
                'reference' => $reference,
                'auto_link' => false,
                'product' => $product,
                'candidates' => array($product),
            );
        }

        $active = $purchase ? !empty($product['tobuy']) : !empty($product['tosell']);
        if (!$active) {
            return array(
                'status' => 'inactive',
                'match_type' => $matchType,
                'reference' => $reference,
                'auto_link' => false,
                'product' => $product,
                'candidates' => array($product),
            );
        }

        return array(
            'status' => 'matched',
            'match_type' => $matchType,
            'reference' => $reference,
            'auto_link' => true,
            'product' => $product,
            'candidates' => array($product),
        );
    }

    private function ownReference(array $line): string
    {
        foreach (($line['product_codes'] ?? array()) as $productCode) {
            $category = strtoupper(trim((string) ($productCode['category'] ?? '')));
            $value = trim((string) ($productCode['value'] ?? ''));
            if ($category === 'OWN' && $value !== '') {
                return $value;
            }
        }
        foreach (($line['item_numbers'] ?? array()) as $itemNumber) {
            $itemNumber = trim((string) $itemNumber);
            if ($itemNumber !== '') {
                return $itemNumber;
            }
        }
        return '';
    }

    /** @return array<string,mixed> */
    private function emptyResult(string $status, string $matchType, string $reference): array
    {
        return array(
            'status' => $status,
            'match_type' => $matchType,
            'reference' => $reference,
            'auto_link' => false,
            'product' => null,
            'candidates' => array(),
        );
    }
}
