<?php
/**
 * /local/specifications/create/create_spec.php
 * Создание новой спецификации с этапами одним запросом.
 *
 * POST: sessid, title, specId (ID товара из ИБ14), steps (JSON)
 *
 * Структура steps[] — аналогична edit/save_specification.php (rowId игнорируется, всё создаётся новым).
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

if (!check_bitrix_sessid()) {
    jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('crm')) {
    jsonOut(['ok' => false, 'error' => 'crm'], 500);
}

$title  = trim((string)($_POST['title']  ?? ''));
$specId = (int)($_POST['specId'] ?? 0);

try {
    $incomingSteps = Json::decode((string)($_POST['steps'] ?? '[]'));
    if (!is_array($incomingSteps)) $incomingSteps = [];
} catch (\Throwable $e) {
    $incomingSteps = [];
}

if ($title === '' || $specId <= 0) {
    jsonOut(['ok' => false, 'error' => 'validate'], 400);
}

$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow  = Container::getInstance()->getFactory(1146);
if (!$factoryHead || !$factoryRow) jsonOut(['ok' => false, 'error' => 'factory'], 500);

// 1) Создаём head (SP 1142)
$head = $factoryHead->createItem();
$head->setTitle($title);
$head->set('UF_CRM_27_1752661803131', $specId);
$head->set('UF_CRM_27_1767865292', 1);

$resHead = $factoryHead->getAddOperation($head)->launch();
if (!$resHead->isSuccess()) {
    jsonOut(['ok' => false, 'error' => 'head_create', 'errors' => $resHead->getErrorMessages()], 400);
}

$headId = (int)$head->getId();

// 2) Создаём этапы и альтернативы (SP 1146)
$finalMainIds = [];

foreach ($incomingSteps as $step) {
    if (!is_array($step)) continue;

    $rawId      = (int)($step['rawId']       ?? 0);
    $mat        = trim((string)($step['mat']        ?? ''));
    $qty        = (float)($step['qty']        ?? 0);
    $measureSym = trim((string)($step['measureSym']  ?? ''));
    $section    = (int)($step['section']      ?? 0);
    $desc       = trim((string)($step['desc']  ?? ''));
    $time       = (int)($step['time']         ?? 0);
    $alts       = is_array($step['alts'] ?? null) ? $step['alts'] : [];

    if ($rawId <= 0) continue;

    // Сначала создаём альтернативы, чтобы получить их ID
    $altIds = [];
    foreach ($alts as $alt) {
        if (!is_array($alt)) continue;
        $altRawId      = (int)($alt['rawId']        ?? 0);
        $altMat        = trim((string)($alt['mat']         ?? ''));
        $altQty        = (float)($alt['qty']        ?? 0);
        $altMeasureSym = trim((string)($alt['measureSym']  ?? ''));

        if ($altRawId <= 0) continue;

        $altItem = $factoryRow->createItem();
        $altItem->setTitle($altMat !== '' ? mb_substr($altMat, 0, 60) : ('Материал #' . $altRawId));
        $altItem->set('UF_CRM_28_1773398091',    616);
        $altItem->set('UF_CRM_28_1751274644',    (string)$altRawId);
        $altItem->set('UF_CRM_28_1751274777',    $altQty);
        $altItem->set('UF_CRM_28_1752667325075', $altMeasureSym);
        $altItem->set('UF_CRM_28_1773394825',    '[]');

        $resAlt = $factoryRow->getAddOperation($altItem)->launch();
        if (!$resAlt->isSuccess()) continue;

        $altId = (int)$altItem->getId();

        $altItem->set('UF_CRM_28_1752667276886', $headId);
        $factoryRow->getUpdateOperation($altItem)->launch();

        if ($altId > 0) $altIds[] = $altId;
    }

    $altsJson = Json::encode($altIds, JSON_UNESCAPED_UNICODE);

    // Создаём основной этап
    $item = $factoryRow->createItem();
    $item->setTitle($mat !== '' ? mb_substr($mat, 0, 60) : ('Материал #' . $rawId));
    $item->set('UF_CRM_28_1773398091',    615);
    $item->set('UF_CRM_28_1751274644',    (string)$rawId);
    $item->set('UF_CRM_28_1751274777',    $qty);
    $item->set('UF_CRM_28_1752667325075', $measureSym);
    $item->set('UF_CRM_28_1773394604',    $section > 0 ? $section : null);
    $item->set('UF_CRM_28_1773394725',    $desc);
    $item->set('UF_CRM_28_1773394804',    $time);
    $item->set('UF_CRM_28_1773394825',    $altsJson);

    $resRow = $factoryRow->getAddOperation($item)->launch();
    if (!$resRow->isSuccess()) continue;

    $mainId = (int)$item->getId();

    $item->set('UF_CRM_28_1752667276886', $headId);
    $factoryRow->getUpdateOperation($item)->launch();

    if ($mainId > 0) $finalMainIds[] = $mainId;
}

// 3) Обновляем head: сохраняем ID этапов и служебные поля
$head->set('UF_CRM_27_1751274867', $finalMainIds);
$head->set('UF_EDIT_BUTTON', $headId);
$head->set('UF_SWITCH_ACTIVE', Json::encode(['id' => $headId, 'active' => 1], JSON_UNESCAPED_UNICODE));
$factoryHead->getUpdateOperation($head)->launch();

jsonOut([
    'ok'         => true,
    'headId'     => $headId,
    'rowIds'     => $finalMainIds,
    'stepsCount' => count($finalMainIds),
    'title'      => $title,
]);
