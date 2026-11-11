<?php
// 📡 Biometric Live Dashboard — आज कोण आलं / आत्ता आत / उशिरा / अजून नाही, Machines Online/Offline, Live Punch यादी
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/_common.php';
bioRequireViewer();
$orgId = activeOrgId();
$ready = bioReady($pdo);
if ($ready) { bioEnsureV2Reset($pdo); }
$L = $ready ? bioLiveData($pdo, $orgId) : null;

$pageTitle = 'Biometric Live';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-1">📡 Biometric — Live हजेरी</div>
  <div style="font-size:12px;color:var(--text-dim);align-self:center;"><span class="bio-dot on" id="bioPulse"></span>Live · <span id="bioTime"><?= date('h:i:s A') ?></span></div>
</div>
<?= bioTabs('index') ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ System Update → Database Update चालवा — मग Biometric v2 सुरू होईल.</div><?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>

<?php
$pct = $L['staff'] ? round(count($L['present']) * 100 / $L['staff']) : 0;
smartTiles([
    ['label' => '✅ आज हजर', 'value' => count($L['present']) . '/' . $L['staff'], 'sub' => $pct . '% · रजेवर ' . $L['leave'], 'tone' => 'ok', 'href' => 'punches.php'],
    ['label' => '🏢 आत्ता आत', 'value' => count($L['inside']), 'sub' => 'शेवटचा Punch "आत"'],
    ['label' => '⏰ उशिरा', 'value' => count($L['late']), 'sub' => 'Shift + सवलतीनंतर', 'tone' => $L['late'] ? 'warn' : 'ok', 'href' => 'punches.php?f=late'],
    ['label' => '🚫 अजून आले नाहीत', 'value' => count($L['notArrived']), 'sub' => 'Shift सुरू होऊन गेली', 'tone' => $L['notArrived'] ? 'bad' : 'ok'],
    ['label' => '🖥️ Machines Online', 'value' => $L['online'] . '/' . count($L['devices']), 'tone' => $L['online'] < count($L['devices']) ? 'bad' : 'ok', 'href' => hasRole('super_admin') ? 'devices.php' : ''],
]);
$alerts = [];
foreach ($L['devices'] as $d) { if (!bioDeviceOnline($d)) { $alerts[] = ['bad', '🖥️ ' . bioDeviceName($d) . ' Offline — शेवटचा संपर्क ' . bioAgo($d['last_seen_at']), hasRole('super_admin') ? 'devices.php' : '']; } }
if ($L['pending'] && hasRole('super_admin')) { $alerts[] = ['warn', '🆕 ' . $L['pending'] . ' नवीन Machine मंजुरीसाठी प्रलंबित', 'devices.php']; }
if ($L['unmapped'] && hasRole('super_admin')) { $alerts[] = ['warn', '🔗 ' . $L['unmapped'] . ' PIN कोणत्याही कर्मचाऱ्याशी जोडलेले नाहीत — त्यांचे Punches हजेरीत जात नाहीत', 'pins.php']; }
$enP = [(int)$orgId, (int)$orgId]; $enScope = bioScopeSql($enP);
$en = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.organization_id = ? AND u.is_active = 1 AND u.biometric_pin IS NOT NULL AND u.biometric_pin <> ''
    AND NOT EXISTS (SELECT 1 FROM bio_device_users du JOIN bio_devices bd ON bd.id = du.device_id AND bd.status = 'active' AND bd.organization_id = ? WHERE du.pin = u.biometric_pin AND (du.fp_count > 0 OR du.has_face = 1))
    AND NOT EXISTS (SELECT 1 FROM bio_punches bp WHERE bp.user_id = u.id)$enScope");
$en->execute(array_merge([(int)$orgId], array_slice($enP, 1)));
$enPending = (int)$en->fetchColumn();
if ($enPending && $L['devices']) { $alerts[] = ['warn', '🧑‍🔧 ' . $enPending . ' कर्मचाऱ्यांची Machine वर बोट-नोंदणी बाकी — Technician Slip', 'enroll.php']; }
if (!$L['devices'] && hasRole('super_admin')) { $alerts[] = ['info', '⚙️ अजून एकही Machine जोडलेली नाही — Machines पानावर Setup मार्गदर्शन पाहा', 'devices.php']; }
foreach ($L['devices'] as $d) { $dr = bioClockDrift($pdo, $d['id']); if ($dr !== null && abs($dr) >= 3) { $alerts[] = ['warn', '⏰ ' . bioDeviceName($d) . ' ची वेळ ' . abs($dr) . ' मि. ' . ($dr > 0 ? 'पुढे' : 'मागे') . ' — Restart करा', hasRole('super_admin') ? 'devices.php' : '']; } }
smartAlerts($alerts);
?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">🖐️ आजचे Punches — Live</div><div class="sub">नवीन Punch आला की वर दिसतो (दर 20 सेकंदांनी)</div></div>
      <div id="bioFeed" style="max-height:520px;overflow:auto;">
        <?php if (!$L['feed']): ?><div class="sm-empty" id="bioEmpty" style="padding:18px;">आज अजून एकही Punch नाही.</div><?php endif; ?>
        <?php foreach ($L['feed'] as $p): ?>
          <div class="bio-row"><b class="t"><?= date('h:i:s A', strtotime($p['punch_time'])) ?></b>
            <span class="n"><?= $p['name'] ? h($p['name']) : '<span style="color:#B45309;">PIN ' . h($p['pin']) . ($p['other_campus'] ? ' — ' . h($p['other_campus']) . ' (दुसऱ्या Campus चा — हजेरीत नाही)' : ' (न जोडलेला)') . '</span>' ?>
              <?php if ($p['punch_state'] !== null): ?><span class="bio-chip" style="background:var(--canvas);"><?= h(bioStateLabel($p['punch_state'])) ?></span><?php endif; ?></span>
            <small><?= h(bioVerifyLabel($p['verify_mode'])) ?> · <?= h($p['device_name'] . ($p['location'] ? ' · ' . $p['location'] : '')) ?></small></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5 d-flex flex-column gap-3">
    <div class="panel">
      <div class="panel-head"><div class="title">🚫 अजून आले नाहीत (<?= count($L['notArrived']) ?>)</div><div class="sub">रजा / सुट्टी / साप्ताहिक सुट्टी वगळून</div></div>
      <div style="padding:6px 16px 12px;max-height:240px;overflow:auto;">
        <?php if (!$L['notArrived']): ?><div class="sm-empty">सगळे आले ✓</div><?php endif; ?>
        <?php foreach ($L['notArrived'] as $s): ?><div class="bio-li"><span><?= h($s['name']) ?><small><?= h($s['dept'] ?: '—') ?></small></span><small>Shift <?= h($s['shift']) ?></small></div><?php endforeach; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">⏰ उशिरा आलेले (<?= count($L['late']) ?>)</div></div>
      <div style="padding:6px 16px 12px;max-height:220px;overflow:auto;">
        <?php if (!$L['late']): ?><div class="sm-empty">आज कोणीही उशिरा नाही ✓</div><?php endif; ?>
        <?php foreach ($L['late'] as $s): ?><div class="bio-li"><span><?= h($s['name']) ?><small><?= h($s['dept'] ?: '—') ?></small></span><b style="color:#B45309;"><?= date('h:i A', strtotime($s['first_punch_time'])) ?> · <?= bioMinutes($s['late_minutes']) ?></b></div><?php endforeach; ?>
      </div>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">🖥️ Machines</div></div>
      <div style="padding:6px 16px 12px;">
        <?php if (!$L['devices']): ?><div class="sm-empty">Machine नाही</div><?php endif; ?>
        <?php foreach ($L['devices'] as $d): $on = bioDeviceOnline($d); ?>
          <div class="bio-li"><span><span class="bio-dot <?= $on ? 'on' : 'off' ?>"></span><?= h(bioDeviceName($d)) ?></span><small><?= $on ? 'Online' : 'Offline · ' . h(bioAgo($d['last_seen_at'])) ?> · शेवटचा Punch <?= h(bioAgo($d['last_punch_at'])) ?></small></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<style>
  .bio-row{display:grid;grid-template-columns:96px 1fr;gap:2px 10px;padding:9px 16px;border-top:1px solid var(--line);font-size:13px;}
  .bio-row small{grid-column:2;color:var(--text-dim);font-size:11.5px;} .bio-row .t{font-family:monospace;font-size:12.5px;}
  .bio-row.new{animation:bioNew 2.5s ease;} @keyframes bioNew{from{background:#DCFCE7;}to{background:transparent;}}
  .bio-li{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px dashed var(--line);font-size:13px;align-items:center;}
  .bio-li span small{display:block;color:var(--text-dim);font-size:11px;} .bio-li > small{color:var(--text-dim);font-size:11.5px;text-align:right;}
</style>
<script>
(function () {
  var after = <?= (int)$L['maxId'] ?>, feed = document.getElementById('bioFeed');
  var esc = function (s) { var d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML; };
  function tick() {
    if (document.hidden) return;
    fetch('live.php?after=' + after, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) return;
      document.getElementById('bioTime').textContent = j.time;
      var e = document.getElementById('bioEmpty'); if (j.rows.length && e) e.remove();
      j.rows.forEach(function (r) {
        var div = document.createElement('div'); div.className = 'bio-row new';
        div.innerHTML = '<b class="t">' + esc(r.time) + '</b><span class="n">' + (r.mapped ? esc(r.name) : '<span style="color:#B45309;">' + esc(r.name) + '</span>')
          + (r.state ? ' <span class="bio-chip" style="background:var(--canvas);">' + esc(r.state) + '</span>' : '') + '</span><small>' + esc(r.verify) + ' · ' + esc(r.device) + '</small>';
        feed.insertBefore(div, feed.firstChild);
      });
      after = j.maxId;
      // वरचे आकडे
      var vals = document.querySelectorAll('.hi-totals b');
      if (vals.length >= 5) { vals[0].textContent = j.tiles.present + '/<?= (int)$L['staff'] ?>'; vals[1].textContent = j.tiles.inside; vals[2].textContent = j.tiles.late; vals[3].textContent = j.tiles.notArrived; vals[4].textContent = j.tiles.online; }
    }).catch(function () { document.getElementById('bioPulse').className = 'bio-dot off'; });
  }
  setInterval(tick, 20000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) tick(); });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
