<script>
{literal}
/* Joobox attribution protocol 1.0. One manager per document, no tracking or storage. */
(function (w, d) {
  'use strict';
  if (w.JooboxAttribution) { w.JooboxAttribution.reconcile(); return; }
  var selector = '[data-jooim-attribution],.jooim-joobox-attribution,.jooim-module-attribution,.joobox-module-attribution,.joobox-module-credit,.jooim-heureka-attribution';
  var hiddenByUs = new WeakMap(), pending = false;
  function home(link) {
    try { var url = new URL(link.href, d.baseURI); return /^https?:$/.test(url.protocol) && /^(www\.)?joobox\.eu$/i.test(url.hostname) && url.port === '' && url.pathname === '/'; }
    catch (ignored) { return false; }
  }
  function visible(node, ownHidden) {
    for (var element = node; element && element.nodeType === 1; element = element.parentElement) {
      if (element === node && ownHidden) continue;
      if (element.hidden) return false;
      var style = w.getComputedStyle(element);
      if (style.display === 'none' || style.visibility === 'hidden' || style.visibility === 'collapse') return false;
    }
    return true;
  }
  function conceal(node) {
    if (hiddenByUs.has(node)) return;
    hiddenByUs.set(node, {hidden:node.hidden, display:node.style.display, aria:node.getAttribute('aria-hidden')});
    node.hidden = true; node.style.display = 'none'; node.setAttribute('aria-hidden', 'true');
  }
  function reveal(node) {
    var old = hiddenByUs.get(node); if (!old) return;
    hiddenByUs.delete(node); node.hidden = old.hidden; node.style.display = old.display;
    if (old.aria === null) node.removeAttribute('aria-hidden'); else node.setAttribute('aria-hidden', old.aria);
  }
  function reconcile() {
    var nodes = Array.prototype.slice.call(d.querySelectorAll(selector)).filter(function (node) {
      // Only dedicated attribution blocks are managed, never the consent container or footer itself.
      if (node.id === 'joo-consent' || node.matches('footer,#footer,.footer-container') || node.querySelector('button,input,form,dialog')) return false;
      return Array.prototype.some.call(node.querySelectorAll('a[href]'), home);
    });
    var manual = Array.prototype.some.call(d.querySelectorAll('footer a[href],#footer a[href],.footer-container a[href]'), function (link) {
      return !link.closest(selector) && home(link) && visible(link, false);
    });
    var keeper = null;
    nodes.forEach(function (node) {
      if (!hiddenByUs.has(node) && !visible(node, false)) return;
      if (!manual && !keeper && visible(node, hiddenByUs.has(node))) { keeper = node; reveal(node); }
      else conceal(node);
    });
  }
  function schedule() {
    if (pending) return; pending = true;
    (w.requestAnimationFrame || function (fn) { w.setTimeout(fn, 0); })(function () { pending = false; reconcile(); });
  }
  Object.defineProperty(w, 'JooboxAttribution', {value:Object.freeze({version:'1.0',reconcile:reconcile}),writable:false,configurable:false});
  if (w.MutationObserver) new w.MutationObserver(schedule).observe(d.documentElement, {childList:true,subtree:true,attributes:true,attributeFilter:['href','class','style','hidden']});
  d.addEventListener('DOMContentLoaded', reconcile, {once:true});
  w.addEventListener('pageshow', reconcile); w.addEventListener('resize', schedule);
  reconcile();
})(window, document);
{/literal}
</script>
