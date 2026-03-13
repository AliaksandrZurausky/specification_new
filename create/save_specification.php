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

$title = trim((string)($_POST['title'] ?? ''));
$specId = (int)($_POST['specId'] ?? 0);

$rawJson = (string)($_POST['raw'] ?? '[]');
try {
  $raw = Json::decode($rawJson);
  if (!is_array($raw)) {
    $raw = [];
  }
} catch (\Throwable $e) {
  $raw = [];
}

if ($title === '' || $specId <= 0) {
  jsonOut(['ok' => false, 'error' => 'validate_head'], 400);
}

$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow = Container::getInstance()->getFactory(1146);

if (!$factoryHead || !$factoryRow) {
  jsonOut(['ok' => false, 'error' => 'factory'], 500);
}

// 1) Create head (SP 1142)
$head = $factoryHead->createItem();
$head->setTitle($title);
$head->set('UF_CRM_27_1752661803131', $specId);
// Active by default ("Да")
$head->set('UF_CRM_27_1767865292', 1);

$resHead = $factoryHead->getAddOperation($head)->launch();
if (!$resHead->isSuccess()) {
  jsonOut([
    'ok' => false,
    'error' => 'head_create',
    'errors' => $resHead->getErrorMessages(),
  ], 400);
}

$headId = (int)$head->getId();

// 2) Create raw rows (SP 1146)
$rowIds = [];
foreach ($raw as $row) {
  if (!is_array($row)) {
    continue;
  }

  $rawId = (int)($row['rawId'] ?? 0);
  $qty = (float)($row['qty'] ?? 0);
  $unit = trim((string)($row['unit'] ?? ''));
  $rowTitle = trim((string)($row['title'] ?? ''));

  if ($rawId <= 0 || $qty <= 0 || $unit === '') {
    continue;
  }

  $item = $factoryRow->createItem();
  $item->setTitle($rowTitle !== '' ? $rowTitle : ('Сырьё ID=' . $rawId));

  $item->set('UF_CRM_28_1752667276886', $headId);
  $item->set('UF_CRM_28_1752667325075', $unit);
  $item->set('UF_CRM_28_1751274777', $qty);
  $item->set('UF_CRM_28_1751274644', $rawId);

  $resRow = $factoryRow->getAddOperation($item)->launch();
  if (!$resRow->isSuccess()) {
    jsonOut([
      'ok' => false,
      'error' => 'row_create',
      'headId' => $headId,
      'errors' => $resRow->getErrorMessages(),
    ], 400);
  }

  $rowIds[] = (int)$item->getId();
}

// 3) Update head with list of created rows + set UF_EDIT_BUTTON to headId (for list "Изменить" button)
$head->set('UF_CRM_27_1751274867', $rowIds);
$head->set('UF_EDIT_BUTTON', $headId);
$head->set('UF_SWITCH_ACTIVE', Json::encode(['id' => $headId, 'active' => 1], JSON_UNESCAPED_UNICODE));
$resUpd = $factoryHead->getUpdateOperation($head)->launch();
if (!$resUpd->isSuccess()) {
  jsonOut([
    'ok' => false,
    'error' => 'head_update',
    'headId' => $headId,
    'rowIds' => $rowIds,
    'errors' => $resUpd->getErrorMessages(),
  ], 400);
}

jsonOut(['ok' => true, 'headId' => $headId, 'rowIds' => $rowIds]);
