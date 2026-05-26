{* Joobox attribution. If another JOOIM module already printed the Joobox footer attribution, this block removes itself. *}
<div class="jooim-joobox-attribution jooim-dbcleaner-attribution" data-jooim-attribution="jooim_dbcleaner" style="font-size:12px;line-height:1.4;text-align:center;margin:10px 0;opacity:.75;display:none;">
  <span>{l s='Prestashop modul od' mod='jooim_dbcleaner'}</span>
  <a href="{$joobox_home_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" style="text-decoration:none;">joobox.eu</a>
</div>
<script>
(function(){
  var current = document.currentScript && document.currentScript.previousElementSibling;
  if (!current || !current.getAttribute || current.getAttribute('data-jooim-attribution') !== 'jooim_dbcleaner') return;

  var existing = document.querySelectorAll('[data-jooim-attribution], .jooim-joobox-attribution');
  for (var i = 0; i < existing.length; i++) {
    if (existing[i] !== current && existing[i].querySelector && existing[i].querySelector('a[href*="joobox.eu"]')) {
      if (current.parentNode) current.parentNode.removeChild(current);
      return;
    }
  }

  current.style.display = 'block';
})();
</script>
