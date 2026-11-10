<?php
$pageTitle = 'सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

try {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 100");
    $stmt->execute([$user['id']]);
    $notifications = $stmt->fetchAll();
} catch (PDOException $e) {
    $notifications = [];
}
$hasUnread = (bool)array_filter($notifications, fn($n) => !$n['is_read']);
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s93')) ?></div>
  <?php if ($hasUnread): ?>
    <form method="POST" action="mark-all-read.php">
      <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;"><?= h(t('s293')) ?></button>
    </form>
  <?php endif; ?>
</div>

<?php
$myDevices = 0;
try { $dv = $pdo->prepare("SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?"); $dv->execute([$user['id']]); $myDevices = (int)$dv->fetchColumn(); } catch (PDOException $e) {}
$pushReady = notifyPushConfigured(activeOrgId());
?>
<div class="panel mt-2 pn-set">
  <div class="pn-row">
    <div class="pn-st" id="pnState"><span class="pn-dot"></span><div><b id="pnStateT">तपासत आहे…</b><small id="pnStateS"></small></div></div>
    <div class="pn-btns">
      <button type="button" class="btn btn-sm d-none" id="pnEnable" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;">🔔 सूचना चालू करा</button>
      <button type="button" class="btn btn-sm" id="pnTest" style="border:1px solid var(--line);border-radius:9px;">📨 Test सूचना पाठवा</button>
      <label class="pn-mute"><input type="checkbox" id="pnMute"> App उघडं असताना आवाज बंद</label>
    </div>
  </div>
  <div class="pn-meta"><?= $pushReady ? '📱 तुमच्या खात्याशी जोडलेली उपकरणं: <b>' . $myDevices . '</b>' : '⚠️ Super Admin ने अजून Push Setup केलेलं नाही — सध्या फक्त App उघडं असताना सूचना दिसतील' ?></div>
  <details class="pn-help"><summary>सूचना येत नाहीत? — मदत</summary>
    <ul>
      <li><b>Android (Chrome):</b> ⋮ → "Install app / Add to Home screen" करा; मग App उघडून "सूचना चालू करा". Settings → Apps → Chrome / E-Admin → Notifications चालू व Battery → "Unrestricted".</li>
      <li><b>iPhone (iOS 16.4+):</b> Safari मधून Share ⬆️ → "Add to Home Screen"; Home Screen वरच्या App मधूनच Login करून "सूचना चालू करा".</li>
      <li><b>चुकून "Block" केलं असेल:</b> Address-bar मधील 🔒 → Site settings → Notifications → Allow.</li>
      <li>"📨 Test सूचना" दाबा — काही सेकंदांत फोनवर सूचना यायला हवी.</li>
    </ul>
  </details>
</div>
<style>
.pn-set{padding:14px 18px;} .pn-row{display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;align-items:center;}
.pn-st{display:flex;gap:10px;align-items:center;} .pn-st b{display:block;font-size:14px;} .pn-st small{display:block;font-size:12px;color:var(--text-dim);}
.pn-dot{width:12px;height:12px;border-radius:50%;background:#9CA3AF;flex:none;} .pn-st.on .pn-dot{background:#16A34A;box-shadow:0 0 0 4px #DCFCE7;} .pn-st.off .pn-dot{background:var(--rose);box-shadow:0 0 0 4px #FEE2E2;} .pn-st.ask .pn-dot{background:var(--amber);}
.pn-btns{display:flex;flex-wrap:wrap;gap:8px;align-items:center;} .pn-mute{font-size:12px;color:var(--text-dim);display:flex;gap:5px;align-items:center;margin:0;}
.pn-meta{font-size:12px;color:var(--text-dim);margin-top:10px;} .pn-help{margin-top:8px;font-size:12.5px;} .pn-help summary{cursor:pointer;color:var(--rail);font-weight:600;} .pn-help ul{margin:8px 0 0;padding-left:18px;} .pn-help li{margin-bottom:5px;}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
  var st = document.getElementById('pnState'), t = document.getElementById('pnStateT'), sub = document.getElementById('pnStateS'), en = document.getElementById('pnEnable');
  function paint() {
    var s = window.eowmsPushState ? eowmsPushState() : { supported: false };
    st.className = 'pn-st'; en.classList.add('d-none');
    if (!s.supported) {
      st.classList.add('off');
      t.textContent = s.ios && !s.standalone ? 'iPhone वर आधी App Install करा' : 'हा Browser Push सूचना देत नाही';
      sub.textContent = s.ios ? 'Safari → Share ⬆️ → Add to Home Screen' : 'Chrome / Edge / Firefox वापरा';
    } else if (s.permission === 'granted') {
      st.classList.add('on'); t.textContent = 'या फोन / Browser वर सूचना चालू आहेत'; sub.textContent = 'App बंद असतानाही सूचना येतील';
    } else if (s.permission === 'denied') {
      st.classList.add('off'); t.textContent = 'सूचना Block केलेल्या आहेत'; sub.textContent = '🔒 → Site settings → Notifications → Allow';
    } else {
      st.classList.add('ask'); t.textContent = 'सूचना अजून चालू नाहीत'; sub.textContent = 'खालचं बटण दाबून परवानगी द्या'; en.classList.remove('d-none');
    }
  }
  setTimeout(paint, 600);
  en.onclick = function () { en.disabled = true; eowmsPushEnable().then(function () { en.disabled = false; paint(); setTimeout(function () { location.reload(); }, 1200); }); };
  document.getElementById('pnTest').onclick = function () {
    var b = this; b.disabled = true; b.textContent = '⏳ पाठवत आहे…';
    fetch('test.php', { method: 'POST' }).then(function (r) { return r.json(); }).then(function (j) {
      b.textContent = j.sent ? '✅ पाठवली — फोन पहा' : (j.configured ? (j.devices ? '✅ पाठवली' : '⚠️ या खात्याशी एकही उपकरण जोडलेलं नाही') : '⚠️ Push Setup नाही — फक्त App मध्ये दिसेल');
      setTimeout(function () { b.disabled = false; b.textContent = '📨 Test सूचना पाठवा'; }, 5000);
    }).catch(function () { b.disabled = false; b.textContent = '📨 Test सूचना पाठवा'; });
  };
  var m = document.getElementById('pnMute');
  try { m.checked = localStorage.getItem('eowmsPushMute') === '1'; } catch (e) {}
  m.onchange = function () { try { localStorage.setItem('eowmsPushMute', m.checked ? '1' : '0'); } catch (e) {} };
});
</script>

<div class="panel mt-2">
  <?php if (empty($notifications)): ?>
    <div style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s94')) ?></div>
  <?php endif; ?>
  <?php foreach ($notifications as $n): ?>
    <a href="open.php?id=<?= (int)$n['id'] ?>" style="display:block;padding:14px 20px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;<?= $n['is_read'] ? '' : 'background:var(--rail-soft);' ?>">
      <div style="font-size:13.5px;font-weight:600;color:var(--ink);"><?= h($n['title']) ?></div>
      <?php if ($n['message']): ?><div style="font-size:12.5px;color:var(--text-dim);margin-top:2px;"><?= h($n['message']) ?></div><?php endif; ?>
      <div style="font-size:11px;color:var(--text-dim);margin-top:4px;"><?= date('d M Y, h:i A', strtotime($n['created_at'])) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
