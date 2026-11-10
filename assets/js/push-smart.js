// 🔔 Mobile App सारख्या सूचना — Push Permission (वापरकर्त्याच्या Click नंतरच), Subscription नेहमी
// याच Login शी जोडलेली, उघड्या App मध्ये Toast + आवाज, Bell व App-Icon वर आकडा, Tab-Title मध्ये (n).
(function () {
  'use strict';
  var T = function (s) { return window.eowmsT ? window.eowmsT(s) : s; };
  var ls = { get: function (k) { try { return localStorage.getItem(k); } catch (e) { return null; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var root = '', reg = null, vapid = null, lastId = 0, firstPoll = true, pollTimer = null;
  var isIOS = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
  var supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  function b64(s) {
    var pad = '='.repeat((4 - s.length % 4) % 4), raw = atob((s + pad).replace(/-/g, '+').replace(/_/g, '/')), out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }
  function saveSub(sub) {
    return fetch(root + 'push-subscribe.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub) })
      .then(function (r) { return r.json(); }).catch(function () { return {}; });
  }
  function subscribe() {
    if (!reg || !vapid) return Promise.reject(new Error('push-not-ready'));
    return reg.pushManager.getSubscription().then(function (s) {
      return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(vapid) });
    }).then(function (s) { return saveSub(s).then(function () { return s; }); });
  }

  // वापरकर्त्याने "चालू करा" दाबल्यावर — Browser ची Permission मागतो (Click नंतरच — Chrome शांतपणे अडवत नाही)
  function enable() {
    if (!supported) return Promise.resolve('unsupported');
    return Notification.requestPermission().then(function (p) {
      if (p !== 'granted') return p;
      return subscribe().then(function () { toast(T('सूचना चालू झाल्या'), T('आता App बंद असतानाही Mobile वर सूचना येतील'), null, true); hideBanner(); return 'granted'; })
        .catch(function () { return 'error'; });
    });
  }
  window.eowmsPushEnable = enable;
  window.eowmsPushState = function () {
    return { supported: supported, permission: supported ? Notification.permission : 'unsupported', ios: isIOS, standalone: standalone, configured: !!vapid };
  };

  // ---- "सूचना चालू करा" पट्टी ----
  var banner = null;
  function hideBanner() { if (banner) { banner.remove(); banner = null; } }
  function showBanner(kind) {
    if (banner || ls.get('eowmsPushAsk') && Date.now() - +ls.get('eowmsPushAsk') < 5 * 864e5) return;
    banner = document.createElement('div');
    banner.className = 'pn-banner';
    var ios = kind === 'ios';
    banner.innerHTML = '<div class="pn-ic">🔔</div><div class="pn-tx"><b>' + T(ios ? 'iPhone वर सूचनांसाठी App Install करा' : 'Mobile वर सूचना मिळवा') + '</b><span>'
      + T(ios ? 'Share ⬆️ → "Add to Home Screen" — मग App उघडून सूचना चालू करा' : 'काम, पत्र, संदेश, मंजुरी — App बंद असतानाही लगेच कळेल') + '</span></div>'
      + (ios ? '' : '<button type="button" class="pn-on">' + T('चालू करा') + '</button>') + '<button type="button" class="pn-x" aria-label="' + T('नंतर') + '">✕</button>';
    document.body.appendChild(banner);
    var on = banner.querySelector('.pn-on');
    if (on) on.onclick = function () { on.disabled = true; enable().then(function (r) { if (r !== 'granted') { on.disabled = false; if (r === 'denied') { hideBanner(); } } }); };
    banner.querySelector('.pn-x').onclick = function () { ls.set('eowmsPushAsk', String(Date.now())); hideBanner(); };
  }

  // ---- उघड्या App मधला Toast ----
  var box = null;
  function toast(title, msg, url, ok) {
    if (!box) { box = document.createElement('div'); box.className = 'pn-toasts'; document.body.appendChild(box); }
    var el = document.createElement(url ? 'a' : 'div');
    el.className = 'pn-toast' + (ok ? ' ok' : '');
    if (url) el.href = root + url;
    el.innerHTML = '<div class="pn-ic">' + (ok ? '✅' : '🔔') + '</div><div class="pn-tx"><b></b><span></span></div><button type="button" class="pn-x">✕</button>';
    el.querySelector('b').textContent = title;
    el.querySelector('span').textContent = msg || '';
    el.querySelector('.pn-x').onclick = function (e) { e.preventDefault(); e.stopPropagation(); el.remove(); };
    box.prepend(el);
    setTimeout(function () { el.classList.add('out'); setTimeout(function () { el.remove(); }, 400); }, 7000);
  }
  function ding() {
    if (ls.get('eowmsPushMute') === '1') return;
    try {
      var A = window.AudioContext || window.webkitAudioContext; if (!A) return;
      var c = new A(), t = c.currentTime;
      [880, 1320].forEach(function (f, i) {
        var o = c.createOscillator(), g = c.createGain(); o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t + i * 0.16); g.gain.exponentialRampToValueAtTime(0.18, t + i * 0.16 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t + i * 0.16 + 0.3);
        o.connect(g); g.connect(c.destination); o.start(t + i * 0.16); o.stop(t + i * 0.16 + 0.32);
      });
      setTimeout(function () { c.close(); }, 900);
    } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([120, 60, 120]); } catch (e) {}
  }

  // ---- Bell / Title / App-Icon आकडा ----
  var baseTitle = document.title.replace(/^\(\d+\+?\)\s*/, '');
  function setCount(n) {
    var bell = document.querySelector('.fa-bell');
    var btn = bell && bell.closest('.icon-btn');
    if (btn) {
      var dot = btn.querySelector('.badge-dot'), num = btn.querySelector('.pn-num');
      if (n > 0) {
        if (!dot) { dot = document.createElement('span'); dot.className = 'badge-dot'; btn.appendChild(dot); }
        if (!num) { num = document.createElement('span'); num.className = 'pn-num'; btn.appendChild(num); }
        num.textContent = n > 99 ? '99+' : n;
      } else { if (dot) dot.remove(); if (num) num.remove(); }
    }
    document.title = (n > 0 ? '(' + (n > 99 ? '99+' : n) + ') ' : '') + baseTitle;
    try { if ('setAppBadge' in navigator) { if (n > 0) navigator.setAppBadge(n); else navigator.clearAppBadge(); } } catch (e) {}
  }

  function poll() {
    if (document.hidden && !firstPoll) return;
    fetch(root + 'notifications/poll.php?after=' + lastId, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j) return;
        setCount(j.unread || 0);
        if (!firstPoll && j.items && j.items.length) {
          j.items.slice(0, 3).reverse().forEach(function (it) { toast(it.title, it.message, it.url); });
          ding();
        }
        lastId = Math.max(lastId, j.last || 0);
        firstPoll = false;
      }).catch(function () {});
  }

  function start(rootPath) {
    root = rootPath || '';
    poll();
    pollTimer = setInterval(poll, 30000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });
    if (!('serviceWorker' in navigator)) return;
    navigator.serviceWorker.addEventListener('message', function (e) { if (e.data && e.data.type === 'eowms-push') poll(); });
    navigator.serviceWorker.ready.then(function (r) {
      reg = r;
      if (!supported) { if (isIOS && !standalone) showBanner('ios'); return; }
      return fetch(root + 'push-vapid-key.php', { credentials: 'same-origin' }).then(function (x) { return x.json(); }).then(function (k) {
        vapid = k.publicKey || null;
        if (!vapid) return;   // Super Admin ने Push Setup केलेलं नाही — फक्त App मधल्या सूचना
        if (Notification.permission === 'granted') {
          // आधीची Subscription नेहमी याच Login शी पुन्हा जोडतो (एकाच फोनवर दुसरा कर्मचारी Login झाला तरी बरोबर व्यक्तीला)
          var key = 'eowmsPushSync';
          var who = String(window.eowmsUid || '');
          if (sessionStorage.getItem(key) !== who + '|1') {
            subscribe().then(function () { try { sessionStorage.setItem(key, who + '|1'); } catch (e) {} }).catch(function () {});
          }
        } else if (Notification.permission === 'default') {
          setTimeout(function () { showBanner('ask'); }, 2500);
        }
      });
    }).catch(function () {});
  }
  window.eowmsPushStart = start;
})();
