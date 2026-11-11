<?php
// 🧑‍🔧 Machine वर नोंदणी बाकी — PIN दिला पण बोट/चेहरा अजून कोणत्याही Machine वर नाही.
// Technician साठी Print / WhatsApp Slip; Machine ने बोट नोंदल्याचं कळवलं (किंवा पहिला Punch आला) की आपोआप "पूर्ण".
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/_common.php';
if (!hasRole('super_admin') && !isManagementOrAbove() && !hasPermission('manage_attendance')) { header('Location: my.php'); exit; }
$me = currentUser();
$orgId = activeOrgId();
$ready = bioReady($pdo);
if ($ready) { bioEnsureV2Reset($pdo); }

/** संस्थेतले PIN असलेले सक्रिय कर्मचारी + Machine वरची स्थिती (बोटं / चेहरा / Punch) */
function bioEnrollRows(PDO $pdo, $orgId) {
    $p = [(int)$orgId, (int)$orgId, (int)$orgId]; $scope = bioScopeSql($p);
    $st = $pdo->prepare("SELECT u.id, u.name, u.employee_code, u.biometric_pin, u.mobile, COALESCE(NULLIF(d.name_local,''), d.name) dept,
            (SELECT COALESCE(MAX(du.fp_count), 0) FROM bio_device_users du JOIN bio_devices bd ON bd.id = du.device_id AND bd.organization_id = ? AND bd.status = 'active' WHERE du.pin = u.biometric_pin) fp,
            (SELECT COALESCE(MAX(du.has_face), 0) FROM bio_device_users du JOIN bio_devices bd ON bd.id = du.device_id AND bd.organization_id = ? AND bd.status = 'active' WHERE du.pin = u.biometric_pin) face,
            (SELECT COUNT(*) FROM bio_punches bp WHERE bp.user_id = u.id) punches,
            (SELECT GROUP_CONCAT(DISTINCT bd.device_name SEPARATOR ', ') FROM bio_device_users du JOIN bio_devices bd ON bd.id = du.device_id AND bd.status = 'active' WHERE du.pin = u.biometric_pin AND bd.organization_id = u.organization_id) on_devices
        FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.organization_id = ? AND u.is_active = 1 AND u.biometric_pin IS NOT NULL AND u.biometric_pin <> ''$scope
        ORDER BY CAST(u.biometric_pin AS UNSIGNED), u.biometric_pin");
    $st->execute($p);
    $rows = $st->fetchAll();
    foreach ($rows as &$r) { $r['done'] = (int)$r['fp'] > 0 || (int)$r['face'] > 0 || (int)$r['punches'] > 0; }
    return $rows;
}

$rows = $ready ? bioEnrollRows($pdo, $orgId) : [];
$pending = array_values(array_filter($rows, fn($r) => !$r['done']));
$done = array_values(array_filter($rows, fn($r) => $r['done']));

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    $ids = array_map('intval', (array)($_POST['uids'] ?? []));
    $n = 0; $devs = 0;
    foreach ($pending as $r) { if (in_array((int)$r['id'], $ids, true)) { $devs = bioSyncStaff($pdo, $orgId, (int)$r['id'], null, $me['id']); $n++; } }
    $_SESSION[$devs ? 'flash' : 'form_error'] = $devs ? "$n कर्मचारी (नाव + PIN) $devs Machine(s) वर पुन्हा पाठवले." : 'चालू Machine नाही — आधी Machine मंजूर करा.';
    header('Location: enroll.php'); exit;
}

// 🖨️ Technician Slip — निवडलेले (नसेल तर सगळे बाकी)
if (isset($_GET['print'])) {
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
    $list = $ids ? array_values(array_filter($rows, fn($r) => in_array((int)$r['id'], $ids, true))) : $pending;
    $host = h(activeOrgName());
    ?><!doctype html><html lang="mr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Biometric नोंदणी Slip</title>
    <style>
      body{font-family:'Noto Sans Devanagari',Arial,sans-serif;margin:18px;color:#1A1D29;background:#fff;}
      h1{font-size:17px;margin:0 0 2px;} .sub{font-size:12px;color:#666;margin-bottom:12px;}
      .how{border:1px dashed #999;border-radius:8px;padding:8px 12px;font-size:12px;margin-bottom:14px;line-height:1.7;}
      .grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;}
      .card{border:1.5px solid #191C3A;border-radius:10px;padding:10px 12px;page-break-inside:avoid;display:flex;justify-content:space-between;gap:10px;align-items:center;}
      .card b{font-size:15px;} .card small{display:block;color:#555;font-size:11.5px;}
      .pin{font-family:monospace;font-size:28px;font-weight:800;color:#D9530F;border:2px solid #D9530F;border-radius:8px;padding:2px 12px;white-space:nowrap;}
      .tick{font-size:11px;color:#555;margin-top:4px;} @media print{.noprint{display:none;} body{margin:8mm;}}
      .noprint button{padding:8px 16px;border-radius:8px;border:0;background:#D9530F;color:#fff;font-size:14px;cursor:pointer;}
    </style></head><body>
    <div class="noprint" style="margin-bottom:10px;"><button onclick="window.print()">🖨️ Print</button></div>
    <h1>🖐️ Biometric Machine नोंदणी — <?= $host ?></h1>
    <div class="sub"><?= count($list) ?> कर्मचारी · <?= date('d/m/Y h:i A') ?></div>
    <div class="how"><b>Technician साठी:</b> Machine → Menu → <b>User Mgt</b> → (User आधीच असेल तर तो निवडा, नसेल तर <b>New User</b>) →
      <b>User ID = खालचा PIN</b> → Fingerprint (3 वेळा बोट) / Face नोंदवा → Save. <b>User ID बदलू नका.</b></div>
    <div class="grid">
      <?php foreach ($list as $r): ?>
        <div class="card"><div><b><?= h($r['name']) ?></b><small><?= h($r['dept'] ?: '—') ?><?= $r['employee_code'] ? ' · ' . h($r['employee_code']) : '' ?></small>
          <div class="tick">☐ बोट &nbsp; ☐ चेहरा &nbsp; ☐ तपासलं</div></div><div class="pin"><?= h($r['biometric_pin']) ?></div></div>
      <?php endforeach; ?>
    </div>
    <?php if (!$list): ?><p>नोंदणी बाकी असलेले कर्मचारी नाहीत ✓</p><?php endif; ?>
    <script>setTimeout(function(){ if (<?= $list ? 'true' : 'false' ?>) window.print(); }, 400);</script>
    </body></html><?php
    exit;
}

$waText = "🖐️ Biometric नोंदणी बाकी (" . count($pending) . ")\nMachine → User Mgt → User ID = PIN → बोट/चेहरा नोंदवा\n\n"
    . implode("\n", array_map(fn($r) => 'PIN ' . $r['biometric_pin'] . ' — ' . $r['name'] . ($r['dept'] ? ' (' . $r['dept'] . ')' : ''), array_slice($pending, 0, 80)));

$pageTitle = 'Biometric नोंदणी बाकी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🧑‍🔧 Biometric — Machine वर नोंदणी</div>
<?= bioTabs('enroll') ?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div><?php unset($_SESSION['form_error']); endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ System Update → Database Update चालवा.</div><?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>
<?php smartTiles([
    ['label' => '🖐️ PIN दिलेले', 'value' => count($rows), 'sub' => 'सक्रिय कर्मचारी'],
    ['label' => '✅ नोंदणी पूर्ण', 'value' => count($done), 'sub' => 'बोट / चेहरा / Punch आला', 'tone' => 'ok'],
    ['label' => '⏳ नोंदणी बाकी', 'value' => count($pending), 'sub' => 'Machine वर बोट नोंदवायचं', 'tone' => $pending ? 'warn' : 'ok'],
]); ?>
<div class="alert" style="background:var(--surface);border:1px solid var(--line);border-radius:12px;font-size:12.5px;line-height:1.8;">
  <b>Technician ची पद्धत:</b> Machine → Menu → <b>User Mgt</b> → User निवडा (नसेल तर <b>New User</b>) → <b>User ID = यादीतला PIN</b> → बोट (3 वेळा) / चेहरा नोंदवा.
  Machine ने कळवलं (किंवा त्या PIN चा पहिला Punch आला) की कर्मचारी आपोआप <b>"✓ नोंदणी पूर्ण"</b> मध्ये जातो.
</div>

<form method="POST" class="panel mb-3" id="enForm">
  <input type="hidden" name="action" value="resend">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">⏳ नोंदणी बाकी (<?= count($pending) ?>)</div><div class="sub">PIN दिला, पण अजून कोणत्याही Machine वर बोट/चेहरा नाही</div></div>
    <?php if ($pending): ?>
    <div class="d-flex flex-wrap gap-2">
      <button type="button" class="btn" id="enPrint" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;">🖨️ Slip Print</button>
      <a class="btn" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($waText) ?>" style="border:1px solid #16A34A;color:#16A34A;border-radius:9px;font-size:13px;">📲 WhatsApp</a>
      <button class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">📤 निवडलेले Machine वर पुन्हा पाठवा</button>
    </div>
    <?php endif; ?>
  </div>
  <?php if (!$pending): ?><div class="sm-empty" style="padding:18px;">सगळ्यांची Machine वर नोंदणी पूर्ण ✓</div><?php else: ?>
  <div class="table-responsive"><table class="rtable" data-no-filter><thead><tr><th style="width:30px;"><input type="checkbox" id="enAll" checked></th><th>PIN</th><th>कर्मचारी</th><th>Machine वर</th></tr></thead><tbody>
    <?php foreach ($pending as $r): ?>
      <tr><td><input type="checkbox" class="en-u" name="uids[]" value="<?= (int)$r['id'] ?>" checked></td>
        <td><span class="font-mono" style="font-size:18px;font-weight:800;color:var(--rail);"><?= h($r['biometric_pin']) ?></span></td>
        <td><b><?= h($r['name']) ?></b><div style="font-size:11.5px;color:var(--text-dim);"><?= h($r['dept'] ?: '—') ?><?= $r['employee_code'] ? ' · ' . h($r['employee_code']) : '' ?></div></td>
        <td style="font-size:12px;"><?= $r['on_devices'] ? '👤 नाव पोहोचलं (' . h($r['on_devices']) . ') — बोट बाकी' : '<span style="color:#B45309;">Machine वर नाव अजून नाही</span>' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</form>

<details class="panel">
  <summary style="padding:13px 18px;cursor:pointer;font-weight:600;">✅ नोंदणी पूर्ण (<?= count($done) ?>)</summary>
  <?php if ($done): ?><div class="table-responsive"><table class="rtable" data-no-filter><thead><tr><th>PIN</th><th>कर्मचारी</th><th>Machine वर</th></tr></thead><tbody>
    <?php foreach ($done as $r): ?><tr><td class="font-mono" style="font-weight:700;"><?= h($r['biometric_pin']) ?></td><td><?= h($r['name']) ?> <small style="color:var(--text-dim);"><?= h($r['dept'] ?: '') ?></small></td>
      <td style="font-size:12px;"><?= (int)$r['fp'] ? '👆 ' . (int)$r['fp'] . ' बोट ' : '' ?><?= (int)$r['face'] ? '🙂 चेहरा ' : '' ?><?= (int)$r['punches'] ? '· ' . (int)$r['punches'] . ' Punches' : '' ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php else: ?><div class="sm-empty" style="padding:0 18px 16px;">अजून नाही</div><?php endif; ?>
</details>
<script>
(function () {
  var all = document.getElementById('enAll');
  if (all) all.addEventListener('change', function () { document.querySelectorAll('.en-u').forEach(function (c) { c.checked = all.checked; }); });
  var pb = document.getElementById('enPrint');
  if (pb) pb.addEventListener('click', function () {
    var ids = Array.prototype.map.call(document.querySelectorAll('.en-u:checked'), function (c) { return c.value; });
    window.open('enroll.php?print=1&ids=' + ids.join(','), '_blank');
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
