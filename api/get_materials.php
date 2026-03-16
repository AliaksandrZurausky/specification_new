<?php
/**
 * /local/specifications/api/get_materials.php
 * Дерево материалов из инфоблока 14.
 * action=sections — разделы
 * action=items    — товары раздела (с единицей измерения)
 * action=search   — поиск по названию
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);
header('Content-Type: application/json; charset=utf-8');

if (!defined('MAT_IBLOCK_ID')) define('MAT_IBLOCK_ID', 14);

function jsonOut(array $data, int $status = 200): void
{
    http_response_code($status);
    echo Json::encode($data, JSON_UNESCAPED_UNICODE);
    die();
}

if (!check_bitrix_sessid()) {
    jsonOut(['ok' => false, 'error' => 'sessid'], 403);
}

if (!Loader::includeModule('iblock') || !Loader::includeModule('catalog')) {
    jsonOut(['ok' => false, 'error' => 'modules'], 500);
}

$action    = trim((string)($_POST['action'] ?? 'sections'));
$sectionId = (int)($_POST['sectionId'] ?? 0);
$query     = trim((string)($_POST['q'] ?? ''));

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

function buildItems(\CIBlockResult $rs): array
{
    $raw = [];
    while ($el = $rs->GetNext()) {
        $raw[] = [
            'id'        => (int)$el['ID'],
            'name'      => (string)$el['NAME'],
            'sectionId' => (int)$el['IBLOCK_SECTION_ID'],
        ];
    }

    $items = [];
    foreach ($raw as $el) {
        $m = getProductUnitInfo($el['id']);
        $items[] = [
            'id'         => $el['id'],
            'name'       => $el['name'],
            'sectionId'  => $el['sectionId'],
            'measureId'  => $m['measureId'],
            'measureSym' => $m['measureSym'],
        ];
    }
    return $items;
}

// ── Разделы ───────────────────────────────────────────────────────────────────
if ($action === 'sections') {
    $sections = [];
    $rs = \CIBlockSection::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => MAT_IBLOCK_ID, 'ACTIVE' => 'Y', 'SECTION_ID' => $sectionId > 0 ? $sectionId : false],
        false,
        ['ID', 'NAME', 'SECTION_ID']
    );
    while ($sec = $rs->Fetch()) {
        $hasChildren = (int)\CIBlockSection::GetCount([
            'IBLOCK_ID'  => MAT_IBLOCK_ID,
            'ACTIVE'     => 'Y',
            'SECTION_ID' => (int)$sec['ID'],
        ]) > 0;
        $sections[] = [
            'id'          => (int)$sec['ID'],
            'name'        => (string)$sec['NAME'],
            'parentId'    => (int)$sec['SECTION_ID'],
            'hasChildren' => $hasChildren,
        ];
    }
    jsonOut(['ok' => true, 'sections' => $sections]);
}

// ── Товары раздела ────────────────────────────────────────────────────────────
if ($action === 'items') {
    $rs = \CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        ['IBLOCK_ID' => MAT_IBLOCK_ID, 'ACTIVE' => 'Y', 'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false],
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'CATALOG_MEASURE']
    );
    jsonOut(['ok' => true, 'items' => buildItems($rs)]);
}

// ── Поиск ─────────────────────────────────────────────────────────────────────
if ($action === 'search') {
    if ($query === '') jsonOut(['ok' => true, 'items' => []]);
    $rs = \CIBlockElement::GetList(
        ['NAME' => 'ASC'],
        ['IBLOCK_ID' => MAT_IBLOCK_ID, 'ACTIVE' => 'Y', '%NAME' => $query],
        false,
        ['nTopCount' => 50],
        ['ID', 'NAME', 'IBLOCK_SECTION_ID', 'CATALOG_MEASURE']
    );
    jsonOut(['ok' => true, 'items' => buildItems($rs)]);
}

jsonOut(['ok' => false, 'error' => 'unknown_action'], 400);