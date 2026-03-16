<?php
/**
 * /local/specifications/edit/edit_form.php
 */

use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
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
            'UF_CRM_27_1751274867',
            'UF_CRM_27_1767865292',
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/local/specifications/edit/edit_form.css?v=<?= time() ?>">
</head>
<body>

<!-- ── Топбар ── -->
<div class="topbar">
  <div class="nav-tabs">
    <div class="nav-tab active" data-tab="editor">Редактор изделия</div>
    <div class="nav-tab" data-tab="bulk">Массовое назначение</div>
  </div>
</div>

<!-- ── Основной лэйаут ── -->
<div class="sf-layout">

  <!-- ── Левая панель (общая для обоих режимов) ── -->
  <div class="sidebar">
    <div class="cat-search">
      <input type="text" id="sf-search" placeholder="Поиск по названию..." autocomplete="off">
    </div>
    <div id="sf-catalog"></div>
  </div>

  <!-- ── Правая область ── -->
  <div class="main">

    <!-- ── Вкладка: Редактор ── -->
    <div class="sf-tab-pane" data-tab="editor">
      <div class="card">
        <div class="card-header">
          <div id="sf-title" class="card-title">Выберите изделие из каталога</div>
          <div id="sf-time-badge" style="display:none;">
            Общее время: <strong id="sf-time-total">0</strong> мин
          </div>
        </div>

        <table class="spec-table">
          <thead>
            <tr>
              <th style="width:360px;">Материал + Альтернативы</th>
              <th style="width:140px;">Кол-во / Ед.</th>
              <th style="width:133px;">Участок</th>
              <th>Описание техпроцесса</th>
              <th style="width:82px;">Время (мин)</th>
              <th style="width:36px;"></th>
            </tr>
          </thead>
          <tbody id="sf-tbody"></tbody>
        </table>

        <button class="btn btn-outline btn-dashed" id="sf-add-row" style="display:none;">
          + Добавить новый этап
        </button>

        <div class="card-footer" id="sf-footer" style="display:none;">
          <button class="btn btn-outline" id="sf-reset">Сбросить</button>
          <button class="btn btn-primary" id="sf-save">Сохранить изменения</button>
        </div>
      </div>
    </div>

    <!-- ── Вкладка: Массовое назначение ── -->
    <div class="sf-tab-pane" data-tab="bulk" style="display:none;">

      <!-- Карточка-счётчик -->
      <div class="summary-card">
        <div class="summary-title">Выбрано для обновления: <span id="sf-selected-count">0</span> позиций</div>
        <div class="summary-hint">Отметьте изделия в каталоге слева. Настройте шаблон операции ниже — он будет добавлен в конец спецификации каждого выбранного изделия.</div>
      </div>

      <div class="card">
        <div class="card-header">
          <div class="card-title">Шаблон операции</div>
        </div>

        <table class="spec-table">
          <thead>
            <tr>
              <th style="width:360px;">Материал + Альтернативы</th>
              <th style="width:140px;">Кол-во / Ед.</th>
              <th style="width:133px;">Участок</th>
              <th>Описание техпроцесса</th>
              <th style="width:82px;">Время (мин)</th>
              <th style="width:36px;"></th>
            </tr>
          </thead>
          <tbody id="sf-bulk-tbody"></tbody>
        </table>

        <button class="btn btn-outline btn-dashed" id="sf-bulk-add-row">
          + Добавить этап в шаблон
        </button>

        <div class="card-footer">
          <button class="btn btn-outline" id="sf-bulk-reset">Очистить шаблон</button>
          <button class="btn btn-primary" id="sf-bulk-apply">Применить шаблон к выбранным</button>
        </div>
      </div>
    </div>

  </div><!-- /main -->
</div><!-- /sf-layout -->

<!-- ── Дерево материалов ── -->
<div id="sf-tree-overlay" style="display:none;">
  <div id="sf-tree-box">
    <div id="sf-tree-header">
      <h3 id="sf-tree-title">Выбор материала</h3>
      <input type="text" id="sf-tree-search" placeholder="Поиск по названию...">
      <button id="sf-tree-close" title="Закрыть">×</button>
    </div>
    <div id="sf-tree-body">
      <div id="sf-tree-left"></div>
      <div id="sf-tree-right"></div>
    </div>
    <div id="sf-tree-footer">
      <button class="btn btn-outline" id="sf-tree-cancel">Отмена</button>
      <button class="btn btn-primary" id="sf-tree-select">Выбрать</button>
    </div>
  </div>
</div>

<div class="toast" id="sf-toast"></div>

<script>
var SF_DATA = {
  sessid:       '<?= bitrix_sessid() ?>',
  catalog:      <?= Json::encode($catalog,      JSON_UNESCAPED_UNICODE) ?>,
  workSections: <?= Json::encode($workSections, JSON_UNESCAPED_UNICODE) ?>,
  urls: {
    save:      '/local/specifications/edit/save_specification.php',
    materials: '/local/specifications/api/get_materials.php',
    load:      '/local/specifications/api/get_specification.php',
    applyBulk: '/local/specifications/edit/apply_bulk.php',
  }
};
</script>
<script src="/local/specifications/edit/edit_form.js?v=<?= time() ?>"></script>

</body>
</html>
<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
