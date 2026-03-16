<?php
/**
 * /local/specifications/api/get_materials.php
 * Дерево материалов из инфоблока 14 для выбора сырья в строке этапа.
 *
 * POST: sessid, action, sectionId (для items), q (для search)
 * action=sections — дерево разделов
 * action=items    — товары раздела
 * action=search   — поиск по названию
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Web\Json;

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

if (!Loader::includeModule('iblock')) {
    jsonOut(['ok' => false, 'error' => 'iblock'], 500);
}

$action    = trim((string)($_POST['action'] ?? 'sections'));
$sectionId = (int)($_POST['sectionId'] ?? 0);
$query     = trim((string)($_POST['q'] ?? ''));

// ── Разделы ───────────────────────────────────────────────────────────────────
if ($action === 'sections') {
    $parentId = $sectionId > 0 ? $sectionId : false;

    $sections = [];
    $rs = \CIBlockSection::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        [
            'IBLOCK_ID'        => MAT_IBLOCK_ID,
            'ACTIVE'           => 'Y',
            'SECTION_ID'       => $parentId,
        ],
        false,
        ['ID', 'NAME', 'DEPTH_LEVEL', 'SECTION_ID']
    );
    while ($sec = $rs->Fetch()) {
        // Проверяем есть ли дочерние разделы
        $hasChildren = (int)\CIBlockSection::GetCount([
            'IBLOCK_ID'  => MAT_IBLOCK_ID,
            'ACTIVE'     => 'Y',
            'SECTION_ID' => (int)$sec['ID'],
        ]) > 0;

        $sections[] = [
            'id'          => (int)$sec['ID'],
            'name'        => $sec['NAME'],
            'parentId'    => (int)$sec['SECTION_ID'],
            'hasChildren' => $hasChildren,
        ];
    }

    jsonOut(['ok' => true, 'sections' => $sections]);
}

// ── Товары раздела ────────────────────────────────────────────────────────────
if ($action === 'items') {
    $filter = [
        'IBLOCK_ID'        => MAT_IBLOCK_ID,
        'ACTIVE'           => 'Y',
        'IBLOCK_SECTION_ID' => $sectionId > 0 ? $sectionId : false,
    ];

    $items = [];
    $rs = \CIBlockElement::GetList(
        ['SORT' => 'ASC', 'NAME' => 'ASC'],
        $filter,
        false,
        false,
        ['ID', 'NAME', 'IBLOCK_SECTION_ID']
    );
    while ($el = $rs->Fetch()) {
        $items[] = [
            'id'        => (int)$el['ID'],
            'name'      => $el['NAME'],
            'sectionId' => (int)$el['IBLOCK_SECTION_ID'],
        ];
    }

    jsonOut(['ok' => true, 'items' => $items]);
}

// ── Поиск ─────────────────────────────────────────────────────────────────────
if ($action === 'search') {
    if ($query === '') {
        jsonOut(['ok' => true, 'items' => []]);
    }

    $items = [];
    $rs = \CIBlockElement::GetList(
        ['NAME' => 'ASC'],
        [
            'IBLOCK_ID' => MAT_IBLOCK_ID,
            'ACTIVE'    => 'Y',
            '%NAME'     => $query,
        ],
        false,
        ['nTopCount' => 50],
        ['ID', 'NAME', 'IBLOCK_SECTION_ID']
    );
    while ($el = $rs->Fetch()) {
        $items[] = [
            'id'        => (int)$el['ID'],
            'name'      => $el['NAME'],
            'sectionId' => (int)$el['IBLOCK_SECTION_ID'],
        ];
    }

    jsonOut(['ok' => true, 'items' => $items]);
}

jsonOut(['ok' => false, 'error' => 'unknown_action'], 400);