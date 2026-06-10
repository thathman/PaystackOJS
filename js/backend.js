/*
 * Paystack backend DOM enhancer
 * Adds plugin-specific classes for plugin modal panels only.
 */
(function () {
  function markPaystackAreas() {
    document.querySelectorAll('.pkp_form').forEach(function (panel) {
      var text = (panel.textContent || '').toLowerCase();
      if (text.indexOf('paystack') !== -1 || text.indexOf('settlement') !== -1 || text.indexOf('webhook') !== -1) {
        panel.classList.add('psx-panel');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', markPaystackAreas);
  } else {
    markPaystackAreas();
  }

  var observer = new MutationObserver(function () {
    markPaystackAreas();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
})();
