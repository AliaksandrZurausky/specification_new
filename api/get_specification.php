<?php
/**
 * /local/specifications/api/get_specification.php
 * Загружает основные этапы (тип 615) и их альтернативы (тип 616) из смарта 1146.
 *
 * POST: sessid, headId
 * Response: { ok, title, steps: [{rowId, rawId, mat, measureId, measureSym, qty, section, desc, time,
 *   alts: [{rowId, rawId, mat, measureId, measureSym, qty}]
 * }] }
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

/**
 * Единица измерения товара — адаптация _getProductUnit() из masterCard/api.php.
 * Возвращает ['measureId' => int, 'measureSym' => string].
 */
function getProductUnitInfo(int $productId): array
{
    static $cache = [];
    if (isset($cache[$productId])) return $cache[$productId];

    if ($productId <= 0) {
        return $cache[$productId] = ['measureId' => 0, 'measureSym' => ''];
    }

    $fetchUnit = function (int $id): array {
        try {
            $row = \Bitrix\Catalog\ProductTable::getCurrentRatioWithMeasure($id)[$id] ?? null;
            $m = is_array($row) ? ($row['MEASURE'] ?? []) : [];
            $sym = '';
            if (!empty($m['SYMBOL_RUS']))    $sym = (string)$m['SYMBOL_RUS'];
            elseif (!empty($m['SYMBOL_INTL']))   $sym = (string)$m['SYMBOL_INTL'];
            elseif (!empty($m['MEASURE_TITLE'])) $sym = (string)$m['MEASURE_TITLE'];
            return ['measureId' => (int)($m['ID'] ?? 0), 'measureSym' => trim($sym)];
        } catch (\Throwable $e) {}
        return ['measureId' => 0, 'measureSym' => ''];
    };

    $info     = $fetchUnit($productId);
    $parentId = 0;

    // offer → parent
    if ($info['measureSym'] === '' || $info['measureSym'] === 'шт') {
        $skuInfo  = \CCatalogSku::GetProductInfo($productId);
        $parentId = (is_array($skuInfo) && !empty($skuInfo['ID'])) ? (int)$skuInfo['ID'] : 0;
        if ($parentId > 0) {
            $parentInfo = $fetchUnit($parentId);
            if ($parentInfo['measureSym'] !== '') $info = $parentInfo;
        }
    }

    // parent → first offer
    if (($info['measureSym'] === '' || $info['measureSym'] === 'шт') && $parentId <= 0) {
        try {
            $offers = \CCatalogSKU::getOffersList([$productId], 0, ['ACTIVE' => 'Y'], ['ID'], []);
            if (is_array($offers) && !empty($offers[$productId])) {
                $offerIds = array_keys($offers[$productId]);
                sort($offerIds, SORT_NUMERIC);
                $firstOfferId = (int)reset($offerIds);
                if ($firstOfferId > 0) {
                    $offerInfo = $fetchUnit($firstOfferId);
                    if ($offerInfo['measureSym'] !== '') $info = $offerInfo;
                }
            }
        } catch (\Throwable $e) {}
    }

    return $cache[$productId] = $info;
}

try {

if (!check_bitrix_sessid()) {
    jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('crm') || !Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    jsonOut(['ok' => false, 'error' => 'modules'], 500);
}

$headId = (int)($_POST['headId'] ?? 0);
if ($headId <= 0) jsonOut(['ok' => false, 'error' => 'headId'], 400);

// ── Фабрики ───────────────────────────────────────────────────────────────────
$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow  = Container::getInstance()->getFactory(1146);
if (!$factoryHead || !$factoryRow) jsonOut(['ok' => false, 'error' => 'factory'], 500);

$head = $factoryHead->getItem($headId);
if (!$head) jsonOut(['ok' => false, 'error' => 'head_not_found'], 404);

// ── ID строк из head ──────────────────────────────────────────────────────────
$rowIds = $head->get('UF_CRM_27_1751274867');
if (!is_array($rowIds)) $rowIds = $rowIds ? [$rowIds] : [];
$rowIds = array_values(array_filter(array_map('intval', $rowIds), fn($v) => $v > 0));

if (empty($rowIds)) {
    jsonOut(['ok' => true, 'title' => $head->getTitle(), 'steps' => []]);
}

// ── Загружаем все строки 1146 ─────────────────────────────────────────────────
$allRows = $factoryRow->getItems([
    'select' => [
        'ID', 'TITLE',
        'UF_CRM_28_1773398091',    // тип: 615=основной, 616=альтернатива
        'UF_CRM_28_1752667325075', // единица измерения (строка)
        'UF_CRM_28_1751274644',    // ID товара ИБ14
        'UF_CRM_28_1751274777',    // количество
        'UF_CRM_28_1773394604',    // участок ИБ26
        'UF_CRM_28_1773394725',    // описание
        'UF_CRM_28_1773394804',    // время мин
        'UF_CRM_28_1773394825',    // JSON IDs альтернатив
    ],
    'filter' => ['ID' => $rowIds],
]);

// Собираем все ID товаров для батч-загрузки
$productIds = [];
foreach ($allRows as $row) {
    $pid = (int)$row->get('UF_CRM_28_1751274644');
    if ($pid > 0) $productIds[] = $pid;
}
$productIds = array_unique($productIds);

// ── Батч-загрузка товаров из ИБ 14 ───────────────────────────────────────────
$productData = []; // id -> [name, measureId, measureSym]
if (!empty($productIds)) {
    $rsProd = \CIBlockElement::GetList(
        [],
        ['IBLOCK_ID' => 14, 'ID' => $productIds],
        false,
        false,
        ['ID', 'NAME']
    );
    while ($p = $rsProd->GetNext()) {
        $pid  = (int)$p['ID'];
        $unit = getProductUnitInfo($pid);
        $productData[$pid] = [
            'name'       => (string)$p['NAME'],
            'measureId'  => $unit['measureId'],
            'measureSym' => $unit['measureSym'],
        ];
    }
}

// Индексируем строки по ID
$rowsById = [];
foreach ($allRows as $row) {
    $rowsById[(int)$row->getId()] = $row;
}

// ── Собираем результат — только основные (615) в порядке $rowIds ──────────────
$steps = [];

foreach ($rowIds as $rid) {
    $row = $rowsById[$rid] ?? null;
    if (!$row) continue;

    $typeVal = $row->get('UF_CRM_28_1773398091');
    if (is_array($typeVal)) $typeVal = $typeVal[0] ?? null;
    $type = (int)$typeVal;

    // Пропускаем альтернативы на верхнем уровне (на случай если ID попал не туда)
    if ($type === 616) continue;

    $pid  = (int)$row->get('UF_CRM_28_1751274644');
    $prod = $productData[$pid] ?? ['name' => '', 'measureId' => 0, 'measureSym' => ''];

    // Единица из сохранённого поля; если пусто или старое числовое значение (615/616) — fallback на каталог
    $storedMSym = trim((string)$row->get('UF_CRM_28_1752667325075'));
    $measureSym = ($storedMSym !== '' && !ctype_digit($storedMSym)) ? $storedMSym : $prod['measureSym'];

    $secRaw = $row->get('UF_CRM_28_1773394604');
    if (is_array($secRaw)) $secRaw = $secRaw[0] ?? 0;

    // IDs альтернатив из JSON
    $altsJson = (string)$row->get('UF_CRM_28_1773394825');
    $altIds   = [];
    if ($altsJson !== '') {
        try {
            $decoded = Json::decode($altsJson);
            if (is_array($decoded)) $altIds = array_filter(array_map('intval', $decoded));
        } catch (\Throwable $e) {}
    }

    // Загружаем альтернативы
    $alts = [];
    foreach ($altIds as $altId) {
        $altRow = $rowsById[$altId] ?? null;

        // Если альтернатива не была в $rowIds (нет в head) — догружаем
        if (!$altRow) {
            $altRow = $factoryRow->getItem($altId);
        }
        if (!$altRow) continue;

        $altPid  = (int)$altRow->get('UF_CRM_28_1751274644');
        $altProd = $productData[$altPid] ?? null;

        // Если товар ещё не загружен — грузим
        if (!$altProd && $altPid > 0) {
            $rsSingle = \CIBlockElement::GetList([], ['IBLOCK_ID' => 14, 'ID' => $altPid],
                false, false, ['ID', 'NAME']);
            $nameVal = '';
            if ($elRow = $rsSingle->GetNext()) $nameVal = (string)$elRow['NAME'];
            $unit    = getProductUnitInfo($altPid);
            $altProd = ['name' => $nameVal, 'measureId' => $unit['measureId'], 'measureSym' => $unit['measureSym']];
            $productData[$altPid] = $altProd;
        }
        $altProd = $altProd ?? ['name' => '', 'measureId' => 0, 'measureSym' => ''];

        $altStoredMSym = trim((string)$altRow->get('UF_CRM_28_1752667325075'));
        $altMeasureSym = ($altStoredMSym !== '' && !ctype_digit($altStoredMSym)) ? $altStoredMSym : ($altProd['measureSym'] ?? '');

        $alts[] = [
            'rowId'      => (int)$altRow->getId(),
            'rawId'      => $altPid,
            'mat'        => $altProd['name'],
            'measureId'  => $altProd['measureId'],
            'measureSym' => $altMeasureSym,
            'qty'        => (float)$altRow->get('UF_CRM_28_1751274777'),
        ];
    }

    $steps[] = [
        'rowId'      => (int)$row->getId(),
        'rawId'      => $pid,
        'mat'        => $prod['name'],
        'measureId'  => $prod['measureId'],
        'measureSym' => $measureSym,
        'qty'        => (float)$row->get('UF_CRM_28_1751274777'),
        'section'    => (int)$secRaw,
        'desc'       => (string)$row->get('UF_CRM_28_1773394725'),
        'time'       => (int)$row->get('UF_CRM_28_1773394804'),
        'alts'       => $alts,
    ];
}

jsonOut(['ok' => true, 'title' => $head->getTitle(), 'steps' => $steps]);

} catch (\Throwable $e) {
    jsonOut([
        'ok'    => false,
        'error' => 'exception',
        'msg'   => $e->getMessage(),
        'file'  => basename($e->getFile()),
        'line'  => $e->getLine(),
    ], 500);
}