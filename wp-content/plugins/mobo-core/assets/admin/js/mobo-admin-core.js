(function () {
  'use strict';

  var root = document.getElementById('mobo-core-admin');
  if (!root) return;

  function toast(message) {
    var old = root.querySelector('.mobo-admin-toast');
    if (old) old.remove();
    var el = document.createElement('div');
    el.className = 'mobo-admin-toast';
    el.setAttribute('role', 'status');
    el.textContent = message;
    root.appendChild(el);
    window.setTimeout(function () { if (el.parentNode) el.remove(); }, 1800);
  }



  window.addEventListener('pageshow', function () {
    root.querySelectorAll('form[data-mobo-busy-submit] [aria-busy="true"]').forEach(function (button) {
      button.disabled = false;
      button.removeAttribute('aria-busy');
      button.classList.remove('is-busy');
      if (button.tagName === 'INPUT') {
        var originalValue = button.getAttribute('data-mobo-original-value');
        if (originalValue !== null) button.value = originalValue;
      } else {
        var originalLabel = button.getAttribute('data-mobo-original-label');
        if (originalLabel !== null) button.textContent = originalLabel;
      }
    });
  });

  root.addEventListener('submit', function (event) {
    var form = event.target && event.target.closest ? event.target.closest('form[data-mobo-busy-submit]') : null;
    if (!form || !root.contains(form) || event.defaultPrevented) return;

    window.setTimeout(function () {
      if (event.defaultPrevented) return;
      var buttons = form.querySelectorAll('button[type="submit"], input[type="submit"]');
      buttons.forEach(function (button) {
        if (button.disabled) return;
        button.disabled = true;
        button.setAttribute('aria-busy', 'true');
        button.classList.add('is-busy');
        if (button.tagName === 'INPUT') {
          if (!button.getAttribute('data-mobo-original-value')) {
            button.setAttribute('data-mobo-original-value', button.value || '');
          }
          button.value = button.getAttribute('data-mobo-busy-label') || 'در حال انجام…';
        } else {
          if (!button.getAttribute('data-mobo-original-label')) {
            button.setAttribute('data-mobo-original-label', button.textContent || '');
          }
          button.textContent = button.getAttribute('data-mobo-busy-label') || 'در حال انجام…';
        }
      });
    }, 0);
  });

  root.addEventListener('click', function (event) {
    var copy = event.target.closest('[data-mobo-copy]');
    if (copy && root.contains(copy)) {
      event.preventDefault();
      var holder = copy.closest('[data-mobo-copy-row]');
      var valueNode = holder ? holder.querySelector('[data-mobo-copy-value]') : null;
      var value = valueNode ? (valueNode.getAttribute('data-mobo-copy-value') || valueNode.textContent || '') : '';
      value = value.trim();
      if (!value) return;
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(value).then(function () { toast('کپی شد.'); });
      } else {
        var area = document.createElement('textarea');
        area.value = value;
        area.style.position = 'fixed';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.select();
        try { document.execCommand('copy'); toast('کپی شد.'); } catch (e) { /* no-op */ }
        area.remove();
      }
      return;
    }

    var toggle = event.target.closest('[data-mobo-secret-toggle]');
    if (toggle && root.contains(toggle)) {
      event.preventDefault();
      var row = toggle.closest('.mobo-admin-secret-row');
      var input = row ? row.querySelector('input') : null;
      if (!input) return;
      var reveal = input.type === 'password';
      input.type = reveal ? 'text' : 'password';
      toggle.setAttribute('aria-label', reveal ? 'پنهان کردن مقدار' : 'نمایش مقدار');
      var icon = toggle.querySelector('.dashicons');
      if (icon) {
        icon.classList.toggle('dashicons-visibility', !reveal);
        icon.classList.toggle('dashicons-hidden', reveal);
      }
    }
  });
}());
