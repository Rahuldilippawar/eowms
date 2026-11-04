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
