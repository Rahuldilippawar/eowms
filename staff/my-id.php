<?php
// 📱 माझं Digital ID — मोबाईलमध्ये ओळखपत्र. QR दर 30 सेकंदाला बदलतो (Screenshot / Forward चालत नाही); Gate वर Scan करा.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/gate.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
$me = currentUser();
$orgId = (int)$me['organization_id'];
$emp = gateUserRow($pdo, $orgId, (int)$me['id']);
if (!$emp) { http_response_code(404); die('खातं सापडलं नाही.'); }
$org = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$org = $org->fetch() ?: [];
$c = idCardData($emp, $org);

// Live QR (JSON) — पान दर 30 सेकंदाला मागतं
if (isset($_GET['token'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $win = gateLiveWindow();
    echo json_encode(['svg' => idCardQrSvg(gateLiveUrl($orgId, $emp['id'], $win)), 'win' => $win, 'step' => GATE_LIVE_STEP, 'now' => time()]);
    exit;
}
$win = gateLiveWindow();
$todayLogs = [];
if (gateReady($pdo)) {
    $st = $pdo->prepare("SELECT direction, gate, created_at FROM gate_logs WHERE organization_id = ? AND user_id = ? AND result = 'ok' AND created_at >= CURDATE() ORDER BY id DESC LIMIT 6");
    $st->execute([$orgId, $emp['id']]);
    $todayLogs = $st->fetchAll();
}
$myAtt = null;
try {
    $st = $pdo->prepare("SELECT status, first_punch_time, last_punch_time, late_minutes, worked_minutes FROM staff_attendance WHERE user_id = ? AND attendance_date = CURDATE()");
    $st->execute([$emp['id']]);
    $myAtt = $st->fetch() ?: null;
} catch (PDOException $e) { $myAtt = null; }
$initials = mb_strtoupper(mb_substr(trim($c['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($c['name']), $m) ? $m[1] : ''));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#3B1D5C">
<meta name="robots" content="noindex">
<link rel="manifest" href="../manifest.php">
<title>Digital ID — <?= h($c['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&family=JetBrains+Mono:wght@500;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;margin:0;}
  body{font-family:'Poppins','Noto Sans Devanagari','Segoe UI',sans-serif;min-height:100vh;color:#1A1D29;padding:max(14px,env(safe-area-inset-top)) 14px 24px;
    background:radial-gradient(circle at 15% 0%,rgba(123,63,158,.6),transparent 50%),radial-gradient(circle at 100% 100%,rgba(240,147,43,.4),transparent 50%),#14112A;}
  .bar{max-width:400px;margin:0 auto 10px;display:flex;align-items:center;justify-content:space-between;color:#D9D3EC;font-size:13px;}
  .bar a,.bar button{color:#D9D3EC;text-decoration:none;background:rgba(255,255,255,.08);border:0;border-radius:99px;padding:6px 12px;font:inherit;font-size:12.5px;cursor:pointer;}
  .card{max-width:400px;margin:0 auto;background:#fff;border-radius:24px;overflow:hidden;position:relative;box-shadow:0 30px 70px -20px rgba(0,0,0,.7);}
  .holo{position:absolute;inset:0;pointer-events:none;background:linear-gradient(115deg,transparent 30%,rgba(255,255,255,.55) 45%,rgba(255,215,140,.35) 50%,transparent 65%);background-size:250% 100%;animation:sheen 4.5s linear infinite;mix-blend-mode:soft-light;z-index:3;}
  @keyframes sheen{from{background-position:120% 0;}to{background-position:-120% 0;}}
  .head{position:relative;padding:18px 18px 64px;text-align:center;color:#fff;overflow:hidden;
    background:radial-gradient(circle at 85% -10%,rgba(255,255,255,.2),transparent 45%),linear-gradient(150deg,#5B2A86 0%,#7B3F9E 60%,#9548B5 100%);}
  .head::after{content:'';position:absolute;left:-10%;right:-10%;bottom:-36px;height:72px;background:#fff;border-radius:50%;box-shadow:0 -4px 0 #F0932B;}
  .logo{position:absolute;top:16px;left:14px;width:46px;height:46px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;box-shadow:0 0 0 2px #F0932B;}
  .logo img{width:100%;height:100%;object-fit:contain;} .logo span{font-weight:800;color:#7B3F9E;font-size:14px;}
  .mantra{font-size:11.5px;color:#FFE2B8;font-weight:600;letter-spacing:.06em;}
  .org{font-size:11px;opacity:.9;margin:2px 44px 0;line-height:1.3;}
  .campus{font-size:15px;font-weight:800;text-transform:uppercase;line-height:1.15;margin:4px 30px 0;letter-spacing:.01em;}
  .live{position:absolute;top:14px;right:14px;display:flex;align-items:center;gap:5px;background:rgba(0,0,0,.25);border-radius:99px;padding:4px 9px;font-size:10.5px;font-weight:800;letter-spacing:.1em;}
  .live i{width:8px;height:8px;border-radius:50%;background:#22C55E;box-shadow:0 0 0 0 rgba(34,197,94,.7);animation:ping 1.4s infinite;}
  @keyframes ping{70%{box-shadow:0 0 0 8px rgba(34,197,94,0);}100%{box-shadow:0 0 0 0 rgba(34,197,94,0);}}
  .photo{position:relative;z-index:2;width:118px;height:136px;margin:-56px auto 0;border-radius:18px;border:4px solid #fff;box-shadow:0 0 0 3px #F0932B,0 14px 28px -12px rgba(60,20,90,.6);overflow:hidden;background:linear-gradient(150deg,#F4ECF8,#FCE9DE);display:flex;align-items:center;justify-content:center;}
  .photo img{width:100%;height:100%;object-fit:cover;} .photo span{font-size:44px;font-weight:800;background:linear-gradient(150deg,#7B3F9E,#D9530F);-webkit-background-clip:text;background-clip:text;color:transparent;}
  .name{text-align:center;font-size:21px;font-weight:800;color:#C4293D;margin:12px 16px 0;line-height:1.2;}
  .desig{display:table;margin:6px auto 0;padding:4px 14px;border-radius:99px;background:linear-gradient(90deg,#D9530F,#F0932B);color:#fff;font-size:12.5px;font-weight:700;}
  .grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:14px 16px 0;}
  .grid div{background:#F8F5FB;border-radius:12px;padding:8px 10px;} .grid span{display:block;font-size:10.5px;color:#6B7186;font-weight:600;text-transform:uppercase;letter-spacing:.06em;}
  .grid b{font-size:14px;} .mono{font-family:'JetBrains Mono',monospace;}
  .qrwrap{margin:16px auto 0;width:236px;position:relative;}
  .ring{position:absolute;inset:-8px;width:calc(100% + 16px);height:calc(100% + 16px);transform:rotate(-90deg);}
  .ring circle{fill:none;stroke-width:5;} .ring .bg{stroke:#EEE8F3;} .ring .fg{stroke:#7B3F9E;stroke-linecap:round;transition:stroke-dashoffset 1s linear,stroke .3s;}
  .qr{background:#fff;border-radius:16px;padding:14px;box-shadow:0 0 0 1px #EEE8F3;}
  .qr svg{width:100%;height:auto;display:block;aspect-ratio:1;} .qr svg .dark{fill:#1E1530;}
  .qr.fade{opacity:.25;transition:opacity .2s;}
  .tick{text-align:center;margin-top:12px;font-size:12.5px;color:#6B7186;} .tick b{color:#1A1D29;}
  .clock{text-align:center;font-family:'JetBrains Mono',monospace;font-weight:700;font-size:22px;letter-spacing:.06em;color:#3B1D5C;margin-top:4px;}
  .ok{display:flex;align-items:center;justify-content:center;gap:6px;margin:10px 16px 0;padding:8px;border-radius:12px;background:#ECFDF5;color:#15803D;font-weight:700;font-size:13px;}
  .ok.off{background:#FEF2F2;color:#B91C1C;}
  .band{height:8px;margin-top:16px;display:flex;} .band i{flex:1;background:#F0932B;} .band i:nth-child(2){flex:1.4;background:#7B3F9E;}
  .logs{max-width:400px;margin:14px auto 0;background:rgba(255,255,255,.07);border-radius:16px;padding:12px 14px;color:#E9E4F5;}
  .logs h3{font-size:13px;font-weight:700;margin-bottom:6px;} .logs div{display:flex;justify-content:space-between;font-size:13px;padding:5px 0;border-top:1px dashed rgba(255,255,255,.12);}
  .in{color:#4ADE80;font-weight:700;} .out{color:#FDBA74;font-weight:700;}
  .hint{max-width:400px;margin:12px auto 0;color:#A9A2C2;font-size:12px;text-align:center;line-height:1.6;}
  .offline{display:none;max-width:400px;margin:10px auto 0;background:#7F1D1D;color:#fff;border-radius:12px;padding:8px 12px;font-size:12.5px;text-align:center;}
</style>
</head>
<body>
<div class="bar"><a href="../index.php">← E-Admin</a><span>📱 Digital ID</span><button type="button" id="fsBtn">⛶ मोठं</button></div>
<div class="offline" id="offline">⚠️ Internet नाही — QR बदलत नाही. Internet सुरू करा.</div>
<div class="card">
  <div class="holo"></div>
  <div class="head">
    <div class="logo"><?php if ($c['orgLogo']): ?><img src="../<?= h($c['orgLogo']) ?>" alt=""><?php else: ?><span><?= h(mb_substr($c['org'], 0, 2)) ?></span><?php endif; ?></div>
    <div class="live"><i></i>LIVE</div>
    <div class="mantra">॥ आत्मा मालिक ॥</div>
    <div class="org"><?= h($c['org']) ?></div>
    <div class="campus"><?= h($c['campus']) ?></div>
  </div>
  <div class="photo"><?php if ($c['photo'] && eowmsPhotoExists($c['photo'])): ?><img src="../<?= h($c['photo']) ?>" alt=""><?php else: ?><span><?= h($initials) ?></span><?php endif; ?></div>
  <div class="name"><?= h($c['name']) ?></div>
  <?php if ($c['designation'] !== '—'): ?><div class="desig"><?= h($c['designation']) ?></div><?php endif; ?>
  <div class="grid">
    <div><span>Emp. ID</span><b class="mono"><?= h($c['code']) ?></b></div>
    <div><span>विभाग</span><b><?= h($c['dept']) ?></b></div>
  </div>
  <div class="ok<?= $c['active'] ? '' : ' off' ?>"><?= $c['active'] ? '✓ Active कर्मचारी' : '⛔ Inactive' ?></div>
  <div class="qrwrap">
    <svg class="ring" viewBox="0 0 100 100" aria-hidden="true"><circle class="bg" cx="50" cy="50" r="47"/><circle class="fg" id="ring" cx="50" cy="50" r="47" pathLength="100" stroke-dasharray="100" stroke-dashoffset="0"/></svg>
    <div class="qr" id="qr"><?= idCardQrSvg(gateLiveUrl($orgId, $emp['id'], $win)) ?></div>
  </div>
  <div class="tick">QR बदलेल <b id="left"><?= GATE_LIVE_STEP - (time() % GATE_LIVE_STEP) ?></b> सेकंदात · Gate वर Scan करा</div>
  <div class="clock" id="clock"><?= date('h:i:s A') ?></div>
  <div class="band"><i></i><i></i><i></i></div>
</div>
<?php if ($myAtt || $todayLogs): ?>
<div class="logs"><?php if ($myAtt): $st0 = ['present' => '✓ हजर', 'half_day' => '◐ अर्धा दिवस', 'absent' => '✕ गैरहजर', 'holiday' => 'सुट्टी', 'on_leave' => 'रजा'][$myAtt['status']] ?? $myAtt['status']; ?>
  <h3>🕘 आजची हजेरी: <span class="in"><?= h($st0) ?></span></h3>
  <div><span>आलो</span><b><?= $myAtt['first_punch_time'] ? date('h:i A', strtotime($myAtt['first_punch_time'])) : '—' ?><?= (int)$myAtt['late_minutes'] > 0 ? ' <span class="out">· उशीर ' . ((int)$myAtt['late_minutes'] >= 60 ? intdiv((int)$myAtt['late_minutes'], 60) . ' ता ' . ((int)$myAtt['late_minutes'] % 60) . ' मि' : (int)$myAtt['late_minutes'] . ' मि') . '</span>' : '' ?></b></div>
  <?php if ($myAtt['last_punch_time']): ?><div><span>गेलो</span><b><?= date('h:i A', strtotime($myAtt['last_punch_time'])) ?> · काम <?= intdiv((int)$myAtt['worked_minutes'], 60) ?> ता <?= (int)$myAtt['worked_minutes'] % 60 ?> मि</b></div><?php endif; ?>
  <?php endif; ?>
  <?php if ($todayLogs): ?><h3 style="margin-top:<?= $myAtt ? '10px' : '0' ?>;">🚪 आजची ये-जा</h3>
  <?php foreach ($todayLogs as $l): ?><div><span class="<?= $l['direction'] === 'in' ? 'in' : 'out' ?>"><?= $l['direction'] === 'in' ? '⬇ आत' : '⬆ बाहेर' ?></span><span><?= h($l['gate'] ?: 'Gate') ?></span><b><?= date('h:i A', strtotime($l['created_at'])) ?></b></div><?php endforeach; ?><?php endif; ?>
</div>
<?php endif; ?>
<p class="hint">हा QR दर <?= GATE_LIVE_STEP ?> सेकंदाला बदलतो — Screenshot किंवा Forward केलेला फोटो Gate वर चालत नाही. Home Screen वर ठेवण्यासाठी Browser मेनू → "Add to Home screen".</p>
<script>
(function () {
  var tz = <?= json_encode(date_default_timezone_get()) ?>, step = <?= GATE_LIVE_STEP ?>, win = <?= $win ?>, skew = <?= time() ?> - Date.now() / 1000, busy = false;
  var qr = document.getElementById('qr'), ring = document.getElementById('ring'), left = document.getElementById('left'), clock = document.getElementById('clock'), off = document.getElementById('offline');
  function now() { return Date.now() / 1000 + skew; }
  function refresh() {
    if (busy) return; busy = true; qr.classList.add('fade');
    fetch('my-id.php?token=1', { cache: 'no-store', credentials: 'same-origin' }).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (d) {
      qr.innerHTML = d.svg; win = d.win; skew = d.now - Date.now() / 1000; off.style.display = 'none';
    }).catch(function () { off.style.display = 'block'; }).finally(function () { busy = false; qr.classList.remove('fade'); });
  }
  function tick() {
    var t = now(), rem = step - (t % step);
    left.textContent = Math.ceil(rem);
    ring.style.strokeDashoffset = String(100 - rem / step * 100);
    ring.style.stroke = rem < 6 ? '#D9530F' : '#7B3F9E';
    clock.textContent = new Date(t * 1000).toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true, timeZone: tz }).toUpperCase();
    if (Math.floor(t / step) !== win) refresh();
  }
  setInterval(tick, 250); tick();
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
  // स्क्रीन बंद होऊ नये (Gate वर दाखवताना)
  var lock = null; function wake() { if ('wakeLock' in navigator) navigator.wakeLock.request('screen').then(function (l) { lock = l; }).catch(function () {}); }
  wake(); document.addEventListener('visibilitychange', function () { if (!document.hidden) wake(); });
  document.getElementById('fsBtn').onclick = function () { var e = document.documentElement; (e.requestFullscreen || e.webkitRequestFullscreen || function () {}).call(e); };
})();
</script>
</body>
</html>
