/**
 * /local/specifications/edit/edit_form.js
 */
(function () {
  'use strict';

  var D            = SF_DATA;
  var catalog      = D.catalog;
  var workSections = D.workSections;

  var elWrap       = document.getElementById('sf-wrap');
  var currentHead  = null;
  var rowIdx       = 0;
  var isMassMode   = false;
  var selectedIds  = {};   // headId → true

  // ── DOM ──────────────────────────────────────────────────────────────────
  var elCatalog     = document.getElementById('sf-catalog');
  var elSearch      = document.getElementById('sf-search');
  var elTitle       = document.getElementById('sf-title');
  var elTbody       = document.getElementById('sf-tbody');
  var elTimeBadge   = document.getElementById('sf-time-badge');
  var elTimeTotal   = document.getElementById('sf-time-total');
  var elAddRow      = document.getElementById('sf-add-row');
  var elFooter      = document.getElementById('sf-footer');
  var elSave        = document.getElementById('sf-save');
  var elReset       = document.getElementById('sf-reset');

  var elBulkTbody   = document.getElementById('sf-bulk-tbody');
  var elBulkAddRow  = document.getElementById('sf-bulk-add-row');
  var elBulkApply   = document.getElementById('sf-bulk-apply');
  var elBulkReset   = document.getElementById('sf-bulk-reset');
  var elSelCount    = document.getElementById('sf-selected-count');

  // Дерево
  var elTreeOverlay = document.getElementById('sf-tree-overlay');
  var elTreeLeft    = document.getElementById('sf-tree-left');
  var elTreeRight   = document.getElementById('sf-tree-right');
  var elTreeSearch  = document.getElementById('sf-tree-search');
  var elTreeClose   = document.getElementById('sf-tree-close');
  var elTreeCancel  = document.getElementById('sf-tree-cancel');
  var elTreeSelect  = document.getElementById('sf-tree-select');
  var elTreeTitle   = document.getElementById('sf-tree-title');

  // Toast
  var toastEl    = document.getElementById('sf-toast');
  var toastTimer;
  function showToast(msg, type) {
    clearTimeout(toastTimer);
    toastEl.textContent = msg;
    toastEl.className = 'toast show' + (type ? ' ' + type : '');
    toastTimer = setTimeout(function () { toastEl.className = 'toast'; }, 3200);
  }

  // ── AJAX ─────────────────────────────────────────────────────────────────
  function ajaxPost(url, params) {
    var body = new FormData();
    body.append('sessid', D.sessid);
    Object.keys(params).forEach(function (k) { body.append(k, String(params[k])); });
    return fetch(url, { method: 'POST', body: body }).then(function (r) { return r.json(); });
  }

  function esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── Переключение вкладок ──────────────────────────────────────────────────
  document.querySelectorAll('.nav-tab').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var tab = this.dataset.tab;
      isMassMode = (tab === 'bulk');

      document.querySelectorAll('.nav-tab').forEach(function (b) { b.classList.remove('active'); });
      document.querySelectorAll('.sf-tab-pane').forEach(function (p) {
        p.style.display = p.dataset.tab === tab ? 'flex' : 'none';
      });
      this.classList.add('active');

      elWrap.classList.toggle('mass-mode', isMassMode);

      if (isMassMode) {
        // Сбрасываем выбор, рендерим каталог с чекбоксами
        selectedIds = {};
        elSelCount.textContent = '0';
        renderCatalog(elSearch.value);
        // Если таблица шаблона пуста — добавляем одну строку
        if (!elBulkTbody.querySelector('tr')) {
          addBulkRow();
        }
      } else {
        renderCatalog(elSearch.value);
      }
    });
  });

  // ── Каталог ────────────────────────────────────────────────────────────────
  function renderCatalog(filter) {
    filter = (filter || '').toLowerCase();
    var list = filter
      ? catalog.filter(function (c) { return c.title.toLowerCase().indexOf(filter) >= 0; })
      : catalog;

    if (list.length === 0) {
      elCatalog.innerHTML = '<div style="padding:20px;color:var(--muted);font-size:12px;text-align:center;font-style:italic;">Ничего не найдено</div>';
      return;
    }

    var html = '';
    list.forEach(function (c) {
      var isActive   = !isMassMode && currentHead && currentHead.headId === c.headId;
      var isSelected = isMassMode && !!selectedIds[c.headId];
      var cls = 'cat-item' + (isActive ? ' active' : '') + (isSelected ? ' selected' : '');

      html += '<div class="' + cls + '" data-head-id="' + c.headId + '">'
        + '<div class="check-box"></div>'
        + '<div class="cat-item-info">'
        +   '<div class="cat-item-name">' + esc(c.title) + '</div>'
        +   '<div class="cat-item-meta">'
        +     '<span>' + c.stepsCount + ' эт.</span>'
        +     '<span class="' + (c.active ? 'badge-active' : 'badge-inactive') + '">'
        +       (c.active ? '● Актив.' : '● Неактив.') + '</span>'
        +   '</div>'
        + '</div>'
        + '</div>';
    });
    elCatalog.innerHTML = html;
  }

  elCatalog.addEventListener('click', function (e) {
    var item = e.target.closest('.cat-item');
    if (!item) return;
    var headId = parseInt(item.dataset.headId, 10);
    var found  = catalog.find(function (c) { return c.headId === headId; });
    if (!found) return;

    if (isMassMode) {
      // Переключаем чекбокс
      if (selectedIds[headId]) {
        delete selectedIds[headId];
      } else {
        selectedIds[headId] = true;
      }
      elSelCount.textContent = Object.keys(selectedIds).length;
      renderCatalog(elSearch.value);
    } else {
      selectHead(found);
    }
  });

  elSearch.addEventListener('input', function () { renderCatalog(this.value); });

  // ── Выбор спецификации (одиночный режим) ──────────────────────────────────
  function selectHead(head) {
    currentHead = head;
    renderCatalog(elSearch.value);
    elTitle.textContent = head.title;
    elTimeBadge.style.display = '';
    elAddRow.style.display    = '';
    elFooter.style.display    = '';
    elTbody.innerHTML = '<tr><td colspan="6" class="sf-loader">Загрузка...</td></tr>';

    ajaxPost(D.urls.load, { headId: head.headId }).then(function (data) {
      elTbody.innerHTML = '';
      if (!data.ok) { showToast('Ошибка: ' + data.error, 'err'); return; }
      (data.steps || []).forEach(function (s) { addRow(s); });
      calcTime();
    }).catch(function () {
      elTbody.innerHTML = '';
      showToast('Ошибка загрузки', 'err');
    });
  }

  // ── Опции участков ─────────────────────────────────────────────────────────
  function buildSectionOpts(selectedId) {
    var html = '<option value="">— участок —</option>';
    workSections.forEach(function (s) {
      html += '<option value="' + s.id + '"' + (parseInt(selectedId, 10) === s.id ? ' selected' : '') + '>' + esc(s.name) + '</option>';
    });
    return html;
  }

  // ── Добавить строку (одиночный режим) ──────────────────────────────────────
  function addRow(data) {
    data = data || {};
    var idx   = rowIdx++;
    var rowId = data.rowId || 0;
    var rawId = data.rawId || 0;
    var mat   = data.mat   || '';
    var mSym  = data.measureSym || '';
    var mId   = data.measureId  || 0;

    var tr = document.createElement('tr');
    tr.dataset.rowId     = rowId;
    tr.dataset.rawId     = rawId;
    tr.dataset.measureId = mId;
    tr.dataset.idx       = idx;

    tr.innerHTML = [
      '<td>',
        '<button class="sf-mat-btn' + (mat ? ' has-value' : '') + '" data-idx="' + idx + '" title="' + esc(mat) + '">',
          esc(mat || '+ Выбрать материал'),
        '</button>',
        '<div class="sf-alts" data-idx="' + idx + '"></div>',
        '<button class="sf-alt-add" data-idx="' + idx + '">+ альтернатива</button>',
      '</td>',
      '<td>',
        '<div class="sf-qty-group">',
          '<input type="number" class="sf-inp-qty" value="' + (data.qty !== undefined ? data.qty : 0) + '" min="0" step="any">',
          '<input type="text" class="sf-unit-field" value="' + esc(mSym || '—') + '" readonly tabindex="-1" data-measure-id="' + mId + '" title="' + esc(mSym || '') + '">',
        '</div>',
      '</td>',
      '<td><select class="t-inp sf-inp-sec" title="">' + buildSectionOpts(data.section || 0) + '</select></td>',
      '<td><textarea class="t-inp sf-inp-desc" rows="2" placeholder="Описание операции..." title="' + esc(data.desc || '') + '">' + esc(data.desc || '') + '</textarea></td>',
      '<td><input type="number" class="t-inp time-inp sf-inp-time" value="' + (data.time || 0) + '" min="0"></td>',
      '<td><button type="button" class="sf-btn-icon sf-row-del" title="Удалить">✕</button></td>',
    ].join('');

    elTbody.appendChild(tr);

    // Обновляем title у select участка при изменении
    var secSel = tr.querySelector('.sf-inp-sec');
    var updateSecTitle = function () {
      var opt = secSel.options[secSel.selectedIndex];
      secSel.title = opt ? opt.textContent : '';
    };
    secSel.addEventListener('change', updateSecTitle);
    updateSecTitle();

    // Обновляем title у textarea при вводе
    tr.querySelector('.sf-inp-desc').addEventListener('input', function () { this.title = this.value; });

    tr.querySelector('.sf-mat-btn').addEventListener('click', function () { openTree(tr, false); });
    tr.querySelector('.sf-alt-add').addEventListener('click', function () { openTree(tr, true); });
    tr.querySelector('.sf-row-del').addEventListener('click', function () { tr.remove(); calcTime(); });
    tr.querySelector('.sf-inp-time').addEventListener('input', calcTime);

    if (Array.isArray(data.alts)) {
      var altsEl = tr.querySelector('.sf-alts');
      data.alts.forEach(function (a) { addAltRow(altsEl, a); });
    }
  }

  elAddRow.addEventListener('click', function () { addRow(); });

  // ── Добавить строку альтернативы (одиночный режим) ─────────────────────────
  function addAltRow(container, data) {
    data = data || {};
    var rawId = data.rawId || 0;
    var mat   = data.mat   || '';
    var mSym  = data.measureSym || '';
    var mId   = data.measureId  || 0;

    var div = document.createElement('div');
    div.className = 'sf-alt-row';
    div.dataset.rowId     = data.rowId || 0;
    div.dataset.rawId     = rawId;
    div.dataset.measureId = mId;

    div.innerHTML = [
      '<span class="sf-alt-prio"></span>',
      '<button type="button" class="sf-mat-btn sf-alt-mat-btn' + (mat ? ' has-value' : '') + '" title="' + esc(mat) + '">',
        esc(mat || '+ Материал'),
      '</button>',
      '<input type="number" class="sf-alt-qty" value="' + (data.qty !== undefined ? data.qty : 0) + '" min="0" step="any">',
      '<span class="sf-unit-sm" data-measure-id="' + mId + '" title="' + esc(mSym || '') + '">' + esc(mSym || '—') + '</span>',
      '<button type="button" class="sf-alt-del" title="Удалить">✕</button>',
    ].join('');

    container.appendChild(div);

    div.querySelector('.sf-alt-mat-btn').addEventListener('click', function () { openTreeForAlt(div); });
    div.querySelector('.sf-alt-del').addEventListener('click', function () { div.remove(); reindexAlts(container); });

    reindexAlts(container);
  }

  function reindexAlts(container) {
    container.querySelectorAll('.sf-alt-row').forEach(function (row, i) {
      var n = i + 1;
      var badge = row.querySelector('.sf-alt-prio');
      badge.textContent = 'П-' + n;
      badge.className = 'sf-alt-prio sf-prio-' + (n <= 3 ? n : 'n');
    });
  }

  // ── Время ──────────────────────────────────────────────────────────────────
  function calcTime() {
    var total = 0;
    elTbody.querySelectorAll('.sf-inp-time').forEach(function (inp) { total += parseInt(inp.value, 10) || 0; });
    elTimeTotal.textContent = total;
  }

  // ── Сохранение (одиночный режим) ───────────────────────────────────────────
  elReset.addEventListener('click', function () { if (currentHead) selectHead(currentHead); });

  elSave.addEventListener('click', function () {
    if (!currentHead) { showToast('Выберите спецификацию', 'err'); return; }
    var steps = collectSteps();
    elSave.disabled = true;
    elSave.textContent = 'Сохранение...';

    ajaxPost(D.urls.save, {
      headId: currentHead.headId,
      title:  currentHead.title,
      steps:  JSON.stringify(steps),
    }).then(function (data) {
      if (!data.ok) { showToast('Ошибка: ' + (data.error || ''), 'err'); return; }
      var cat = catalog.find(function (c) { return c.headId === currentHead.headId; });
      if (cat) cat.stepsCount = data.rowIds.length;
      renderCatalog(elSearch.value);
      showToast('Спецификация сохранена ✓', 'ok');
    }).catch(function () {
      showToast('Ошибка AJAX', 'err');
    }).finally(function () {
      elSave.disabled = false;
      elSave.textContent = 'Сохранить изменения';
    });
  });

  function collectSteps() {
    var steps = [];
    elTbody.querySelectorAll('tr[data-idx]').forEach(function (tr) {
      var alts = [];
      tr.querySelectorAll('.sf-alt-row').forEach(function (div) {
        var altBtn = div.querySelector('.sf-alt-mat-btn');
        alts.push({
          rowId:      parseInt(div.dataset.rowId, 10) || 0,
          rawId:      parseInt(div.dataset.rawId, 10) || 0,
          mat:        altBtn && altBtn.classList.contains('has-value') ? altBtn.textContent.trim() : '',
          qty:        parseFloat(div.querySelector('.sf-alt-qty').value) || 0,
          measureSym: ((div.querySelector('.sf-unit-sm') || {}).textContent || '').replace('—', '').trim(),
        });
      });
      var matBtn = tr.querySelector('.sf-mat-btn');
      steps.push({
        rowId:      parseInt(tr.dataset.rowId, 10) || 0,
        rawId:      parseInt(tr.dataset.rawId, 10) || 0,
        mat:        matBtn && matBtn.classList.contains('has-value') ? matBtn.textContent.trim() : '',
        qty:        parseFloat(tr.querySelector('.sf-inp-qty').value) || 0,
        measureSym: tr.querySelector('.sf-unit-field').value.replace('—', '').trim(),
        section:    parseInt(tr.querySelector('.sf-inp-sec').value, 10) || 0,
        desc:       tr.querySelector('.sf-inp-desc').value.trim(),
        time:       parseInt(tr.querySelector('.sf-inp-time').value, 10) || 0,
        alts:       alts,
      });
    });
    return steps;
  }

  // ── Массовое назначение: строки шаблона ────────────────────────────────────
  function addBulkRow(data) {
    data = data || {};
    var rawId = data.rawId || 0;
    var mat   = data.mat   || '';
    var mSym  = data.measureSym || '';
    var mId   = data.measureId  || 0;

    var tr = document.createElement('tr');
    tr.dataset.rawId     = rawId;
    tr.dataset.measureId = mId;

    tr.innerHTML = [
      '<td>',
        '<button class="sf-mat-btn' + (mat ? ' has-value' : '') + '" title="' + esc(mat) + '">',
          esc(mat || '+ Выбрать материал'),
        '</button>',
        '<div class="sf-alts"></div>',
        '<button class="sf-alt-add">+ альтернатива</button>',
      '</td>',
      '<td>',
        '<div class="sf-qty-group">',
          '<input type="number" class="sf-inp-qty" value="' + (data.qty !== undefined ? data.qty : 1) + '" min="0" step="any">',
          '<input type="text" class="sf-unit-field" value="' + esc(mSym || '—') + '" readonly tabindex="-1" data-measure-id="' + mId + '" title="' + esc(mSym || '') + '">',
        '</div>',
      '</td>',
      '<td><select class="t-inp sf-inp-sec" title="">' + buildSectionOpts(data.section || 0) + '</select></td>',
      '<td><textarea class="t-inp sf-inp-desc" rows="2" placeholder="Описание операции..." title="' + esc(data.desc || '') + '">' + esc(data.desc || '') + '</textarea></td>',
      '<td><input type="number" class="t-inp time-inp sf-inp-time" value="' + (data.time || 0) + '" min="0"></td>',
      '<td><button type="button" class="sf-btn-icon sf-row-del" title="Удалить">✕</button></td>',
    ].join('');

    elBulkTbody.appendChild(tr);

    var bulkSecSel = tr.querySelector('.sf-inp-sec');
    var updateBulkSecTitle = function () {
      var opt = bulkSecSel.options[bulkSecSel.selectedIndex];
      bulkSecSel.title = opt ? opt.textContent : '';
    };
    bulkSecSel.addEventListener('change', updateBulkSecTitle);
    updateBulkSecTitle();

    tr.querySelector('.sf-inp-desc').addEventListener('input', function () { this.title = this.value; });

    tr.querySelector('.sf-mat-btn').addEventListener('click', function () { openTreeForBulk(tr); });
    tr.querySelector('.sf-alt-add').addEventListener('click', function () { openTreeForBulkAlt(tr); });
    tr.querySelector('.sf-row-del').addEventListener('click', function () { tr.remove(); });
  }

  elBulkAddRow.addEventListener('click', function () { addBulkRow(); });

  elBulkReset.addEventListener('click', function () {
    elBulkTbody.innerHTML = '';
    addBulkRow();
  });

  elBulkApply.addEventListener('click', function () {
    var ids = Object.keys(selectedIds).map(Number);
    if (ids.length === 0) { showToast('Выберите изделия в каталоге слева', 'err'); return; }
    var rows = elBulkTbody.querySelectorAll('tr');
    if (!rows.length) { showToast('Добавьте хотя бы один этап в шаблон', 'err'); return; }

    var steps = [];
    rows.forEach(function (tr) {
      var alts = [];
      tr.querySelectorAll('.sf-alt-row').forEach(function (div) {
        var altBtn = div.querySelector('.sf-alt-mat-btn');
        alts.push({
          rawId:      parseInt(div.dataset.rawId, 10) || 0,
          mat:        altBtn && altBtn.classList.contains('has-value') ? altBtn.textContent.trim() : '',
          qty:        parseFloat(div.querySelector('.sf-alt-qty').value) || 0,
          measureSym: ((div.querySelector('.sf-unit-sm') || {}).textContent || '').replace('—', '').trim(),
        });
      });
      var bulkMatBtn = tr.querySelector('.sf-mat-btn');
      steps.push({
        rawId:   parseInt(tr.dataset.rawId, 10) || 0,
        mat:     bulkMatBtn && bulkMatBtn.classList.contains('has-value') ? bulkMatBtn.textContent.trim() : '',
        qty:     parseFloat(tr.querySelector('.sf-inp-qty').value) || 0,
        section: parseInt(tr.querySelector('.sf-inp-sec').value, 10) || 0,
        desc:    tr.querySelector('.sf-inp-desc').value.trim(),
        time:    parseInt(tr.querySelector('.sf-inp-time').value, 10) || 0,
        alts:    alts,
      });
    });

    elBulkApply.disabled = true;
    elBulkApply.textContent = 'Применяю...';

    ajaxPost(D.urls.applyBulk, {
      headIds: JSON.stringify(ids),
      steps:   JSON.stringify(steps),
    }).then(function (data) {
      if (!data.ok) { showToast('Ошибка: ' + (data.error || ''), 'err'); return; }
      showToast('Шаблон применён к ' + ids.length + ' позициям ✓', 'ok');
      selectedIds = {};
      elSelCount.textContent = '0';
      renderCatalog(elSearch.value);
      // Обновляем stepsCount в каталоге
      if (data.updated) {
        data.updated.forEach(function (u) {
          var cat = catalog.find(function (c) { return c.headId === u.headId; });
          if (cat) cat.stepsCount = u.stepsCount;
        });
        renderCatalog(elSearch.value);
      }
    }).catch(function () {
      showToast('Ошибка AJAX', 'err');
    }).finally(function () {
      elBulkApply.disabled = false;
      elBulkApply.textContent = 'Применить шаблон к выбранным';
    });
  });

  // ── Дерево материалов ─────────────────────────────────────────────────────
  var treeSections    = {};
  var treeExpanded    = {};
  var treeActiveSec   = 0;
  var treeSelectedId   = 0;
  var treeSelectedName = '';
  var treeSelectedMId  = 0;
  var treeSelectedMSym = '';
  var treeTargetRow    = null;
  var treeTargetAlt    = null;
  var treeMode         = 'single'; // 'single' | 'bulk' | 'bulk-alt'
  var treeSearchTimer  = null;

  function openTree(tr, isAlt) {
    treeTargetRow = tr; treeTargetAlt = null;
    treeMode = isAlt ? 'single-alt' : 'single';
    _openTreeModal(isAlt ? 'Выбор материала (альтернатива)' : 'Выбор материала');
  }

  function openTreeForAlt(div) {
    treeTargetRow = null; treeTargetAlt = div;
    treeMode = 'alt-existing';
    _openTreeModal('Выбор материала (альтернатива)');
  }

  function openTreeForBulk(tr) {
    treeTargetRow = tr; treeTargetAlt = null;
    treeMode = 'bulk';
    _openTreeModal('Выбор материала');
  }

  function openTreeForBulkAlt(tr) {
    treeTargetRow = tr; treeTargetAlt = null;
    treeMode = 'bulk-alt';
    _openTreeModal('Выбор материала (альтернатива)');
  }

  function _openTreeModal(title) {
    treeSelectedId = 0; treeSelectedName = ''; treeSelectedMId = 0; treeSelectedMSym = '';
    treeExpanded = { 0: true }; treeActiveSec = 0;
    elTreeTitle.textContent = title;
    elTreeSearch.value = '';
    elTreeRight.innerHTML = '<div class="sf-loader">Выберите раздел слева</div>';
    loadTreeSections(0, function () { renderTreeLeft(); loadTreeItems(0); });
    elTreeOverlay.style.display = '';
  }

  function closeTree() {
    elTreeOverlay.style.display = 'none';
    treeTargetRow = null; treeTargetAlt = null;
  }

  elTreeClose.addEventListener('click', closeTree);
  elTreeCancel.addEventListener('click', closeTree);
  elTreeOverlay.addEventListener('click', function (e) { if (e.target === elTreeOverlay) closeTree(); });

  elTreeSelect.addEventListener('click', function () {
    if (!treeSelectedId) { showToast('Выберите материал', 'err'); return; }

    if (treeMode === 'alt-existing' && treeTargetAlt) {
      // Обновляем материал в существующей alt-строке
      var btn = treeTargetAlt.querySelector('.sf-alt-mat-btn');
      btn.textContent = treeSelectedName;
      btn.title = treeSelectedName;
      btn.classList.add('has-value');
      treeTargetAlt.dataset.rawId     = treeSelectedId;
      treeTargetAlt.dataset.measureId = treeSelectedMId;
      var unitSm = treeTargetAlt.querySelector('.sf-unit-sm');
      unitSm.textContent = treeSelectedMSym || '—';
      closeTree(); return;
    }

    if (!treeTargetRow) { closeTree(); return; }

    if (treeMode === 'single-alt') {
      // Добавляем альтернативу в строку редактора
      addAltRow(treeTargetRow.querySelector('.sf-alts'), {
        rawId: treeSelectedId, mat: treeSelectedName,
        measureId: treeSelectedMId, measureSym: treeSelectedMSym, qty: 0,
      });
    } else if (treeMode === 'bulk-alt') {
      // Добавляем альтернативу в строку шаблона
      addBulkAltRow(treeTargetRow.querySelector('.sf-alts'), {
        rawId: treeSelectedId, mat: treeSelectedName,
        measureId: treeSelectedMId, measureSym: treeSelectedMSym, qty: 0,
      });
    } else {
      // Обновляем основной материал строки (single или bulk)
      var matBtn = treeTargetRow.querySelector('.sf-mat-btn');
      matBtn.textContent = treeSelectedName;
      matBtn.title = treeSelectedName;
      matBtn.classList.add('has-value');
      treeTargetRow.dataset.rawId     = treeSelectedId;
      treeTargetRow.dataset.measureId = treeSelectedMId;
      treeTargetRow.querySelector('.sf-unit-field').value = treeSelectedMSym || '—';
    }

    closeTree();
  });

  // Альтернатива в bulk-строке
  function addBulkAltRow(container, data) {
    data = data || {};
    var div = document.createElement('div');
    div.className = 'sf-alt-row';
    div.dataset.rawId     = data.rawId || 0;
    div.dataset.measureId = data.measureId || 0;

    div.innerHTML = [
      '<span class="sf-alt-prio"></span>',
      '<button type="button" class="sf-mat-btn sf-alt-mat-btn' + (data.mat ? ' has-value' : '') + '" title="' + esc(data.mat || '') + '">',
        esc(data.mat || '+ Материал'),
      '</button>',
      '<input type="number" class="sf-alt-qty" value="' + (data.qty || 0) + '" min="0" step="any">',
      '<span class="sf-unit-sm" title="' + esc(data.measureSym || '') + '">' + esc(data.measureSym || '—') + '</span>',
      '<button type="button" class="sf-alt-del">✕</button>',
    ].join('');

    container.appendChild(div);
    div.querySelector('.sf-alt-mat-btn').addEventListener('click', function () { openTreeForAlt(div); });
    div.querySelector('.sf-alt-del').addEventListener('click', function () { div.remove(); reindexAlts(container); });
    reindexAlts(container);
  }

  // Поиск в дереве
  elTreeSearch.addEventListener('input', function () {
    clearTimeout(treeSearchTimer);
    var q = this.value.trim();
    treeSearchTimer = setTimeout(function () {
      if (q.length < 2) { renderTreeLeft(); loadTreeItems(treeActiveSec); return; }
      elTreeRight.innerHTML = '<div class="sf-loader">Поиск...</div>';
      ajaxPost(D.urls.materials, { action: 'search', q: q }).then(function (data) {
        renderTreeItems(data.items || []);
      });
    }, 300);
  });

  function loadTreeSections(parentId, cb) {
    if (treeSections[parentId] !== undefined) { if (cb) cb(); return; }
    ajaxPost(D.urls.materials, { action: 'sections', sectionId: parentId }).then(function (data) {
      treeSections[parentId] = data.sections || [];
      if (cb) cb();
    });
  }

  function renderTreeLeft() {
    elTreeLeft.innerHTML = '';
    renderTreeLevel(0, 0, elTreeLeft);
  }

  function renderTreeLevel(parentId, depth, container) {
    (treeSections[parentId] || []).forEach(function (sec) {
      var isActive   = treeActiveSec === sec.id;
      var isExpanded = !!treeExpanded[sec.id];
      var node = document.createElement('div');
      node.className = 'sf-tree-node' + (isActive ? ' is-active' : '');
      node.style.paddingLeft = (10 + depth * 14) + 'px';
      node.dataset.secId = sec.id;
      node.innerHTML = (sec.hasChildren
        ? '<span class="sf-tree-toggle">' + (isExpanded ? '▾' : '▸') + '</span>'
        : '<span class="sf-tree-toggle"> </span>')
        + '<span>' + esc(sec.name) + '</span>';
      container.appendChild(node);

      if (isExpanded && treeSections[sec.id]) renderTreeLevel(sec.id, depth + 1, container);

      node.addEventListener('click', function () {
        treeActiveSec = sec.id;
        if (sec.hasChildren) {
          if (treeExpanded[sec.id]) { delete treeExpanded[sec.id]; }
          else { treeExpanded[sec.id] = true; loadTreeSections(sec.id, function () { renderTreeLeft(); }); }
        }
        renderTreeLeft();
        loadTreeItems(sec.id);
      });
    });
  }

  function loadTreeItems(sectionId) {
    treeActiveSec = sectionId;
    elTreeRight.innerHTML = '<div class="sf-loader">Загрузка...</div>';
    ajaxPost(D.urls.materials, { action: 'items', sectionId: sectionId }).then(function (data) {
      renderTreeItems(data.items || []);
    });
  }

  function renderTreeItems(items) {
    elTreeRight.innerHTML = '';
    if (!items.length) {
      elTreeRight.innerHTML = '<div class="sf-tree-empty">Нет товаров в этом разделе</div>';
      return;
    }
    items.forEach(function (item) {
      var el = document.createElement('div');
      el.className = 'sf-tree-item' + (treeSelectedId === item.id ? ' is-selected' : '');
      el.dataset.id         = item.id;
      el.dataset.measureId  = item.measureId  || 0;
      el.dataset.measureSym = item.measureSym || '';
      el.innerHTML = '<span class="sf-tree-item-name" title="' + esc(item.name) + '">' + esc(item.name) + '</span>'
        + (item.measureSym ? '<span class="sf-tree-item-unit">' + esc(item.measureSym) + '</span>' : '');

      el.addEventListener('click', function () {
        treeSelectedId = item.id; treeSelectedName = item.name;
        treeSelectedMId = item.measureId || 0; treeSelectedMSym = item.measureSym || '';
        elTreeRight.querySelectorAll('.sf-tree-item').forEach(function (e) {
          e.classList.toggle('is-selected', parseInt(e.dataset.id, 10) === treeSelectedId);
        });
      });
      el.addEventListener('dblclick', function () {
        treeSelectedId = item.id; treeSelectedName = item.name;
        treeSelectedMId = item.measureId || 0; treeSelectedMSym = item.measureSym || '';
        elTreeSelect.click();
      });
      elTreeRight.appendChild(el);
    });
  }

  // ── Старт ──────────────────────────────────────────────────────────────────

  // Высота обёртки = доступное место под шапкой портала
  function resizeWrap() {
    elWrap.style.height = (window.innerHeight - elWrap.getBoundingClientRect().top) + 'px';
  }
  resizeWrap();
  window.addEventListener('resize', resizeWrap);

  elWrap.classList.add('sf-ready');
  renderCatalog();

  // Предзаполняем bulk-таблицу одной пустой строкой
  addBulkRow();

})();
