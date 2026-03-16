(function () {
  'use strict';

  const SLIDER_URL = '/local/specifications/edit/edit_form.php';
  const BTN_ID = 'spec-create-slider-btn';

  function openSlider(e) {
    e.preventDefault();
    e.stopPropagation();

    if (window.BX && BX.SidePanel && BX.SidePanel.Instance) {
      BX.SidePanel.Instance.open(SLIDER_URL, {
        width: 700,
        cacheable: false,
        allowChangeHistory: false,
        requestMethod: 'get',
        animationDuration: 200,
        loader: 'default',
        label: { text: 'Создать', bgColor: '#2fc566', opacity: 100 }
      });
    } else {
      window.location.href = SLIDER_URL;
    }
  }

  function ensureButton() {
    const filterBox = document.querySelector('#uiToolbarContainer .ui-toolbar-filter-box');
    if (!filterBox) return;

    // Если кнопка уже есть — проверим, что она именно внутри filterBox (а не где-то ещё)
    const existing = document.getElementById(BTN_ID);
    if (existing) {
      if (!filterBox.contains(existing)) {
        filterBox.appendChild(existing);
      }
      return;
    }

    const btn = document.createElement('a');
    btn.id = BTN_ID;
    btn.href = SLIDER_URL;

    // Как штатная зелёная "Создать"
    btn.className = 'ui-btn ui-btn-success --air ui-btn-no-caps --style-filled-success ui-icon-set__scope --with-left-icon ui-btn-icon-add-m';
    btn.innerHTML = '<span class="ui-btn-text"><span class="ui-btn-text-inner">Создать</span></span>';

    // Чтобы визуально было "рядом" (не прилипало к полю)
    btn.style.marginLeft = '10px';

    btn.addEventListener('click', openSlider);

    // Ключевое отличие: добавляем ВНУТРЬ блока фильтра
    filterBox.appendChild(btn);
  }

  function init() {
    ensureButton();

    // Страховка от динамических перерисовок тулбара/фильтра
    if (typeof MutationObserver !== 'undefined') {
      const mo = new MutationObserver(ensureButton);
      mo.observe(document.body, { childList: true, subtree: true });
    }
  }

  if (window.BX && typeof BX.ready === 'function') {
    BX.ready(init);
  } else if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
