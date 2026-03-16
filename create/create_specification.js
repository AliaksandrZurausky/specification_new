(function () {
  'use strict';

  const SLIDER_URL = '/local/specifications/create/create_form.php';
  const BTN_ID = 'spec-create-slider-btn';

  const TOOLBAR_BUTTONS_SELECTOR = '#uiToolbarContainer .ui-toolbar-after-title-buttons';

  // Hide the native "Создать" button in this toolbar block.
  // We target the Smart Process create link (/crm/type/1142/details/0/...) to avoid affecting other buttons.
  const HIDE_NATIVE_BTN_CSS = `${TOOLBAR_BUTTONS_SELECTOR} > a.ui-btn[href^="/crm/type/1142/details/0/"]{display:none!important;}`;

  function injectStyleOnce() {
    if (document.getElementById('spec-hide-native-create-btn-style')) return;

    const style = document.createElement('style');
    style.id = 'spec-hide-native-create-btn-style';
    style.type = 'text/css';
    style.appendChild(document.createTextNode(HIDE_NATIVE_BTN_CSS));

    document.head.appendChild(style);
  }

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
    const toolbarButtons = document.querySelector(TOOLBAR_BUTTONS_SELECTOR);
    if (!toolbarButtons) return;

    injectStyleOnce();

    // If button already exists — ensure it's placed inside toolbarButtons.
    const existing = document.getElementById(BTN_ID);
    if (existing) {
      existing.style.marginLeft = '';
      if (!toolbarButtons.contains(existing)) {
        toolbarButtons.appendChild(existing);
      }
      return;
    }

    const btn = document.createElement('a');
    btn.id = BTN_ID;
    btn.href = SLIDER_URL;

    // Same as the native green "Создать"
    btn.className = 'ui-btn ui-btn-success --air ui-btn-no-caps --style-filled-success ui-icon-set__scope --with-left-icon ui-btn-icon-add-m';
    btn.innerHTML = '<span class="ui-btn-text"><span class="ui-btn-text-inner">Создать</span></span>';

    btn.addEventListener('click', openSlider);

    toolbarButtons.appendChild(btn);
  }

  function init() {
    ensureButton();

    // Safety net for dynamic toolbar re-render.
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
