<?php

$res = 0;
foreach (array(__DIR__.'/../main.inc.php', __DIR__.'/../../main.inc.php') as $main) {
    if (!$res && file_exists($main)) {
        $res = @include $main;
    }
}
if (!$res) {
    http_response_code(500);
    exit;
}

dol_include_once('/navinvoice/class/navinvoiceparser.class.php');
dol_include_once('/navinvoice/class/navpartnermatcher.class.php');
dol_include_once('/navinvoice/class/navinvoiceimportpreview.class.php');
dol_include_once('/navinvoice/class/navproductmatcher.class.php');
$langs->loadLangs(array('navinvoice@navinvoice', 'navinvoiceui@navinvoice'));

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (!$user->hasRight('navinvoice', 'invoice', 'read')) {
    http_response_code(403);
    print json_encode(array('error' => 'forbidden'));
    exit;
}

$id = GETPOSTINT('id');
if ($id <= 0) {
    http_response_code(400);
    print json_encode(array('error' => 'invalid_id'));
    exit;
}

$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'navinvoice_invoice';
$sql .= ' WHERE rowid = '.$id;
$sql .= ' AND entity = '.((int) $conf->entity);
$resql = $db->query($sql);
if (!$resql) {
    http_response_code(500);
    print json_encode(array('error' => 'database_error'));
    exit;
}
$record = $db->fetch_object($resql);
$db->free($resql);
if (!$record) {
    http_response_code(404);
    print json_encode(array('error' => 'not_found'));
    exit;
}

$tr = static function (string $key) use ($langs): string {
    return $langs->transnoentities($key);
};

try {
    if (empty($record->invoice_data)) {
        throw new Exception('NAV invoice XML is not available.');
    }

    $parser = new NavInvoiceParser();
    $parsed = $parser->parse((string) $record->invoice_data);
    $direction = strtoupper(trim((string) $record->invoice_direction));
    $partyKey = $direction === 'INBOUND' ? 'supplier' : 'customer';

    $partnerMatcher = new NavPartnerMatcher($db, (int) $conf->entity);
    $partnerMatch = $partnerMatcher->match($parsed[$partyKey], $partyKey);

    // Reuse the import-preview mapper because it already applies the canonical
    // NAV OWN -> supplier_ref / itemNumber fallback rules and line-type mapping.
    $previewBuilder = new NavInvoiceImportPreview($db, (int) $conf->entity, strtoupper((string) $conf->currency));
    $preview = $previewBuilder->build($parsed, $record, $partnerMatch);
    $partnerId = is_array($preview['partner'] ?? null) ? (int) ($preview['partner']['id'] ?? 0) : 0;

    $matcher = new NavProductMatcher($db, (int) $conf->entity);
    $lines = array();
    $summary = array('matched' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'review' => 0);

    foreach (($preview['lines'] ?? array()) as $line) {
        if (!is_array($line)) {
            continue;
        }
        $match = $matcher->matchLine($direction, $partnerId, $line);
        $status = (string) ($match['status'] ?? 'none');
        if ($status === 'matched') {
            $summary['matched']++;
        } elseif ($status === 'ambiguous') {
            $summary['ambiguous']++;
        } elseif (in_array($status, array('type_mismatch', 'inactive'), true)) {
            $summary['review']++;
        } else {
            $summary['unmatched']++;
        }

        $lines[] = array(
            'number' => (string) ($line['number'] ?? ''),
            'description' => (string) ($line['description'] ?? ''),
            'supplier_ref' => (string) ($line['supplier_ref'] ?? ''),
            'supplier_ref_source' => (string) ($line['supplier_ref_source'] ?? ''),
            'product_type' => (int) ($line['product_type'] ?? 0),
            'match' => $match,
        );
    }

    $labels = array(
        'title' => $tr('NavProductRelations'),
        'help' => $tr('NavProductRelationsHelp'),
        'line' => $tr('NavProductRelationLine'),
        'description' => $tr('Description'),
        'nav_reference' => $tr('NavProductReference'),
        'dolibarr_product' => $tr('DolibarrProduct'),
        'match_status' => $tr('ProductMatchStatus'),
        'matched' => $tr('ProductMatchMatched'),
        'none' => $tr('ProductMatchNone'),
        'no_reference' => $tr('ProductMatchNoReference'),
        'partner_required' => $tr('ProductMatchPartnerRequired'),
        'ambiguous' => $tr('ProductMatchAmbiguous'),
        'type_mismatch' => $tr('ProductMatchTypeMismatch'),
        'inactive' => $tr('ProductMatchInactive'),
        'unsupported_direction' => $tr('ProductMatchUnsupportedDirection'),
        'summary' => $tr('ProductMatchSummary'),
        'summary_matched' => $tr('ProductMatchSummaryMatched'),
        'summary_unmatched' => $tr('ProductMatchSummaryUnmatched'),
        'summary_review' => $tr('ProductMatchSummaryReview'),
        'summary_ambiguous' => $tr('ProductMatchSummaryAmbiguous'),
    );

    print json_encode(
        array('labels' => $labels, 'summary' => $summary, 'lines' => $lines),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    dol_syslog('NAV product-match preview failed for mirror row '.$id.': '.$e->getMessage(), LOG_ERR);
    http_response_code(422);
    print json_encode(array('error' => 'product_match_failed'));
}

$db->close();
