<?php
/**
 * /local/specifications/edit/save_specification.php
 * Сохранение спецификации — расширенная версия с новыми полями этапа.
 *
 * POST: sessid, headId, title, specId, steps (JSON)
 *
 * Структура одного шага в steps[]:
 *   rowId   — int, 0=новый
 *   rawId   — int, ID элемента инфоблока (материал)
 *   qty     — float
 *   unit    — string (текст единицы)
 *   section — int, ID элемента инфоблока 26 (участок)
 *   desc    — string
 *   time    — int (минуты)
 *   alts    — array [{name,qty}]
 */

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
    if (is_array($v)) $v = $v[0] ?? null;
    if ($v === true || (string)$v === 'Y' || (int)$v === 1) return 1;
    return 0;
}

if (!check_bitrix_sessid()) {
    jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('crm')) {
    jsonOut(['ok' => false, 'error' => 'crm'], 500);
}

// ── Входные данные ────────────────────────────────────────────────────────────
$headId  = (int)($_POST['headId']  ?? 0);
$title   = trim((string)($_POST['title']  ?? ''));
$specId  = (int)($_POST['specId']  ?? 0);

try {
    $incomingSteps = Json::decode((string)($_POST['steps'] ?? '[]'));
    if (!is_array($incomingSteps)) $incomingSteps = [];
} catch (\Throwable $e) {
    $incomingSteps = [];
}

if ($headId <= 0 || $title === '' || $specId <= 0) {
    jsonOut(['ok' => false, 'error' => 'validate_head'], 400);
}

// ── Фабрики ───────────────────────────────────────────────────────────────────
$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow  = Container::getInstance()->getFactory(1146);

if (!$factoryHead || !$factoryRow) {
    jsonOut(['ok' => false, 'error' => 'factory'], 500);
}

$head = $factoryHead->getItem($headId);
if (!$head) {
    jsonOut(['ok' => false, 'error' => 'head_not_found', 'headId' => $headId], 404);
}

$activeNow = normalizeActive($head->get('UF_CRM_27_1767865292'));

// ── Существующие строки ───────────────────────────────────────────────────────
$existingRowIds = $head->get('UF_CRM_27_1751274867');
if (!is_array($existingRowIds)) {
    $existingRowIds = $existingRowIds ? [$existingRowIds] : [];
}
$existingRowIds   = array_values(array_filter(array_map('intval', $existingRowIds), static fn($v) => $v > 0));
$existingRowIdSet = array_fill_keys($existingRowIds, true);

// ── Разбираем входящие шаги ───────────────────────────────────────────────────
$incomingExistingIds = [];
$validSteps          = [];

foreach ($incomingSteps as $step) {
    if (!is_array($step)) continue;

    $rowId   = (int)($step['rowId']  ?? 0);
    $rawId   = (int)($step['rawId']  ?? 0);
    $qty     = (float)($step['qty']  ?? 0);
    $unit    = trim((string)($step['unit']    ?? ''));
    $section = (int)($step['section'] ?? 0);
    $desc    = trim((string)($step['desc']    ?? ''));
    $time    = (int)($step['time']   ?? 0);
    $alts    = is_array($step['alts'] ?? null) ? $step['alts'] : [];

    // Существующая строка с невалидными данными — ошибка
    if ($rowId > 0 && ($rawId <= 0 || $qty <= 0)) {
        jsonOut(['ok' => false, 'error' => 'validate_step', 'rowId' => $rowId], 400);
    }

    // Новая пустая строка — пропускаем
    if ($rowId <= 0 && ($rawId <= 0 || $qty <= 0)) continue;

    // Нормализуем альтернативы
    $cleanAlts = [];
    foreach ($alts as $alt) {
        if (!is_array($alt)) continue;
        $altName = trim((string)($alt['name'] ?? ''));
        $altQty  = (float)($alt['qty'] ?? 0);
        if ($altName !== '') {
            $cleanAlts[] = ['name' => $altName, 'qty' => $altQty];
        }
    }

    $validSteps[] = compact('rowId', 'rawId', 'qty', 'unit', 'section', 'desc', 'time', 'cleanAlts');

    if ($rowId > 0) $incomingExistingIds[] = $rowId;
}

$incomingExistingIds = array_values(array_unique(array_filter($incomingExistingIds)));

// ── Удаляем строки которых больше нет ────────────────────────────────────────
$toDelete = array_diff($existingRowIds, $incomingExistingIds);
$deleted  = [];

foreach ($toDelete as $rid) {
    $rid     = (int)$rid;
    $rowItem = $factoryRow->getItem($rid);
    if (!$rowItem) continue;

    $parentId = (int)$rowItem->get('UF_CRM_28_1752667276886');
    if ($parentId !== $headId) continue;

    $resDel = $factoryRow->getDeleteOperation($rowItem)->launch();
    if (!$resDel->isSuccess()) {
        jsonOut(['ok' => false, 'error' => 'row_delete', 'rowId' => $rid, 'errors' => $resDel->getErrorMessages()], 400);
    }
    $deleted[] = $rid;
}

// ── Обновляем / создаём строки ────────────────────────────────────────────────
$updated = [];
$created = [];
$keepIds = [];

foreach ($validSteps as $s) {
    $rowId    = (int)$s['rowId'];
    $altsJson = Json::encode($s['cleanAlts'], JSON_UNESCAPED_UNICODE);

    if ($rowId > 0) {
        if (!isset($existingRowIdSet[$rowId])) continue;

        $rowItem = $factoryRow->getItem($rowId);
        if (!$rowItem) {
            jsonOut(['ok' => false, 'error' => 'row_not_found', 'rowId' => $rowId], 404);
        }

        $rowTitle = $s['desc'] !== '' ? mb_substr($s['desc'], 0, 60) : ('Материал ID=' . $s['rawId']);

        $rowItem->setTitle($rowTitle);
        $rowItem->set('UF_CRM_28_1751274644',   (string)$s['rawId']);
        $rowItem->set('UF_CRM_28_1751274777',   $s['qty']);
        $rowItem->set('UF_CRM_28_1752667325075', $s['unit']);
        $rowItem->set('UF_CRM_28_1773394604',   $s['section'] > 0 ? $s['section'] : null);
        $rowItem->set('UF_CRM_28_1773394725',   $s['desc']);
        $rowItem->set('UF_CRM_28_1773394804',   $s['time']);
        $rowItem->set('UF_CRM_28_1773394825',   $altsJson);

        $resUpd = $factoryRow->getUpdateOperation($rowItem)->launch();
        if (!$resUpd->isSuccess()) {
            jsonOut(['ok' => false, 'error' => 'row_update', 'rowId' => $rowId, 'errors' => $resUpd->getErrorMessages()], 400);
        }

        $updated[] = $rowId;
        $keepIds[] = $rowId;
        continue;
    }

    // Новая строка
    $rowTitle = $s['desc'] !== '' ? mb_substr($s['desc'], 0, 60) : ('Материал ID=' . $s['rawId']);

    $rowItem = $factoryRow->createItem();
    $rowItem->setTitle($rowTitle);
    $rowItem->set('UF_CRM_28_1752667276886',  $headId);
    $rowItem->set('UF_CRM_28_1751274644',     (string)$s['rawId']);
    $rowItem->set('UF_CRM_28_1751274777',     $s['qty']);
    $rowItem->set('UF_CRM_28_1752667325075',  $s['unit']);
    $rowItem->set('UF_CRM_28_1773394604',    $s['section'] > 0 ? $s['section'] : null);
    $rowItem->set('UF_CRM_28_1773394725',    $s['desc']);
    $rowItem->set('UF_CRM_28_1773394804',    $s['time']);
    $rowItem->set('UF_CRM_28_1773394825',    $altsJson);

    $resAdd = $factoryRow->getAddOperation($rowItem)->launch();
    if (!$resAdd->isSuccess()) {
        jsonOut(['ok' => false, 'error' => 'row_create', 'errors' => $resAdd->getErrorMessages()], 400);
    }

    $newId = (int)$rowItem->getId();
    if ($newId > 0) {
        $created[] = $newId;
        $keepIds[] = $newId;
    }
}

// ── Обновляем head ────────────────────────────────────────────────────────────
$finalRowIds = array_values(array_unique(array_filter(array_map('intval', $keepIds))));

$head->setTitle($title);
$head->set('UF_CRM_27_1752661803131', [$specId]);
$head->set('UF_CRM_27_1751274867', $finalRowIds);
$head->set('UF_SWITCH_ACTIVE', Json::encode(['id' => $headId, 'active' => $activeNow], JSON_UNESCAPED_UNICODE));

$resHead = $factoryHead->getUpdateOperation($head)->launch();
if (!$resHead->isSuccess()) {
    jsonOut(['ok' => false, 'error' => 'head_update', 'headId' => $headId, 'errors' => $resHead->getErrorMessages()], 400);
}

jsonOut([
    'ok'      => true,
    'headId'  => $headId,
    'rowIds'  => $finalRowIds,
    'deleted' => $deleted,
    'updated' => $updated,
    'created' => $created,
]);