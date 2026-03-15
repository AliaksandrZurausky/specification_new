<?php
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Extension;
use Bitrix\Main\Web\Json;
use Bitrix\Catalog\MeasureTable;
use Bitrix\Crm\Service\Container;

require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

define('BX_SESSION_ID_CHANGE', false);

if (!Loader::includeModule('ui')) {
  die('ui');
}
if (!Loader::includeModule('iblock')) {
  die('iblock');
}
if (!Loader::includeModule('crm')) {
  die('crm');
}

Extension::load([
  'main.core',
  'ajax',
  'ui.forms',
  'ui.layout-form',
  'ui.buttons',
  'ui.alerts',
  'ui.field-selector',
  'ui.entity-selector',
  'ui.notification',
  'iblock.field-selector',
]);

global $APPLICATION, $USER_FIELD_MANAGER;

// UF for Smart Process CRM_27
$crmEntityId = 'CRM_27';
$userFields = $USER_FIELD_MANAGER->GetUserFields($crmEntityId, 0, LANGUAGE_ID);

$headId = (int)($_GET['headId'] ?? 0);
$loadError = '';
$headTitle = '';
$specIdPrefill = 0;
$prefillRows = [];
$prefillActive = 0;

if ($headId <= 0) {
  $loadError = 'Ошибка получения данных';
} else {
  $factoryHead = Container::getInstance()->getFactory(1142);
  $factoryRow = Container::getInstance()->getFactory(1146);

  if (!$factoryHead || !$factoryRow) {
    $loadError = 'Ошибка получения данных';
  } else {
    $head = $factoryHead->getItem($headId);
    if (!$head) {
      $loadError = 'Ошибка получения данных';
    } else {
      $headTitle = (string)$head->getTitle();

      // Active flag (UF_CRM_27_1767865292)
      $activeValue = $head->get('UF_CRM_27_1767865292');
      if (is_array($activeValue)) {
        $activeValue = $activeValue[0] ?? null;
      }
      $prefillActive = ((string)$activeValue === 'Y' || (int)$activeValue === 1 || $activeValue === true) ? 1 : 0;

      // UF_CRM_27_1752661803131 is multiple (array), but logically contains only one value.
      $specValue = $head->get('UF_CRM_27_1752661803131');
      if (is_array($specValue)) {
        $specIdPrefill = (int)($specValue[0] ?? 0);
      } else {
        $specIdPrefill = (int)$specValue;
      }

      $rowIds = $head->get('UF_CRM_27_1751274867');
      if (!is_array($rowIds)) {
        $rowIds = $rowIds ? [$rowIds] : [];
      }

      foreach ($rowIds as $rid) {
        $rid = (int)$rid;
        if ($rid <= 0) {
          continue;
        }

        $rowItem = $factoryRow->getItem($rid);
        if (!$rowItem) {
          // если строка не найдена — просто пропускаем
          continue;
        }

        $prefillRows[] = [
          'rowId' => (int)$rowItem->getId(),
          'title' => (string)$rowItem->getTitle(),
          'rawId' => (int)$rowItem->get('UF_CRM_28_1751274644'),
          'qty' => (float)$rowItem->get('UF_CRM_28_1751274777'),
          'unit' => trim((string)$rowItem->get('UF_CRM_28_1752667325075')),
        ];
      }

      // Prefill спецификации (каталог)
      if (!empty($userFields['UF_CRM_27_1767679276'])) {
        $userFields['UF_CRM_27_1767679276']['VALUE'] = $specIdPrefill;
      }
    }
  }
}

function renderUf(array $userFields, string $code, string $formName = 'speccreateform'): void
{
  global $APPLICATION;
  if (empty($userFields[$code])) {
    echo '<div class="ui-alert ui-alert-danger"><span class="ui-alert-message">Не найдено поле: '
      . htmlspecialcharsbx($code)
      . '</span></div>';
    return;
  }

  $uf = $userFields[$code];
  $uf['ENTITY_VALUE_ID'] = 0;
  $uf['VALUE'] = $_POST[$code] ?? ($uf['VALUE'] ?? null);

  $APPLICATION->IncludeComponent(
    'bitrix:system.field.edit',
    $uf['USER_TYPE_ID'],
    [
      'arUserField'   => $uf,
      'bVarsFromForm' => !empty($_POST),
      'form_name'     => $formName,
    ],
    false,
    ['HIDE_ICONS' => 'Y']
  );
}

// Units from Bitrix catalog measure dictionary
$units = [
  ['id' => '', 'name' => '— выберите —'],
];

if (Loader::includeModule('catalog'))
{
  $rows = MeasureTable::getList([
    'select' => ['ID', 'CODE', 'MEASURE_TITLE', 'SYMBOL'],
    'order' => ['ID' => 'ASC'],
  ])->fetchAll();

  foreach ($rows as $m)
  {
    $title = '';

    if (!empty($m['SYMBOL'])) {
      $title = trim((string)$m['SYMBOL']);
    }

    if ($title === '' && !empty($m['MEASURE_TITLE'])) {
      $title = trim((string)$m['MEASURE_TITLE']);
    }

    if ($title === '' && !empty($m['CODE'])) {
      $title = trim((string)CCatalogMeasureClassifier::getMeasureTitle((int)$m['CODE'], 'SYMBOL_RUS'));
    }

    if ($title === '') {
      $title = 'ID=' . (int)$m['ID'];
    }

    $units[] = [
      'id' => (int)$m['ID'],
      'name' => $title,
    ];
  }
}

$prefill = [
  'ok' => $loadError === '',
  'error' => $loadError,
  'headId' => $headId,
  'title' => $headTitle,
  'specId' => $specIdPrefill,
  'rows' => $prefillRows,
  'active' => $prefillActive,
];

$APPLICATION->ShowHead();
?>
<!doctype html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Редактировать спецификацию</title>
  <style>
    :root {
      --c-text: #1f2a37;
      --c-sub: #525c69;
      --c-border: rgba(82, 92, 105, .14);
      --c-bg: #ffffff;
      --c-soft: rgba(82, 92, 105, .06);
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 18px;
      background: #fff;
      color: var(--c-text);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    }
    .spec-page { max-width: 980px; margin: 0 auto; }
    .spec-card {
      background: var(--c-bg);
      border: 1px solid var(--c-border);
      border-radius: 14px;
      padding: 16px;
      box-shadow: 0 2px 12px rgba(0,0,0,.04);
    }
    .spec-title {
      margin: 0 0 14px 0;
      font-size: 18px;
      font-weight: 600;
    }
    .ui-layout-form-label .ui-ctl-label-text,
    .ui-ctl-label-text {
      font-weight: 600;
      color: var(--c-sub);
    }
    .ui-layout-form-row { margin-bottom: 14px; }

    .raw-list { display: flex; flex-direction: column; gap: 10px; }
    .raw-row {
      border: 1px solid var(--c-border);
      border-radius: 12px;
      padding: 12px;
      background: #fff;
    }
    .raw-row-grid {
      display: grid;
      grid-template-columns: 1fr 180px 180px 44px;
      gap: 10px;
      align-items: end;
    }
    .raw-remove-wrap { display: flex; justify-content: flex-end; }
    .raw-remove-btn { min-width: 34px; height: 34px; padding: 0; line-height: 34px; }

    .raw-toolbar { margin-top: 10px; display: flex; gap: 10px; }

    .spec-actions {
      display: flex;
      gap: 10px;
      justify-content: flex-end;
      margin-top: 14px;
    }
    @media (max-width: 900px) {
      body { padding: 12px; }
      .raw-row-grid { grid-template-columns: 1fr; }
      .raw-remove-wrap { justify-content: flex-start; }
      .spec-actions { justify-content: flex-start; flex-wrap: wrap; }
    }
  </style>
</head>
<body>
<div class="spec-page">
  <div class="spec-card">
    <h2 class="spec-title">Редактирование спецификации</h2>

    <?php if ($loadError !== ''): ?>
      <div class="ui-alert ui-alert-danger">
        <span class="ui-alert-message"><?= htmlspecialcharsbx($loadError) ?></span>
      </div>
      <div style="margin-top:12px;">
        <button class="ui-btn ui-btn-light-border ui-btn-no-caps" type="button" id="cancel-btn">Закрыть</button>
      </div>
    <?php else: ?>

    <div class="ui-layout-form" id="spec-edit-form">
      <!-- SPEC (UF) -->
      <div class="ui-layout-form-row">
        <div class="ui-layout-form-label">
          <div class="ui-ctl-label-text">Спецификация (товар)</div>
        </div>
        <div class="ui-layout-form-content" id="spec-field-wrap">
          <div id="spec-selector"></div>
          <div class="ui-alert ui-alert-danger" id="spec-required" style="margin-top:10px; display:none;">
            <span class="ui-alert-message">Выберите спецификацию (товар).</span>
          </div>
        </div>
      </div>

      <!-- NAME -->
      <div class="ui-layout-form-row">
        <div class="ui-layout-form-label">
          <div class="ui-ctl-label-text">Название</div>
        </div>
        <div class="ui-layout-form-content">
          <div class="ui-ctl ui-ctl-textbox ui-ctl-w100">
            <input class="ui-ctl-element" type="text" id="specname" name="NAME" value="<?= htmlspecialcharsbx($headTitle) ?>">
          </div>
        </div>
      </div>

      <!-- RAW LIST -->
      <div class="ui-layout-form-row">
        <div class="ui-layout-form-label">
          <div class="ui-ctl-label-text">Сырьё</div>
        </div>
        <div class="ui-layout-form-content">
          <div class="raw-list" id="raw-list"></div>

          <div class="raw-toolbar">
            <button class="ui-btn ui-btn-light-border ui-btn-no-caps" type="button" id="raw-add">Добавить строку</button>
          </div>

          <div class="ui-alert ui-alert-info" style="margin-top:10px;">
            <span class="ui-alert-message">Сырьё добавляется строками: выбрать сырьё + количество + единица измерения.</span>
          </div>
        </div>
      </div>

      <div class="spec-actions">
        <button class="ui-btn ui-btn-success ui-btn-no-caps" type="button" id="save-btn">Сохранить</button>
        <button class="ui-btn ui-btn-light-border ui-btn-no-caps" type="button" id="toggle-active-btn">...</button>
        <button class="ui-btn ui-btn-light-border ui-btn-no-caps" type="button" id="cancel-btn">Отмена</button>
      </div>
    </div>

    <?php endif; ?>

  </div>
</div>

<script>
BX.ready(function () {
  const PREFILL = <?= Json::encode($prefill, JSON_UNESCAPED_UNICODE) ?>;

  const cancelBtn = document.getElementById('cancel-btn');
  if (cancelBtn) {
    cancelBtn.addEventListener('click', function () {
      if (BX.SidePanel && BX.SidePanel.Instance) {
        BX.SidePanel.Instance.close();
      }
    });
  }

  if (!PREFILL || !PREFILL.ok) {
    return;
  }

  const warnSpec = document.getElementById('spec-required');

  const RAW_IBLOCK_ID = 14;
  const SPEC_FIELD = 'UF_CRM_27_1767679276';
  const specWrap = document.getElementById('spec-field-wrap');

  function renderElementSelector(containerId, fieldName, iblockId, selectedId) {
    if (!containerId || !fieldName || !iblockId) {
      return null;
    }

    if (!BX || !BX.Iblock || typeof BX.Iblock.FieldSelector !== 'function') {
      return null;
    }

    const selector = new BX.Iblock.FieldSelector({
      containerId: containerId,
      fieldName: fieldName,
      multiple: false,
      collectionType: 'int',
      selectedItems: selectedId ? selectedId : [],
      iblockId: iblockId,
      userType: 'EAutocomplete',
      entityId: 'iblock-property-element'
    });

    selector.render();
    return selector;
  }

  function fetchProductMeasure(productId, cb) {
    const id = parseInt(productId || '0', 10) || 0;
    if (id <= 0) {
      cb(0);
      return;
    }

    BX.ajax({
      url: '/local/specifications/api/get_product_measure.php',
      method: 'POST',
      dataType: 'json',
      data: {
        sessid: BX.bitrix_sessid(),
        productId: id,
      },
      onsuccess: function (res) {
        const measureId = (res && res.ok) ? (parseInt(res.measureId || '0', 10) || 0) : 0;
        cb(measureId);
      },
      onfailure: function () {
        cb(0);
      },
    });
  }

  // SPEC selector (prefill from head UF_CRM_27_1752661803131)
  renderElementSelector('spec-selector', SPEC_FIELD, RAW_IBLOCK_ID, PREFILL.specId);

  function getSpecHidden() {
    return document.querySelector(`[name="${SPEC_FIELD}"]`);
  }

  function getSpecValueLive() {
    const el = getSpecHidden();
    return el && typeof el.value === 'string' ? el.value.trim() : '';
  }

  function getSpecValue() {
    return getSpecValueLive();
  }

  const nameInput = document.getElementById('specname');

  function extractSpecTitle() {
    if (!specWrap) return '';
    const t =
      specWrap.querySelector('.ui-tag-selector-tag-title') ||
      specWrap.querySelector('.ui-tag-selector-item-text');
    return t ? (t.textContent || '').trim() : '';
  }

  // В edit:
  // - при открытии формы "Название" = сохранённый TITLE
  // - если пользователь меняет "Спецификация" => "Название" становится как в create
  let lastSpecValue = String(PREFILL.specId || getSpecValueLive() || '');
  let lastAppliedSpecValue = lastSpecValue;

  let lastAutoName = nameInput.value || '';
  let nameTouched = false;

  nameInput.addEventListener('input', function () {
    if ((nameInput.value || '') !== lastAutoName) {
      nameTouched = true;
    }
  });

  function syncName() {
    const currentSpecValue = getSpecValueLive();
    if (!currentSpecValue) {
      return;
    }

    // no overwrite on initial prefill/render; only on actual spec change
    if (String(currentSpecValue) === String(lastAppliedSpecValue)) {
      return;
    }

    const title = extractSpecTitle();
    if (!title) {
      return;
    }

    nameInput.value = title;
    lastAutoName = title;
    nameTouched = false;
    lastAppliedSpecValue = String(currentSpecValue);
  }

  nameInput.disabled = false;

  function bindDefaultChange() {
    const specDefault = document.getElementById(`${SPEC_FIELD}_default`);
    if (specDefault && !specDefault.__specBound) {
      specDefault.__specBound = true;
      specDefault.addEventListener('change', syncName);
    }
  }

  bindDefaultChange();
  setTimeout(bindDefaultChange, 300);

  if (specWrap && typeof MutationObserver !== 'undefined') {
    new MutationObserver(function () {
      bindDefaultChange();
      syncName();
    }).observe(specWrap, { childList: true, subtree: true });
  }

  function watchSpecValue() {
    const v = getSpecValueLive();
    if (v !== lastSpecValue) {
      lastSpecValue = v;
      syncName();
    }
  }

  setTimeout(syncName, 50);
  setInterval(watchSpecValue, 300);

  function closeSliderAndReload() {
    const topBX = window.top && window.top.BX ? window.top.BX : null;
    if (!(topBX && topBX.SidePanel && topBX.SidePanel.Instance)) {
      window.top.location.reload();
      return;
    }

    const currentSlider = topBX.SidePanel.Instance.getSliderByWindow(window) || topBX.SidePanel.Instance.getTopSlider();

    if (!currentSlider) {
      window.top.location.reload();
      return;
    }

    const handler = function (event) {
      const slider = event.getSlider && event.getSlider();
      if (!slider || slider !== currentSlider) {
        return;
      }

      topBX.removeCustomEvent('SidePanel.Slider:onCloseComplete', handler);
      window.top.location.reload();
    };

    topBX.addCustomEvent('SidePanel.Slider:onCloseComplete', handler);
    currentSlider.close();
  }

  function showTopNotification(content) {
    try {
      const topCenter = window.top?.BX?.UI?.Notification?.Center;
      if (topCenter && typeof topCenter.notify === 'function') {
        topCenter.notify({content: content});
        return;
      }
    } catch (e) {}

    BX.UI.Notification.Center.notify({content: content});
  }

  // Active/Inactive toggle
  const toggleBtn = document.getElementById('toggle-active-btn');
  let isToggling = false;
  let activeState = parseInt(PREFILL.active || 0, 10) === 1 ? 1 : 0;

  function renderToggle() {
    if (!toggleBtn) return;

    if (activeState === 1) {
      toggleBtn.textContent = 'Деактивировать';
      toggleBtn.className = 'ui-btn ui-btn-danger ui-btn-no-caps';
    } else {
      toggleBtn.textContent = 'Активировать';
      toggleBtn.className = 'ui-btn ui-btn-primary ui-btn-no-caps';
    }
  }

  renderToggle();

  if (toggleBtn) {
    toggleBtn.addEventListener('click', function () {
      if (isToggling) return;

      isToggling = true;
      toggleBtn.classList.add('ui-btn-wait');

      const next = activeState === 1 ? 0 : 1;

      BX.ajax({
        url: '/local/specifications/edit/toggle_active.php',
        method: 'POST',
        dataType: 'json',
        data: {
          sessid: BX.bitrix_sessid(),
          headId: PREFILL.headId,
          active: next
        },
        onsuccess: function (res) {
          if (!res || !res.ok) {
            BX.UI.Notification.Center.notify({content: 'Ошибка переключения активности.'});
            return;
          }

          activeState = parseInt(res.active || 0, 10) === 1 ? 1 : 0;
          renderToggle();
          showTopNotification(activeState === 1 ? 'Спецификация активирована' : 'Спецификация деактивирована');
          setTimeout(closeSliderAndReload, 500);
        },
        onfailure: function () {
          BX.UI.Notification.Center.notify({content: 'Ошибка AJAX.'});
        },
        oncomplete: function () {
          isToggling = false;
          toggleBtn.classList.remove('ui-btn-wait');
        }
      });
    });
  }

  const rawList = document.getElementById('raw-list');
  const btnAdd = document.getElementById('raw-add');
  let rowIndex = 0;

  const rowSelectors = new Map();

  function destroyRowSelector(index) {
    const selector = rowSelectors.get(index);
    if (selector && typeof selector.destroy === 'function') {
      selector.destroy();
    }
    rowSelectors.delete(index);
  }

  function setRowInputsEnabled(rowEl, enabled, clearOnDisable) {
    const unitSelect = rowEl.querySelector('select[name$="[UNIT]"]');
    const qtyInput = rowEl.querySelector('input[name$="[QTY]"]');

    if (unitSelect) unitSelect.disabled = !enabled;
    if (qtyInput) qtyInput.disabled = !enabled;

    if (!enabled && clearOnDisable) {
      if (unitSelect) unitSelect.value = '';
      if (qtyInput) qtyInput.value = '';
    }
  }

  function setUnitByText(selectEl, unitText) {
    if (!selectEl || !unitText) return;
    const target = String(unitText).trim().replace(/\s+/g, ' ');
    const options = Array.from(selectEl.options || []);
    for (const opt of options) {
      const t = String(opt.textContent || '').trim().replace(/\s+/g, ' ');
      if (t === target) {
        opt.selected = true;
        return;
      }
    }
  }

  function watchRawId(rowEl, index, initialRawId) {
    const rawName = `RAW[${index}][RAWID]`;
    const unitSelect = rowEl.querySelector(`select[name="RAW[${index}][UNIT]"]`);

    let lastRawId = parseInt(initialRawId || '0', 10) || 0;
    let requestToken = 0;

    function getRawIdOrNull() {
      const hidden = rowEl.querySelector(`[name="${rawName}"]`);
      if (!hidden) {
        return null;
      }
      return parseInt(hidden.value || '0', 10) || 0;
    }

    function handleChange(rawId) {
      if (rawId <= 0) {
        // disable and clear for UX (since raw not selected)
        setRowInputsEnabled(rowEl, false, true);
        return;
      }

      setRowInputsEnabled(rowEl, true, false);

      const token = ++requestToken;
      fetchProductMeasure(rawId, function (measureId) {
        if (token !== requestToken) {
          return;
        }
        if (measureId > 0 && unitSelect) {
          unitSelect.value = String(measureId);
        }
      });
    }

    return setInterval(function () {
      const rawId = getRawIdOrNull();
      if (rawId === null) {
        return;
      }
      if (rawId !== lastRawId) {
        lastRawId = rawId;
        handleChange(rawId);
      }
    }, 250);
  }

  function renderRawRow(index, data) {
    data = data || {};

    const rawSelectorId = 'raw-selector-' + index;

    const row = document.createElement('div');
    row.className = 'raw-row';

    const hasPrefilledRaw = (parseInt(data.rawId || '0', 10) || 0) > 0;

    row.innerHTML = `
      <div class="raw-row-grid">
        <div>
          <div class="ui-ctl-label-text" style="margin-bottom:6px;">Сырьё (выбор)</div>
          <div id="${rawSelectorId}"></div>
          <input type="hidden" name="RAW[${index}][ROWID]" value="${data.rowId ? String(data.rowId) : ''}">
        </div>

        <div>
          <div class="ui-ctl-label-text" style="margin-bottom:6px;">Ед. изм.</div>
          <div class="ui-ctl ui-ctl-after-icon ui-ctl-dropdown ui-ctl-w100">
            <div class="ui-ctl-after ui-ctl-icon-angle"></div>
            <select class="ui-ctl-element" name="RAW[${index}][UNIT]" ${hasPrefilledRaw ? '' : 'disabled'}>
              <?php foreach ($units as $u): ?>
                <option value="<?= htmlspecialcharsbx($u['id']) ?>"><?= htmlspecialcharsbx($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div>
          <div class="ui-ctl-label-text" style="margin-bottom:6px;">Количество</div>
          <div class="ui-ctl ui-ctl-textbox ui-ctl-w100">
            <input class="ui-ctl-element" type="number" step="any" min="0" inputmode="decimal" name="RAW[${index}][QTY]" value="${data.qty ? String(data.qty) : ''}" ${hasPrefilledRaw ? '' : 'disabled'}>
          </div>
        </div>

        <div class="raw-remove-wrap">
          <button type="button" class="ui-btn ui-btn-light-border ui-btn-round ui-btn-xs raw-remove-btn" title="Удалить">×</button>
        </div>
      </div>
    `;

    row.__rawWatcher = null;

    row.querySelector('button.raw-remove-btn').addEventListener('click', function () {
      destroyRowSelector(index);
      if (row.__rawWatcher) {
        clearInterval(row.__rawWatcher);
        row.__rawWatcher = null;
      }
      row.remove();
    });

    rawList.appendChild(row);

    // Селектор сырья
    const selector = renderElementSelector(rawSelectorId, `RAW[${index}][RAWID]`, RAW_IBLOCK_ID, data.rawId);
    if (selector) {
      rowSelectors.set(index, selector);
    }

    // Ед. изм. (в базе строкой) — проставляем ТОЛЬКО при первичном заполнении формы
    const selectEl = row.querySelector('select[name$="[UNIT]"]');
    setUnitByText(selectEl, data.unit);

    // Watch raw changes: auto-fill unit ONLY when user changes raw
    row.__rawWatcher = watchRawId(row, index, data.rawId || 0);

    // For new row ensure clean disabled state
    if (!hasPrefilledRaw) {
      setRowInputsEnabled(row, false, true);
    }
  }

  btnAdd.addEventListener('click', function () {
    renderRawRow(rowIndex++, {});
  });

  // Prefill rows from head
  const initialRows = Array.isArray(PREFILL.rows) ? PREFILL.rows : [];
  if (initialRows.length) {
    initialRows.forEach(function (r) {
      renderRawRow(rowIndex++, r);
    });
  } else {
    renderRawRow(rowIndex++, {});
  }

  function extractRawTitleFromRow(rowEl) {
    const t =
      rowEl.querySelector('.ui-tag-selector-tag-title') ||
      rowEl.querySelector('.ui-tag-selector-item-text');
    return t ? (t.textContent || '').trim() : '';
  }

  const saveBtn = document.getElementById('save-btn');
  let isSaving = false;

  saveBtn.addEventListener('click', function () {
    if (isSaving) return;

    const specId = getSpecValue();
    if (!specId) {
      warnSpec.style.display = '';
      return;
    }
    warnSpec.style.display = 'none';

    const title = (nameInput.value || '').trim();
    if (!title) {
      BX.UI.Notification.Center.notify({content: 'Заполните название.'});
      return;
    }

    const rows = [];
    const rawRows = Array.from(document.querySelectorAll('#raw-list .raw-row'));

    for (const row of rawRows) {
      const rowId = parseInt(row.querySelector('[name$="[ROWID]"]')?.value || '0', 10) || 0;
      const rawId = parseInt(row.querySelector('[name$="[RAWID]"]')?.value || '0', 10) || 0;
      const qty = parseFloat(row.querySelector('[name$="[QTY]"]')?.value || '0') || 0;
      const unit = (row.querySelector('select[name$="[UNIT]"] option:checked')?.textContent || '').trim();
      const rowTitle = extractRawTitleFromRow(row);

      const isEmptyNewRow = rowId <= 0 && rawId <= 0 && qty <= 0 && unit === '';
      if (isEmptyNewRow) {
        continue;
      }

      const isValid = rawId > 0 && qty > 0 && unit !== '';
      if (!isValid) {
        BX.UI.Notification.Center.notify({content: 'Заполните все поля в строке сырья или удалите строку.'});
        return;
      }

      rows.push({ rowId: rowId, rawId: rawId, qty: qty, unit: unit, title: rowTitle });
    }

    isSaving = true;
    saveBtn.classList.add('ui-btn-wait');

    BX.ajax({
      url: '/local/specifications/edit/save_specification.php',
      method: 'POST',
      dataType: 'json',
      data: {
        sessid: BX.bitrix_sessid(),
        headId: PREFILL.headId,
        title: title,
        specId: specId,
        raw: JSON.stringify(rows)
      },
      onsuccess: function (res) {
        if (!res || !res.ok) {
          BX.UI.Notification.Center.notify({content: 'Ошибка сохранения.'});
          return;
        }

        showTopNotification('Изменения сохранены');
        setTimeout(closeSliderAndReload, 500);
      },
      onfailure: function () {
        BX.UI.Notification.Center.notify({content: 'Ошибка AJAX.'});
      },
      oncomplete: function () {
        isSaving = false;
        saveBtn.classList.remove('ui-btn-wait');
      }
    });
  });
});
</script>
</body>
</html>

<?php
require_once($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_after.php');
