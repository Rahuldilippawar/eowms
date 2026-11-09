// 🗂️ Smart Dropdown — गटवार (<optgroup>) यादीवर: आधी Head (प्रकार / Campus) निवडा → खाली फक्त त्या Head च्या वस्तू, आणि 🔍 शोध
// लागू: data-smart="1" असलेले <select>, किंवा 2+ गट आणि 20+ पर्याय असलेले कोणतेही <select> (सगळ्या Modules मध्ये आपोआप)
// मूळ <select> तसाच राहतो (Form, required, onchange सगळं तसंच) — फक्त वर एक छोटी पट्टी जोडली जाते.
(function () {
  'use strict';
  var T = function (s) { return window.eowmsT ? window.eowmsT(s) : s; };
  var MIN_OPTIONS = 20;
  var isPlaceholder = function (o) { var v = (o.value || '').trim(); return v === '' || v === '0' || /^(all|any|none)$/i.test(v); };

  function eligible(sel) {
    if (sel.multiple || sel.type === 'hidden' || sel.dataset.smart === '0' || sel.closest('[data-no-smart]')) return false;
    if (sel.dataset.smart === '1') return true;
    return sel.querySelectorAll('optgroup').length >= 2 && sel.options.length >= MIN_OPTIONS;
  }

  function enhance(sel) {
    if (sel._ss) { refreshGroups(sel); return; }
    if (!eligible(sel)) return;
    var bar = document.createElement('div');
    bar.className = 'sel-smart';
    var grp = document.createElement('select');
    grp.className = 'ss-grp';
    grp.setAttribute('aria-label', T('प्रकार / Head'));
    var q = document.createElement('input');
    q.type = 'search'; q.className = 'ss-q'; q.autocomplete = 'off';
    q.placeholder = '🔍 ' + T('शोधा') + '…';
    q.setAttribute('aria-label', T('शोधा'));
    var cnt = document.createElement('span');
    cnt.className = 'ss-cnt';
    bar.appendChild(grp); bar.appendChild(q); bar.appendChild(cnt);
    sel.parentNode.insertBefore(bar, sel);
    sel._ss = { bar: bar, grp: grp, q: q, cnt: cnt, sig: '' };
    sel.classList.add('ss-on');
    grp.addEventListener('change', function () {
      apply(sel);
      // Head बदलला आणि आधी निवडलेली वस्तू त्या Head मध्ये नाही → "-- निवडा --" (फक्त वापरकर्त्याने Head बदलल्यावर)
      var cur = sel.options[sel.selectedIndex];
      if (cur && !isPlaceholder(cur) && cur.hidden) {
        var ph = Array.prototype.find.call(sel.options, isPlaceholder);
        if (ph) { sel.value = ph.value; sel.dispatchEvent(new Event('change', { bubbles: true })); apply(sel); }
      }
    });
    q.addEventListener('input', function () { apply(sel); });
    q.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();   // Form submit होऊ नये — पहिला जुळणारा पर्याय निवडा
      var first = Array.prototype.find.call(sel.options, function (o) { return !o.hidden && !isPlaceholder(o); });
      if (first) { sel.value = first.value; sel.dispatchEvent(new Event('change', { bubbles: true })); sel.focus(); }
    });
    refreshGroups(sel);
  }

  // गटांची यादी (Options बदलले — उदा. Campus निवडल्यावर विभाग — तर पुन्हा)
  function refreshGroups(sel) {
    var s = sel._ss, groups = Array.prototype.slice.call(sel.querySelectorAll('optgroup'));
    var sig = groups.map(function (g) { return g.label + ':' + g.children.length; }).join('|') + '#' + sel.options.length;
    if (sig === s.sig) return;
    s.sig = sig;
    var keep = s.grp.value;
    s.grp.textContent = '';
    var all = document.createElement('option');
    all.value = ''; all.textContent = '📂 ' + T('सर्व') + ' (' + Array.prototype.filter.call(sel.options, function (o) { return !isPlaceholder(o); }).length + ')';
    s.grp.appendChild(all);
    groups.forEach(function (g) {
      var o = document.createElement('option');
      o.value = g.label; o.textContent = g.label + ' (' + g.children.length + ')';
      s.grp.appendChild(o);
    });
    s.grp.value = groups.some(function (g) { return g.label === keep; }) ? keep : '';
    s.grp.style.display = groups.length >= 2 ? '' : 'none';
    apply(sel);
  }

  function apply(sel) {
    var s = sel._ss, g = s.grp.value, text = s.q.value.trim().toLowerCase(), shown = 0, total = 0;
    Array.prototype.forEach.call(sel.options, function (o) {
      if (isPlaceholder(o)) { o.hidden = false; return; }
      total++;
      var og = o.parentNode.tagName === 'OPTGROUP' ? o.parentNode : null;
      var ok = (!g || (og && og.label === g)) && (!text || o.text.toLowerCase().indexOf(text) !== -1 || (og && og.label.toLowerCase().indexOf(text) !== -1));
      o.hidden = !ok;
      // iOS Safari "hidden" पर्याय दाखवतो — म्हणून न-जुळणारे Disable (निवडलेला पर्याय कधीच नाही, नाहीतर Form मध्ये Value जाणार नाही)
      o.disabled = !ok && !o.selected ? true : (o.dataset.ssWasDisabled === '1');
      if (ok) shown++;
    });
    Array.prototype.forEach.call(sel.querySelectorAll('optgroup'), function (og) {
      og.hidden = !Array.prototype.some.call(og.children, function (o) { return !o.hidden; });
    });
    s.cnt.textContent = (g || text) ? shown + ' / ' + total : '';
  }

  function scan(root) {
    Array.prototype.forEach.call((root || document).querySelectorAll('select'), function (sel) {
      // मूळ HTML मध्येच disabled असलेले पर्याय लक्षात ठेवा
      if (!sel._ss) { Array.prototype.forEach.call(sel.options, function (o) { if (o.disabled) o.dataset.ssWasDisabled = '1'; }); }
      enhance(sel);
    });
  }

  function start() {
    scan(document);
    var pending = null;
    new MutationObserver(function () { clearTimeout(pending); pending = setTimeout(function () { scan(document); }, 60); })
      .observe(document.body, { childList: true, subtree: true });
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start); else start();
  window.eowmsSmartSelects = scan;
})();
