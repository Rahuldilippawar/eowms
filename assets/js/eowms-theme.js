/* ============================================================
   🎨 Theme Studio — रंग, Mode, अक्षर-आकार, मांडणी, Sidebar, Animation
   निवड या Browser मध्ये लक्षात राहते (localStorage). पान Render
   होण्याआधीच header.php मधली छोटी Script हे लागू करते.
   ============================================================ */
(function () {
  'use strict';
  var KEYS = { theme: 'eowms_theme', accent: 'eowms_accent', fs: 'eowms_fs', density: 'eowms_density', sidebar: 'eowms_sidebar', motion: 'eowms_motion' };
  var DEF = { theme: 'light', accent: 'teal', fs: 'md', density: 'cozy', sidebar: 'dark', motion: 'on' };
  var ACCENTS = [
    ['teal', 'मोरपंखी', '#138A80'], ['blue', 'निळा', '#2563EB'], ['saffron', 'भगवा', '#D9530F'],
    ['purple', 'जांभळा', '#7C3AED'], ['green', 'हिरवा', '#15803D'], ['maroon', 'मरून', '#A3123A']
  ];
  function get(k) { try { return localStorage.getItem(KEYS[k]) || DEF[k]; } catch (e) { return DEF[k]; } }
  function set(k, v) { try { if (v === DEF[k]) localStorage.removeItem(KEYS[k]); else localStorage.setItem(KEYS[k], v); } catch (e) {} }

  function apply() {
    var h = document.documentElement, t = get('theme');
    var dark = t === 'dark' || (t === 'auto' && window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches);
    if (dark) h.setAttribute('data-theme', 'dark'); else h.removeAttribute('data-theme');
    ['accent', 'fs', 'density', 'sidebar', 'motion'].forEach(function (k) {
      var v = get(k); if (v === DEF[k]) h.removeAttribute('data-' + k); else h.setAttribute('data-' + k, v);
    });
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', dark ? '#0B1020' : getComputedStyle(h).getPropertyValue('--rail').trim() || '#138A80');
  }
  window.eowmsApplyPrefs = apply;
  // सध्याचं Sun/Moon बटण — Light ↔ Dark
  window.eowmsToggleTheme = function () {
    set('theme', document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
    apply(); sync();
  };
  if (window.matchMedia) {
    var mq = matchMedia('(prefers-color-scheme: dark)');
    var onChange = function () { if (get('theme') === 'auto') apply(); };
    if (mq.addEventListener) mq.addEventListener('change', onChange); else if (mq.addListener) mq.addListener(onChange);
  }
  apply();

  // ---------- Panel ----------
  var panel, back;
  function seg(key, opts) {
    return '<div class="ts-seg" data-key="' + key + '">' + opts.map(function (o) {
      return '<button type="button" data-v="' + o[0] + '">' + (o[2] ? '<i class="' + o[2] + '"></i>' : '') + '<span>' + o[1] + '</span></button>';
    }).join('') + '</div>';
  }
  function build() {
    back = document.createElement('div'); back.className = 'ts-backdrop';
    panel = document.createElement('aside'); panel.className = 'ts-panel'; panel.setAttribute('role', 'dialog'); panel.setAttribute('aria-label', 'Theme Studio');
    panel.innerHTML =
      '<div class="ts-head"><h3><i class="fa-solid fa-palette"></i> Theme Studio</h3><button type="button" class="ts-close" aria-label="बंद करा"><i class="fa-solid fa-xmark"></i></button></div>' +
      '<div class="ts-body">' +
        '<div class="ts-sec"><div class="ts-label">पूर्वावलोकन</div><div class="ts-preview"><div class="s"><i class="a"></i><i></i><i></i><i></i><i></i></div><div class="m"><div class="bar"></div><div class="row"><div class="card"></div><div class="card"></div><div class="card"></div></div></div></div></div>' +
        '<div class="ts-sec"><div class="ts-label">Mode</div>' + seg('theme', [['light', 'Light', 'fa-solid fa-sun'], ['dark', 'Dark', 'fa-solid fa-moon'], ['auto', 'आपोआप', 'fa-solid fa-circle-half-stroke']]) +
          '<div class="ts-note">आपोआप निवडलं तर तुमच्या Phone / Computer च्या सेटिंगनुसार दिवसा Light, रात्री Dark.</div></div>' +
        '<div class="ts-sec"><div class="ts-label">मुख्य रंग</div><div class="ts-swatches">' + ACCENTS.map(function (a) {
          return '<button type="button" class="ts-sw" data-v="' + a[0] + '" style="--sw:' + a[2] + '"><span class="dot" style="background:' + a[2] + '"></span>' + a[1] + '</button>';
        }).join('') + '</div></div>' +
        '<div class="ts-sec"><div class="ts-label">Sidebar</div>' + seg('sidebar', [['dark', 'गडद', 'fa-solid fa-square'], ['light', 'हलका', 'fa-regular fa-square']]) + '</div>' +
        '<div class="ts-sec"><div class="ts-label">अक्षरांचा आकार</div>' + seg('fs', [['sm', 'लहान', ''], ['md', 'नेहमीचा', ''], ['lg', 'मोठा', ''], ['xl', 'खूप मोठा', '']]) + '</div>' +
        '<div class="ts-sec"><div class="ts-label">मांडणी</div>' + seg('density', [['cozy', 'आरामदायक', 'fa-solid fa-bars'], ['compact', 'Compact', 'fa-solid fa-grip-lines']]) +
          '<div class="ts-note">Compact — एका पानावर जास्त माहिती (मोठ्या याद्यांसाठी उपयुक्त).</div></div>' +
        '<div class="ts-sec"><div class="ts-label">Animation</div>' + seg('motion', [['on', 'चालू', 'fa-solid fa-wand-magic-sparkles'], ['off', 'बंद', 'fa-solid fa-ban']]) + '</div>' +
      '</div>' +
      '<div class="ts-foot"><button type="button" class="ts-reset"><i class="fa-solid fa-rotate-left"></i> मूळ सेटिंग</button><button type="button" class="ts-done">झालं</button></div>';
    document.body.appendChild(back); document.body.appendChild(panel);
    if (window.eowmsT) panel.querySelectorAll('span, .ts-label, .ts-note, h3, button.ts-reset, button.ts-done').forEach(function (e) {
      if (e.children.length === 0) e.textContent = window.eowmsT(e.textContent);
    });
    panel.addEventListener('click', function (e) {
      var b = e.target.closest('button'); if (!b) return;
      if (b.classList.contains('ts-close') || b.classList.contains('ts-done')) return close();
      if (b.classList.contains('ts-reset')) { Object.keys(DEF).forEach(function (k) { set(k, DEF[k]); }); apply(); return sync(); }
      if (b.classList.contains('ts-sw')) { set('accent', b.getAttribute('data-v')); apply(); return sync(); }
      var s = b.closest('.ts-seg'); if (s) { set(s.getAttribute('data-key'), b.getAttribute('data-v')); apply(); sync(); }
    });
    back.addEventListener('click', close);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && panel.classList.contains('open')) close(); });
  }
  function sync() {
    if (!panel) return;
    panel.querySelectorAll('.ts-seg').forEach(function (s) {
      var v = get(s.getAttribute('data-key'));
      s.querySelectorAll('button').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === v); });
    });
    var a = get('accent');
    panel.querySelectorAll('.ts-sw').forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-v') === a); });
  }
  function open() { if (!panel) build(); sync(); back.classList.add('open'); panel.classList.add('open'); setTimeout(function () { panel.querySelector('.ts-close').focus(); }, 50); }
  function close() { back.classList.remove('open'); panel.classList.remove('open'); }
  window.eowmsOpenThemeStudio = open;
  document.addEventListener('click', function (e) { var t = e.target.closest('[data-theme-studio]'); if (t) { e.preventDefault(); open(); } });
})();
