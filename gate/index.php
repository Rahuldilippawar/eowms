<?php
// 🚪 Gate Scanner — Security Guard चा मोबाईल: Camera ने Digital ID / Card चा QR (किंवा Barcode) Scan → फोटो, नाव, आत / बाहेर नोंद
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/gate.php';
if (!canGate()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">Gate Scan ची परवानगी नाही.</div>'); }
$ready = gateReady($pdo);
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0B1020">
<meta name="robots" content="noindex">
<link rel="manifest" href="../manifest.php">
<title>Gate Scanner — E-Admin</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;}
  html,body{background:#0B1020;color:#E7EAF6;font-family:'Poppins','Noto Sans Devanagari','Segoe UI',sans-serif;min-height:100%;}
  .wrap{max-width:520px;margin:0 auto;padding:max(12px,env(safe-area-inset-top)) 12px 24px;}
  .top{display:flex;align-items:center;gap:8px;margin-bottom:10px;}
  .top a{color:#AEB6D6;text-decoration:none;font-size:13px;background:rgba(255,255,255,.07);padding:6px 11px;border-radius:99px;}
  .top h1{font-size:16px;font-weight:800;margin-right:auto;display:flex;align-items:center;gap:6px;}
  .stats{display:grid;grid-template-columns:repeat(4,1fr);gap:6px;margin-bottom:10px;}
  .stats div{background:#141B33;border-radius:12px;padding:8px 6px;text-align:center;} .stats b{display:block;font-size:20px;font-weight:800;} .stats span{font-size:10.5px;color:#9AA3C7;}
  .stats .in b{color:#4ADE80;} .stats .out b{color:#FDBA74;} .stats .ins b{color:#60A5FA;} .stats .bad b{color:#F87171;}
  .ctl{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px;}
  .seg{display:inline-flex;background:#141B33;border-radius:12px;padding:3px;gap:2px;}
  .seg button{border:0;background:transparent;color:#AEB6D6;font:600 13px inherit;font-family:inherit;padding:7px 12px;border-radius:9px;cursor:pointer;}
  .seg button.on{background:#fff;color:#0B1020;}
  .seg button.on[data-v=in]{background:#22C55E;color:#fff;} .seg button.on[data-v=out]{background:#F97316;color:#fff;}
  .gate{flex:1;min-width:120px;background:#141B33;border:1px solid #232B4D;color:#E7EAF6;border-radius:12px;padding:7px 11px;font:inherit;font-size:13px;}
  .cam{position:relative;border-radius:22px;overflow:hidden;background:#000;aspect-ratio:1/1;box-shadow:0 20px 50px -20px rgba(0,0,0,.8);}
  .cam video{width:100%;height:100%;object-fit:cover;display:block;}
  .frame{position:absolute;inset:14%;border-radius:20px;box-shadow:0 0 0 999px rgba(0,0,0,.35);pointer-events:none;}
  .frame::before,.frame::after{content:'';position:absolute;inset:0;border-radius:20px;border:3px solid transparent;
    background:linear-gradient(#fff,#fff) top left/28px 3px no-repeat,linear-gradient(#fff,#fff) top left/3px 28px no-repeat,linear-gradient(#fff,#fff) top right/28px 3px no-repeat,linear-gradient(#fff,#fff) top right/3px 28px no-repeat,
      linear-gradient(#fff,#fff) bottom left/28px 3px no-repeat,linear-gradient(#fff,#fff) bottom left/3px 28px no-repeat,linear-gradient(#fff,#fff) bottom right/28px 3px no-repeat,linear-gradient(#fff,#fff) bottom right/3px 28px no-repeat;}
  .laser{position:absolute;left:16%;right:16%;height:2px;background:linear-gradient(90deg,transparent,#60A5FA,transparent);box-shadow:0 0 12px #60A5FA;animation:scan 2.2s ease-in-out infinite;top:20%;}
  @keyframes scan{50%{top:78%;}}
  .camtools{position:absolute;bottom:10px;right:10px;display:flex;gap:6px;}
  .camtools button{width:42px;height:42px;border-radius:50%;border:0;background:rgba(0,0,0,.55);color:#fff;font-size:18px;cursor:pointer;}
  .start{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;text-align:center;padding:20px;background:#0E1530;}
  .start button{border:0;background:linear-gradient(90deg,#6D28D9,#2563EB);color:#fff;font:700 16px inherit;font-family:inherit;padding:14px 26px;border-radius:14px;cursor:pointer;}
  .start p{font-size:12.5px;color:#9AA3C7;line-height:1.6;}
  .res{position:absolute;inset:0;z-index:5;display:none;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:18px;color:#fff;animation:pop .25s ease-out;}
  @keyframes pop{from{transform:scale(.94);}to{transform:none;}}
  .res.show{display:flex;} .res.in{background:linear-gradient(160deg,#047857,#16A34A);} .res.out{background:linear-gradient(160deg,#C2410C,#F97316);}
  .res.warn{background:linear-gradient(160deg,#92400E,#D97706);} .res.bad{background:linear-gradient(160deg,#7F1D1D,#DC2626);}
  .res .ph{width:110px;height:126px;border-radius:16px;border:4px solid rgba(255,255,255,.9);overflow:hidden;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:42px;font-weight:800;}
  .res .ph img{width:100%;height:100%;object-fit:cover;}
  .res .dir{font-size:30px;font-weight:800;letter-spacing:.04em;margin-top:10px;line-height:1.1;}
  .res .nm{font-size:19px;font-weight:800;margin-top:6px;} .res .sub{font-size:13px;opacity:.9;margin-top:2px;} .res .tm{font-size:12.5px;opacity:.85;margin-top:6px;}
  .res .icon{font-size:56px;}
  .res .att{margin-top:8px;background:rgba(0,0,0,.22);border-radius:99px;padding:5px 14px;font-size:13.5px;font-weight:700;}
  .manual{display:flex;gap:6px;margin-top:10px;}
  .manual input{flex:1;background:#141B33;border:1px solid #232B4D;color:#E7EAF6;border-radius:12px;padding:10px 12px;font:inherit;font-size:14px;}
  .manual button{border:0;background:#2563EB;color:#fff;border-radius:12px;padding:0 16px;font:700 14px inherit;font-family:inherit;cursor:pointer;}
  .recent{margin-top:12px;background:#141B33;border-radius:16px;padding:10px 12px;}
  .recent h3{font-size:13px;font-weight:700;color:#AEB6D6;margin-bottom:4px;display:flex;justify-content:space-between;} .recent h3 a{color:#93C5FD;text-decoration:none;font-weight:600;}
  .row{display:flex;align-items:center;gap:9px;padding:7px 0;border-top:1px solid #1D2545;font-size:13px;}
  .row:first-of-type{border-top:0;} .row .av{width:32px;height:32px;border-radius:9px;background:#232B4D;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-weight:800;}
  .row .av img{width:100%;height:100%;object-fit:cover;} .row .t{margin-left:auto;color:#9AA3C7;font-size:12px;white-space:nowrap;} .row .n{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .tag{font-size:11px;font-weight:800;padding:2px 8px;border-radius:99px;white-space:nowrap;} .tag.in{background:#14532D;color:#86EFAC;} .tag.out{background:#7C2D12;color:#FDBA74;} .tag.bad{background:#7F1D1D;color:#FCA5A5;} .tag.warn{background:#78350F;color:#FCD34D;}
  .empty{font-size:12.5px;color:#7C86AE;padding:6px 0;}
  .err{background:#7F1D1D;color:#fff;border-radius:12px;padding:10px 12px;font-size:13px;margin-bottom:10px;}
</style>
</head>
<body>
<div class="wrap">
  <div class="top"><a href="../index.php">← E-Admin</a><h1>🚪 Gate Scanner</h1><a href="log.php">📋 अहवाल</a></div>
  <?php if (!$ready): ?><div class="err">⚠️ Database Update बाकी आहे — Super Admin ने Database Update चालवावं.</div><?php endif; ?>
  <div class="stats">
    <div class="in"><b id="sIn">0</b><span>आत आले</span></div>
    <div class="out"><b id="sOut">0</b><span>बाहेर गेले</span></div>
    <div class="ins"><b id="sInside">0</b><span>आत आहेत</span></div>
    <div class="bad"><b id="sBad">0</b><span>अवैध</span></div>
  </div>
  <div class="ctl">
    <div class="seg" id="mode"><button type="button" data-v="auto" class="on">Auto</button><button type="button" data-v="in">⬇ आत</button><button type="button" data-v="out">⬆ बाहेर</button></div>
    <input class="gate" id="gate" placeholder="Gate नाव (उदा. Main Gate)" maxlength="60">
  </div>
  <div class="cam" id="cam">
    <video id="video" playsinline muted></video>
    <div class="frame"></div><div class="laser" id="laser"></div>
    <div class="camtools"><button type="button" id="torch" title="Torch" style="display:none;">🔦</button><button type="button" id="flip" title="Camera बदला">🔄</button></div>
    <div class="start" id="start"><div style="font-size:44px;">📷</div><button type="button" id="startBtn">Scan सुरू करा</button>
      <p>कर्मचाऱ्याच्या मोबाईलवरचं <b>Digital ID</b> किंवा छापलेल्या <b>ID Card</b> चा QR चौकटीत धरा.<br>आत / बाहेर आपोआप ठरतं (Auto).</p></div>
    <div class="res" id="res"></div>
  </div>
  <form class="manual" id="manual" autocomplete="off"><input id="code" placeholder="Emp ID हाताने (QR खराब असेल तर)" maxlength="40"><button type="submit">नोंद</button></form>
  <div class="recent"><h3><span>अलीकडचे Scan</span><a href="log.php">सगळे →</a></h3><div id="recent"><div class="empty">अजून Scan नाही.</div></div></div>
</div>
<script>
(function () {
  var video = document.getElementById('video'), res = document.getElementById('res'), startEl = document.getElementById('start');
  var mode = 'auto', facing = 'environment', stream = null, detector = null, jsqr = false, busy = false, last = { t: '', at: 0 }, hideT = null, canvas = document.createElement('canvas'), ctx = canvas.getContext('2d', { willReadFrequently: true });
  var gateIn = document.getElementById('gate');
  try { gateIn.value = localStorage.getItem('eowmsGate') || ''; } catch (e) {}
  gateIn.addEventListener('change', function () { try { localStorage.setItem('eowmsGate', gateIn.value.trim()); } catch (e) {} });
  document.querySelectorAll('#mode button').forEach(function (b) { b.onclick = function () { mode = b.dataset.v; document.querySelectorAll('#mode button').forEach(function (x) { x.classList.toggle('on', x === b); }); }; });

  // 🔊 आवाज + कंप
  var ac = null;
  function beep(ok) {
    try { ac = ac || new (window.AudioContext || window.webkitAudioContext)(); var o = ac.createOscillator(), g = ac.createGain(); o.connect(g); g.connect(ac.destination);
      o.frequency.value = ok ? 1320 : 220; o.type = ok ? 'sine' : 'square'; g.gain.setValueAtTime(.18, ac.currentTime); g.gain.exponentialRampToValueAtTime(.001, ac.currentTime + (ok ? .18 : .5)); o.start(); o.stop(ac.currentTime + (ok ? .18 : .5)); } catch (e) {}
    if (navigator.vibrate) navigator.vibrate(ok ? 80 : [200, 80, 200]);
  }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function stats(s) { if (!s) return; document.getElementById('sIn').textContent = s.in; document.getElementById('sOut').textContent = s.out; document.getElementById('sInside').textContent = s.inside; document.getElementById('sBad').textContent = s.bad; }

  function show(d) {
    var tone = d.result === 'ok' ? d.direction : (d.result === 'duplicate' || d.result === 'expired' ? 'warn' : 'bad'), u = d.user;
    var head = d.result === 'ok' ? (d.direction === 'in' ? '⬇ आत — स्वागत' : '⬆ बाहेर — शुभ दिवस') : (d.result === 'duplicate' ? '↺ आधीच नोंद' : '⛔ ' + d.label);
    var ph = u ? '<div class="ph">' + (u.photo ? '<img src="' + esc(u.photo) + '" alt="">' : esc((u.name || '?').charAt(0))) + '</div>' : '<div class="icon">' + (tone === 'warn' ? '⏱' : '⛔') + '</div>';
    res.className = 'res show ' + tone;
    res.innerHTML = ph + '<div class="dir">' + esc(head) + '</div>' + (u ? '<div class="nm">' + esc(u.name) + '</div><div class="sub">' + esc([u.designation, u.dept].filter(Boolean).join(' · ')) + '</div><div class="sub">' + esc(u.code) + '</div>' : '<div class="sub">' + esc(d.label) + '</div>') +
      (d.att ? '<div class="att">' + esc(d.att) + '</div>' : '') + '<div class="tm">' + esc(d.time) + ' · ' + esc(d.methodLabel) + '</div>';
    beep(d.result === 'ok'); stats(d.stats);
    var list = document.getElementById('recent'); if (list.querySelector('.empty')) list.innerHTML = '';
    var tag = d.result === 'ok' ? '<span class="tag ' + d.direction + '">' + (d.direction === 'in' ? 'आत' : 'बाहेर') + '</span>' : '<span class="tag ' + tone + '">' + esc(d.result === 'duplicate' ? 'आधीच' : 'अवैध') + '</span>';
    var row = document.createElement('div'); row.className = 'row';
    row.innerHTML = '<div class="av">' + (u && u.photo ? '<img src="' + esc(u.photo) + '" alt="">' : esc(u ? u.name.charAt(0) : '?')) + '</div><div class="n">' + esc(u ? u.name : d.label) + '</div>' + tag + '<span class="t">' + esc(d.time) + '</span>';
    list.insertBefore(row, list.firstChild); while (list.children.length > 10) list.removeChild(list.lastChild);
    clearTimeout(hideT); hideT = setTimeout(function () { res.className = 'res'; busy = false; }, d.result === 'ok' ? 2200 : 3200);
  }
  function send(text) {
    if (busy) return; busy = true;
    fetch('scan.php', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify({ q: text, mode: mode, gate: gateIn.value.trim() }) })
      .then(function (r) { return r.json(); }).then(function (d) { if (!d.ok) { show({ result: 'invalid', label: d.message || 'त्रुटी', time: '', methodLabel: '' }); return; } show(d); })
      .catch(function () { show({ result: 'invalid', label: 'Internet / Server त्रुटी — पुन्हा Scan करा', time: '', methodLabel: '' }); });
  }
  function found(text) {
    var n = Date.now(); if (!text || (text === last.t && n - last.at < 4000)) return; last = { t: text, at: n }; send(text);
  }
  function loop() {
    if (!stream) return;
    if (!busy && video.readyState >= 2) {
      if (detector) {
        detector.detect(video).then(function (c) { if (c && c[0]) found(c[0].rawValue); }).catch(function () {});
      } else if (window.jsQR) {
        var w = video.videoWidth, h = video.videoHeight, s = Math.min(w, h, 720) / Math.min(w, h); canvas.width = w * s; canvas.height = h * s;
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        var img = ctx.getImageData(0, 0, canvas.width, canvas.height), c = window.jsQR(img.data, img.width, img.height, { inversionAttempts: 'dontInvert' });
        if (c && c.data) found(c.data);
      }
    }
    setTimeout(function () { requestAnimationFrame(loop); }, 120);
  }
  function loadJsQR(cb) { if (window.jsQR) return cb(); var s = document.createElement('script'); s.src = '../assets/js/vendor/jsQR.js?v=1.4.0'; s.onload = cb; document.head.appendChild(s); }
  function start() {
    if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
    navigator.mediaDevices.getUserMedia({ video: { facingMode: facing, width: { ideal: 1280 }, height: { ideal: 1280 } }, audio: false }).then(function (s) {
      stream = s; video.srcObject = s; video.play(); startEl.style.display = 'none';
      var tr = s.getVideoTracks()[0], caps = tr.getCapabilities ? tr.getCapabilities() : {};
      var tb = document.getElementById('torch'); tb.style.display = caps.torch ? '' : 'none'; var on = false; tb.onclick = function () { on = !on; tr.applyConstraints({ advanced: [{ torch: on }] }).catch(function () {}); };
      var go = function () { loop(); };
      if ('BarcodeDetector' in window) {
        window.BarcodeDetector.getSupportedFormats().then(function (f) { if (f.indexOf('qr_code') >= 0) { detector = new window.BarcodeDetector({ formats: f.filter(function (x) { return ['qr_code', 'code_39', 'code_128'].indexOf(x) >= 0; }) }); go(); } else loadJsQR(go); }).catch(function () { loadJsQR(go); });
      } else loadJsQR(go);
    }).catch(function () {
      startEl.style.display = ''; startEl.querySelector('p').innerHTML = '⚠️ Camera सुरू झाला नाही — Browser ला Camera ची परवानगी द्या (HTTPS वर). तोपर्यंत खाली Emp ID हाताने टाका.';
    });
  }
  document.getElementById('startBtn').onclick = function () { try { ac = ac || new (window.AudioContext || window.webkitAudioContext)(); } catch (e) {} start(); };
  document.getElementById('flip').onclick = function () { facing = facing === 'environment' ? 'user' : 'environment'; start(); };
  document.getElementById('manual').onsubmit = function (e) { e.preventDefault(); var c = document.getElementById('code'); if (c.value.trim()) { busy = false; send(c.value.trim()); c.value = ''; } };
  res.onclick = function () { clearTimeout(hideT); res.className = 'res'; busy = false; };
  fetch('scan.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) { stats(d.stats); }).catch(function () {});
  setInterval(function () { if (document.hidden) return; fetch('scan.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) { stats(d.stats); }).catch(function () {}); }, 30000);
  if ('wakeLock' in navigator) navigator.wakeLock.request('screen').catch(function () {});
})();
</script>
</body>
</html>
