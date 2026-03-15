/**
 * /local/specifications/edit/edit_form.js
 */
(function () {
  'use strict';

  var D            = SF_DATA;
  var catalog      = D.catalog;       // [{headId, title, stepsCount, active}]
  var workSections = D.workSections;  // [{id, name}] — участки ИБ26

  var currentHead = null;
  var rowIdx      = 0;

  // ── DOM ──────────────────────────────────────────────────────────────────────
  var elCatalog   = document.getElementById('sf-catalog');
  var elSearch    = document.getElementById('sf-search');
  var elTitle     = document.getElementById('sf-title');
  var elTbody     = document.getElementById('sf-tbody');
  var elTimeBadge = document.getElementById('sf-time-badge');
  var elTimeTotal = document.getElementById('sf-time-total');
  var elAddRow    = document.getElementById('sf-add-row');
  var elFooter    = document.getElementById('sf-footer');
  var elSave      = document.getElementById('sf-save');
  var elReset     = document.getElementById('sf-reset');

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
  var toastEl = document.createElement('div');
  toastEl.className = 'sf-toast';
  document.body.appendChild(toastEl);
  var toastTimer;
  function showToast(msg, isErr) {
    clearTimeout(toastTimer);
    toastEl.textContent = msg;
    toastEl.className = 'sf-toast show' + (isErr ? ' err' : '');
    toastTimer = setTimeout(function () { toastEl.className = 'sf-toast'; }, 3500);
  }

  // ── AJAX ─────────────────────────────────────────────────────────────────────
  function ajaxPost(url, params) {
    var body = new FormData();
    body.append('sessid', D.sessid);
    Object.keys(params).forEach(function (k) { body.append(k, String(params[k])); });
    return fetch(url, { method: 'POST', body: body }).then(function (r) { return r.json(); });
  }

  // ── Утилиты ───────────────────────────────────────────────────────────────
  function esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  // ── Каталог (левая панель) ────────────────────────────────────────────────
  function renderCatalog(filter) {
    filter = (filter || '').toLowerCase();
    var list = filter
      ? catalog.filter(function (c) { return c.title.toLowerCase().indexOf(filter) >= 0; })
      : catalog;

    if (list.length === 0) {
      elCatalog.innerHTML = '<div class="sf-tree-empty">Ничего не найдено</div>';
      return;
    }

    var html = '';
    list.forEach(function (c) {
      var isActive = currentHead && currentHead.headId === c.headId ? ' is-active' : '';
      html += '<div class="sf-cat-item' + isActive + '" data-head-id="' + c.headId + '">'
        + '<div class="sf-cat-item-name">' + esc(c.title) + '</div>'
        + '<div class="sf-cat-item-meta">'
        +   '<span>' + c.stepsCount + ' этапов</span>'
        +   '<span class="' + (c.active ? 'sf-badge-active' : 'sf-badge-inactive') + '">'
        +     (c.active ? '● Активна' : '● Неактивна') + '</span>'
        + '</div>'
        + '</div>';
    });
    elCatalog.innerHTML = html;
  }

  elCatalog.addEventListener('click', function (e) {
    var item = e.target.closest('.sf-cat-item');
    if (!item) return;
    var headId = parseInt(item.dataset.headId, 10);
    var found  = catalog.find(function (c) { return c.headId === headId; });
    if (found) selectHead(found);
  });

  elSearch.addEventListener('input', function () { renderCatalog(this.value); });

  // ── Выбор спецификации ────────────────────────────────────────────────────
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
      if (!data.ok) { showToast('Ошибка: ' + data.error, true); return; }
      (data.steps || []).forEach(function (s) { addRow(s); });
      calcTime();
    }).catch(function () {
      elTbody.innerHTML = '';
      showToast('Ошибка загрузки', true);
    });
  }

  // ── Опции участков ────────────────────────────────────────────────────────
  function buildSectionOpts(selectedId) {
    var html = '<option value="">— участок —</option>';
    workSections.forEach(function (s) {
      var sel = parseInt(selectedId, 10) === s.id ? ' selected' : '';
      html += '<option value="' + s.id + '"' + sel + '>' + esc(s.name) + '</option>';
    });
    return html;
  }

  // ── Добавить строку основного этапа ──────────────────────────────────────
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
        '<button class="sf-mat-btn' + (mat ? ' has-value' : '') + '" data-idx="' + idx + '">',
          esc(mat || '+ Выбрать материал'),
        '</button>',
        '<div class="sf-alts" data-idx="' + idx + '"></div>',
        '<button class="sf-alt-add" data-idx="' + idx + '">+ альтернатива</button>',
      '</td>',

      // Кол-во + единица (нередактируемая)
      '<td>',
        '<div class="sf-qty-wrap">',
          '<button class="sf-qty-btn sf-qty-minus">−</button>',
          '<input type="number" class="sf-inp sf-inp-qty" value="' + (data.qty !== undefined ? data.qty : 0) + '" min="0" step="1">',
          '<button class="sf-qty-btn sf-qty-plus">+</button>',
        '</div>',
        '<div class="sf-unit-label" data-measure-id="' + mId + '">' + esc(mSym || '—') + '</div>',
      '</td>',

      '<td><select class="sf-inp sf-inp-sec">' + buildSectionOpts(data.section || 0) + '</select></td>',
      '<td><textarea class="sf-inp sf-inp-desc" rows="2" placeholder="Описание операции...">' + esc(data.desc || '') + '</textarea></td>',
      '<td><input type="number" class="sf-inp sf-inp-time" value="' + (data.time || 0) + '" min="0"></td>',
      '<td><button class="sf-btn-icon sf-row-del" title="Удалить">✕</button></td>',
    ].join('');

    elTbody.appendChild(tr);

    // Кнопки ±
    tr.querySelector('.sf-qty-minus').addEventListener('click', function () {
      var inp = tr.querySelector('.sf-inp-qty');
      inp.value = Math.max(0, (parseFloat(inp.value) || 0) - 1);
    });
    tr.querySelector('.sf-qty-plus').addEventListener('click', function () {
      var inp = tr.querySelector('.sf-inp-qty');
      inp.value = (parseFloat(inp.value) || 0) + 1;
    });

    // Выбор материала
    tr.querySelector('.sf-mat-btn').addEventListener('click', function () {
      openTree(tr, false);
    });

    // Добавить альтернативу
    tr.querySelector('.sf-alt-add').addEventListener('click', function () {
      openTree(tr, true);
    });

    // Удалить строку
    tr.querySelector('.sf-row-del').addEventListener('click', function () {
      tr.remove();
      calcTime();
    });

    tr.querySelector('.sf-inp-time').addEventListener('input', calcTime);

    // Заполняем альтернативы
    if (Array.isArray(data.alts)) {
      var altsEl = tr.querySelector('.sf-alts');
      data.alts.forEach(function (a) { addAltRow(altsEl, a); });
    }
  }

  elAddRow.addEventListener('click', function () { addRow(); });

  // ── Строка альтернативы ───────────────────────────────────────────────────
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
      '<button class="sf-mat-btn sf-alt-mat-btn' + (mat ? ' has-value' : '') + '">',
        esc(mat || '+ Выбрать материал'),
      '</button>',
      '<div class="sf-qty-wrap sf-qty-wrap-sm">',
        '<button class="sf-qty-btn sf-qty-minus">−</button>',
        '<input type="number" class="sf-inp sf-alt-qty" value="' + (data.qty !== undefined ? data.qty : 0) + '" min="0" step="1" style="width:60px;">',
        '<button class="sf-qty-btn sf-qty-plus">+</button>',
      '</div>',
      '<span class="sf-unit-sm">' + esc(mSym || '—') + '</span>',
      '<button class="sf-alt-del" title="Удалить">✕</button>',
    ].join('');

    container.appendChild(div);

    // ±
    div.querySelector('.sf-qty-minus').addEventListener('click', function () {
      var inp = div.querySelector('.sf-alt-qty');
      inp.value = Math.max(0, (parseFloat(inp.value) || 0) - 1);
    });
    div.querySelector('.sf-qty-plus').addEventListener('click', function () {
      var inp = div.querySelector('.sf-alt-qty');
      inp.value = (parseFloat(inp.value) || 0) + 1;
    });

    // Выбор материала альтернативы
    div.querySelector('.sf-alt-mat-btn').addEventListener('click', function () {
      openTreeForAlt(div);
    });

    div.querySelector('.sf-alt-del').addEventListener('click', function () {
      div.remove();
      reindexAlts(container);
    });

    reindexAlts(container);
  }

  function reindexAlts(container) {
    var rows = container.querySelectorAll('.sf-alt-row');
    rows.forEach(function (row, i) {
      var n     = i + 1;
      var badge = row.querySelector('.sf-alt-prio');
      badge.textContent = 'П-' + n;
      badge.className = 'sf-alt-prio sf-prio-' + (n <= 3 ? n : 'n');
    });
  }

  // ── Время ─────────────────────────────────────────────────────────────────
  function calcTime() {
    var total = 0;
    elTbody.querySelectorAll('.sf-inp-time').forEach(function (inp) {
      total += parseInt(inp.value, 10) || 0;
    });
    elTimeTotal.textContent = total;
  }

  // ── Сброс / сохранение ────────────────────────────────────────────────────
  elReset.addEventListener('click', function () {
    if (currentHead) selectHead(currentHead);
  });

  elSave.addEventListener('click', function () {
    if (!currentHead) { showToast('Выберите спецификацию', true); return; }
    var steps = collectSteps();
    elSave.disabled = true;
    elSave.textContent = 'Сохранение...';

    ajaxPost(D.urls.save, {
      headId: currentHead.headId,
      title:  currentHead.title,
      steps:  JSON.stringify(steps),
    }).then(function (data) {
      if (!data.ok) {
        showToast('Ошибка: ' + (data.error || 'неизвестная'), true);
        return;
      }
      var cat = catalog.find(function (c) { return c.headId === currentHead.headId; });
      if (cat) cat.stepsCount = data.rowIds.length;
      renderCatalog(elSearch.value);
      showToast('Спецификация сохранена ✓');
    }).catch(function () {
      showToast('Ошибка AJAX', true);
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
        alts.push({
          rowId: parseInt(div.dataset.rowId, 10) || 0,
          rawId: parseInt(div.dataset.rawId, 10) || 0,
          qty:   parseFloat(div.querySelector('.sf-alt-qty').value) || 0,
        });
      });
      steps.push({
        rowId:   parseInt(tr.dataset.rowId, 10) || 0,
        rawId:   parseInt(tr.dataset.rawId, 10) || 0,
        qty:     parseFloat(tr.querySelector('.sf-inp-qty').value) || 0,
        section: parseInt(tr.querySelector('.sf-inp-sec').value, 10) || 0,
        desc:    tr.querySelector('.sf-inp-desc').value.trim(),
        time:    parseInt(tr.querySelector('.sf-inp-time').value, 10) || 0,
        alts:    alts,
      });
    });
    return steps;
  }

  // ── Дерево материалов ─────────────────────────────────────────────────────
  var treeSections   = {};
  var treeExpanded   = {};
  var treeActiveSec  = 0;
  var treeSelectedId   = 0;
  var treeSelectedName = '';
  var treeSelectedMId  = 0;
  var treeSelectedMSym = '';
  var treeTargetRow  = null;  // <tr> основного этапа
  var treeTargetAlt  = null;  // <div> альтернативы (null = основной материал)
  var treeSearchTimer = null;

  function openTree(tr, isAlt) {
    treeTargetRow  = tr;
    treeTargetAlt  = null;
    treeSelectedId = 0;
    treeSelectedName = '';
    treeSelectedMId  = 0;
    treeSelectedMSym = '';
    treeExpanded   = { 0: true };
    treeActiveSec  = 0;
    elTreeTitle.textContent = isAlt ? 'Выбор материала (альтернатива)' : 'Выбор материала';
    elTreeSearch.value = '';
    elTreeRight.innerHTML = '<div class="sf-loader">Выберите раздел слева</div>';
    loadTreeSections(0, function () { renderTreeLeft(); loadTreeItems(0); });
    elTreeOverlay.style.display = '';

    // Если isAlt — после выбора добавляем альтернативу а не меняем основной
    elTreeSelect._isAlt = isAlt;
  }

  function openTreeForAlt(div) {
    treeTargetRow  = null;
    treeTargetAlt  = div;
    treeSelectedId = 0;
    treeSelectedName = '';
    treeSelectedMId  = 0;
    treeSelectedMSym = '';
    treeExpanded   = { 0: true };
    treeActiveSec  = 0;
    elTreeTitle.textContent = 'Выбор материала (альтернатива)';
    elTreeSearch.value = '';
    elTreeRight.innerHTML = '<div class="sf-loader">Выберите раздел слева</div>';
    loadTreeSections(0, function () { renderTreeLeft(); loadTreeItems(0); });
    elTreeOverlay.style.display = '';
    elTreeSelect._isAlt = false;
  }

  function closeTree() {
    elTreeOverlay.style.display = 'none';
    treeTargetRow = null;
    treeTargetAlt = null;
  }

  elTreeClose.addEventListener('click', closeTree);
  elTreeCancel.addEventListener('click', closeTree);
  elTreeOverlay.addEventListener('click', function (e) {
    if (e.target === elTreeOverlay) closeTree();
  });

  elTreeSelect.addEventListener('click', function () {
    if (!treeSelectedId) { showToast('Выберите материал', true); return; }

    if (treeTargetAlt) {
      // Меняем материал в существующей альтернативе
      var btn = treeTargetAlt.querySelector('.sf-alt-mat-btn');
      btn.textContent = treeSelectedName;
      btn.classList.add('has-value');
      treeTargetAlt.dataset.rawId     = treeSelectedId;
      treeTargetAlt.dataset.measureId = treeSelectedMId;
      treeTargetAlt.querySelector('.sf-unit-sm').textContent = treeSelectedMSym || '—';
      closeTree();
      return;
    }

    if (!treeTargetRow) { closeTree(); return; }

    if (elTreeSelect._isAlt) {
      // Добавляем новую альтернативу в строку
      var altsContainer = treeTargetRow.querySelector('.sf-alts');
      addAltRow(altsContainer, {
        rowId:      0,
        rawId:      treeSelectedId,
        mat:        treeSelectedName,
        measureId:  treeSelectedMId,
        measureSym: treeSelectedMSym,
        qty:        0,
      });
    } else {
      // Обновляем основной материал строки
      var btn = treeTargetRow.querySelector('.sf-mat-btn');
      btn.textContent = treeSelectedName;
      btn.classList.add('has-value');
      treeTargetRow.dataset.rawId     = treeSelectedId;
      treeTargetRow.dataset.measureId = treeSelectedMId;
      var unitLabel = treeTargetRow.querySelector('.sf-unit-label');
      unitLabel.textContent = treeSelectedMSym || '—';
      unitLabel.dataset.measureId = treeSelectedMId;
    }

    closeTree();
  });

  // Поиск в дереве
  elTreeSearch.addEventListener('input', function () {
    clearTimeout(treeSearchTimer);
    var q = this.value.trim();
    treeSearchTimer = setTimeout(function () {
      if (q.length < 2) {
        renderTreeLeft();
        loadTreeItems(treeActiveSec);
        return;
      }
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
    var secs = treeSections[parentId] || [];
    secs.forEach(function (sec) {
      var isActive   = treeActiveSec === sec.id;
      var isExpanded = !!treeExpanded[sec.id];

      var node = document.createElement('div');
      node.className = 'sf-tree-node' + (isActive ? ' is-active' : '');
      node.style.paddingLeft = (10 + depth * 14) + 'px';
      node.dataset.secId = sec.id;

      var toggle = sec.hasChildren
        ? '<span class="sf-tree-toggle">' + (isExpanded ? '▾' : '▸') + '</span>'
        : '<span class="sf-tree-toggle"> </span>';
      node.innerHTML = toggle + '<span>' + esc(sec.name) + '</span>';
      container.appendChild(node);

      if (isExpanded && treeSections[sec.id]) {
        renderTreeLevel(sec.id, depth + 1, container);
      }

      node.addEventListener('click', function () {
        treeActiveSec = sec.id;
        if (sec.hasChildren) {
          if (treeExpanded[sec.id]) {
            delete treeExpanded[sec.id];
          } else {
            treeExpanded[sec.id] = true;
            loadTreeSections(sec.id, function () { renderTreeLeft(); });
          }
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
    if (items.length === 0) {
      elTreeRight.innerHTML = '<div class="sf-tree-empty">Нет товаров в этом разделе</div>';
      return;
    }
    items.forEach(function (item) {
      var el = document.createElement('div');
      el.className = 'sf-tree-item' + (treeSelectedId === item.id ? ' is-selected' : '');
      el.dataset.id         = item.id;
      el.dataset.measureId  = item.measureId;
      el.dataset.measureSym = item.measureSym;

      el.innerHTML = '<span class="sf-tree-item-name">' + esc(item.name) + '</span>'
        + '<span class="sf-tree-item-unit">' + esc(item.measureSym || '') + '</span>';

      el.addEventListener('click', function () {
        treeSelectedId   = item.id;
        treeSelectedName = item.name;
        treeSelectedMId  = item.measureId;
        treeSelectedMSym = item.measureSym;
        elTreeRight.querySelectorAll('.sf-tree-item').forEach(function (el) {
          el.classList.toggle('is-selected', parseInt(el.dataset.id, 10) === treeSelectedId);
        });
      });
      el.addEventListener('dblclick', function () {
        treeSelectedId   = item.id;
        treeSelectedName = item.name;
        treeSelectedMId  = item.measureId;
        treeSelectedMSym = item.measureSym;
        elTreeSelect.click();
      });
      elTreeRight.appendChild(el);
    });
  }

  // ── Старт ─────────────────────────────────────────────────────────────────
  document.body.classList.add('sf-ready');
  renderCatalog();

  var qs  = new URLSearchParams(window.location.search);
  var hid = parseInt(qs.get('headId'), 10) || 0;
  if (hid > 0) {
    var found = catalog.find(function (c) { return c.headId === hid; });
    if (found) selectHead(found);
  }

})();