<?php
/**
 * /local/specifications/edit/edit_form.php
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

Extension::load(['main.core', 'ui.notification', 'ui.buttons']);

global $APPLICATION;

// ── Участки из инфоблока 26 ───────────────────────────────────────────────────
$workSections = [];
$rsSec = \CIBlockElement::GetList(
    ['SORT' => 'ASC', 'NAME' => 'ASC'],
    ['IBLOCK_ID' => 26, 'ACTIVE' => 'Y'],
    false,
    false,
    ['ID', 'NAME']
);
while ($sec = $rsSec->Fetch()) {
    $workSections[] = ['id' => (int)$sec['ID'], 'name' => $sec['NAME']];
}

// ── Каталог: все head-элементы смарта 1142 ────────────────────────────────────
$catalog = [];
$factoryHead = Container::getInstance()->getFactory(1142);

if ($factoryHead) {
    $heads = $factoryHead->getItems([
        'select' => [
            'ID', 'TITLE',
            'UF_CRM_27_1751274867', // строки 1146
            'UF_CRM_27_1767865292', // активный
        ],
        'order' => ['TITLE' => 'ASC'],
    ]);

    foreach ($heads as $h) {
        $rowIds = $h->get('UF_CRM_27_1751274867');
        if (!is_array($rowIds)) $rowIds = $rowIds ? [$rowIds] : [];
        $stepsCount = count(array_filter(array_map('intval', $rowIds)));

        $activeVal = $h->get('UF_CRM_27_1767865292');
        if (is_array($activeVal)) $activeVal = $activeVal[0] ?? null;
        $active = ((string)$activeVal === 'Y' || (int)$activeVal === 1) ? 1 : 0;

        $catalog[] = [
            'headId'     => (int)$h->getId(),
            'title'      => $h->getTitle(),
            'stepsCount' => $stepsCount,
            'active'     => $active,
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

  <!-- ── Левая панель ── -->
  <div class="sf-sidebar">
    <div class="sf-sidebar-search">
      <input type="text" id="sf-search" placeholder="Поиск по названию..." autocomplete="off">
    </div>
    <div id="sf-catalog"></div>
  </div>

  <!-- ── Правая панель ── -->
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
            <th style="width:240px;">Материал</th>
            <th style="width:120px;">Кол-во / Ед.</th>
            <th style="width:180px;">Участок</th>
            <th>Описание техпроцесса</th>
            <th style="width:90px;">Время (мин)</th>
            <th style="width:36px;"></th>
          </tr>
        </thead>
        <tbody id="sf-tbody"></tbody>
      </table>
    </div>

    <button class="sf-btn sf-btn-dashed" id="sf-add-row" style="display:none;">+ Добавить этап</button>

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
      <h3 id="sf-tree-title">Выбор материала</h3>
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

<script>
var SF_DATA = {
  sessid:       '<?= bitrix_sessid() ?>',
  catalog:      <?= Json::encode($catalog,      JSON_UNESCAPED_UNICODE) ?>,
  workSections: <?= Json::encode($workSections, JSON_UNESCAPED_UNICODE) ?>,
  urls: {
    save:      '/local/specifications/edit/save_specification.php',
    materials: '/local/specifications/api/get_materials.php',
    load:      '/local/specifications/api/get_specification.php',
  }
};
</script>
<script src="/local/specifications/edit/edit_form.js?v=<?= time() ?>"></script>

</body>
</html>
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');