<?php
/**
 * /local/specifications/api/get_specification.php
 * Загружает этапы (строки смарта 1146) для выбранного head (1142).
 *
 * POST: sessid, headId
 * Response: { ok, steps: [{rowId, rawId, mat, qty, unit, section, desc, time, alts}] }
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

if (!Loader::includeModule('crm') || !Loader::includeModule('iblock')) {
    jsonOut(['ok' => false, 'error' => 'modules'], 500);
}

$headId = (int)($_POST['headId'] ?? 0);
if ($headId <= 0) {
    jsonOut(['ok' => false, 'error' => 'headId'], 400);
}

// ── Загружаем head ────────────────────────────────────────────────────────────
$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow  = Container::getInstance()->getFactory(1146);

if (!$factoryHead || !$factoryRow) {
    jsonOut(['ok' => false, 'error' => 'factory'], 500);
}

$head = $factoryHead->getItem($headId);
if (!$head) {
    jsonOut(['ok' => false, 'error' => 'head_not_found'], 404);
}

// ── ID строк из поля head ─────────────────────────────────────────────────────
$rowIds = $head->get('UF_CRM_27_1751274867');
if (!is_array($rowIds)) {
    $rowIds = $rowIds ? [$rowIds] : [];
}
$rowIds = array_values(array_filter(array_map('intval', $rowIds), static fn($v) => $v > 0));

if (empty($rowIds)) {
    jsonOut(['ok' => true, 'steps' => []]);
}

// ── Загружаем строки 1146 ─────────────────────────────────────────────────────
$steps = [];

foreach ($rowIds as $rowId) {
    $row = $factoryRow->getItem($rowId);
    if (!$row) continue;

    // rawId — ID элемента инфоблока 14
    $rawId = (int)$row->get('UF_CRM_28_1751274644');

    // Название материала (из инфоблока 14)
    $matName = '';
    if ($rawId > 0) {
        $elRes = \CIBlockElement::GetByID($rawId);
        if ($el = $elRes->GetNextElement()) {
            $fields = $el->GetFields();
            $matName = (string)$fields['NAME'];
        }
    }

    // Альтернативы из JSON
    $altsRaw = (string)$row->get('UF_CRM_28_1773394825');
    $alts    = [];
    if ($altsRaw !== '') {
        try {
            $decoded = Json::decode($altsRaw);
            if (is_array($decoded)) $alts = $decoded;
        } catch (\Throwable $e) {}
    }

    // Участок — может вернуться как ID или массив
    $sectionRaw = $row->get('UF_CRM_28_1773394604');
    if (is_array($sectionRaw)) $sectionRaw = $sectionRaw[0] ?? 0;
    $sectionId = (int)$sectionRaw;

    $steps[] = [
        'rowId'   => (int)$row->getId(),
        'rawId'   => $rawId,
        'mat'     => $matName,
        'qty'     => (float)$row->get('UF_CRM_28_1751274777'),
        'unit'    => (string)$row->get('UF_CRM_28_1752667325075'),
        'section' => $sectionId,
        'desc'    => (string)$row->get('UF_CRM_28_1773394725'),
        'time'    => (int)$row->get('UF_CRM_28_1773394804'),
        'alts'    => $alts,
    ];
}

jsonOut(['ok' => true, 'steps' => $steps]);