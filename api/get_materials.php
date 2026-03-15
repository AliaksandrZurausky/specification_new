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
use Bitrix\Catalog\MeasureTable;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);
header('Content-Type: application/json; charset=utf-8');

const MAT_IBLOCK_ID = 14;

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

// ── Единицы измерения ─────────────────────────────────────────────────────────
$measuresMap = [];
$measRows = MeasureTable::getList(['select' => ['ID', 'SYMBOL', 'MEASURE_TITLE']])->fetchAll();
foreach ($measRows as $m) {
    $sym = trim((string)($m['SYMBOL'] ?: $m['MEASURE_TITLE']));
    $measuresMap[(int)$m['ID']] = $sym ?: ('ID=' . (int)$m['ID']);
}

function buildItems(\CIBlockResult $rs, array $measuresMap): array
{
    $items = [];
    while ($el = $rs->GetNext()) {
        $measureId  = (int)($el['CATALOG_MEASURE'] ?? 0);
        $items[] = [
            'id'         => (int)$el['ID'],
            'name'       => (string)$el['NAME'],
            'sectionId'  => (int)$el['IBLOCK_SECTION_ID'],
            'measureId'  => $measureId,
            'measureSym' => $measureId > 0 ? ($measuresMap[$measureId] ?? '') : '',
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
    jsonOut(['ok' => true, 'items' => buildItems($rs, $measuresMap)]);
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
    jsonOut(['ok' => true, 'items' => buildItems($rs, $measuresMap)]);
}

jsonOut(['ok' => false, 'error' => 'unknown_action'], 400);