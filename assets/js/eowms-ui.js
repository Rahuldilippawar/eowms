// EOWMS UI Polish — सगळ्या पानांवर आपोआप लागणाऱ्या छोट्या सोयी
// 1) POST Form एकदा Submit केला की बटण "काम चालू" दाखवतं — दोनदा Click करून
//    दुहेरी नोंद होत नाही.  2) Alert बंद करण्याचं ✕ बटण.
// 3) मोठ्या Tables वर झटपट शोध (Quick Filter).  4) "वर जा" बटण.
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
