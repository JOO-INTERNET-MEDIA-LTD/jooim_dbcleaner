{* Keep one Joobox attribution even when another module renders later or uses different markers. *}
<div class="jooim-joobox-attribution jooim-dbcleaner-attribution" data-jooim-attribution="jooim_dbcleaner" style="font-size:12px;line-height:1.4;text-align:center;margin:10px 0;opacity:.75;">
  <span>{l s='Prestashop modul od' mod='jooim_dbcleaner'}</span>
  <a href="{$joobox_home_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" style="text-decoration:none;">joobox.eu</a>
</div>
<script>
(function(){
  var current = document.currentScript && document.currentScript.previousElementSibling;
  if (!current || !current.getAttribute || current.getAttribute('data-jooim-attribution') !== 'jooim_dbcleaner') return;

  function reconcile() {
    var footer = current.closest('footer, #footer, .footer-container') || current.parentNode;
    if (!footer) return;
    var existing = footer.querySelectorAll('a[href]');
    for (var i = 0; i < existing.length; i++) {
      if (current.contains(existing[i])) continue;
      var host;
      try { host = new URL(existing[i].href, document.baseURI).hostname.toLowerCase(); }
      catch (e) { continue; }
      if (host === 'joobox.eu' || host === 'www.joobox.eu') {
        // Keep the first cleaner block if several copies of this template render.
        var owner = existing[i].closest('[data-jooim-attribution="jooim_dbcleaner"]');
        if (owner && !(owner.compareDocumentPosition(current) & 4)) continue;
        if (current.parentNode) current.parentNode.removeChild(current);
        if (observer) observer.disconnect();
        return;
      }
    }
  }
  var observer = null;
  reconcile();
  if (!current.parentNode) return;
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', reconcile, { once: true });
  }
  if (window.MutationObserver) {
    observer = new MutationObserver(reconcile);
    observer.observe(current.closest('footer, #footer, .footer-container') || current.parentNode,
      { childList: true, subtree: true, attributes: true, attributeFilter: ['href'] });
  }
})();
</script>
