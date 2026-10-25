  </div>

  <div style="text-align:center;padding:18px 20px 24px;font-size:12px;color:var(--text-dim);border-top:1px solid var(--line);margin-top:10px;">
    <div style="font-size:12.5px;color:var(--ink);margin-bottom:3px;"><?= h(t('s68')) ?></div>
    <div>© <?= date('Y') ?> <?= h(activeOrgName()) ?><?= h(t('s69')) ?></div>
    <div style="margin-top:3px;">Developed by <strong style="color:var(--ink);">Rahul Pawar</strong></div>
  </div>
</div>

<div class="sidebar-backdrop" id="sidebarBackdrop"></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.3/js/bootstrap.bundle.min.js"></script>
<script src="<?= $rootPath ?? '' ?>assets/js/eowms-advance.js?v=2"></script>
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

// ---------------- Idle-timeout warning (security) ----------------
// Server auto-logs-out after 30 min of no requests. This client-side
// timer warns the user 2 minutes beforehand so they aren't surprised,
// and lets them stay logged in with one click instead of losing work.
(function () {
  var IDLE_LIMIT_MS = 28 * 60 * 1000;   // show warning at 28 minutes
  var COUNTDOWN_MS = 2 * 60 * 1000;     // 2-minute countdown after that
  var idleTimer, countdownTimer, countdownEnd;
  var rootPath = <?= json_encode($rootPath ?? '') ?>;

  var modal = document.createElement('div');
  modal.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(16,22,43,.55);z-index:9999;align-items:center;justify-content:center;';
  modal.innerHTML = '<div style="background:#fff;border-radius:14px;padding:26px 28px;max-width:340px;width:90%;text-align:center;font-family:\'Noto Sans Devanagari\',sans-serif;">' +
    '<div style="font-size:32px;margin-bottom:8px;">⏰</div>' +
    '<div style="font-weight:700;font-size:16px;color:#10162B;margin-bottom:8px;">तुम्ही निष्क्रिय आहात</div>' +
    '<div style="font-size:13px;color:#666B7D;margin-bottom:6px;">सुरक्षिततेसाठी <span id="idleCountdown">02:00</span> मिनिटांत आपोआप Logout होईल.</div>' +
    '<button id="idleStayBtn" style="background:#1B8A83;color:#fff;border:none;border-radius:9px;padding:10px 22px;font-size:14px;font-weight:600;margin-top:10px;cursor:pointer;">मी इथे आहे — सुरू ठेवा</button>' +
    '</div>';
  document.body.appendChild(modal);

  function showWarning() {
    modal.style.display = 'flex';
    countdownEnd = Date.now() + COUNTDOWN_MS;
    countdownTimer = setInterval(updateCountdown, 500);
  }
  function updateCountdown() {
    var remaining = Math.max(0, countdownEnd - Date.now());
    var mins = Math.floor(remaining / 60000);
    var secs = Math.floor((remaining % 60000) / 1000);
    document.getElementById('idleCountdown').textContent =
      String(mins).padStart(2, '0') + ':' + String(secs).padStart(2, '0');
    if (remaining <= 0) {
      clearInterval(countdownTimer);
      window.location.href = rootPath + 'logout.php';
    }
  }
  function resetIdleTimer() {
    clearTimeout(idleTimer);
    idleTimer = setTimeout(showWarning, IDLE_LIMIT_MS);
  }
  function stayLoggedIn() {
    modal.style.display = 'none';
    clearInterval(countdownTimer);
    fetch(rootPath + 'includes/keep-alive.php').catch(function () {});
    resetIdleTimer();
  }

  document.getElementById('idleStayBtn').addEventListener('click', stayLoggedIn);
  ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(function (evt) {
    document.addEventListener(evt, function () {
      if (modal.style.display !== 'flex') { resetIdleTimer(); }
    }, { passive: true });
  });
  resetIdleTimer();
})();

// ---------------- PWA: register service worker (Add to Home Screen) ----------------
if ('serviceWorker' in navigator) {
  var swRootPath = <?= json_encode($rootPath ?? '') ?>;
  window.addEventListener('load', function () {
    navigator.serviceWorker.register(swRootPath + 'sw.js', { scope: swRootPath || './' })
      .then(function (registration) {
        setupPushSubscription(registration, swRootPath);
      })
      .catch(function () {});
  });
}

// ---------------- Push Notification (App बंद असतानाही सूचना) ----------------
function urlBase64ToUint8Array(base64String) {
  var padding = '='.repeat((4 - base64String.length % 4) % 4);
  var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  var rawData = window.atob(base64);
  var outputArray = new Uint8Array(rawData.length);
  for (var i = 0; i < rawData.length; ++i) { outputArray[i] = rawData.charCodeAt(i); }
  return outputArray;
}

function setupPushSubscription(registration, rootPath) {
  if (!('PushManager' in window)) { return; } // हा Browser Push समर्थित करत नाही
  if (Notification.permission === 'denied') { return; } // User ने आधीच नाकारलं आहे

  fetch(rootPath + 'push-vapid-key.php')
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.publicKey) { return; } // Super Admin ने अजून Push Setup केलेलं नाही

      registration.pushManager.getSubscription().then(function (existingSub) {
        if (existingSub) { return; } // आधीच Subscribe केलेलं आहे

        // पहिल्यांदाच — थेट न विचारता, User च्या एखाद्या कृतीनंतर (इथे,
        // पान Load झाल्यावर काही सेकंदांनी) सौम्यपणे विचारतो.
        setTimeout(function () {
          if (Notification.permission === 'granted') {
            subscribeUser(registration, rootPath, data.publicKey);
          } else if (Notification.permission === 'default') {
            Notification.requestPermission().then(function (permission) {
              if (permission === 'granted') { subscribeUser(registration, rootPath, data.publicKey); }
            });
          }
        }, 4000);
      });
    })
    .catch(function () {});
}

function subscribeUser(registration, rootPath, publicKey) {
  registration.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(publicKey),
  }).then(function (subscription) {
    fetch(rootPath + 'push-subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(subscription),
    }).catch(function () {});
  }).catch(function () {});
}
</script>

<!-- Offline-first form queue (works alongside the service worker above) -->
<script src="<?= $rootPath ?? '' ?>assets/js/offline-queue.js"></script>

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
</body>
</html>
