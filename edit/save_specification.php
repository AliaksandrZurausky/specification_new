<?php
/**
 * /local/specifications/edit/save_specification.php
 * Сохранение спецификации. Основные этапы — тип 615, альтернативы — тип 616.
 *
 * POST: sessid, headId, title, steps (JSON)
 *
 * Структура steps[]:
 * {
 *   rowId:      int (0=новый),
 *   rawId:      int (ID товара ИБ14),
 *   qty:        float,
 *   measureSym: string (единица измерения, напр. «шт», «м²»),
 *   section:    int (ID элемента ИБ26),
 *   desc:       string,
 *   time:       int,
 *   alts: [{ rowId: int, rawId: int, qty: float, measureSym: string }]
 * }
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

$headId = (int)($_POST['headId'] ?? 0);
$title  = trim((string)($_POST['title'] ?? ''));

try {
    $incomingSteps = Json::decode((string)($_POST['steps'] ?? '[]'));
    if (!is_array($incomingSteps)) $incomingSteps = [];
} catch (\Throwable $e) {
    $incomingSteps = [];
}

if ($headId <= 0 || $title === '') {
    jsonOut(['ok' => false, 'error' => 'validate'], 400);
}

$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow  = Container::getInstance()->getFactory(1146);
if (!$factoryHead || !$factoryRow) jsonOut(['ok' => false, 'error' => 'factory'], 500);

$head = $factoryHead->getItem($headId);
if (!$head) jsonOut(['ok' => false, 'error' => 'head_not_found'], 404);

// Текущие строки head (только основные)
$existingIds = $head->get('UF_CRM_27_1751274867');
if (!is_array($existingIds)) $existingIds = $existingIds ? [$existingIds] : [];
$existingIds = array_values(array_filter(array_map('intval', $existingIds), fn($v) => $v > 0));

// ── Вспомогательные функции ───────────────────────────────────────────────────

function saveRow(
    \Bitrix\Crm\Service\Factory $factory,
    int $rowId,
    int $headId,
    int $type,
    int $rawId,
    float $qty,
    string $measureSym,
    int $section,
    string $desc,
    int $time,
    string $altsJson,
    string $mat = ''
): int
{
    $isNew = $rowId <= 0;
    $item  = $isNew ? $factory->createItem() : $factory->getItem($rowId);
    if (!$item) return 0;

    $itemTitle = $mat !== '' ? mb_substr($mat, 0, 60) : ('Материал #' . $rawId);

    $item->setTitle($itemTitle);
    $item->set('UF_CRM_28_1773398091',    $type);          // тип: 615=основной, 616=альтернатива
    $item->set('UF_CRM_28_1752667325075', $measureSym);    // единица измерения (строка)
    $item->set('UF_CRM_28_1751274644',    (string)$rawId); // ID товара
    $item->set('UF_CRM_28_1751274777',    $qty);           // количество
    $item->set('UF_CRM_28_1773394604',    $section > 0 ? $section : null); // участок
    $item->set('UF_CRM_28_1773394725',    $desc);          // описание
    $item->set('UF_CRM_28_1773394804',    $time);          // время
    $item->set('UF_CRM_28_1773394825',    $altsJson);      // JSON alt IDs

    if (!$isNew) {
        $item->set('UF_CRM_28_1752667276886', $headId);   // parentId
    }

    $op = $isNew
        ? $factory->getAddOperation($item)
        : $factory->getUpdateOperation($item);

    $res = $op->launch();
    if (!$res->isSuccess()) return 0;

    return (int)$item->getId();
}

function deleteRow(\Bitrix\Crm\Service\Factory $factory, int $rowId): void
{
    $item = $factory->getItem($rowId);
    if ($item) $factory->getDeleteOperation($item)->launch();
}

// ── Обработка входящих шагов ─────────────────────────────────────────────────
$finalMainIds = [];

// Собираем все incoming основные rowId для определения удалённых
$incomingMainIds = array_filter(array_map(
    fn($s) => (int)($s['rowId'] ?? 0),
    $incomingSteps
), fn($v) => $v > 0);

// Удаляем основные строки которых больше нет (и их альтернативы)
foreach ($existingIds as $existId) {
    if (!in_array($existId, $incomingMainIds, true)) {
        // Загружаем чтобы удалить альтернативы
        $oldItem = $factoryRow->getItem($existId);
        if ($oldItem) {
            $oldAltsJson = (string)$oldItem->get('UF_CRM_28_1773394825');
            if ($oldAltsJson !== '') {
                try {
                    $oldAltIds = Json::decode($oldAltsJson);
                    if (is_array($oldAltIds)) {
                        foreach ($oldAltIds as $oldAltId) {
                            deleteRow($factoryRow, (int)$oldAltId);
                        }
                    }
                } catch (\Throwable $e) {}
            }
        }
        deleteRow($factoryRow, $existId);
    }
}

// Сохраняем шаги
foreach ($incomingSteps as $step) {
    if (!is_array($step)) continue;

    $rowId      = (int)($step['rowId']      ?? 0);
    $rawId      = (int)($step['rawId']      ?? 0);
    $mat        = trim((string)($step['mat']       ?? ''));
    $qty        = (float)($step['qty']      ?? 0);
    $measureSym = trim((string)($step['measureSym'] ?? ''));
    $section    = (int)($step['section']    ?? 0);
    $desc       = trim((string)($step['desc'] ?? ''));
    $time       = (int)($step['time']       ?? 0);
    $alts       = is_array($step['alts'] ?? null) ? $step['alts'] : [];

    if ($rawId <= 0) continue;

    // Сохраняем альтернативы сначала (нам нужны их ID для JSON)
    $altIds = [];

    // Текущие alt IDs из существующей строки (для определения удалённых)
    $existingAltIds = [];
    if ($rowId > 0) {
        $existingMain = $factoryRow->getItem($rowId);
        if ($existingMain) {
            $existingAltsJson = (string)$existingMain->get('UF_CRM_28_1773394825');
            if ($existingAltsJson !== '') {
                try {
                    $decoded = Json::decode($existingAltsJson);
                    if (is_array($decoded)) {
                        $existingAltIds = array_filter(array_map('intval', $decoded));
                    }
                } catch (\Throwable $e) {}
            }
        }
    }

    $incomingAltIds = array_filter(array_map(
        fn($a) => (int)($a['rowId'] ?? 0),
        $alts
    ), fn($v) => $v > 0);

    // Удаляем альтернативы которых больше нет
    foreach ($existingAltIds as $oldAltId) {
        if (!in_array($oldAltId, $incomingAltIds, true)) {
            deleteRow($factoryRow, $oldAltId);
        }
    }

    // Сохраняем/создаём альтернативы
    foreach ($alts as $alt) {
        if (!is_array($alt)) continue;
        $altRowId      = (int)($alt['rowId']      ?? 0);
        $altRawId      = (int)($alt['rawId']       ?? 0);
        $altMat        = trim((string)($alt['mat']        ?? ''));
        $altQty        = (float)($alt['qty']       ?? 0);
        $altMeasureSym = trim((string)($alt['measureSym'] ?? ''));

        if ($altRawId <= 0) continue;

        $savedAltId = saveRow(
            $factoryRow,
            $altRowId,
            $headId,
            616, // тип: альтернатива
            $altRawId,
            $altQty,
            $altMeasureSym,
            0,   // section у альтернативы не нужен
            '',  // desc у альтернативы не нужен
            0,   // time у альтернативы не нужен
            '[]',
            $altMat
        );

        if ($savedAltId > 0) $altIds[] = $savedAltId;
    }

    $altsJson = Json::encode($altIds, JSON_UNESCAPED_UNICODE);

    // Сохраняем основную строку
    $savedMainId = saveRow(
        $factoryRow,
        $rowId,
        $headId,
        615, // тип: основной
        $rawId,
        $qty,
        $measureSym,
        $section,
        $desc,
        $time,
        $altsJson,
        $mat
    );

    // Для новых строк — ставим parentId отдельным вызовом
    if ($rowId <= 0 && $savedMainId > 0) {
        $newItem = $factoryRow->getItem($savedMainId);
        if ($newItem) {
            $newItem->set('UF_CRM_28_1752667276886', $headId);
            $factoryRow->getUpdateOperation($newItem)->launch();
        }
    }

    if ($savedMainId > 0) $finalMainIds[] = $savedMainId;
}

// ── Обновляем head ────────────────────────────────────────────────────────────
$head->setTitle($title);
$head->set('UF_CRM_27_1751274867', $finalMainIds);

$resHead = $factoryHead->getUpdateOperation($head)->launch();
if (!$resHead->isSuccess()) {
    jsonOut(['ok' => false, 'error' => 'head_update', 'errors' => $resHead->getErrorMessages()], 400);
}

jsonOut([
    'ok'     => true,
    'headId' => $headId,
    'rowIds' => $finalMainIds,
]);