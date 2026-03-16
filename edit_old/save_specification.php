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

function normalizeActive($v): int
{
  if (is_array($v)) {
    $v = $v[0] ?? null;
  }

  if ($v === true || (string)$v === 'Y' || (int)$v === 1) {
    return 1;
  }

  return 0;
}

if (!check_bitrix_sessid()) {
  jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('crm')) {
  jsonOut(['ok' => false, 'error' => 'crm'], 500);
}

$headId = (int)($_POST['headId'] ?? 0);
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

if ($headId <= 0 || $title === '' || $specId <= 0) {
  jsonOut(['ok' => false, 'error' => 'validate_head'], 400);
}

$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow = Container::getInstance()->getFactory(1146);

if (!$factoryHead || !$factoryRow) {
  jsonOut(['ok' => false, 'error' => 'factory'], 500);
}

$head = $factoryHead->getItem($headId);
if (!$head) {
  jsonOut(['ok' => false, 'error' => 'head_not_found', 'headId' => $headId], 404);
}

$activeNow = normalizeActive($head->get('UF_CRM_27_1767865292'));

// Head fields
$head->setTitle($title);
// UF_CRM_27_1752661803131 is multiple, but contains only one value.
$head->set('UF_CRM_27_1752661803131', [$specId]);

// Existing row IDs
$existingRowIds = $head->get('UF_CRM_27_1751274867');
if (!is_array($existingRowIds)) {
  $existingRowIds = $existingRowIds ? [$existingRowIds] : [];
}
$existingRowIds = array_values(array_filter(array_map('intval', $existingRowIds), static fn($v) => $v > 0));
$existingRowIdSet = array_fill_keys($existingRowIds, true);

// cache parent head -> rowIds
$headRowIdsCache = [];
$getHeadRowIdsById = function (int $hid) use ($factoryHead, &$headRowIdsCache): ?array {
  if ($hid <= 0) {
    return [];
  }

  if (array_key_exists($hid, $headRowIdsCache)) {
    return $headRowIdsCache[$hid];
  }

  $h = $factoryHead->getItem($hid);
  if (!$h) {
    $headRowIdsCache[$hid] = null;
    return null;
  }

  $ids = $h->get('UF_CRM_27_1751274867');
  if (!is_array($ids)) {
    $ids = $ids ? [$ids] : [];
  }

  $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
  $headRowIdsCache[$hid] = $ids;
  return $ids;
};

$incomingExistingIds = [];
$incomingRows = [];

foreach ($raw as $row) {
  if (!is_array($row)) {
    continue;
  }

  $rowId = (int)($row['rowId'] ?? 0);
  $rawId = (int)($row['rawId'] ?? 0);
  $qty = (float)($row['qty'] ?? 0);
  $unit = trim((string)($row['unit'] ?? ''));
  $rowTitle = trim((string)($row['title'] ?? ''));

  // If rowId exists - row must be valid (otherwise user should delete it via X)
  if ($rowId > 0 && ($rawId <= 0 || $qty <= 0 || $unit === '')) {
    jsonOut([
      'ok' => false,
      'error' => 'validate_row',
      'rowId' => $rowId,
    ], 400);
  }

  // New empty rows are ignored
  if ($rowId <= 0 && ($rawId <= 0 || $qty <= 0 || $unit === '')) {
    continue;
  }

  $incomingRows[] = [
    'rowId' => $rowId,
    'rawId' => $rawId,
    'qty' => $qty,
    'unit' => $unit,
    'title' => $rowTitle,
  ];

  if ($rowId > 0) {
    $incomingExistingIds[] = $rowId;
  }
}

$incomingExistingIds = array_values(array_unique(array_filter($incomingExistingIds, static fn($v) => $v > 0)));

$toDelete = array_values(array_diff($existingRowIds, $incomingExistingIds));

$deleted = [];
$updated = [];
$created = [];
$keepIds = [];
$warnings = [];

// Delete removed rows
foreach ($toDelete as $rid) {
  $rid = (int)$rid;
  if ($rid <= 0) {
    continue;
  }

  $rowItem = $factoryRow->getItem($rid);
  if (!$rowItem) {
    // already deleted
    continue;
  }

  $parentId = (int)$rowItem->get('UF_CRM_28_1752667276886');
  if ($parentId !== $headId) {
    // If row реально принадлежит другому head (он содержит rowId в своём UF списке) - не удаляем.
    $parentRows = $getHeadRowIdsById($parentId);
    if (is_array($parentRows) && in_array($rid, $parentRows, true)) {
      $warnings[] = 'skip_delete_rowId=' . $rid . '_parentId=' . $parentId;
      continue;
    }

    // Otherwise, treat as broken data: repair parentId and continue delete.
    $rowItem->set('UF_CRM_28_1752667276886', $headId);
    $resFix = $factoryRow->getUpdateOperation($rowItem)->launch();
    if (!$resFix->isSuccess()) {
      jsonOut([
        'ok' => false,
        'error' => 'row_repair_parent',
        'rowId' => $rid,
        'errors' => $resFix->getErrorMessages(),
      ], 400);
    }

    $warnings[] = 'repair_parent_before_delete_rowId=' . $rid . '_from=' . $parentId;
  }

  $resDel = $factoryRow->getDeleteOperation($rowItem)->launch();
  if (!$resDel->isSuccess()) {
    jsonOut([
      'ok' => false,
      'error' => 'row_delete',
      'rowId' => $rid,
      'errors' => $resDel->getErrorMessages(),
    ], 400);
  }

  $deleted[] = $rid;
}

// Update / create rows
foreach ($incomingRows as $r) {
  $rowId = (int)$r['rowId'];
  $rawId = (int)$r['rawId'];
  $qty = (float)$r['qty'];
  $unit = (string)$r['unit'];
  $rowTitle = (string)$r['title'];

  if ($rowId > 0) {
    // Extra safety: do not accept чужие rowId not present in current head list.
    if (!isset($existingRowIdSet[$rowId])) {
      $warnings[] = 'skip_update_rowId_not_in_head=' . $rowId;
      continue;
    }

    $rowItem = $factoryRow->getItem($rowId);
    if (!$rowItem) {
      jsonOut(['ok' => false, 'error' => 'row_not_found', 'rowId' => $rowId], 404);
    }

    $parentId = (int)$rowItem->get('UF_CRM_28_1752667276886');
    if ($parentId !== $headId) {
      // If row реально принадлежит другому head - не трогаем.
      $parentRows = $getHeadRowIdsById($parentId);
      if (is_array($parentRows) && in_array($rowId, $parentRows, true)) {
        $warnings[] = 'skip_update_rowId=' . $rowId . '_parentId=' . $parentId;
        $keepIds[] = $rowId;
        continue;
      }

      // Otherwise repair broken parentId and continue update.
      $rowItem->set('UF_CRM_28_1752667276886', $headId);
      $warnings[] = 'repair_parent_rowId=' . $rowId . '_from=' . $parentId;
    }

    $rowItem->setTitle($rowTitle !== '' ? $rowTitle : ('Сырьё ID=' . $rawId));
    $rowItem->set('UF_CRM_28_1752667325075', $unit);
    $rowItem->set('UF_CRM_28_1751274777', $qty);
    $rowItem->set('UF_CRM_28_1751274644', $rawId);

    $resUpd = $factoryRow->getUpdateOperation($rowItem)->launch();
    if (!$resUpd->isSuccess()) {
      jsonOut([
        'ok' => false,
        'error' => 'row_update',
        'rowId' => $rowId,
        'errors' => $resUpd->getErrorMessages(),
      ], 400);
    }

    $updated[] = $rowId;
    $keepIds[] = $rowId;
    continue;
  }

  // Create new row
  $item = $factoryRow->createItem();
  $item->setTitle($rowTitle !== '' ? $rowTitle : ('Сырьё ID=' . $rawId));

  $item->set('UF_CRM_28_1752667276886', $headId);
  $item->set('UF_CRM_28_1752667325075', $unit);
  $item->set('UF_CRM_28_1751274777', $qty);
  $item->set('UF_CRM_28_1751274644', $rawId);

  $resAdd = $factoryRow->getAddOperation($item)->launch();
  if (!$resAdd->isSuccess()) {
    jsonOut([
      'ok' => false,
      'error' => 'row_create',
      'headId' => $headId,
      'errors' => $resAdd->getErrorMessages(),
    ], 400);
  }

  $newId = (int)$item->getId();
  if ($newId > 0) {
    $created[] = $newId;
    $keepIds[] = $newId;
  }
}

$finalRowIds = array_values(array_unique(array_filter(array_map('intval', $keepIds), static fn($v) => $v > 0)));

$head->set('UF_CRM_27_1751274867', $finalRowIds);
$head->set('UF_SWITCH_ACTIVE', Json::encode(['id' => $headId, 'active' => $activeNow], JSON_UNESCAPED_UNICODE));

$resHead = $factoryHead->getUpdateOperation($head)->launch();
if (!$resHead->isSuccess()) {
  jsonOut([
    'ok' => false,
    'error' => 'head_update',
    'headId' => $headId,
    'errors' => $resHead->getErrorMessages(),
  ], 400);
}

jsonOut([
  'ok' => true,
  'headId' => $headId,
  'rowIds' => $finalRowIds,
  'deleted' => $deleted,
  'updated' => $updated,
  'created' => $created,
  'warnings' => $warnings,
]);
