<?php
use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;
use Bitrix\Crm\Service\Container;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);

header('Content-Type: application/json; charset=utf-8');

function jsonOut(array $data, int $status = 200): void
{
  http_response_code($status);
  echo Json::encode($data, JSON_UNESCAPED_UNICODE);
  die();
}

if (!check_bitrix_sessid()) {
  jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('crm')) {
  jsonOut(['ok' => false, 'error' => 'crm'], 500);
}

$headId = (int)($_POST['headId'] ?? 0);
$active = (int)($_POST['active'] ?? -1);

if ($headId <= 0 || ($active !== 0 && $active !== 1)) {
  jsonOut(['ok' => false, 'error' => 'validate'], 400);
}

$factoryHead = Container::getInstance()->getFactory(1142);
if (!$factoryHead) {
  jsonOut(['ok' => false, 'error' => 'factory'], 500);
}

$head = $factoryHead->getItem($headId);
if (!$head) {
  jsonOut(['ok' => false, 'error' => 'head_not_found', 'headId' => $headId], 404);
}

$head->set('UF_CRM_27_1767865292', $active);
$head->set('UF_SWITCH_ACTIVE', Json::encode(['id' => $headId, 'active' => $active], JSON_UNESCAPED_UNICODE));

$res = $factoryHead->getUpdateOperation($head)->launch();
if (!$res->isSuccess()) {
  jsonOut([
    'ok' => false,
    'error' => 'update',
    'headId' => $headId,
    'errors' => $res->getErrorMessages(),
  ], 400);
}

jsonOut(['ok' => true, 'headId' => $headId, 'active' => $active]);
