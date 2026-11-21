(function () {
  'use strict';
  var root = document.getElementById('mobo-core-admin');
  if (!root) return;

  function submitCategorySearch() {
    var input = root.querySelector('[data-mobo-category-global-search]');
    if (!input) return;
    var url = new URL(window.location.href);
    var value = String(input.value || '').trim();
    if (value) url.searchParams.set('mobo_cat_search', value);
    else url.searchParams.delete('mobo_cat_search');
    url.searchParams.set('mobo_cat_page', '1');
    window.location.assign(url.toString());
  }

  var categorySearch = root.querySelector('[data-mobo-category-global-search]');
  var categorySearchButton = root.querySelector('[data-mobo-category-search-submit]');
  if (categorySearchButton) categorySearchButton.addEventListener('click', submitCategorySearch);
  if (categorySearch) {
    categorySearch.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitCategorySearch();
      }
    });
  }

  function syncCategoryPolicyControls() {
    var mappingEnabled = root.querySelector('input[name="mobo_core_category_mapping_enabled"]:checked');
    var requiredInputs = root.querySelectorAll('input[name="mobo_core_category_mapping_required"]');
    var requiredOn = root.querySelector('input[name="mobo_core_category_mapping_required"][value="1"]');
    var requiredOff = root.querySelector('input[name="mobo_core_category_mapping_required"][value="0"]');
    var fallbackHelp = root.querySelector('[data-mobo-category-fallback-help]');
    var policyNote = root.querySelector('[data-mobo-category-policy-note]');
    if (!mappingEnabled || !requiredInputs.length) return;

    var enabled = String(mappingEnabled.value) === '1';
    if (!enabled && requiredOff) requiredOff.checked = true;
    requiredInputs.forEach(function (input) { input.disabled = !enabled; });
    var required = enabled && requiredOn && requiredOn.checked;
    if (fallbackHelp) {
      fallbackHelp.textContent = required
        ? 'در حالت نگاشت اجباری، این دسته جای نگاشت گمشده را نمی‌گیرد.'
        : 'اگر سیستم برای یک محصول جدید نتیجه معتبر دیگری پیدا نکند، این دسته می‌تواند به‌عنوان آخرین مسیر جایگزین استفاده شود.';
    }
    if (policyNote) {
      policyNote.textContent = required
        ? 'در حالت نگاشت اجباری، دسته جایگزین جای نگاشت گمشده را نمی‌گیرد.'
        : 'دسته جایگزین فقط زمانی استفاده می‌شود که runtime نتواند دسته معتبری پیدا کند؛ محصول موجود بدون نتیجه معتبر بی‌دلیل جابه‌جا نمی‌شود.';
    }
  }

  root.querySelectorAll('input[name="mobo_core_category_mapping_enabled"], input[name="mobo_core_category_mapping_required"]').forEach(function (input) {
    input.addEventListener('change', syncCategoryPolicyControls);
  });
  syncCategoryPolicyControls();

  root.querySelectorAll('.mobo-ux-secondary-details').forEach(function (details) {
    details.addEventListener('toggle', function () {
      if (details.open) {
        window.setTimeout(function () { window.dispatchEvent(new Event('resize')); }, 0);
      }
    });
  });
})();
