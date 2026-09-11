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
dol_include_once('/navinvoice/class/navapi.class.php');

$langs->loadLangs(array('admin', 'navinvoice@navinvoice'));
if (!$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'aZ09');

if ($action === 'save') {
    $environment = GETPOST('environment', 'alpha') === 'production' ? 'production' : 'test';
    $taxNumber = preg_replace('/\D+/', '', GETPOST('tax_number', 'alphanohtml'));
    $taxNumber = substr((string) $taxNumber, 0, 8);

    dolibarr_set_const($db, 'NAVINVOICE_ENVIRONMENT', $environment, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'NAVINVOICE_LOGIN', trim(GETPOST('login', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'NAVINVOICE_TAX_NUMBER', $taxNumber, 'chaine', 0, '', $conf->entity);
    dolibarr_set_const($db, 'NAVINVOICE_SYNC_ENABLED', GETPOSTINT('sync_enabled') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    dolibarr_set_const($db, 'NAVINVOICE_FETCH_FULL_DATA', GETPOSTINT('fetch_full_data') ? '1' : '0', 'yesno', 0, '', $conf->entity);
    dolibarr_set_const($db, 'NAVINVOICE_SYNC_LOOKBACK_DAYS', (string) max(1, min(35, GETPOSTINT('lookback_days'))), 'chaine', 0, '', $conf->entity);

    $password = GETPOST('password', 'none');
    if ($password !== '') {
        dolibarr_set_const($db, 'NAVINVOICE_PASSWORD', $password, 'chaine', 0, '', $conf->entity);
    }
    $signingKey = GETPOST('signing_key', 'none');
    if ($signingKey !== '') {
        dolibarr_set_const($db, 'NAVINVOICE_SIGNING_KEY', trim($signingKey), 'chaine', 0, '', $conf->entity);
    }

    setEventMessages($langs->trans('SettingsSaved'), null, 'mesgs');
}

if ($action === 'test') {
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

llxHeader('', $langs->trans('NavInvoiceSetup'));
print load_fiche_titre($langs->trans('NavInvoiceSetup'), '', 'title_setup');

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans('NavApiConfiguration').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('Environment').'</td><td><select name="environment">';
foreach (array('test' => 'Test', 'production' => 'Production') as $value => $label) {
    $selected = getDolGlobalString('NAVINVOICE_ENVIRONMENT', 'test') === $value ? ' selected' : '';
    print '<option value="'.$value.'"'.$selected.'>'.$langs->trans($label).'</option>';
}
print '</select></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTechnicalUserLogin').'</td><td><input class="minwidth300" type="text" name="login" value="'.dol_escape_htmltag(getDolGlobalString('NAVINVOICE_LOGIN')).'"></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTechnicalUserPassword').'</td><td><input class="minwidth300" type="password" name="password" value="" autocomplete="new-password"> <span class="opacitymedium">'.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavTaxNumber').'</td><td><input class="minwidth200" maxlength="8" type="text" name="tax_number" value="'.dol_escape_htmltag(getDolGlobalString('NAVINVOICE_TAX_NUMBER')).'"> <span class="opacitymedium">'.$langs->trans('NavTaxNumberHelp').'</span></td></tr>';
print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('NavSigningKey').'</td><td><input class="minwidth300" type="password" name="signing_key" value="" autocomplete="new-password"> <span class="opacitymedium">'.$langs->trans('LeaveBlankToKeep').'</span></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('ScheduledSync').'</td><td><input type="checkbox" name="sync_enabled" value="1"'.(getDolGlobalInt('NAVINVOICE_SYNC_ENABLED') ? ' checked' : '').'></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('SyncLookbackDays').'</td><td><input type="number" min="1" max="35" name="lookback_days" value="'.getDolGlobalInt('NAVINVOICE_SYNC_LOOKBACK_DAYS', 7).'"></td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('DownloadFullInvoiceXml').'</td><td><input type="checkbox" name="fetch_full_data" value="1"'.(getDolGlobalInt('NAVINVOICE_FETCH_FULL_DATA', 1) ? ' checked' : '').'></td></tr>';
print '</table>';
print '<div class="center"><input class="button button-save" type="submit" value="'.$langs->trans('Save').'"></div>';
print '</form>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" class="center">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="test">';
print '<input class="button" type="submit" value="'.$langs->trans('TestNavConnection').'">';
print '</form>';

llxFooter();
$db->close();
