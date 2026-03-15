<?php
use Bitrix\Main\Loader;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);

if (!check_bitrix_sessid()) {
  http_response_code(403);
  die('sessid');
}

if (!Loader::includeModule('ui')) {
  http_response_code(500);
  die('ui');
}
if (!Loader::includeModule('crm')) {
  http_response_code(500);
  die('crm');
}

global $APPLICATION, $USER_FIELD_MANAGER;

$index = isset($_POST['index']) ? (int)$_POST['index'] : 0;

$crmEntityId = 'CRM_27';
$userFields = $USER_FIELD_MANAGER->GetUserFields($crmEntityId, 0, LANGUAGE_ID);

$code = 'UF_CRM_27_1767622214';
if (empty($userFields[$code])) {
  $APPLICATION->RestartBuffer();
  echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Не найдено поле: '
    . htmlspecialcharsbx($code)
    . '</span></div>';
  die();
}

$uf = $userFields[$code];
$uf['ENTITY_VALUE_ID'] = 0;

// ВАЖНО: поле UF одиночное, но в форме сырьё хранится построчно.
// Поэтому переопределяем имя, чтобы значение попало в RAW[index][RAWID].
$uf['FIELD_NAME'] = 'RAW[' . $index . '][RAWID]';
$uf['VALUE'] = null;

$APPLICATION->RestartBuffer();
$APPLICATION->IncludeComponent(
  'bitrix:system.field.edit',
  $uf['USER_TYPE_ID'],
  [
    'arUserField'   => $uf,
    'bVarsFromForm' => false,
    'form_name'     => 'speccreateform_raw_' . $index,
  ],
  false,
  ['HIDE_ICONS' => 'Y']
);

die();
