(function () {
  'use strict';

  var root = document.getElementById('mobo-core-admin');
  if (!root) return;

  var SELECTOR = '.mobo-search-select, .mobo-category-select2, .mobo-address-select2, .mobo-shipping-select2';
  var activeSelect = null;
  var activeTrigger = null;
  var popover = null;
  var searchInput = null;
  var list = null;
  var resizeTimer = null;

  function isMobile() {
    return !!(window.matchMedia && window.matchMedia('(max-width: 782px)').matches);
  }

  function normalize(value) {
    return String(value || '')
      .replace(/[يى]/g, 'ی')
      .replace(/ك/g, 'ک')
      .replace(/[\u064B-\u065F\u0670]/g, '')
      .replace(/\u200c/g, ' ')
      .toLowerCase()
      .trim();
  }

  function isEligible(select) {
    if (!select || select.tagName !== 'SELECT' || select.multiple || select.disabled || isMobile()) {
      return false;
    }

    if (select.matches('.mobo-address-select2, .mobo-shipping-select2, [data-mobo-category-map-select], .mobo-search-select')) {
      return true;
    }

    return select.options && select.options.length > 8;
  }

  function getSelectedText(select) {
    if (!select || !select.options || select.selectedIndex < 0) return '';
    return select.options[select.selectedIndex].textContent.trim();
  }

  function ensurePopover() {
    if (popover) return;

    popover = document.createElement('div');
    popover.className = 'mobo-picker-popover';
    popover.hidden = true;
    popover.setAttribute('role', 'dialog');
    popover.setAttribute('aria-label', 'انتخاب از فهرست');
    popover.innerHTML =
      '<div class="mobo-picker-search-wrap">' +
        '<span class="dashicons dashicons-search" aria-hidden="true"></span>' +
        '<input type="search" class="mobo-picker-search" autocomplete="off" placeholder="جستجو..." aria-label="جستجو در گزینه‌ها">' +
      '</div>' +
      '<div class="mobo-picker-list" role="listbox"></div>' +
      '<div class="mobo-picker-empty" hidden>گزینه‌ای پیدا نشد.</div>';

    root.appendChild(popover);
    searchInput = popover.querySelector('.mobo-picker-search');
    list = popover.querySelector('.mobo-picker-list');

    searchInput.addEventListener('input', renderOptions);
    searchInput.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        event.preventDefault();
        closePicker(true);
      } else if (event.key === 'ArrowDown') {
        event.preventDefault();
        var first = list.querySelector('.mobo-picker-option:not([hidden])');
        if (first) first.focus();
      }
    });

    list.addEventListener('click', function (event) {
      var optionButton = event.target.closest('.mobo-picker-option');
      if (!optionButton || !activeSelect) return;
      event.preventDefault();
      selectValue(optionButton.getAttribute('data-value'));
    });

    list.addEventListener('keydown', function (event) {
      var current = event.target.closest('.mobo-picker-option');
      if (!current) return;

      if (event.key === 'Escape') {
        event.preventDefault();
        closePicker(true);
        return;
      }
      if (event.key === 'Enter' || event.key === ' ') {
        event.preventDefault();
        selectValue(current.getAttribute('data-value'));
        return;
      }
      if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;

      event.preventDefault();
      var buttons = Array.prototype.slice.call(list.querySelectorAll('.mobo-picker-option:not([hidden])'));
      var index = buttons.indexOf(current);
      if (index < 0 || !buttons.length) return;
      var next = event.key === 'ArrowDown' ? index + 1 : index - 1;
      if (next < 0) {
        searchInput.focus();
        return;
      }
      if (next >= buttons.length) next = buttons.length - 1;
      buttons[next].focus();
    });
  }

  function positionPopover() {
    if (!popover || popover.hidden || !activeTrigger) return;

    var rect = activeTrigger.getBoundingClientRect();
    var viewportWidth = Math.max(document.documentElement.clientWidth || 0, window.innerWidth || 0);
    var viewportHeight = Math.max(document.documentElement.clientHeight || 0, window.innerHeight || 0);
    var width = Math.max(260, Math.min(Math.max(rect.width, 320), viewportWidth - 24));
    var left = Math.max(12, Math.min(rect.left, viewportWidth - width - 12));
    var desiredHeight = Math.min(390, Math.max(220, viewportHeight * 0.52));
    var below = viewportHeight - rect.bottom - 12;
    var top;

    if (below >= Math.min(260, desiredHeight) || rect.top < below) {
      top = rect.bottom + 6;
    } else {
      top = Math.max(12, rect.top - desiredHeight - 6);
    }

    popover.style.width = width + 'px';
    popover.style.maxHeight = desiredHeight + 'px';
    popover.style.left = left + 'px';
    popover.style.top = top + 'px';
  }

  function renderOptions() {
    if (!activeSelect || !list) return;

    var query = normalize(searchInput.value);
    var selectedValue = String(activeSelect.value || '');
    var fragment = document.createDocumentFragment();
    var visible = 0;

    Array.prototype.forEach.call(activeSelect.options || [], function (option) {
      if (option.disabled) return;
      var text = option.textContent.trim();
      var haystack = normalize(text + ' ' + (option.getAttribute('data-term-slug') || '') + ' ' + option.value);
      if (query && haystack.indexOf(query) === -1) return;

      var button = document.createElement('button');
      button.type = 'button';
      button.className = 'mobo-picker-option';
      button.setAttribute('role', 'option');
      button.setAttribute('data-value', String(option.value));
      button.setAttribute('aria-selected', String(String(option.value) === selectedValue));
      if (String(option.value) === selectedValue) button.classList.add('is-selected');

      var textSpan = document.createElement('span');
      textSpan.className = 'mobo-picker-option__text';
      textSpan.textContent = text || 'بدون عنوان';
      button.appendChild(textSpan);

      if (String(option.value) === selectedValue) {
        var check = document.createElement('span');
        check.className = 'dashicons dashicons-yes-alt';
        check.setAttribute('aria-hidden', 'true');
        button.appendChild(check);
      }

      fragment.appendChild(button);
      visible++;
    });

    list.innerHTML = '';
    list.appendChild(fragment);
    popover.querySelector('.mobo-picker-empty').hidden = visible > 0;
  }

  function updateTrigger(select) {
    if (!select || !select._moboPickerTrigger) return;
    var trigger = select._moboPickerTrigger;
    var label = trigger.querySelector('.mobo-picker-trigger__label');
    if (label) label.textContent = getSelectedText(select) || select.getAttribute('data-placeholder') || 'انتخاب کنید';
    trigger.disabled = !!select.disabled;
    trigger.classList.toggle('is-placeholder', !select.value || String(select.value) === '0');
  }

  function openPicker(select) {
    if (!isEligible(select)) return;
    ensurePopover();
    activeSelect = select;
    activeTrigger = select._moboPickerTrigger;
    searchInput.value = '';
    renderOptions();
    popover.hidden = false;
    activeTrigger.setAttribute('aria-expanded', 'true');
    positionPopover();
    window.setTimeout(function () {
      searchInput.focus();
      searchInput.select();
    }, 0);
  }

  function closePicker(returnFocus) {
    if (!popover || popover.hidden) return;
    popover.hidden = true;
    if (activeTrigger) {
      activeTrigger.setAttribute('aria-expanded', 'false');
      if (returnFocus) activeTrigger.focus();
    }
    activeSelect = null;
    activeTrigger = null;
  }

  function selectValue(value) {
    if (!activeSelect) return;
    activeSelect.value = value;
    activeSelect.dispatchEvent(new Event('change', { bubbles: true }));
    updateTrigger(activeSelect);
    closePicker(true);
  }

  function enhance(select) {
    if (!select || select._moboPickerTrigger) return;
    if (!isEligible(select)) return;

    select.classList.add('mobo-picker-native');

    var trigger = document.createElement('button');
    trigger.type = 'button';
    trigger.className = 'mobo-picker-trigger';
    trigger.setAttribute('aria-haspopup', 'listbox');
    trigger.setAttribute('aria-expanded', 'false');
    trigger.innerHTML = '<span class="mobo-picker-trigger__label"></span><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>';
    select.insertAdjacentElement('afterend', trigger);
    select._moboPickerTrigger = trigger;

    trigger.addEventListener('click', function () {
      if (activeSelect === select && popover && !popover.hidden) {
        closePicker(false);
      } else {
        closePicker(false);
        openPicker(select);
      }
    });

    select.addEventListener('change', function () {
      updateTrigger(select);
      if (activeSelect === select) renderOptions();
    });

    updateTrigger(select);
  }

  function destroy(select) {
    if (!select || !select._moboPickerTrigger) return;
    if (activeSelect === select) closePicker(false);
    select._moboPickerTrigger.remove();
    select._moboPickerTrigger = null;
    select.classList.remove('mobo-picker-native');
  }

  function refresh(context) {
    var scope = context && context.nodeType === 1 ? context : root;
    var selects = [];

    if (scope.matches && scope.matches(SELECTOR)) selects.push(scope);
    if (scope.querySelectorAll) {
      selects = selects.concat(Array.prototype.slice.call(scope.querySelectorAll(SELECTOR)));
    }

    selects.forEach(function (select) {
      if (isEligible(select)) enhance(select);
      else destroy(select);
    });
  }

  document.addEventListener('click', function (event) {
    if (!popover || popover.hidden) return;
    if (popover.contains(event.target) || (activeTrigger && activeTrigger.contains(event.target))) return;
    closePicker(false);
  });

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape') closePicker(true);
  });

  window.addEventListener('scroll', function () {
    if (popover && !popover.hidden) positionPopover();
  }, true);

  window.addEventListener('resize', function () {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(function () {
      closePicker(false);
      root.querySelectorAll(SELECTOR).forEach(function (select) {
        if (isEligible(select)) enhance(select);
        else destroy(select);
      });
    }, 120);
  });

  if (window.MutationObserver) {
    var observer = new MutationObserver(function (mutations) {
      mutations.forEach(function (mutation) {
        Array.prototype.forEach.call(mutation.addedNodes || [], function (node) {
          if (node && node.nodeType === 1) refresh(node);
        });
      });
    });
    observer.observe(root, { childList: true, subtree: true });
  }

  window.MoboCorePicker = {
    refresh: refresh,
    close: function () { closePicker(false); }
  };

  refresh(root);
}());
