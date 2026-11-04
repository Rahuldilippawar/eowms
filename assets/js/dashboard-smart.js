// 🧠 Smart Command Center — Tabs, विभाग शोध/क्रम/Filter, झटपट Task
(function () {
  'use strict';

  // ---------- Tabs (Requests / Tasks) ----------
  document.querySelectorAll('[data-sm-tabs]').forEach(function (panel) {
    var btns = panel.querySelectorAll('.sm-tabs button');
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        btns.forEach(function (o) { o.classList.toggle('active', o === b); });
        panel.querySelectorAll('[data-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== b.getAttribute('data-tab'); });
      });
    });
  });

  // ---------- विभाग-आरोग्य: शोध / Campus / स्तर / क्रम ----------
  var grid = document.getElementById('smDeptGrid');
  if (grid) {
    var cards = Array.prototype.slice.call(grid.querySelectorAll('.sm-dept:not(.sm-dept-all)'));
    var search = document.getElementById('smDeptSearch');
    var group = document.getElementById('smDeptGroup');
    var sort = document.getElementById('smDeptSort');
    var none = document.getElementById('smDeptNone');
    var levelBtns = document.querySelectorAll('.sm-health-sum button');
    var level = '';

    function apply() {
      var q = search ? search.value.trim().toLowerCase() : '';
      var g = group ? group.value : '';
      var shown = 0;
      cards.forEach(function (c) {
        var ok = (!q || c.getAttribute('data-name').indexOf(q) !== -1)
          && (!g || c.getAttribute('data-group') === g)
          && (!level || c.getAttribute('data-level') === level);
        c.style.display = ok ? '' : 'none';
        if (ok) shown++;
      });
      if (none) none.hidden = shown !== 0;
    }
    function order() {
      var mode = sort ? sort.value : 'attn';
      cards.sort(function (a, b) {
        if (mode === 'name') return a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'));
        var d = (+a.getAttribute('data-score')) - (+b.getAttribute('data-score'));
        return mode === 'best' ? -d : d;
      });
      cards.forEach(function (c) { grid.appendChild(c); });
      try { localStorage.setItem('eowmsDeptSort', mode); } catch (e) {}
    }
    if (search) search.addEventListener('input', apply);
    if (group) group.addEventListener('change', apply);
    if (sort) {
      try { var saved = localStorage.getItem('eowmsDeptSort'); if (saved) sort.value = saved; } catch (e) {}
      sort.addEventListener('change', order);
    }
    levelBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        level = b.getAttribute('data-level');
        levelBtns.forEach(function (o) { o.classList.toggle('active', o === b); });
        apply();
      });
    });
    order();
  }

  // ---------- झटपट Task द्या ----------
  var staffEl = document.getElementById('smQaStaff');
  var deptSel = document.getElementById('smQaDept');
  var userSel = document.getElementById('smQaUser');
  if (staffEl && deptSel && userSel) {
    var staff = {};
    try { staff = JSON.parse(staffEl.textContent || '{}'); } catch (e) {}
    function fillUsers() {
      var list = staff[deptSel.value] || [];
      userSel.innerHTML = '';
      var first = document.createElement('option');
      first.value = '';
      first.textContent = deptSel.value ? (list.length ? '-- कर्मचारी निवडा --' : 'या विभागात कर्मचारी नाहीत') : '-- आधी विभाग निवडा --';
      userSel.appendChild(first);
      list.forEach(function (u, i) {
        var o = document.createElement('option');
        o.value = u.id;
        // कामाचा भार नावासोबत — सर्वात कमी भार असलेल्याला ⭐
        o.textContent = (i === 0 && list.length > 1 ? '⭐ ' : '') + u.name + ' — ' + (u.open ? u.open + ' उघडे' + (u.overdue ? ' · ' + u.overdue + ' मुदतबाह्य' : '') : 'मोकळे');
        userSel.appendChild(o);
      });
    }
    deptSel.addEventListener('change', fillUsers);
    fillUsers();
    var dateInput = document.getElementById('smQaDate');
    document.querySelectorAll('.sm-qa-chips button').forEach(function (b) {
      b.addEventListener('click', function () {
        var d = new Date();
        d.setDate(d.getDate() + parseInt(b.getAttribute('data-days'), 10));
        var m = d.getMonth() + 1, day = d.getDate();
        dateInput.value = d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
      });
    });
    var modal = document.getElementById('smQuickAssign');
    if (modal) modal.addEventListener('shown.bs.modal', function () { var t = modal.querySelector('input[name=title]'); if (t) t.focus(); });
  }
})();
