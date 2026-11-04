/* ============================================================
   📱 EOWMS Mobile — तळाचा Menu, "अधिक" Sheet, Swipe ने Sidebar बंद,
   खाली Scroll करताना Menu लपतो, वर करताना परत येतो.
   ============================================================ */
(function () {
  'use strict';
  var mq = window.matchMedia ? matchMedia('(max-width: 767px)') : { matches: false };
  var nav = document.getElementById('mbNav');
  var sheet = document.getElementById('mbSheet'), back = document.getElementById('mbSheetBack');
  var sidebar = document.querySelector('.rail-sidebar'), sbBack = document.getElementById('sidebarBackdrop');
  var sos = document.getElementById('sosFloatBtn');

  function openSidebar() { if (!sidebar) return; sidebar.classList.add('open'); if (sbBack) sbBack.classList.add('show'); }
  function closeSidebar() { if (!sidebar) return; sidebar.classList.remove('open'); if (sbBack) sbBack.classList.remove('show'); }

  // ---------- "अधिक" Sheet ----------
  function openSheet() { if (!sheet) return; sheet.classList.add('open'); back.classList.add('open'); document.body.style.overflow = 'hidden'; }
  function closeSheet() { if (!sheet) return; sheet.classList.remove('open'); back.classList.remove('open'); document.body.style.overflow = ''; }
  document.addEventListener('click', function (e) {
    var t = e.target.closest('[data-mobile-more]'); if (t) { e.preventDefault(); openSheet(); return; }
    if (e.target.closest('[data-mb-menu]')) { e.preventDefault(); closeSheet(); openSidebar(); return; }
    if (e.target.closest('[data-mb-search]')) { e.preventDefault(); if (window.eowmsOpenCommandPalette) eowmsOpenCommandPalette(); return; }
    if (sheet && e.target.closest('#mbSheet [data-theme-studio]')) closeSheet();
  });
  if (back) back.addEventListener('click', closeSheet);
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeSheet(); });

  // Sheet खाली ओढून बंद करा
  if (sheet) {
    var sy = null, dy = 0;
    sheet.addEventListener('touchstart', function (e) { if (sheet.scrollTop > 0) return; sy = e.touches[0].clientY; dy = 0; sheet.style.transition = 'none'; }, { passive: true });
    sheet.addEventListener('touchmove', function (e) { if (sy === null) return; dy = Math.max(0, e.touches[0].clientY - sy); sheet.style.transform = 'translateY(' + dy + 'px)'; }, { passive: true });
    sheet.addEventListener('touchend', function () { if (sy === null) return; sheet.style.transition = ''; sheet.style.transform = ''; if (dy > 90) closeSheet(); sy = null; });
  }

  // ---------- Sidebar डावीकडे Swipe करून बंद ----------
  if (sidebar) {
    var sx = null, sxd = 0;
    sidebar.addEventListener('touchstart', function (e) { sx = e.touches[0].clientX; sxd = 0; }, { passive: true });
    sidebar.addEventListener('touchmove', function (e) {
      if (sx === null || !sidebar.classList.contains('open')) return;
      sxd = Math.min(0, e.touches[0].clientX - sx);
      sidebar.style.transition = 'none'; sidebar.style.transform = 'translateX(' + sxd + 'px)';
    }, { passive: true });
    sidebar.addEventListener('touchend', function () {
      if (sx === null) return; sidebar.style.transition = ''; sidebar.style.transform = '';
      if (sxd < -70) closeSidebar(); sx = null;
    });
    // डाव्या कडेतून उजवीकडे Swipe → Sidebar उघडा
    var ex = null;
    document.addEventListener('touchstart', function (e) { if (!mq.matches || sidebar.classList.contains('open')) return; var x = e.touches[0].clientX; ex = x < 18 ? x : null; }, { passive: true });
    document.addEventListener('touchmove', function (e) { if (ex !== null && e.touches[0].clientX - ex > 60) { openSidebar(); ex = null; } }, { passive: true });
  }

  // ---------- खाली Scroll → Menu लपवा, वर → दाखवा ----------
  if (nav) {
    var lastY = window.scrollY, ticking = false;
    window.addEventListener('scroll', function () {
      if (ticking || !mq.matches) return; ticking = true;
      requestAnimationFrame(function () {
        var y = window.scrollY, d = y - lastY;
        if (Math.abs(d) > 6) {
          var hide = d > 0 && y > 120 && !(window.innerHeight + y >= document.body.scrollHeight - 40);
          nav.classList.toggle('hide', hide);
          if (sos) sos.style.transform = hide ? 'translateY(' + (nav.offsetHeight) + 'px)' : '';
          lastY = y;
        }
        ticking = false;
      });
    }, { passive: true });
  }

  // भाषांतर (JS ने टाकलेला मजकूर)
  if (window.eowmsT && sheet) sheet.querySelectorAll('.mb-label').forEach(function (e) { e.textContent = eowmsT(e.textContent); });
})();

/* ---------- Loading पट्टी + खाली ओढून Refresh ---------- */
(function () {
  'use strict';
  var bar = document.createElement('div'); bar.className = 'eo-progress'; document.body.appendChild(bar);
  var timer = null, safety = null;
  function start() {
    clearInterval(timer); var w = 8; bar.style.transition = 'none'; bar.style.width = '0'; void bar.offsetWidth;
    bar.style.transition = ''; bar.classList.add('run'); bar.style.width = w + '%';
    timer = setInterval(function () { w += (90 - w) * 0.12; bar.style.width = w + '%'; }, 250);
    clearTimeout(safety); safety = setTimeout(done, 8000); // Download/Export नंतर पान बदलत नाही — पट्टी अडकू नये
  }
  function done() { clearInterval(timer); bar.style.width = '100%'; setTimeout(function () { bar.classList.remove('run'); bar.style.width = '0'; }, 300); }
  window.eowmsProgress = { start: start, done: done };
  document.addEventListener('click', function (e) {
    var a = e.target.closest('a[href]'); if (!a || e.defaultPrevented || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey) return;
    var h = a.getAttribute('href'); if (!h || h.charAt(0) === '#' || /^(javascript:|mailto:|tel:)/i.test(h) || a.target === '_blank' || a.hasAttribute('download') || a.hasAttribute('data-bs-toggle')) return;
    if (/\.(csv|pdf|xlsx?|zip)(\?|$)|export|download/i.test(h)) return;
    try { var u = new URL(a.href, location.href); if (u.origin !== location.origin || (u.pathname === location.pathname && u.search === location.search && u.hash)) return; } catch (x) { return; }
    start();
  });
  document.addEventListener('submit', function (e) { if (e.defaultPrevented || e.target.target) return; start(); });
  window.addEventListener('pageshow', done);
  window.addEventListener('pagehide', function () { clearInterval(timer); });

  // खाली ओढून Refresh — फक्त Touch, पानाच्या अगदी वर असताना
  if (!('ontouchstart' in window) || !window.matchMedia || !matchMedia('(max-width: 767px)').matches) return;
  var ptr = document.createElement('div'); ptr.className = 'eo-ptr'; ptr.innerHTML = '<i class="fa-solid fa-arrow-rotate-right"></i>'; document.body.appendChild(ptr);
  var y0 = null, dy = 0, MAX = 110, GO = 75;
  var blocked = function (t) { return t.closest('.mb-sheet, .ts-panel, .rail-sidebar, .modal, .cmdk-overlay, input, textarea, select, [contenteditable]'); };
  document.addEventListener('touchstart', function (e) {
    if (window.scrollY > 0 || document.body.style.overflow === 'hidden' || blocked(e.target)) { y0 = null; return; }
    y0 = e.touches[0].clientY; dy = 0;
  }, { passive: true });
  document.addEventListener('touchmove', function (e) {
    if (y0 === null) return; dy = e.touches[0].clientY - y0;
    if (dy <= 0 || window.scrollY > 0) { ptr.style.opacity = 0; ptr.style.transform = 'translateY(-60px)'; return; }
    var d = Math.min(MAX, dy * 0.55);
    ptr.style.transition = 'none'; ptr.style.opacity = Math.min(1, d / GO);
    ptr.style.transform = 'translateY(' + (d - 20) + 'px) rotate(' + (d * 3) + 'deg)';
    ptr.classList.toggle('ready', d >= GO);
  }, { passive: true });
  document.addEventListener('touchend', function () {
    if (y0 === null) return; y0 = null;
    var d = Math.min(MAX, dy * 0.55); ptr.style.transition = 'transform .25s, opacity .25s';
    if (d >= GO) { ptr.classList.add('spin'); ptr.style.transform = 'translateY(' + (GO - 20) + 'px)'; start(); setTimeout(function () { location.reload(); }, 150); }
    else { ptr.style.opacity = 0; ptr.style.transform = 'translateY(-60px)'; ptr.classList.remove('ready'); }
  });
})();

/* ---------- Keyboard उघडा → तळचा Menu लपवा · Menu ला हलका Haptic प्रतिसाद ---------- */
(function () {
  'use strict';
  if (!window.matchMedia || !matchMedia('(max-width: 767px)').matches) return;
  var typing = function (el) { return el && (el.tagName === 'TEXTAREA' || el.tagName === 'SELECT' || (el.tagName === 'INPUT' && !/^(checkbox|radio|button|submit|reset|file|range|color)$/i.test(el.type))); };
  var t = null;
  document.addEventListener('focusin', function (e) { if (typing(e.target) && !e.target.closest('.mb-sheet, .cmdk-overlay')) { clearTimeout(t); document.body.classList.add('kb-open'); } });
  document.addEventListener('focusout', function () { clearTimeout(t); t = setTimeout(function () { if (!typing(document.activeElement)) document.body.classList.remove('kb-open'); }, 150); });
  document.addEventListener('click', function (e) { if (e.target.closest('.mb-nav a, .mb-nav button, .mb-grid > *') && navigator.vibrate) { try { navigator.vibrate(8); } catch (x) {} } });
})();
