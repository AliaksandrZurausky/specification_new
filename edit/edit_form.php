<?php
/**
 * /local/specifications/edit/edit_form.php
 * Редактор спецификаций — новый интерфейс.
 * Левая панель: все head-элементы смарта 1142.
 * Правая панель: этапы выбранной спецификации.
 */

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Bitrix\Catalog\MeasureTable;
use Bitrix\Crm\Service\Container;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);

Loader::includeModule('ui');
Loader::includeModule('iblock');
Loader::includeModule('crm');

Extension::load(['main.core', 'ui.notification', 'ui.buttons', 'ui.alerts']);

global $APPLICATION;

// ── Единицы измерения из каталога ─────────────────────────────────────────────
$units = [['id' => '', 'name' => '— ед. изм. —']];
if (Loader::includeModule('catalog')) {
    $rows = MeasureTable::getList([
        'select' => ['ID', 'SYMBOL', 'MEASURE_TITLE'],
        'order'  => ['ID' => 'ASC'],
    ])->fetchAll();
    foreach ($rows as $m) {
        $label = trim((string)($m['SYMBOL'] ?: $m['MEASURE_TITLE']));
        if ($label === '') $label = 'ID=' . (int)$m['ID'];
        $units[] = ['id' => (int)$m['ID'], 'name' => $label];
    }
}

// ── Участки из инфоблока 26 ───────────────────────────────────────────────────
$sections = [];
$rsSec = \CIBlockElement::GetList(
    ['SORT' => 'ASC', 'NAME' => 'ASC'],
    ['IBLOCK_ID' => 26, 'ACTIVE' => 'Y'],
    false,
    false,
    ['ID', 'NAME']
);
while ($sec = $rsSec->Fetch()) {
    $sections[] = ['id' => (int)$sec['ID'], 'name' => $sec['NAME']];
}

// ── Загружаем все head-элементы смарта 1142 ───────────────────────────────────
$catalog = []; // [{headId, title, productId, productName, folder, stepsCount, active}]

$factoryHead = Container::getInstance()->getFactory(1142);
$factoryRow  = Container::getInstance()->getFactory(1146);

if ($factoryHead) {
    $heads = $factoryHead->getItems([
        'select' => [
            'ID', 'TITLE',
            'UF_CRM_27_1749727214', // ID товара инфоблока (Номенклатура)
            'UF_CRM_27_1751274867', // Список строк 1146
            'UF_CRM_27_1767865292', // Активный
        ],
        'order' => ['ID' => 'ASC'],
    ]);

    // Собираем ID товаров для батч-загрузки
    $productIds = [];
    foreach ($heads as $h) {
        $pid = $h->get('UF_CRM_27_1749727214');
        if (is_array($pid)) $pid = $pid[0] ?? 0;
        $pid = (int)$pid;
        if ($pid > 0) $productIds[] = $pid;
    }
    $productIds = array_unique($productIds);

    // Загружаем названия и разделы товаров
    $productNames   = [];
    $productFolders = [];
    $sectionNames   = [];

    if (!empty($productIds)) {
        // Разделы инфоблока 14
        $rsSections = \CIBlockSection::GetList(
            [],
            ['IBLOCK_ID' => 14, 'ACTIVE' => 'Y'],
            false,
            ['ID', 'NAME']
        );
        while ($s = $rsSections->Fetch()) {
            $sectionNames[(int)$s['ID']] = $s['NAME'];
        }

        // Товары
        $rsProducts = \CIBlockElement::GetList(
            [],
            ['IBLOCK_ID' => 14, 'ID' => $productIds],
            false,
            false,
            ['ID', 'NAME', 'IBLOCK_SECTION_ID']
        );
        while ($p = $rsProducts->Fetch()) {
            $pid = (int)$p['ID'];
            $productNames[$pid]   = $p['NAME'];
            $secId = (int)$p['IBLOCK_SECTION_ID'];
            $productFolders[$pid] = $sectionNames[$secId] ?? 'Без раздела';
        }
    }

    // Формируем каталог
    foreach ($heads as $h) {
        $pid = $h->get('UF_CRM_27_1749727214');
        if (is_array($pid)) $pid = $pid[0] ?? 0;
        $pid = (int)$pid;

        $rowIds = $h->get('UF_CRM_27_1751274867');
        if (!is_array($rowIds)) $rowIds = $rowIds ? [$rowIds] : [];
        $stepsCount = count(array_filter(array_map('intval', $rowIds)));

        $activeVal = $h->get('UF_CRM_27_1767865292');
        if (is_array($activeVal)) $activeVal = $activeVal[0] ?? null;
        $active = ((string)$activeVal === 'Y' || (int)$activeVal === 1) ? 1 : 0;

        $catalog[] = [
            'headId'      => (int)$h->getId(),
            'title'       => $h->getTitle(),
            'productId'   => $pid,
            'productName' => $productNames[$pid] ?? ('Товар #' . $pid),
            'folder'      => $productFolders[$pid] ?? 'Без раздела',
            'stepsCount'  => $stepsCount,
            'active'      => $active,
        ];
    }
}

$APPLICATION->ShowHead();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Спецификации</title>
  <link rel="stylesheet" href="/local/specifications/edit/edit_form.css?v=<?= time() ?>">
</head>
<body>

<div class="sf-layout">

  <!-- ── Левая панель: каталог ── -->
  <div class="sf-sidebar">
    <div class="sf-sidebar-search">
      <input type="text" id="sf-search" placeholder="Поиск по названию..." autocomplete="off">
    </div>
    <div id="sf-catalog"></div>
  </div>

  <!-- ── Правая панель: редактор ── -->
  <div class="sf-main">
    <div class="sf-main-header">
      <div id="sf-title">Выберите спецификацию из списка</div>
      <div id="sf-time-badge" style="display:none;">
        Общее время: <strong id="sf-time-total">0</strong> мин
      </div>
    </div>

    <div class="sf-table-wrap">
      <table class="sf-table" id="sf-table">
        <thead>
          <tr>
            <th style="width:260px;">Материал</th>
            <th style="width:80px;">Кол-во</th>
            <th style="width:100px;">Ед. изм.</th>
            <th style="width:180px;">Участок</th>
            <th>Описание техпроцесса</th>
            <th style="width:90px;">Время (мин)</th>
            <th style="width:36px;"></th>
          </tr>
        </thead>
        <tbody id="sf-tbody"></tbody>
      </table>
    </div>

    <button class="sf-btn sf-btn-dashed" id="sf-add-row" style="display:none;">
      + Добавить этап
    </button>

    <div class="sf-footer" id="sf-footer" style="display:none;">
      <button class="sf-btn sf-btn-outline" id="sf-reset">Сбросить</button>
      <button class="sf-btn sf-btn-primary" id="sf-save">Сохранить изменения</button>
    </div>
  </div>
</div>

<!-- ── Дерево материалов ── -->
<div id="sf-tree-overlay" style="display:none;">
  <div id="sf-tree-box">
    <div id="sf-tree-header">
      <h3>Выбор материала</h3>
      <input type="text" id="sf-tree-search" placeholder="Поиск...">
      <button id="sf-tree-close">×</button>
    </div>
    <div id="sf-tree-body">
      <div id="sf-tree-left"></div>
      <div id="sf-tree-right"></div>
    </div>
    <div id="sf-tree-footer">
      <button class="sf-btn sf-btn-outline" id="sf-tree-cancel">Отмена</button>
      <button class="sf-btn sf-btn-primary" id="sf-tree-select">Выбрать</button>
    </div>
  </div>
</div>

<!-- Данные для JS -->
<script>
var SF_DATA = {
  sessid:   '<?= bitrix_sessid() ?>',
  catalog:  <?= Json::encode($catalog,  JSON_UNESCAPED_UNICODE) ?>,
  units:    <?= Json::encode($units,    JSON_UNESCAPED_UNICODE) ?>,
  sections: <?= Json::encode($sections, JSON_UNESCAPED_UNICODE) ?>,
  urls: {
    save:      '/local/specifications/edit/save_specification.php',
    materials: '/local/specifications/api/get_materials.php',
  }
};
</script>
<script src="/local/specifications/edit/edit_form.js?v=<?= time() ?>"></script>

</body>
</html>
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');