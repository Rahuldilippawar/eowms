// 🏛️ विभाग-Dropdown गटवार — प्रत्येक विभाग त्याच्या संस्था / College / School खाली (<optgroup>)
// नकाशा: window.EOWMS_DEPT_GROUPS (footer.php → includes/dept-groups.php)
// फक्त असे <select> बदलतो: नाव/ID मध्ये dept/department आहे, आधीच गट नाहीत, आणि प्रत्येक पर्याय नकाशातला विभाग आहे
// (रिकामा / "सर्व" / 0 असे पर्याय वर तसेच राहतात). JS ने नंतर भरलेले Dropdown सुद्धा (MutationObserver).
(function () {
  'use strict';
  var MAP = window.EOWMS_DEPT_GROUPS;
  if (!MAP) return;
  var NAME_RE = /(^|[_\-\[])(dept|department)s?(_|\[|$|id)/i;
  var isDeptSelect = function (s) {
    var n = (s.getAttribute('name') || '') + ' ' + (s.id || '');
    return NAME_RE.test(n) || /dept|department/i.test(s.getAttribute('data-dept-select') || '');
  };
  var isPlaceholder = function (o) { var v = (o.value || '').trim(); return v === '' || v === '0' || /^(all|any|none|null)$/i.test(v); };

  function group(sel) {
    if (sel.dataset.deptGrouped === '1' || sel.dataset.noDeptGroup !== undefined) return;
    if (sel.querySelector('optgroup')) return;
    if (!isDeptSelect(sel)) return;
    var opts = Array.prototype.slice.call(sel.options);
    var real = opts.filter(function (o) { return !isPlaceholder(o); });
    if (real.length < 2) return;
    for (var i = 0; i < real.length; i++) { if (!MAP[real[i].value]) return; }   // एखादा पर्याय विभाग नसेल तर हात लावत नाही
    var groups = {}, order = [];
    real.forEach(function (o) {
      var g = MAP[o.value];
      if (!groups[g.g]) { groups[g.g] = { o: g.o, list: [] }; order.push(g.g); }
      groups[g.g].list.push(o);
    });
    order.sort(function (a, b) { return groups[a].o < groups[b].o ? -1 : (groups[a].o > groups[b].o ? 1 : 0); });
    var selVals = opts.filter(function (o) { return o.selected; }).map(function (o) { return o.value; });
    var frag = document.createDocumentFragment();
    opts.filter(isPlaceholder).forEach(function (o) { frag.appendChild(o); });
    order.forEach(function (label) {
      var og = document.createElement('optgroup');
      og.label = window.eowmsT ? label.replace('संस्था-स्तरीय', window.eowmsT('संस्था-स्तरीय')) : label;   // भाषांतर
      groups[label].list.forEach(function (o) { og.appendChild(o); });
      frag.appendChild(og);
    });
    sel.dataset.deptGrouped = '1';
    sel.appendChild(frag);
    // निवड जशीच्या तशी (DOM हलवताना Browser कधी कधी बदलतो)
    Array.prototype.forEach.call(sel.options, function (o) { o.selected = selVals.indexOf(o.value) !== -1; });
    if (!sel.multiple && !selVals.length) { sel.selectedIndex = 0; }
  }
  function scan(root) { Array.prototype.forEach.call((root || document).querySelectorAll('select'), group); }

  scan(document);
  // JS ने नव्याने भरलेले Dropdown (उदा. Campus निवडल्यावर विभाग) — पुन्हा गटवार
  var pending = null;
  new MutationObserver(function (muts) {
    muts.forEach(function (m) {
      var t = m.target;
      if (t && t.tagName === 'SELECT' && !t.querySelector('optgroup')) { t.dataset.deptGrouped = ''; }
    });
    clearTimeout(pending);
    pending = setTimeout(function () { scan(document); }, 30);
  }).observe(document.body, { childList: true, subtree: true });
  window.eowmsGroupDeptSelects = scan;
})();
