  </div>

  <div style="text-align:center;padding:18px 20px 24px;font-size:12px;color:var(--text-dim);border-top:1px solid var(--line);margin-top:10px;">
    <div style="font-size:12.5px;color:var(--ink);margin-bottom:3px;"><?= h(t('s68')) ?></div>
    <div>© <?= date('Y') ?> <?= h(activeOrgName()) ?><?= h(t('s69')) ?></div>
    <div style="margin-top:3px;">Developed by <strong style="color:var(--ink);">Rahul Pawar</strong></div>
  </div>
</div>

<?php
  // 📱 Mobile — तळाचा App-Menu व "अधिक" Sheet (फक्त लहान पडद्यावर दिसतात)
  $__rp = $rootPath ?? '';
  $__script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
  $__isActive = function ($needle) use ($__script) { return substr($__script, -strlen($needle)) === $needle; };
  $__u = function_exists('currentUser') ? currentUser() : null;
?>
<nav class="mb-nav" id="mbNav" aria-label="मुख्य मेनू">
  <a href="<?= $__rp ?>index.php" class="<?= basename($__script) === 'index.php' && $__rp === '' ? 'on' : '' ?>"><i class="fa-solid fa-house"></i><span>मुख्य</span></a>
  <a href="<?= $__rp ?>tasks/my-tasks.php" class="<?= $__isActive('tasks/my-tasks.php') ? 'on' : '' ?>"><i class="fa-solid fa-list-check"></i><span>माझे Tasks</span></a>
  <a href="<?= $__rp ?>daily-work/entry.php" class="mb-fab <?= $__isActive('daily-work/entry.php') ? 'on' : '' ?>" aria-label="नवीन काम नोंदवा"><span class="mb-fab-in"><i class="fa-solid fa-plus"></i></span><span>काम नोंदवा</span></a>
  <button type="button" data-mb-search><i class="fa-solid fa-magnifying-glass"></i><span>शोध</span></button>
  <button type="button" data-mb-menu><i class="fa-solid fa-grip"></i><span>Menu</span></button>
</nav>
<div class="mb-sheet-back" id="mbSheetBack"></div>
<div class="mb-sheet" id="mbSheet" role="dialog" aria-modal="true" aria-label="अधिक पर्याय">
  <div class="mb-sheet-grab"></div>
  <?php if ($__u): ?>
    <a class="mb-user" href="<?= $__rp ?>account/profile.php">
      <span class="mb-av"><?= h(mb_strtoupper(mb_substr(trim($__u['name'] ?? '?'), 0, 1))) ?></span>
      <span><b><?= h($__u['name'] ?? '') ?></b><small><?= h(activeOrgName()) ?></small></span>
      <i class="fa-solid fa-chevron-right"></i>
    </a>
  <?php endif; ?>
  <div class="mb-grid">
    <button type="button" data-theme-studio><i class="fa-solid fa-palette"></i><span>Theme Studio</span></button>
    <button type="button" onclick="eowmsToggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i><span>Dark / Light</span></button>
    <a href="<?= $__rp ?>notifications/index.php"><i class="fa-regular fa-bell"></i><span>सूचना</span></a>
    <a href="<?= $__rp ?>help/index.php"><i class="fa-solid fa-circle-question"></i><span>मदत</span></a>
  </div>
  <div class="mb-label">भाषा</div>
  <!--i18n:off--><div class="mb-langs">
    <?php foreach (EOWMS_LANGS as $__k => $__l): ?>
      <button type="button" class="<?= i18nLang() === $__k ? 'on' : '' ?>" onclick="eowmsSetLang('<?= $__k ?>')"><?= h($__l['label']) ?></button>
    <?php endforeach; ?>
  </div><!--i18n:on-->
  <a class="mb-logout" href="<?= $__rp ?>logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</div>
<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<!-- ⏰ Auto-Logout उलटी गिनती + "मजकूर परत भरला" सूचना (मजकूर HTML मध्ये — भाषांतर होतं) -->
<style>
  #idleModal{display:none;position:fixed;inset:0;z-index:10050;align-items:center;justify-content:center;background:rgba(14,16,36,.62);backdrop-filter:blur(3px);padding:16px;}
  #idleModal .im-card{background:var(--surface,#fff);color:var(--text,#1A1D29);border-radius:20px;padding:26px 24px 22px;max-width:360px;width:100%;text-align:center;box-shadow:0 30px 70px -20px rgba(0,0,0,.5);animation:imUp .35s cubic-bezier(.2,.8,.2,1);}
  @keyframes imUp{from{opacity:0;transform:translateY(14px) scale(.98);}to{opacity:1;transform:none;}}
  #idleRing{--p:100;width:118px;height:118px;border-radius:50%;margin:0 auto 12px;display:grid;place-items:center;background:conic-gradient(var(--rail,#D9530F) calc(var(--p)*1%), var(--canvas,#EEE9E3) 0);transition:background .9s linear;}
  #idleRing span{width:94px;height:94px;border-radius:50%;background:var(--surface,#fff);display:grid;place-items:center;font-size:26px;font-weight:800;font-family:'Space Grotesk',monospace;}
  #idleModal h3{font-size:17px;font-weight:800;margin:0 0 6px;} #idleModal p{font-size:13px;color:var(--text-dim,#666B7D);margin:0 0 16px;line-height:1.6;}
  #idleModal .im-btns{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;}
  #idleModal button{border-radius:11px;font-size:14px;font-weight:700;padding:11px 18px;cursor:pointer;font-family:inherit;}
  #idleStayBtn{background:var(--rail,#D9530F);color:#fff;border:0;min-width:170px;} #idleOutBtn{background:transparent;border:1px solid var(--line,#ddd);color:inherit;}
  #idleRestored{display:none;position:fixed;left:50%;transform:translateX(-50%);bottom:90px;z-index:10040;background:#191C3A;color:#fff;border-radius:12px;padding:11px 16px;font-size:13.5px;gap:10px;align-items:center;box-shadow:0 14px 30px -12px rgba(0,0,0,.5);max-width:calc(100% - 32px);}
</style>
<div id="idleModal" role="alertdialog" aria-modal="true" aria-labelledby="idleTitle">
  <div class="im-card">
    <div id="idleRing"><span id="idleCountdown">2:00</span></div>
    <h3 id="idleTitle">⏰ तुम्ही काही वेळ निष्क्रिय आहात</h3>
    <p>सुरक्षिततेसाठी वेळ संपताच आपोआप Logout होईल. भरलेला न-जतन मजकूर जपून ठेवला जाईल — परत Login केल्यावर त्याच पानावर परत मिळेल.</p>
    <div class="im-btns"><button type="button" id="idleStayBtn">मी इथे आहे — सुरू ठेवा</button><button type="button" id="idleOutBtn">आत्ता Logout</button></div>
  </div>
</div>
<div id="idleRestored"><i class="fa-solid fa-rotate-left"></i><span>Logout आधी भरलेला मजकूर परत भरला — तपासून जतन करा.</span></div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/eowms-advance.js?v=2"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/eowms-theme.js?v=3"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/eowms-mobile.js?v=3"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/eowms-ui.js?v=3"></script>
<?php // 🏛️ विभाग-Dropdown — संस्था / College / School नुसार गटवार (सगळ्या Modules मध्ये)
if (isset($pdo) && $pdo instanceof PDO && function_exists('currentUser') && currentUser()) { require_once __DIR__ . '/dept-groups.php'; echo deptGroupsScript($pdo, activeOrgId()); } ?>
<script src="<?= $rootPath ?? '' ?>assets/js/dept-groups.js?v=1"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/select-smart.js?v=1"></script>
<script>
// Mobile hamburger menu — opens/closes the sidebar on small screens
(function () {
  var btn = document.getElementById('mobileMenuBtn');
  var sidebar = document.querySelector('.rail-sidebar');
  var backdrop = document.getElementById('sidebarBackdrop');
  if (!btn || !sidebar || !backdrop) return;

  function openMenu() {
    sidebar.classList.add('open');
    backdrop.classList.add('show');
  }
  function closeMenu() {
    sidebar.classList.remove('open');
    backdrop.classList.remove('show');
  }

  btn.addEventListener('click', function () {
    sidebar.classList.contains('open') ? closeMenu() : openMenu();
  });
  backdrop.addEventListener('click', closeMenu);

  // Close automatically after tapping a nav link (mobile UX)
  sidebar.querySelectorAll('a').forEach(function (link) {
    link.addEventListener('click', closeMenu);
  });
})();

// ---------------- ⏰ Smart Auto-Logout (निष्क्रियता) ----------------
// • सगळ्या Tabs चा एकच Timer (localStorage) — एका Tab मध्ये काम चालू असेल तर दुसरा Tab Logout करत नाही
// • काम चालू असताना (टाइप/Click) Server ला अधूनमधून कळवतो — एकाच पानावर बराच वेळ काम केलं तरी Session जात नाही
// • शेवटच्या काही मिनिटांत उलटी गिनती; वेळ संपली की Logout — आणि भरलेला न-जतन मजकूर जपून ठेवतो
// • परत Login केल्यावर त्याच पानावर, आणि मजकूर पुन्हा भरलेला
(function () {
  var LIMIT = <?= (int)sessionIdleLimitSeconds() ?> * 1000;
  var WARN = Math.min(120000, Math.round(LIMIT * 0.2));
  var PING_EVERY = Math.min(5 * 60000, Math.round(LIMIT / 3));
  var root = <?= json_encode($rootPath ?? '') ?>;
  var KEY = 'eowms_last_act', OUT = 'eowms_logout_at';
  var modal = document.getElementById('idleModal'); if (!modal) return;
  var ls = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} }, del: function (k) { try { localStorage.removeItem(k); } catch (e) {} } };
  var now = function () { return Date.now(); };
  var localLast = now(), lastPing = now(), shown = false, gone = false, baseTitle = document.title;
  ls.set(KEY, String(localLast)); // पान उघडलं = Server वर Request झाली

  var basePath = new URL(root || './', location.href).pathname;
  function relUrl() { var p = location.pathname; return (p.indexOf(basePath) === 0 ? p.slice(basePath.length) : p.replace(/^\//, '')) + location.search; }
  function last() { return Math.max(localLast, +(ls.get(KEY) || 0)); }
  function mark() { localLast = now(); if (localLast - (+(ls.get(KEY) || 0)) > 5000) ls.set(KEY, String(localLast)); }

  // ---- न-जतन मजकूर (Logout आधी) जपणं / परत भरणं ----
  function fields() { return [].slice.call(document.querySelectorAll('form input[name], form textarea[name], form select[name]')).filter(function (f) { return !/^(password|hidden|file|submit|button|reset|image)$/i.test(f.type || '') && f.name !== '_csrf'; }); }
  function dirty(f) { if (f.type === 'checkbox' || f.type === 'radio') return f.checked !== f.defaultChecked; if (f.tagName === 'SELECT') return [].some.call(f.options, function (o) { return o.selected !== o.defaultSelected; }); return f.value !== f.defaultValue; }
  function saveDrafts() {
    var seen = {}, out = [];
    fields().forEach(function (f) { var k = f.name; seen[k] = (seen[k] || 0) + 1; if (dirty(f)) out.push({ n: k, i: seen[k] - 1, v: f.value, c: f.checked }); });
    if (out.length) ls.set('eowms_draft:' + location.pathname + location.search, JSON.stringify({ at: now(), f: out }));
  }
  (function restoreDrafts() {
    var key = 'eowms_draft:' + location.pathname + location.search, raw = ls.get(key); if (!raw) return;
    ls.del(key);
    var d; try { d = JSON.parse(raw); } catch (e) { return; }
    if (!d || now() - d.at > 3 * 3600 * 1000) return;
    var byName = {}, n = 0;
    fields().forEach(function (f) { (byName[f.name] = byName[f.name] || []).push(f); });
    d.f.forEach(function (x) { var f = (byName[x.n] || [])[x.i]; if (!f || dirty(f)) return; if (f.type === 'checkbox' || f.type === 'radio') f.checked = x.c; else f.value = x.v; f.dispatchEvent(new Event('input', { bubbles: true })); n++; });
    if (n) { var t = document.getElementById('idleRestored'); if (t) { t.style.display = 'flex'; setTimeout(function () { t.style.display = 'none'; }, 9000); } }
  })();

  // ---- Server ला "काम चालू आहे" कळवणं ----
  function ping(force) {
    if (!force && now() - lastPing < PING_EVERY) return Promise.resolve(true);
    lastPing = now();
    return fetch(root + 'ajax-keep-alive.php', { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401) { expired(); return false; } return true; })
      .catch(function () { return true; }); // Network नसेल तर Logout करत नाही
  }
  ['mousemove', 'keydown', 'click', 'scroll', 'touchstart', 'input'].forEach(function (ev) {
    document.addEventListener(ev, function () { if (!shown && !gone) { mark(); ping(false); } }, { passive: true, capture: true });
  });

  // ---- Logout ----
  function go(url) { if (gone) return; gone = true; saveDrafts(); ls.set(OUT, String(now())); location.href = url; }
  function doLogout() { go(root + 'logout.php?reason=idle&next=' + encodeURIComponent(relUrl())); }
  function expired() { go(root + 'login.php?next=' + encodeURIComponent(relUrl())); }
  window.addEventListener('storage', function (e) {
    if (e.key === OUT && e.newValue && !gone) { gone = true; saveDrafts(); location.href = root + 'login.php?next=' + encodeURIComponent(relUrl()); }
    if (e.key === KEY && shown && LIMIT - (now() - last()) > WARN) hide(); // दुसऱ्या Tab मध्ये काम सुरू झालं
  });

  // ---- उलटी गिनती ----
  var ring = document.getElementById('idleRing'), cd = document.getElementById('idleCountdown');
  function show(left) {
    if (!shown) { shown = true; modal.style.display = 'flex'; document.getElementById('idleStayBtn').focus(); }
    var s = Math.ceil(left / 1000), m = Math.floor(s / 60);
    cd.textContent = m + ':' + String(s % 60).padStart(2, '0');
    ring.style.setProperty('--p', Math.max(0, Math.min(100, left / WARN * 100)));
    document.title = (s % 2 ? '⏰ ' : '') + cd.textContent + ' — Logout · ' + baseTitle;
  }
  function hide() { shown = false; modal.style.display = 'none'; document.title = baseTitle; }
  function tick() {
    if (gone) return;
    var left = LIMIT - (now() - last());
    if (left <= 0) return doLogout();
    if (left <= WARN) show(left); else if (shown) hide();
  }
  setInterval(tick, 1000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
  document.getElementById('idleStayBtn').addEventListener('click', function () {
    ping(true).then(function (ok) { if (ok) { mark(); ls.set(KEY, String(now())); hide(); } });
  });
  document.getElementById('idleOutBtn').addEventListener('click', function () { go(root + 'logout.php'); });
})();

// ---------------- PWA: register service worker (Add to Home Screen) ----------------
if ('serviceWorker' in navigator) {
  var swRootPath = <?= json_encode($rootPath ?? '') ?>;
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(swRootPath + 'sw.js', { scope: swRootPath || './' })
      .then(function (registration) {
      })
      .catch(function () {});
  });
}

// Push / सूचना — assets/js/push-smart.js (Permission फक्त "चालू करा" दाबल्यावर)
</script>

<script>window.eowmsUid = <?= (int)(currentUser()['id'] ?? 0) ?>;</script>
<script src="<?= $rootPath ?? '' ?>assets/js/push-smart.js?v=1"></script>
<script>if (window.eowmsPushStart && window.eowmsUid) eowmsPushStart(<?= json_encode($rootPath ?? '') ?>);</script>

<!-- Offline-first form queue (works alongside the service worker above) -->
<script src="<?= $rootPath ?? '' ?>assets/js/offline-queue.js?v=2"></script>

<?php if (!empty($_SESSION['play_welcome_chime'])): unset($_SESSION['play_welcome_chime']);
  // प्राधान्य 1: Super Admin ने Upload केलेलं Music (Database मध्ये साठवलेलं)
  $welcomeAudioFile = null;
  try {
      $waStmt = $pdo->prepare("SELECT welcome_audio_path FROM organizations WHERE id = ?");
      $waStmt->execute([activeOrgId()]);
      $welcomeAudioFile = $waStmt->fetch()['welcome_audio_path'] ?? null;
  } catch (Throwable $e) { /* organization_id उपलब्ध नसेल तर दुर्लक्ष करा */ }

  // प्राधान्य 2 (जुनी पद्धत): assets/audio/ फोल्डरमध्ये FTP ने टाकलेली file
  if (!$welcomeAudioFile) {
      $audioDir = __DIR__ . '/../assets/audio/';
      if (is_dir($audioDir)) {
          foreach (['mp3', 'wav', 'ogg', 'm4a', 'MP3', 'WAV'] as $ext) {
              $matches = glob($audioDir . '*.' . $ext);
              if (!empty($matches)) {
                  $welcomeAudioFile = 'assets/audio/' . basename($matches[0]);
                  break;
              }
          }
      }
  }
?>
<script src="<?= $rootPath ?? '' ?>assets/js/welcome-chime.js"></script>
<script>
window.addEventListener('load', function () {
  if (typeof eowmsPlayWelcomeChime === 'function') { eowmsPlayWelcomeChime(<?= json_encode($rootPath ?? '') ?>, <?= json_encode($welcomeAudioFile) ?>); }
});
</script>
<?php endif; ?>

<!-- ==================== Global Search (Ctrl+K Command Palette) ==================== -->
<div id="cmdPaletteBackdrop" style="display:none;position:fixed;inset:0;background:rgba(16,22,43,.55);z-index:2000;">
  <div style="max-width:560px;margin:80px auto 0;background:#fff;border-radius:14px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.3);">
    <div style="padding:14px 18px;border-bottom:1px solid #EEF0F4;">
      <input type="text" id="cmdPaletteInput" placeholder="🔍 शोधा — Staff, Letters, वाहन, IT Asset, विभाग..." style="width:100%;border:none;outline:none;font-size:15px;font-family:inherit;">
    </div>
    <div id="cmdPaletteResults" style="max-height:360px;overflow-y:auto;"></div>
    <div style="padding:8px 18px;border-top:1px solid #EEF0F4;font-size:11px;color:#999;">Esc दाबून बंद करा</div>
  </div>
</div>
<script>
(function () {
  var rootPath = <?= json_encode($rootPath ?? '') ?>;
  var backdrop = document.getElementById('cmdPaletteBackdrop');
  var input = document.getElementById('cmdPaletteInput');
  var resultsBox = document.getElementById('cmdPaletteResults');
  var debounceTimer = null;

  function openPalette() {
    backdrop.style.display = 'block';
    input.value = '';
    resultsBox.innerHTML = '';
    setTimeout(function () { input.focus(); }, 50);
  }
  function closePalette() { backdrop.style.display = 'none'; }

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
      e.preventDefault();
      openPalette();
    } else if (e.key === 'Escape') {
      closePalette();
    }
  });
  backdrop.addEventListener('click', function (e) { if (e.target === backdrop) closePalette(); });

  input.addEventListener('input', function () {
    var q = input.value.trim();
    clearTimeout(debounceTimer);
    if (q.length < 2) { resultsBox.innerHTML = ''; return; }
    debounceTimer = setTimeout(function () {
      fetch(rootPath + 'search-global.php?q=' + encodeURIComponent(q))
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data.results || data.results.length === 0) {
            resultsBox.innerHTML = '<div style="padding:20px;text-align:center;color:#999;font-size:13px;">काहीही सापडलं नाही</div>';
            return;
          }
          resultsBox.innerHTML = data.results.map(function (r) {
            return '<a href="' + rootPath + r.url + '" style="display:flex;align-items:center;gap:12px;padding:11px 18px;text-decoration:none;color:#1A1D29;border-bottom:1px solid #F5F6F8;">' +
              '<span style="font-size:18px;">' + r.icon + '</span>' +
              '<div style="flex:1;min-width:0;"><div style="font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + r.title + '</div>' +
              '<div style="font-size:11px;color:#999;">' + r.type + (r.subtitle ? ' · ' + r.subtitle : '') + '</div></div></a>';
          }).join('');
        });
    }, 250);
  });
})();
</script>

<!-- ==================== 🆘 Emergency/SOS Button (सगळीकडे उपलब्ध) ==================== -->
<button type="button" id="sosFloatBtn" data-bs-toggle="modal" data-bs-target="#sosModal"
  style="position:fixed;bottom:22px;right:22px;z-index:1500;width:56px;height:56px;border-radius:50%;background:#B23A48;color:#fff;border:none;box-shadow:0 6px 18px rgba(178,58,72,.4);font-size:22px;">
  🆘
</button>
<div class="modal fade" id="sosModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:14px;border-top:4px solid #B23A48;">
      <form method="POST" action="<?= $rootPath ?? '' ?>emergency/trigger.php" id="sosForm">
        <input type="hidden" name="latitude" id="sosLat">
        <input type="hidden" name="longitude" id="sosLng">
        <div class="modal-body" style="padding:24px;">
          <div style="font-size:17px;font-weight:700;color:#B23A48;margin-bottom:4px;">🆘 आणीबाणी सूचना पाठवा</div>
          <div style="font-size:12px;color:#666B7D;margin-bottom:16px;">ही सूचना Management, Security, तुमचे विभाग-प्रमुख यांना त्वरित पाठवली जाईल.</div>
          <label class="form-label-custom">प्रकार</label>
          <select name="alert_type" class="form-select-custom mb-3" required>
            <option value="fire">🔥 आग</option>
            <option value="medical">🚑 वैद्यकीय आणीबाणी</option>
            <option value="security">🛡️ सुरक्षा धोका</option>
            <option value="other">🆘 इतर</option>
          </select>
          <label class="form-label-custom">स्थान</label>
          <input type="text" name="location" class="form-control-custom mb-3" placeholder="उदा. मुख्य इमारत, दुसरा मजला">
          <label class="form-label-custom">तपशील (ऐच्छिक)</label>
          <textarea name="message" class="form-control-custom" rows="2"></textarea>
          <div id="sosGeoStatus" style="font-size:11px;color:#666B7D;margin-top:8px;">📍 ठिकाण (GPS) — परवानगी दिल्यास नकाशा-Link सोबत जाईल; नाही दिली तरी सूचना जाईल.</div>
        </div>
        <div class="modal-footer" style="border-top:1px solid #EEF0F4;">
          <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid #D7DAE0;border-radius:7px;">रद्द करा</button>
          <button type="submit" class="btn btn-sm" style="background:#B23A48;color:#fff;border-radius:7px;font-weight:600;">🆘 त्वरित पाठवा</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
(function () {
  // SOS उघडताच Browser Geolocation विचारतो (ऐच्छिक) — नकार/अपयश आलं तरी पाठवता येतं
  var modal = document.getElementById('sosModal');
  if (!modal) return;
  modal.addEventListener('shown.bs.modal', function () {
    var st = document.getElementById('sosGeoStatus');
    if (!navigator.geolocation) { st.textContent = '📍 हा Browser ठिकाण देत नाही — तरीही सूचना जाईल.'; return; }
    st.textContent = '📍 ठिकाण शोधत आहे…';
    navigator.geolocation.getCurrentPosition(function (p) {
      document.getElementById('sosLat').value = p.coords.latitude.toFixed(7);
      document.getElementById('sosLng').value = p.coords.longitude.toFixed(7);
      st.textContent = '📍 ठिकाण मिळालं (±' + Math.round(p.coords.accuracy) + ' मी.) — नकाशा-Link सोबत जाईल.';
    }, function () {
      st.textContent = '📍 ठिकाण मिळालं नाही — तरीही सूचना जाईल (स्थान वर लिहा).';
    }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
  });
  document.getElementById('sosForm').addEventListener('submit', function (e) {
    var b = this.querySelector('button[type=submit]');
    if (b.disabled) { e.preventDefault(); return; }
    b.disabled = true; b.textContent = 'पाठवत आहे…';
  });
})();
</script>
<?php if (function_exists('recordLockActive') && recordLockActive()): ?>
<script>
/* 🔒 Edit / Delete फक्त Super Admin — त्यांची बटणं/Links इतरांना दिसत नाहीत (Server वरही अडवलं जातं) */
(function () {
  var SCRIPT = /^((edit|delete|update)\.php|[a-z0-9_-]+[-_](edit|delete|update)\.php|(edit|delete)[-_][a-z0-9_-]+\.php|toggle-active\.php)$/i;
  var ACT = /^(delete|remove|edit|update|deactivate|rename|rollback|clear|disable|unassign|end_allocation|trash|destroy)(_|$)/i;
  var ALLOW = { update_status: 1, update_task_status: 1, update_photo: 1 };
  function base(u) { try { var x = new URL(u, location.href); if (x.origin !== location.origin || /\/account\//.test(x.pathname)) return ''; return x.pathname.split('/').pop(); } catch (e) { return ''; } }
  function badAct(v) { v = String(v || ''); return !ALLOW[v.toLowerCase()] && ACT.test(v); }
  function hide(el) { if (el && !el.dataset.rl) { el.dataset.rl = 1; el.style.setProperty('display', 'none', 'important'); } }
  function scan(root) {
    root.querySelectorAll('a[href]').forEach(function (a) { if (SCRIPT.test(base(a.getAttribute('href')))) hide(a); });
    root.querySelectorAll('form').forEach(function (f) {
      var post = (f.getAttribute('method') || '').toLowerCase() === 'post';
      if (post && SCRIPT.test(base(f.getAttribute('action') || location.href))) { if (!/\/account\//.test(location.pathname)) hide(f); return; }
      if (!post) return;
      var h = f.querySelector('input[type=hidden][name=action],input[type=hidden][name=op],input[type=hidden][name=do]');
      if (h && badAct(h.value)) { hide(f); return; }
      if (f.querySelector('input[type=hidden][name^=delete]')) { hide(f); return; }
      f.querySelectorAll('button[name=action],input[type=submit][name=action]').forEach(function (b) { if (badAct(b.value)) hide(b); });
    });
  }
  function go() { scan(document); new MutationObserver(function (m) { m.forEach(function (r) { r.addedNodes.forEach(function (n) { if (n.nodeType === 1) scan(n.parentNode || n); }); }); }).observe(document.body, { childList: true, subtree: true }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', go); else go();
})();
</script>
<?php endif; ?>
<?php
// 🌙 दैनिक अहवाल — Cron नसला तरी: वेळ झाली असेल आणि अहवाल गेला नसेल तर पार्श्वभूमीत पाठवा (includes/report-autosend.php)
if (function_exists('currentUser') && currentUser() && isset($pdo)) {
    require_once __DIR__ . '/report-autosend.php';
    if (reportAutoSendDueDate($pdo, activeOrgId())): ?>
<script>
setTimeout(function () {
  try { fetch('<?= h($rootPath ?? '') ?>ajax-report-autosend.php', { method: 'POST', credentials: 'same-origin', keepalive: true, headers: { 'Accept': 'application/json' } }).catch(function () {}); } catch (e) {}
}, 1500);
</script>
<?php endif;
} ?>
</body>
</html>
