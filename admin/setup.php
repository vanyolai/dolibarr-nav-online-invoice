<?php

$res = 0;
foreach (array(__DIR__.'/../../main.inc.php', __DIR__.'/../../../main.inc.php') as $main) {
    if (!$res && file_exists($main)) {
        $res = @include $main;
    }
}
if (!$res) {
    die('Failed to include Dolibarr main.inc.php');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
dol_include_once('/navinvoice/class/navapi.class.php');

$langs->loadLangs(array('admin', 'navinvoice@navinvoice', 'navinvoiceui@navinvoice', 'navpurchase@navinvoice'));
if (!$user->admin) {
    accessforbidden();
}

/**
 * Keep the module-level scheduled-sync switch and the Dolibarr cron entry in
 * one state. Older module versions registered the cron disabled, so merely
 * changing the module descriptor is not enough for already installed systems.
 */
function navinvoiceSetScheduledSyncCronState($db, int $entity, bool $enabled, $user): void
{
    $label = 'NavInvoiceScheduledSync';
    $findCronId = static function () use ($db, $entity, $label): int {
        $sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'cronjob';
        $sql .= " WHERE label = '".$db->escape($label)."' AND entity = ".$entity;
        $sql .= ' ORDER BY rowid DESC LIMIT 1';
        $resql = $db->query($sql);
        if (!$resql) {
            throw new Exception($db->lasterror());
        }
        $obj = $db->fetch_object($resql);
        $db->free($resql);
        return $obj ? (int) $obj->rowid : 0;
    };

    $cronId = $findCronId();
    if ($cronId <= 0) {
        dol_include_once('/navinvoice/core/modules/modNavInvoice.class.php');
        $module = new modNavInvoice($db);
        if ($module->insert_cronjobs() < 0) {
            throw new Exception('The NAV scheduled synchronization job could not be registered.');
        }
        $cronId = $findCronId();
    }
    if ($cronId <= 0) {
        throw new Exception('The NAV scheduled synchronization job is missing.');
    }

    $cron = new Cronjob($db);
    if ($cron->fetch($cronId) <= 0) {
        throw new Exception('The NAV scheduled synchronization job could not be loaded.');
    }

    $wasEnabled = ((int) $cron->status === Cronjob::STATUS_ENABLED);
    $cron->status = $enabled ? Cronjob::STATUS_ENABLED : Cronjob::STATUS_DISABLED;
    $cron->frequency = 1;
    $cron->unitfrequency = '3600';
    $cron->test = 'isModEnabled("navinvoice") && getDolGlobalInt("NAVINVOICE_SYNC_ENABLED")';

    // When switching the job on, make it immediately due. The next Dolibarr
    // cron-runner pass can then execute it without waiting for a full hour.
    if ($enabled && !$wasEnabled) {
        $cron->datenextrun = dol_now();
    }

    if ($cron->update($user) < 0) {
        $message = trim((string) $cron->error);
        if ($message === '' && !empty($cron->errors)) {
            $message = implode('; ', $cron->errors);
        }
        throw new Exception($message !== '' ? $message : 'The NAV scheduled synchronization job could not be updated.');
    }
}

$action = GETPOST('action', 'aZ09');
$isConfigPost = in_array($action, array('save', 'test_production', 'test_test'), true);
$storedSyncEnabled = (bool) getDolGlobalInt('NAVINVOICE_SYNC_ENABLED');
$legacyEnvironment = getDolGlobalString('NAVINVOICE_ENVIRONMENT', 'test') === 'production' ? 'production' : 'test';

$storedProfileValue = static function (string $environment, string $suffix, string $legacyName) use ($legacyEnvironment): string {
    $name = 'NAVINVOICE_'.strtoupper($environment).'_'.$suffix;
    $value = trim((string) getDolGlobalString($name));
    if ($value !== '') {
        return $value;
    }
    return $legacyEnvironment === $environment ? trim((string) getDolGlobalString($legacyName)) : '';
};
$storedProfileSecretPresent = static function (string $environment, string $suffix, string $legacyName) use ($legacyEnvironment): bool {
    $name = 'NAVINVOICE_'.strtoupper($environment).'_'.$suffix;
    if (getDolGlobalString($name) !== '') {
        return true;
    }
    return $legacyEnvironment === $environment && getDolGlobalString($legacyName) !== '';
};

$formProfiles = array();
foreach (array('production', 'test') as $environment) {
    $prefix = $environment.'_';
    $formProfiles[$environment] = array(
        'login' => $isConfigPost ? trim(GETPOST($prefix.'login', 'alphanohtml')) : $storedProfileValue($environment, 'LOGIN', 'NAVINVOICE_LOGIN'),
        'tax_number' => $isConfigPost
            ? (string) preg_replace('/\\D+/', '', GETPOST($prefix.'tax_number', 'alphanohtml'))
            : $storedProfileValue($environment, 'TAX_NUMBER', 'NAVINVOICE_TAX_NUMBER'),
        'password' => $isConfigPost ? GETPOST($prefix.'password', 'none') : '',
        'signing_key' => $isConfigPost ? trim(GETPOST($prefix.'signing_key', 'none')) : '',
        'exchange_key' => $isConfigPost ? trim(GETPOST($prefix.'exchange_key', 'none')) : '',
        'has_password' => $storedProfileSecretPresent($environment, 'PASSWORD', 'NAVINVOICE_PASSWORD'),
        'has_signing_key' => $storedProfileSecretPresent($environment, 'SIGNING_KEY', 'NAVINVOICE_SIGNING_KEY'),
        'has_exchange_key' => $storedProfileSecretPresent($environment, 'EXCHANGE_KEY', 'NAVINVOICE_EXCHANGE_KEY'),
    );
}

$formLookbackDays = $isConfigPost ? max(1, min(35, GETPOSTINT('lookback_days'))) : getDolGlobalInt('NAVINVOICE_SYNC_LOOKBACK_DAYS', 7);
$formSyncEnabled = $isConfigPost ? (bool) GETPOSTINT('sync_enabled') : $storedSyncEnabled;
$formFetchFullData = $isConfigPost ? (bool) GETPOSTINT('fetch_full_data') : (bool) getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1);
$formAutoValidateInbound = $isConfigPost ? (bool) GETPOSTINT('auto_validate_inbound') : (bool) getDolGlobalInt('NAVINVOICE_AUTO_VALIDATE_INBOUND');
$formPurchaseWorkbench = $isConfigPost ? (bool) GETPOSTINT('purchase_workbench_enabled') : (bool) getDolGlobalInt('NAVINVOICE_PURCHASE_WORKBENCH_ENABLED');
$formShowTechnicalXml = $isConfigPost ? (bool) GETPOSTINT('show_technical_xml') : (bool) getDolGlobalInt('NAVINVOICE_SHOW_TECHNICAL_XML', 1);
$validationErrors = array();

if ($isConfigPost) {
    foreach (array('production', 'test') as $environment) {
        $profile = &$formProfiles[$environment];
        $configured = $environment === 'production'
            || $profile['login'] !== ''
            || $profile['tax_number'] !== ''
            || $profile['password'] !== ''
            || $profile['signing_key'] !== ''
            || $profile['exchange_key'] !== ''
            || $profile['has_password']
            || $profile['has_signing_key']
            || $profile['has_exchange_key'];

        if (!$configured) {
            unset($profile);
            continue;
        }

        if ($profile['login'] === '') {
            $validationErrors[] = ucfirst($environment).': '.$langs->trans('NavLoginRequired');
        }
        if (strlen((string) $profile['tax_number']) < 8) {
            $validationErrors[] = ucfirst($environment).': '.$langs->trans('NavTaxNumberInvalid');
        } else {
            $profile['tax_number'] = substr((string) $profile['tax_number'], 0, 8);
        }
        if ($profile['password'] === '' && !$profile['has_password']) {
            $validationErrors[] = ucfirst($environment).': '.$langs->trans('NavPasswordRequired');
        }
        if ($profile['signing_key'] === '' && !$profile['has_signing_key']) {
            $validationErrors[] = ucfirst($environment).': '.$langs->trans('NavSigningKeyRequired');
        }
        // The production profile is already useful for read-only synchronization
        // without an exchange key. The test profile is specifically our outbound
        // sandbox, therefore require its exchange key as soon as it is configured.
        if ($environment === 'test' && $profile['exchange_key'] === '' && !$profile['has_exchange_key']) {
            $validationErrors[] = 'Test: XML exchange key is required for outbound sandbox testing.';
        }
        unset($profile);
    }

    if (empty($validationErrors)) {
        foreach (array('production', 'test') as $environment) {
            $profile = $formProfiles[$environment];
            $constPrefix = 'NAVINVOICE_'.strtoupper($environment).'_';
            dolibarr_set_const($db, $constPrefix.'LOGIN', $profile['login'], 'chaine', 0, '', $conf->entity);
            dolibarr_set_const($db, $constPrefix.'TAX_NUMBER', $profile['tax_number'], 'chaine', 0, '', $conf->entity);

            if ($profile['password'] !== '') {
                dolibarr_set_const($db, $constPrefix.'PASSWORD', $profile['password'], 'chaine', 0, '', $conf->entity);
                $formProfiles[$environment]['has_password'] = true;
            }
            if ($profile['signing_key'] !== '') {
                dolibarr_set_const($db, $constPrefix.'SIGNING_KEY', $profile['signing_key'], 'chaine', 0, '', $conf->entity);
                $formProfiles[$environment]['has_signing_key'] = true;
            }
            if ($profile['exchange_key'] !== '') {
                dolibarr_set_const($db, $constPrefix.'EXCHANGE_KEY', $profile['exchange_key'], 'chaine', 0, '', $conf->entity);
                $formProfiles[$environment]['has_exchange_key'] = true;
            }
        }

        dolibarr_set_const($db, 'NAVINVOICE_SYNC_ENABLED', $formSyncEnabled ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_FETCH_FULL_DATA', $formFetchFullData ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_SYNC_LOOKBACK_DAYS', (string) $formLookbackDays, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_AUTO_VALIDATE_INBOUND', $formAutoValidateInbound ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_PURCHASE_WORKBENCH_ENABLED', $formPurchaseWorkbench ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_SHOW_TECHNICAL_XML', $formShowTechnicalXml ? '1' : '0', 'yesno', 0, '', $conf->entity);

        try {
            navinvoiceSetScheduledSyncCronState($db, (int) $conf->entity, $formSyncEnabled, $user);
        } catch (Throwable $e) {
            dolibarr_set_const($db, 'NAVINVOICE_SYNC_ENABLED', $storedSyncEnabled ? '1' : '0', 'yesno', 0, '', $conf->entity);
            $formSyncEnabled = $storedSyncEnabled;
            $validationErrors[] = $langs->trans('ScheduledSync').': '.$e->getMessage();
        }
    }

    if (empty($validationErrors)) {
        if ($action === 'save') {
            setEventMessages($langs->trans('SettingsSaved'), null, 'mesgs');
        }
    } else {
        setEventMessages(null, $validationErrors, 'errors');
    }
}

if (in_array($action, array('test_production', 'test_test'), true) && empty($validationErrors)) {
    $testEnvironment = $action === 'test_production' ? 'production' : 'test';
    try {
        $api = new NavInvoiceApi($testEnvironment);
        $response = $api->queryTaxpayer();
        $validity = $response->xpath('//*[local-name()="taxpayerValidity"]');
        $name = $response->xpath('//*[local-name()="taxpayerName"]');
        $message = ucfirst($testEnvironment).' - '.$langs->trans('NavConnectionSuccessful');
        if ($name) {
            $message .= ' - '.(string) $name[0];
        }
        if ($validity && strtolower((string) $validity[0]) !== 'true') {
            $message .= ' ('.$langs->trans('TaxpayerNotValid').')';
        }
        setEventMessages($message, null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages(ucfirst($testEnvironment).' - '.$langs->trans('NavConnectionFailed').': '.$e->getMessage(), null, 'errors');
    }
}

$secretHint = static function (bool $hasValue) use ($langs): string {
    return $hasValue ? $langs->trans('StoredSecretPresent') : $langs->trans('StoredSecretMissing');
};
$stockValidationNeedsWarehouse = isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');
$isHungarianUi = substr(strtolower((string) $langs->defaultlang), 0, 2) === 'hu';
$fetchFullXmlHelp = $isHungarianUi
    ? 'Bekapcsolva az új vagy módosult számlák teljes NAV XML-je is letöltődik. Ez szükséges a tételszintű részletekhez, az importhoz és a beszerzési workbenchhez. Kikapcsolva csak az összesítő NAV-adatok frissülnek; a korábban letöltött XML-ek megmaradnak.'
    : 'When enabled, the complete NAV XML is downloaded for new or changed invoices. It is required for line-level details, invoice import and the purchase workbench. When disabled, only summary NAV data are refreshed; XML files downloaded earlier are kept.';

llxHeader('', $langs->trans('NavInvoiceSetup'));
print load_fiche_titre($langs->trans('NavInvoiceSetup'), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';

$profileTitles = array(
    'production' => $isHungarianUi ? 'Éles NAV kapcsolat' : 'Production NAV connection',
    'test' => $isHungarianUi ? 'NAV teszt / sandbox kapcsolat' : 'NAV test / sandbox connection',
);
$profileHelp = array(
    'production' => $isHungarianUi
        ? 'Az élő NAV számlák lekérdezése és szinkronizálása mindig ezt a profilt használja.'
        : 'Live NAV invoice queries and synchronization always use this profile.',
    'test' => $isHungarianUi
        ? 'A fejlesztési outbound beküldések kizárólag ezt a profilt és a NAV teszt API-t használják.'
        : 'Development outbound submissions use only this profile and the NAV test API.',
);

foreach (array('production', 'test') as $environment) {
    $profile = $formProfiles[$environment];
    $fieldPrefix = $environment.'_';
    print '<tr class="liste_titre"><td colspan="2">'.dol_escape_htmltag($profileTitles[$environment]).'</td></tr>';
    print '<tr class="oddeven"><td>'.$langs->trans('Environment').'</td><td><strong>'.($environment === 'production' ? 'PRODUCTION' : 'TEST').'</strong> <span class="opacitymedium">'.dol_escape_htmltag($profileHelp[$environment]).'</span></td></tr>';
    print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTechnicalUserLogin').'</td><td><input class="minwidth300" type="text" name="'.$fieldPrefix.'login" value="'.dol_escape_htmltag((string) $profile['login']).'" autocomplete="off"></td></tr>';
    print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTechnicalUserPassword').'</td><td><input class="minwidth300" type="password" name="'.$fieldPrefix.'password" value="" autocomplete="new-password"> <span class="opacitymedium">'.$secretHint((bool) $profile['has_password']).'; '.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
    print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTaxNumber').'</td><td><input class="minwidth200" maxlength="8" inputmode="numeric" type="text" name="'.$fieldPrefix.'tax_number" value="'.dol_escape_htmltag((string) $profile['tax_number']).'"> <span class="opacitymedium">'.$langs->trans('NavTaxNumberHelp').'</span></td></tr>';
    print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavSigningKey').'</td><td><input class="minwidth300" type="password" name="'.$fieldPrefix.'signing_key" value="" autocomplete="new-password"> <span class="opacitymedium">'.$secretHint((bool) $profile['has_signing_key']).'; '.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
    $exchangeRequired = $environment === 'test' ? ' class="fieldrequired"' : '';
    print '<tr class="oddeven"><td'.$exchangeRequired.'>XML exchange key</td><td><input class="minwidth300" type="password" name="'.$fieldPrefix.'exchange_key" value="" autocomplete="new-password"> <span class="opacitymedium">'.$secretHint((bool) $profile['has_exchange_key']).'; '.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
    print '<tr class="oddeven"><td></td><td><button class="button" type="submit" name="action" value="test_'.$environment.'">'.($isHungarianUi ? 'Mentés és kapcsolat tesztelése' : 'Save and test connection').'</button></td></tr>';
}

print '<tr class="liste_titre"><td colspan="2">'.($isHungarianUi ? 'Éles NAV szinkronizálás' : 'Production NAV synchronization').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('ScheduledSync').'</td><td><input type="checkbox" name="sync_enabled" value="1"'.($formSyncEnabled ? ' checked' : '').'> <span class="opacitymedium">1 × 3600 s; '.$langs->trans('DirectionBoth').'; PRODUCTION</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SyncLookbackDays').'</td><td><input type="number" min="1" max="35" name="lookback_days" value="'.((int) $formLookbackDays).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DownloadFullInvoiceXml').'</td><td><input type="checkbox" name="fetch_full_data" value="1"'.($formFetchFullData ? ' checked' : '').'> <span class="opacitymedium">'.dol_escape_htmltag($fetchFullXmlHelp).'</span></td></tr>';

print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('NavImportConfiguration').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('AutoValidateInboundInvoices').'</td><td><input type="checkbox" name="auto_validate_inbound" value="1"'.($formAutoValidateInbound ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('AutoValidateInboundInvoicesHelp').'</span>';
if ($stockValidationNeedsWarehouse) {
    print '<br><span class="warning">'.img_picto('', 'warning').' '.$langs->trans('AutoValidateInboundStockWarning').'</span>';
}
print '</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('PurchaseWorkbenchEnabled').'</td><td><input type="checkbox" name="purchase_workbench_enabled" value="1"'.($formPurchaseWorkbench ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('PurchaseWorkbenchEnabledHelp').'</span></td></tr>';

print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('NavDisplayConfiguration').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('ShowTechnicalNavXml').'</td><td><input type="checkbox" name="show_technical_xml" value="1"'.($formShowTechnicalXml ? ' checked' : '').'> <span class="opacitymedium">'.$langs->trans('ShowTechnicalNavXmlHelp').'</span></td></tr>';
print '</table>';
print '<div class="center">';
print '<button class="button button-save" type="submit" name="action" value="save">'.$langs->trans('Save').'</button>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
