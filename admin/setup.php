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
$isConfigPost = in_array($action, array('save', 'test'), true);
$storedSyncEnabled = (bool) getDolGlobalInt('NAVINVOICE_SYNC_ENABLED');

$formEnvironment = $isConfigPost
    ? (GETPOST('environment', 'alpha') === 'production' ? 'production' : 'test')
    : getDolGlobalString('NAVINVOICE_ENVIRONMENT', 'test');
$formLogin = $isConfigPost ? trim(GETPOST('login', 'alphanohtml')) : getDolGlobalString('NAVINVOICE_LOGIN');
$formTaxNumber = $isConfigPost
    ? preg_replace('/\D+/', '', GETPOST('tax_number', 'alphanohtml'))
    : getDolGlobalString('NAVINVOICE_TAX_NUMBER');
$formLookbackDays = $isConfigPost ? max(1, min(35, GETPOSTINT('lookback_days'))) : getDolGlobalInt('NAVINVOICE_SYNC_LOOKBACK_DAYS', 7);
$formSyncEnabled = $isConfigPost ? (bool) GETPOSTINT('sync_enabled') : $storedSyncEnabled;
$formFetchFullData = $isConfigPost ? (bool) GETPOSTINT('fetch_full_data') : (bool) getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1);
$formAutoValidateInbound = $isConfigPost ? (bool) GETPOSTINT('auto_validate_inbound') : (bool) getDolGlobalInt('NAVINVOICE_AUTO_VALIDATE_INBOUND');
$formPurchaseWorkbench = $isConfigPost ? (bool) GETPOSTINT('purchase_workbench_enabled') : (bool) getDolGlobalInt('NAVINVOICE_PURCHASE_WORKBENCH_ENABLED');
$formShowTechnicalXml = $isConfigPost ? (bool) GETPOSTINT('show_technical_xml') : (bool) getDolGlobalInt('NAVINVOICE_SHOW_TECHNICAL_XML', 1);

if ($isConfigPost) {
    $password = GETPOST('password', 'none');
    $signingKey = trim(GETPOST('signing_key', 'none'));
    $validationErrors = array();

    if ($formLogin === '') {
        $validationErrors[] = $langs->trans('NavLoginRequired');
    }
    if (strlen((string) $formTaxNumber) < 8) {
        $validationErrors[] = $langs->trans('NavTaxNumberInvalid');
    } else {
        $formTaxNumber = substr((string) $formTaxNumber, 0, 8);
    }
    if ($action === 'test' && $password === '' && getDolGlobalString('NAVINVOICE_PASSWORD') === '') {
        $validationErrors[] = $langs->trans('NavPasswordRequired');
    }
    if ($action === 'test' && $signingKey === '' && getDolGlobalString('NAVINVOICE_SIGNING_KEY') === '') {
        $validationErrors[] = $langs->trans('NavSigningKeyRequired');
    }

    if (empty($validationErrors)) {
        dolibarr_set_const($db, 'NAVINVOICE_ENVIRONMENT', $formEnvironment, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_LOGIN', $formLogin, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_TAX_NUMBER', $formTaxNumber, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_SYNC_ENABLED', $formSyncEnabled ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_FETCH_FULL_DATA', $formFetchFullData ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_SYNC_LOOKBACK_DAYS', (string) $formLookbackDays, 'chaine', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_AUTO_VALIDATE_INBOUND', $formAutoValidateInbound ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_PURCHASE_WORKBENCH_ENABLED', $formPurchaseWorkbench ? '1' : '0', 'yesno', 0, '', $conf->entity);
        dolibarr_set_const($db, 'NAVINVOICE_SHOW_TECHNICAL_XML', $formShowTechnicalXml ? '1' : '0', 'yesno', 0, '', $conf->entity);

        if ($password !== '') {
            dolibarr_set_const($db, 'NAVINVOICE_PASSWORD', $password, 'chaine', 0, '', $conf->entity);
        }
        if ($signingKey !== '') {
            dolibarr_set_const($db, 'NAVINVOICE_SIGNING_KEY', $signingKey, 'chaine', 0, '', $conf->entity);
        }

        try {
            navinvoiceSetScheduledSyncCronState($db, (int) $conf->entity, $formSyncEnabled, $user);
        } catch (Throwable $e) {
            // Do not leave the module flag claiming scheduled sync is enabled
            // when the underlying Dolibarr cron entry could not be synchronized.
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

if ($action === 'test' && empty($validationErrors)) {
    try {
        $api = new NavInvoiceApi();
        $response = $api->queryTaxpayer();
        $validity = $response->xpath('//*[local-name()="taxpayerValidity"]');
        $name = $response->xpath('//*[local-name()="taxpayerName"]');
        $message = $langs->trans('NavConnectionSuccessful');
        if ($name) {
            $message .= ' - '.(string) $name[0];
        }
        if ($validity && strtolower((string) $validity[0]) !== 'true') {
            $message .= ' ('.$langs->trans('TaxpayerNotValid').')';
        }
        setEventMessages($message, null, 'mesgs');
    } catch (Throwable $e) {
        setEventMessages($langs->trans('NavConnectionFailed').': '.$e->getMessage(), null, 'errors');
    }
}

$hasStoredPassword = getDolGlobalString('NAVINVOICE_PASSWORD') !== '';
$hasStoredSigningKey = getDolGlobalString('NAVINVOICE_SIGNING_KEY') !== '';
$secretHint = static function (bool $hasValue) use ($langs): string {
    return $hasValue ? $langs->trans('StoredSecretPresent') : $langs->trans('StoredSecretMissing');
};
$stockValidationNeedsWarehouse = isModEnabled('stock') && getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_BILL');

llxHeader('', $langs->trans('NavInvoiceSetup'));
print load_fiche_titre($langs->trans('NavInvoiceSetup'), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('NavApiConfiguration').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('Environment').'</td><td><select name="environment">';
foreach (array('test' => 'Test', 'production' => 'Production') as $value => $label) {
    $selected = $formEnvironment === $value ? ' selected' : '';
    print '<option value="'.$value.'"'.$selected.'>'.$langs->trans($label).'</option>';
}
print '</select></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTechnicalUserLogin').'</td><td><input class="minwidth300" type="text" name="login" value="'.dol_escape_htmltag($formLogin).'" autocomplete="off"></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTechnicalUserPassword').'</td><td><input class="minwidth300" type="password" name="password" value="" autocomplete="new-password"> <span class="opacitymedium">'.$secretHint($hasStoredPassword).'; '.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTaxNumber').'</td><td><input class="minwidth200" maxlength="8" inputmode="numeric" type="text" name="tax_number" value="'.dol_escape_htmltag((string) $formTaxNumber).'"> <span class="opacitymedium">'.$langs->trans('NavTaxNumberHelp').'</span></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavSigningKey').'</td><td><input class="minwidth300" type="password" name="signing_key" value="" autocomplete="new-password"> <span class="opacitymedium">'.$secretHint($hasStoredSigningKey).'; '.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('ScheduledSync').'</td><td><input type="checkbox" name="sync_enabled" value="1"'.($formSyncEnabled ? ' checked' : '').'> <span class="opacitymedium">1 × 3600 s; '.$langs->trans('DirectionBoth').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SyncLookbackDays').'</td><td><input type="number" min="1" max="35" name="lookback_days" value="'.((int) $formLookbackDays).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DownloadFullInvoiceXml').'</td><td><input type="checkbox" name="fetch_full_data" value="1"'.($formFetchFullData ? ' checked' : '').'></td></tr>';

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
print '<button class="button button-save" type="submit" name="action" value="save">'.$langs->trans('Save').'</button> ';
print '<button class="button" type="submit" name="action" value="test">'.$langs->trans('SaveAndTestNavConnection').'</button>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
