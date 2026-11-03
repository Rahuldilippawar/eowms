// EOWMS UI Polish — सगळ्या पानांवर आपोआप लागणाऱ्या छोट्या सोयी
// 1) POST Form एकदा Submit केला की बटण "काम चालू" दाखवतं — दोनदा Click करून
//    दुहेरी नोंद होत नाही.  2) Alert बंद करण्याचं ✕ बटण.
// 3) मोठ्या Tables वर झटपट शोध + CSV.  4) "वर जा" बटण.  5) Column Sort.
// 6) "/" ने शोध.  7) न जतन केलेल्या बदलांचा इशारा.
(function () {
  'use strict';

  // ---------- 1. Double-submit Guard ----------
  document.addEventListener('submit', function (e) {
    var form = e.target;
    if (e.defaultPrevented || !(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    if (form.target === '_blank' || form.hasAttribute('data-no-busy')) return;
    if (form.dataset.busy === '1') { e.preventDefault(); return; }
    form.dataset.busy = '1';

    var submitter = e.submitter;
    // Disabled बटणाचं name/value Submit होत नाही — म्हणून Hidden Input मध्ये ठेवतो
    if (submitter && submitter.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = submitter.name;
      hidden.value = submitter.value;
      hidden.setAttribute('data-busy-copy', '1');
      form.appendChild(hidden);
    }
    var buttons = form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]');
    // Submit सुरू झाल्यानंतरच Disable — नाहीतर Browser Submit रद्द करतो
    setTimeout(function () {
      buttons.forEach(function (b) { b.disabled = true; });
      if (submitter && submitter.classList) submitter.classList.add('is-busy');
    }, 0);
    // File Download / Server धीमा असल्यास — १२ सेकंदांनी पुन्हा वापरता येतं
    setTimeout(function () { release(form); }, 12000);
  });

  function release(form) {
    form.dataset.busy = '';
    form.querySelectorAll('[data-busy-copy]').forEach(function (h) { h.remove(); });
    form.querySelectorAll('button, input[type="submit"]').forEach(function (b) {
      if (b.classList.contains('is-busy') || b.disabled) { b.disabled = false; b.classList.remove('is-busy'); }
    });
  }
  // Browser चं "Back" — जुनं पान Cache मधून परत आलं तर बटणं परत चालू करतो
  window.addEventListener('pageshow', function (e) {
    if (e.persisted) document.querySelectorAll('form[data-busy="1"]').forEach(release);
  });

  function downloadCsv(table) {
    var esc = function (t) { t = (t || '').replace(/\s+/g, ' ').trim(); return /[",\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; };
    var lines = [];
    var skip = [];
    if (table.tHead && table.tHead.rows[0]) {
      var hs = Array.prototype.map.call(table.tHead.rows[0].cells, function (c, i) {
        // Action Buttons चा (शीर्षक नसलेला) Column वगळतो
        if (!c.textContent.trim()) skip.push(i);
        return c.textContent;
      });
      lines.push(hs.filter(function (_, i) { return skip.indexOf(i) === -1; }).map(esc).join(','));
    }
    Array.prototype.forEach.call(table.tBodies[0].rows, function (r) {
      if (r.style.display === 'none') return;
      var cells = Array.prototype.filter.call(r.cells, function (_, i) { return skip.indexOf(i) === -1; });
      lines.push(cells.map(function (c) { return esc(c.innerText || c.textContent); }).join(','));
    });
    var blob = new Blob(['﻿' + lines.join('\r\n')], { type: 'text/csv;charset=utf-8' });
    var a = document.createElement('a');
    var title = (document.querySelector('.page-title') || {}).textContent || 'table';
    a.href = URL.createObjectURL(blob);
    a.download = title.trim().replace(/[\\/:*?"<>|\s]+/g, '-').slice(0, 60) + '-' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(a); a.click();
    setTimeout(function () { a.remove(); URL.revokeObjectURL(a.href); }, 1500);
  }

  document.addEventListener('DOMContentLoaded', function () {
    // ---------- 2. Alert बंद करण्याचं बटण ----------
    document.querySelectorAll('.content .alert').forEach(function (a) {
      if (a.querySelector('.btn-close, .alert-x') || a.hasAttribute('data-no-close')) return;
      var x = document.createElement('button');
      x.type = 'button';
      x.className = 'alert-x';
      x.setAttribute('aria-label', 'बंद करा');
      x.innerHTML = '&times;';
      x.addEventListener('click', function () {
        a.classList.add('is-hiding');
        setTimeout(function () { a.remove(); }, 300);
      });
      a.classList.add('has-x');
      a.appendChild(x);
    });

    // ---------- 3. मोठ्या Tables साठी Quick Filter ----------
    document.querySelectorAll('.content table.rtable, .content table.wtable').forEach(function (table) {
      if (table.hasAttribute('data-no-filter') || !table.tBodies.length) return;
      var rows = Array.prototype.slice.call(table.tBodies[0].rows);
      if (rows.length < 10) return;
      // rowspan/colspan असलेल्या (गटवार) Tables मध्ये ओळी लपवल्या तर रचना बिघडते
      if (table.querySelector('tbody td[rowspan]')) return;

      var bar = document.createElement('div');
      bar.className = 'tbl-filter';
      bar.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i><input type="search" placeholder="या यादीत शोधा…" aria-label="या यादीत शोधा"><span class="tbl-count"></span>';
      var input = bar.querySelector('input');
      var count = bar.querySelector('.tbl-count');
      var csv = document.createElement('button');
      csv.type = 'button';
      csv.className = 'tbl-csv';
      csv.title = 'दिसणाऱ्या नोंदी Excel/CSV मध्ये Download करा';
      csv.innerHTML = '<i class="fa-solid fa-file-csv"></i> CSV';
      csv.addEventListener('click', function () { downloadCsv(table); });
      bar.appendChild(csv);
      var anchor = table.parentElement && table.parentElement.classList.contains('table-responsive') ? table.parentElement : table;
      anchor.parentNode.insertBefore(bar, anchor);

      var texts = rows.map(function (r) { return (r.textContent || '').toLowerCase(); });
      function setCount(n) { count.textContent = n === rows.length ? rows.length + ' नोंदी' : n + ' / ' + rows.length + ' नोंदी'; }
      setCount(rows.length);
      var t;
      input.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
          var q = input.value.trim().toLowerCase();
          var shown = 0;
          rows.forEach(function (r, i) {
            var ok = !q || texts[i].indexOf(q) !== -1;
            r.style.display = ok ? '' : 'none';
            if (ok) shown++;
          });
          setCount(shown);
        }, 120);
      });
    });

    // ---------- 3b. Card-यादीसाठी झटपट शोध (data-card-filter) ----------
    document.querySelectorAll('.content [data-card-filter]').forEach(function (list) {
      var cards = Array.prototype.filter.call(list.children, function (c) { return /\bcol-/.test(c.className) && c.querySelector('.panel'); });
      if (cards.length < 6) return;
      var bar = document.createElement('div');
      bar.className = 'tbl-filter card-filter';
      bar.innerHTML = '<i class="fa-solid fa-magnifying-glass"></i><input type="search" aria-label="शोधा"><span class="tbl-count"></span>';
      var input = bar.querySelector('input');
      input.placeholder = list.getAttribute('data-card-filter') || 'शोधा…';
      var count = bar.querySelector('.tbl-count');
      var texts = cards.map(function (c) { return (c.textContent || '').toLowerCase(); });
      var none = document.createElement('div');
      none.className = 'col-12';
      none.innerHTML = '<div class="panel" style="padding:24px;text-align:center;color:var(--text-dim);font-size:13px;">शोधाशी जुळणारं काही सापडलं नाही</div>';
      none.hidden = true;
      list.appendChild(none);
      list.parentNode.insertBefore(bar, list);
      count.textContent = cards.length + ' नोंदी';
      var t;
      input.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
          var q = input.value.trim().toLowerCase(), shown = 0;
          cards.forEach(function (c, i) { var ok = !q || texts[i].indexOf(q) !== -1; c.style.display = ok ? '' : 'none'; if (ok) shown++; });
          count.textContent = shown === cards.length ? cards.length + ' नोंदी' : shown + ' / ' + cards.length + ' नोंदी';
          none.hidden = shown !== 0;
        }, 120);
      });
    });

    // ---------- 5. Column Sort — Table च्या शीर्षकावर Click करा ----------
    function cellValue(cell) {
      if (!cell) return '';
      var v = cell.getAttribute('data-sort');
      if (v !== null) return v;
      return (cell.textContent || '').replace(/\s+/g, ' ').trim();
    }
    var MONTHS = { jan: 0, feb: 1, mar: 2, apr: 3, may: 4, jun: 5, jul: 6, aug: 7, sep: 8, oct: 9, nov: 10, dec: 11 };
    function asNumber(t) {
      // "28 Oct 2026" / "2026-10-28" / "28/10/2026" → तारीख; "₹1,500" / "45%" → संख्या
      var m = t.match(/^(\d{1,2})\s+([A-Za-z]{3})[a-z]*\s+(\d{4})/);
      if (m && MONTHS[m[2].toLowerCase()] !== undefined) return new Date(+m[3], MONTHS[m[2].toLowerCase()], +m[1]).getTime();
      m = t.match(/^(\d{4})-(\d{2})-(\d{2})/);
      if (m) return new Date(+m[1], +m[2] - 1, +m[3]).getTime();
      m = t.match(/^(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/);
      if (m) return new Date(+m[3], +m[2] - 1, +m[1]).getTime();
      var n = t.replace(/[₹,%\s]/g, '');
      if (/^-?\d+(\.\d+)?$/.test(n)) return parseFloat(n);
      return null;
    }
    document.querySelectorAll('.content table.rtable, .content table.wtable').forEach(function (table) {
      if (table.hasAttribute('data-no-sort') || !table.tHead || !table.tBodies.length) return;
      var body = table.tBodies[0];
      if (body.rows.length < 3 || body.querySelector('td[rowspan], td[colspan]')) return;
      var heads = table.tHead.rows[0] ? table.tHead.rows[0].cells : [];
      Array.prototype.forEach.call(heads, function (th, idx) {
        if (th.colSpan > 1 || !th.textContent.trim() || th.hasAttribute('data-no-sort')) return;
        th.classList.add('th-sort');
        th.setAttribute('tabindex', '0');
        th.setAttribute('title', 'क्रम लावण्यासाठी Click करा');
        function doSort() {
          var dir = th.getAttribute('aria-sort') === 'ascending' ? -1 : 1;
          Array.prototype.forEach.call(heads, function (o) { o.removeAttribute('aria-sort'); });
          th.setAttribute('aria-sort', dir === 1 ? 'ascending' : 'descending');
          var rows = Array.prototype.slice.call(body.rows);
          rows.sort(function (a, b) {
            var x = cellValue(a.cells[idx]), y = cellValue(b.cells[idx]);
            var nx = asNumber(x), ny = asNumber(y);
            if (nx !== null && ny !== null) return (nx - ny) * dir;
            if (!x) return 1; if (!y) return -1;
            return x.localeCompare(y, 'mr') * dir;
          });
          rows.forEach(function (r) { body.appendChild(r); });
        }
        th.addEventListener('click', doSort);
        th.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doSort(); } });
      });
    });

    // ---------- 6. "/" दाबा — पानावरचा पहिला शोध Box ----------
    document.addEventListener('keydown', function (e) {
      if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey) return;
      var t = e.target;
      if (t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName))) return;
      var box = document.querySelector('.content .tbl-filter input, .content input[type="search"]');
      if (box) { e.preventDefault(); box.focus(); box.select(); }
    });

    // ---------- 7. न जतन केलेले बदल — पान सोडताना इशारा ----------
    var dirtyForms = new Set();
    document.querySelectorAll('.content form[method="post" i], .content form[method="POST"]').forEach(function (form) {
      if (form.hasAttribute('data-no-dirty')) return;
      // फक्त खरे Data-Entry Forms (३+ भरायचे Fields) — छोटे Status-बदल Forms नाहीत
      var fields = form.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]):not([type=search]), textarea, select');
      if (fields.length < 3) return;
      var mark = function () { dirtyForms.add(form); };
      form.addEventListener('input', mark);
      form.addEventListener('change', mark);
      form.addEventListener('submit', function () { dirtyForms.delete(form); });
    });
    // JS ने (उदा. onchange="this.form.submit()") Submit केलं तरी इशारा नको
    var nativeSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () { dirtyForms.delete(this); return nativeSubmit.apply(this, arguments); };
    window.addEventListener('beforeunload', function (e) {
      if (!dirtyForms.size) return;
      e.preventDefault();
      e.returnValue = '';
    });

    // ---------- 4. वर जा ----------
    var top = document.createElement('button');
    top.type = 'button';
    top.className = 'to-top';
    top.setAttribute('aria-label', 'पानाच्या वर जा');
    top.innerHTML = '<i class="fa-solid fa-arrow-up"></i>';
    top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
    document.body.appendChild(top);
    var ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking) return;
      ticking = true;
      requestAnimationFrame(function () { top.classList.toggle('show', window.scrollY > 600); ticking = false; });
    }, { passive: true });
  });
})();
