(function () {
  'use strict';

  var D        = SF_DATA;
  var catalog  = D.catalog;   // [{headId, title, productId, productName, folder, stepsCount, active}]
  var units    = D.units;     // [{id, name}]
  var sections = D.sections;  // [{id, name}] — участки из инфоблока 26

  var currentHead = null; // текущий выбранный head
  var rowIdx = 0;         // счётчик строк для уникальных id

  // ── DOM ──────────────────────────────────────────────────────────────────────
  var elCatalog  = document.getElementById('sf-catalog');
  var elSearch   = document.getElementById('sf-search');
  var elTitle    = document.getElementById('sf-title');
  var elTbody    = document.getElementById('sf-tbody');
  var elTimeBadge = document.getElementById('sf-time-badge');
  var elTimeTotal = document.getElementById('sf-time-total');
  var elAddRow   = document.getElementById('sf-add-row');
  var elFooter   = document.getElementById('sf-footer');
  var elSave     = document.getElementById('sf-save');
  var elReset    = document.getElementById('sf-reset');

  // Дерево материалов
  var elTreeOverlay = document.getElementById('sf-tree-overlay');
  var elTreeLeft    = document.getElementById('sf-tree-left');
  var elTreeRight   = document.getElementById('sf-tree-right');
  var elTreeSearch  = document.getElementById('sf-tree-search');
  var elTreeClose   = document.getElementById('sf-tree-close');
  var elTreeCancel  = document.getElementById('sf-tree-cancel');
  var elTreeSelect  = document.getElementById('sf-tree-select');

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
    Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
    return fetch(url, { method: 'POST', body: body }).then(function (r) { return r.json(); });
  }

  // ── Каталог (левая панель) ────────────────────────────────────────────────
  function renderCatalog(filter) {
    filter = (filter || '').toLowerCase();

    var filtered = filter
      ? catalog.filter(function (c) {
          return c.productName.toLowerCase().indexOf(filter) >= 0 ||
                 c.title.toLowerCase().indexOf(filter) >= 0;
        })
      : catalog;

    // Группируем по folder
    var folders = {};
    filtered.forEach(function (c) {
      if (!folders[c.folder]) folders[c.folder] = [];
      folders[c.folder].push(c);
    });

    var html = '';

    if (filtered.length === 0) {
      html = '<div class="sf-tree-empty">Ничего не найдено</div>';
    } else {
      Object.keys(folders).sort().forEach(function (f) {
        html += '<div class="sf-folder">' + esc(f) + '</div>';
        folders[f].forEach(function (c) {
          var isActive = currentHead && currentHead.headId === c.headId ? ' is-active' : '';
          html += '<div class="sf-cat-item' + isActive + '" data-head-id="' + c.headId + '">'
            + '<div class="sf-cat-item-name">' + esc(c.productName) + '</div>'
            + '<div class="sf-cat-item-meta">'
            +   '<span>' + c.stepsCount + ' этапов</span>'
            +   '<span class="' + (c.active ? 'sf-badge-active' : 'sf-badge-inactive') + '">'
            +     (c.active ? '● Активна' : '● Неактивна')
            +   '</span>'
            + '</div>'
            + '</div>';
        });
      });
    }

    elCatalog.innerHTML = html;
  }

  elCatalog.addEventListener('click', function (e) {
    var item = e.target.closest('.sf-cat-item');
    if (!item) return;
    var headId = parseInt(item.dataset.headId, 10);
    var found  = catalog.find(function (c) { return c.headId === headId; });
    if (found) selectHead(found);
  });

  elSearch.addEventListener('input', function () {
    renderCatalog(this.value);
  });

  // ── Выбор спецификации ────────────────────────────────────────────────────
  function selectHead(head) {
    currentHead = head;
    renderCatalog(elSearch.value);

    elTitle.textContent = head.productName;
    elTimeBadge.style.display = '';
    elAddRow.style.display    = '';
    elFooter.style.display    = '';

    // Загружаем строки из смарта
    elTbody.innerHTML = '<tr><td colspan="7" class="sf-loader">Загрузка...</td></tr>';

    // Получаем данные head из PHP напрямую (они уже в catalog — только stepsCount)
    // Для строк нужен отдельный запрос. Делаем через save_specification с пустыми steps
    // чтобы не ломать логику — грузим через специальный get-эндпоинт.
    // Используем get_specification.php если он есть, иначе рендерим пустую форму.
    ajaxPost('/local/specifications/api/get_specification.php', {
      headId: head.headId
    }).then(function (data) {
      elTbody.innerHTML = '';
      if (!data.ok) {
        showToast('Ошибка загрузки: ' + data.error, true);
        return;
      }
      (data.steps || []).forEach(function (s) { addRow(s); });
      calcTime();
    }).catch(function () {
      // Эндпоинт ещё не создан — просто рендерим пустой редактор
      elTbody.innerHTML = '';
      calcTime();
    });
  }

  // ── Строки таблицы ────────────────────────────────────────────────────────
  function addRow(data) {
    data = data || {};
    var idx     = rowIdx++;
    var rowId   = data.rowId  || 0;
    var rawId   = data.rawId  || 0;
    var matName = data.mat    || '';

    var tr = document.createElement('tr');
    tr.dataset.rowId = rowId;
    tr.dataset.rawId = rawId;
    tr.dataset.idx   = idx;

    // Единицы
    var unitOpts = units.map(function (u) {
      var sel = (data.unit && String(data.unit) === String(u.id)) ? ' selected' : '';
      return '<option value="' + esc(u.id) + '"' + sel + '>' + esc(u.name) + '</option>';
    }).join('');

    // Участки
    var secOpts = '<option value="">— участок —</option>'
      + sections.map(function (s) {
          var sel = (data.section && parseInt(data.section) === s.id) ? ' selected' : '';
          return '<option value="' + s.id + '"' + sel + '>' + esc(s.name) + '</option>';
        }).join('');

    tr.innerHTML = [
      '<td>',
        '<button class="sf-mat-btn' + (matName ? ' has-value' : '') + '" data-idx="' + idx + '">',
          esc(matName || '+ Выбрать материал'),
        '</button>',
        '<div class="sf-alts" data-idx="' + idx + '"></div>',
        '<button class="sf-alt-add" data-idx="' + idx + '">+ альтернатива</button>',
      '</td>',
      '<td><input type="number" class="sf-inp sf-inp-qty" value="' + (data.qty || 1) + '" step="0.001" min="0"></td>',
      '<td><select class="sf-inp sf-inp-unit">' + unitOpts + '</select></td>',
      '<td><select class="sf-inp sf-inp-sec">' + secOpts + '</select></td>',
      '<td><textarea class="sf-inp sf-inp-desc" rows="2" placeholder="Описание операции...">' + esc(data.desc || '') + '</textarea></td>',
      '<td><input type="number" class="sf-inp sf-inp-time sf-inp-time" value="' + (data.time || 0) + '" min="0"></td>',
      '<td><button class="sf-btn-icon sf-row-del" title="Удалить строку">✕</button></td>',
    ].join('');

    elTbody.appendChild(tr);

    // Навешиваем обработчики
    tr.querySelector('.sf-mat-btn').addEventListener('click', function () {
      openTree(tr);
    });

    tr.querySelector('.sf-alt-add').addEventListener('click', function () {
      addAlt(tr.querySelector('.sf-alts'));
    });

    tr.querySelector('.sf-row-del').addEventListener('click', function () {
      tr.remove();
      calcTime();
    });

    tr.querySelector('.sf-inp-time').addEventListener('input', calcTime);

    // Заполняем альтернативы
    if (Array.isArray(data.alts)) {
      var altsEl = tr.querySelector('.sf-alts');
      data.alts.forEach(function (a) { addAlt(altsEl, a); });
    }
  }

  elAddRow.addEventListener('click', function () { addRow(); });

  // ── Альтернативы ──────────────────────────────────────────────────────────
  function addAlt(container, data) {
    data = data || {};
    var div = document.createElement('div');
    div.className = 'sf-alt-row';
    div.innerHTML = [
      '<span class="sf-alt-prio"></span>',
      '<input type="text" class="sf-inp sf-alt-name" placeholder="Материал замены" value="' + esc(data.name || '') + '">',
      '<input type="number" class="sf-inp sf-alt-qty" placeholder="Кол" value="' + (data.qty || 1) + '" min="0" step="0.001">',
      '<button class="sf-alt-del" title="Удалить">✕</button>',
    ].join('');
    container.appendChild(div);
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
      badge.className   = 'sf-alt-prio sf-prio-' + (n <= 3 ? n : 'n');
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

  // ── Сброс ─────────────────────────────────────────────────────────────────
  elReset.addEventListener('click', function () {
    if (currentHead) selectHead(currentHead);
  });

  // ── Сохранение ────────────────────────────────────────────────────────────
  elSave.addEventListener('click', function () {
    if (!currentHead) { showToast('Выберите спецификацию', true); return; }

    var steps = collectSteps();

    elSave.disabled = true;
    elSave.textContent = 'Сохранение...';

    ajaxPost(D.urls.save, {
      headId:  currentHead.headId,
      title:   currentHead.title,
      specId:  currentHead.productId,
      steps:   JSON.stringify(steps),
    }).then(function (data) {
      if (!data.ok) {
        showToast('Ошибка: ' + (data.errors ? data.errors[0] : data.error), true);
        return;
      }
      // Обновляем счётчик в каталоге
      var cat = catalog.find(function (c) { return c.headId === currentHead.headId; });
      if (cat) cat.stepsCount = data.rowIds.length;
      renderCatalog(elSearch.value);
      showToast('Спецификация сохранена ✓');
    }).catch(function (e) {
      showToast('Ошибка AJAX', true);
    }).finally(function () {
      elSave.disabled = false;
      elSave.textContent = 'Сохранить изменения';
    });
  });

  function collectSteps() {
    var steps = [];
    elTbody.querySelectorAll('tr').forEach(function (tr) {
      if (!tr.dataset) return;
      var alts = [];
      tr.querySelectorAll('.sf-alt-row').forEach(function (row) {
        alts.push({
          name: row.querySelector('.sf-alt-name').value,
          qty:  parseFloat(row.querySelector('.sf-alt-qty').value) || 0,
        });
      });
      steps.push({
        rowId:   parseInt(tr.dataset.rowId, 10) || 0,
        rawId:   parseInt(tr.dataset.rawId, 10) || 0,
        qty:     parseFloat(tr.querySelector('.sf-inp-qty').value) || 0,
        unit:    tr.querySelector('.sf-inp-unit').value,
        section: parseInt(tr.querySelector('.sf-inp-sec').value, 10) || 0,
        desc:    tr.querySelector('.sf-inp-desc').value.trim(),
        time:    parseInt(tr.querySelector('.sf-inp-time').value, 10) || 0,
        alts:    alts,
      });
    });
    return steps;
  }

  // ── Дерево материалов ─────────────────────────────────────────────────────
  var treeSections    = {};  // sectionId -> [{id, name, hasChildren}]
  var treeExpanded    = {};  // sectionId -> bool
  var treeActiveSec   = -1;
  var treeSelectedId  = 0;
  var treeSelectedName= '';
  var treeTargetRow   = null;
  var treeSearchTimer = null;

  function openTree(tr) {
    treeTargetRow    = tr;
    treeSelectedId   = 0;
    treeSelectedName = '';
    treeExpanded     = { 0: true };
    treeActiveSec    = 0;
    elTreeSearch.value = '';
    elTreeRight.innerHTML = '<div class="sf-loader">Выберите раздел слева</div>';
    loadTreeSections(0, function () {
      renderTreeLeft();
      loadTreeItems(0);
    });
    elTreeOverlay.style.display = '';
  }

  function closeTree() {
    elTreeOverlay.style.display = 'none';
    treeTargetRow = null;
  }

  elTreeClose.addEventListener('click', closeTree);
  elTreeCancel.addEventListener('click', closeTree);
  elTreeOverlay.addEventListener('click', function (e) {
    if (e.target === elTreeOverlay) closeTree();
  });

  elTreeSelect.addEventListener('click', function () {
    if (!treeSelectedId) { showToast('Выберите материал', true); return; }
    if (!treeTargetRow) { closeTree(); return; }

    var btn = treeTargetRow.querySelector('.sf-mat-btn');
    btn.textContent = treeSelectedName;
    btn.classList.add('has-value');
    treeTargetRow.dataset.rawId = treeSelectedId;

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

  // Загрузить разделы уровня
  function loadTreeSections(parentId, cb) {
    if (treeSections[parentId] !== undefined) { if (cb) cb(); return; }
    ajaxPost(D.urls.materials, { action: 'sections', sectionId: parentId }).then(function (data) {
      treeSections[parentId] = data.sections || [];
      if (cb) cb();
    });
  }

  // Рендер левой панели
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
        : '<span class="sf-tree-toggle"></span>';

      node.innerHTML = toggle + '<span>' + esc(sec.name) + '</span>';
      container.appendChild(node);

      // Дочерние разделы (если развёрнут)
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

  // Загрузить товары раздела
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
      el.dataset.id = item.id;
      el.innerHTML = '<span class="sf-tree-item-name">' + esc(item.name) + '</span>';
      el.addEventListener('click', function () {
        treeSelectedId   = item.id;
        treeSelectedName = item.name;
        elTreeRight.querySelectorAll('.sf-tree-item').forEach(function (el) {
          el.classList.toggle('is-selected', parseInt(el.dataset.id, 10) === treeSelectedId);
        });
      });
      el.addEventListener('dblclick', function () {
        treeSelectedId   = item.id;
        treeSelectedName = item.name;
        elTreeSelect.click();
      });
      elTreeRight.appendChild(el);
    });
  }

  // ── Утилиты ───────────────────────────────────────────────────────────────
  function esc(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  // ── Старт ─────────────────────────────────────────────────────────────────
  document.body.classList.add('sf-ready');
  renderCatalog();

  // Если открыт через слайдер с ?headId= — сразу выбираем нужный
  var qs  = new URLSearchParams(window.location.search);
  var hid = parseInt(qs.get('headId'), 10) || 0;
  if (hid > 0) {
    var found = catalog.find(function (c) { return c.headId === hid; });
    if (found) selectHead(found);
  }

})();