<?php
// 🔗 PIN जोडणी — Machine वरचे कर्मचारी (PIN) ↔ आपले कर्मचारी. नावावरून आपोआप जोड, नवीन PIN देऊन Machine वर पाठवणे.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/_common.php';
$user = currentUser();
$orgId = activeOrgId();
$ready = bioReady($pdo);
if ($ready) { bioEnsureV2Reset($pdo); }
$back = function ($msg, $err = false) { $_SESSION[$err ? 'form_error' : 'flash'] = $msg; header('Location: pins.php'); exit; };

/** इंग्रजी/मराठी नाव तुलनेसाठी — लहान अक्षरं, फक्त अक्षरं-अंक */
$norm = fn($s) => trim(preg_replace('/[^a-z0-9\p{L}]+/u', ' ', mb_strtolower((string)$s)));
$sim = function ($a, $b) use ($norm) {
    $a = $norm($a); $b = $norm($b);
    if ($a === '' || $b === '') { return 0; }
    if ($a === $b) { return 100; }
    similar_text($a, $b, $p);
    // आडनाव-नाव उलटं असलं तरी
    $wa = explode(' ', $a); $wb = explode(' ', $b); sort($wa); sort($wb);
    similar_text(implode(' ', $wa), implode(' ', $wb), $q);
    return (int)round(max($p, $q));
};

$staffStmt = $pdo->prepare("SELECT u.id, u.name, u.employee_code, u.biometric_pin, u.campus_id, c.name campus, COALESCE(NULLIF(d.name_local,''), d.name) dept
    FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.name");
$staffStmt->execute([$orgId]);
$staff = $staffStmt->fetchAll();
$byPin = [];
foreach ($staff as $s) { if ($s['biometric_pin'] !== null && $s['biometric_pin'] !== '') { $byPin[$s['biometric_pin']] = $s; } }

// Machine वरचे / Punches मध्ये दिसलेले PIN — याच संस्थेच्या Machines चे
$pins = [];
if ($ready) {
    $st = $pdo->prepare("SELECT du.pin, MAX(du.name) mname, GROUP_CONCAT(DISTINCT d.device_name SEPARATOR ', ') devs, MAX(du.fp_count) fp, MAX(du.has_face) face
        FROM bio_device_users du JOIN bio_devices d ON d.id = du.device_id WHERE d.organization_id = ? GROUP BY du.pin");
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $r) { $pins[$r['pin']] = $r + ['punches' => 0, 'last' => null]; }
    // ⚡ (device_id, pin) गटाने — uniq_device_pin_time Index पुरतो (लाखो Punches वर JOIN + GROUP BY pin टाळतो); मग PHP मध्ये PIN नुसार बेरीज
    $devNames = $pdo->prepare("SELECT id, device_name FROM bio_devices WHERE organization_id = ?");
    $devNames->execute([$orgId]);
    $devNames = $devNames->fetchAll(PDO::FETCH_KEY_PAIR);
    $agg = [];
    if ($devNames) {
        $st = $pdo->prepare("SELECT device_id, pin, COUNT(*) n, MAX(punch_time) last FROM bio_punches
            WHERE device_id IN (" . implode(',', array_map('intval', array_keys($devNames))) . ") GROUP BY device_id, pin");
        $st->execute();
        foreach ($st->fetchAll() as $r) {
            $g = &$agg[$r['pin']];
            $g['n'] = ($g['n'] ?? 0) + (int)$r['n'];
            if (empty($g['last']) || $r['last'] > $g['last']) { $g['last'] = $r['last']; }
            $g['devs'][$devNames[$r['device_id']] ?? ''] = true;
            unset($g);
        }
    }
    foreach ($agg as $pin => $r) {
        $pin = (string)$pin;
        $pins[$pin] = ($pins[$pin] ?? ['pin' => $pin, 'mname' => null, 'devs' => implode(', ', array_keys($r['devs'])), 'fp' => 0, 'face' => 0]);
        $pins[$pin]['punches'] = $r['n']; $pins[$pin]['last'] = $r['last'];
    }
}
uksort($pins, fn($a, $b) => (ctype_digit((string)$a) && ctype_digit((string)$b)) ? (int)$a <=> (int)$b : strcmp($a, $b));
// नावावरून सूचना — न जोडलेल्या PIN साठी, न जोडलेल्या कर्मचाऱ्यांपैकी सर्वात जवळचं नाव
$freeStaff = array_filter($staff, fn($s) => $s['biometric_pin'] === null || $s['biometric_pin'] === '');
$suggest = [];
foreach ($pins as $pin => $p) {
    if (isset($byPin[$pin]) || !$p['mname']) { continue; }
    $best = null; $bs = 0;
    foreach ($freeStaff as $s) { $x = $sim($p['mname'], $s['name']); if ($x > $bs) { $bs = $x; $best = $s; } }
    if ($best && $bs >= 70) { $suggest[$pin] = [$best, $bs]; }
}

$setPin = function ($uid, $pin) use ($pdo, $orgId) {
    $pdo->prepare("UPDATE users SET biometric_pin = ? WHERE id = ? AND organization_id = ?")->execute([$pin, (int)$uid, $orgId]);
};

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'map') {
        $pin = trim($_POST['pin'] ?? ''); $uid = (int)($_POST['user_id'] ?? 0);
        if (!preg_match('/^[A-Za-z0-9]{1,20}$/', $pin)) { $back('PIN अवैध.', true); }
        if (!$uid) { $back('कर्मचारी निवडा.', true); }
        if (isset($byPin[$pin]) && (int)$byPin[$pin]['id'] !== $uid) { $back('हा PIN आधीच ' . $byPin[$pin]['name'] . ' यांना दिला आहे — आधी तो काढा.', true); }
        $ok = array_filter($staff, fn($s) => (int)$s['id'] === $uid);
        if (!$ok) { $back('कर्मचारी सापडला नाही.', true); }
        $setPin($uid, $pin);
        $r = bioProcessPending($pdo);
        logActivity("Biometric PIN $pin जोडला", 'user', $uid);
        $back('PIN ' . $pin . ' → ' . reset($ok)['name'] . ' जोडला' . ($r['done'] ? " — {$r['done']} दिवसांची हजेरी आपोआप भरली." : '.'));
    }
    if ($action === 'unmap') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $pdo->prepare("UPDATE users SET biometric_pin = NULL WHERE id = ? AND organization_id = ?")->execute([$uid, $orgId]);
        $pdo->prepare("UPDATE bio_punches p JOIN bio_devices d ON d.id = p.device_id SET p.user_id = NULL, p.processed = 0 WHERE p.user_id = ? AND d.organization_id = ?")->execute([$uid, $orgId]);
        $back('PIN जोड काढला (त्या PIN चे पुढचे Punches कोणाच्याच हजेरीत जाणार नाहीत).');
    }
    if ($action === 'auto') {
        $n = 0; $used = [];
        foreach ($suggest as $pin => [$s, $score]) {
            if ($score < 85 || isset($used[$s['id']])) { continue; }
            $setPin($s['id'], (string)$pin); $used[$s['id']] = true; $n++;
        }
        $r = $n ? bioProcessPending($pdo) : ['done' => 0];
        $back($n ? "$n PIN नावावरून आपोआप जोडले (85%+ जुळणारे). {$r['done']} दिवसांची हजेरी भरली — बाकीचे हाताने तपासा." : '85%+ जुळणारी नावं नाहीत — हाताने जोडा.', !$n);
    }
    if ($action === 'series') {
        // प्रत्येक Campus ची PIN Series — 1000 च्या पटीत, एकमेकांत न मिसळणाऱ्या
        $new = []; $seen = [];
        foreach ((array)($_POST['series'] ?? []) as $cid => $v) {
            $v = (int)$v;
            if ($v < 1000 || $v > 9999000 || $v % 1000 !== 0) { $back('Series 1000 च्या पटीत द्या (उदा. 1000, 2000, 3000).', true); }
            if (isset($seen[$v])) { $back("Series $v दोन Campus ना दिली आहे — प्रत्येकाला वेगळी द्या.", true); }
            $seen[$v] = true; $new[(int)$cid] = $v;
        }
        foreach ($new as $cid => $v) { orgSettingSet($pdo, $orgId, 'bio_series_' . $cid, (string)$v); }
        logActivity('Biometric PIN Series बदलल्या', 'org', $orgId);
        $back('PIN Series जतन झाल्या — नवीन कर्मचाऱ्यांना आता या Series मधले PIN मिळतील (आधीचे PIN बदलत नाहीत).');
    }
    if ($action === 'assign') {
        // PIN नसलेल्यांना पुढचा PIN देऊन सगळ्या चालू Machines वर पाठवतो (बोट/चेहरा Machine वर नोंदवायचा)
        $ids = array_map('intval', (array)($_POST['uids'] ?? []));
        if (!$ids) { $back('कर्मचारी निवडा.', true); }
        // प्रत्येकाला त्याच्या Campus च्या Series मधला पुढचा PIN, व त्याच Campus च्या Machines वरच
        $n = 0; $sent = 0;
        foreach ($freeStaff as $s) {
            if (!in_array((int)$s['id'], $ids, true)) { continue; }
            $pin = bioNextPin($pdo, $orgId, $s['campus_id']);
            $setPin($s['id'], $pin);
            $sent += bioSyncStaff($pdo, $orgId, (int)$s['id'], null, $user['id']);
            $n++;
        }
        $back("$n कर्मचाऱ्यांना त्यांच्या Campus च्या Series मधला PIN दिला" . ($sent ? ' व त्यांच्या Campus च्या Machine(s) वर पाठवला — आता Machine वर बोट/चेहरा नोंदवा.' : ' (त्यांच्या Campus ची Machine मंजूर झाल्यावर "सगळे कर्मचारी पाठवा" वापरा).'));
    }
    $back('अवैध कृती.', true);
}

$mapped = count(array_filter($pins, fn($p, $k) => isset($byPin[$k]), ARRAY_FILTER_USE_BOTH));
// दुसऱ्या Campus च्या Machine वर केलेले Punches — PIN कोणाचा तरी आहे, पण त्या Machine च्या Campus चा नाही (हजेरीत धरले नाहीत)
$wrongCampus = [];
if ($ready) {
    $st = $pdo->prepare("SELECT p.pin, COUNT(*) n, GROUP_CONCAT(DISTINCT d.device_name SEPARATOR ', ') devs FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.organization_id = ? AND d.status = 'active'
        WHERE p.user_id IS NULL GROUP BY p.pin");
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $r) { if (isset($byPin[$r['pin']])) { $wrongCampus[$r['pin']] = $r; } }
}
$campusList = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? ORDER BY id");
$campusList->execute([$orgId]);
$campusList = $campusList->fetchAll();
$seriesMap = bioSeriesMap($pdo, $orgId);
$pageTitle = 'Biometric PIN जोडणी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🔗 Biometric — PIN जोडणी</div>
<?= bioTabs('pins') ?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div><?php unset($_SESSION['form_error']); endif; ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ System Update → Database Update चालवा.</div><?php require_once __DIR__ . '/../includes/footer.php'; exit; endif; ?>
<?php smartTiles([
    ['label' => 'Machine वरचे PIN', 'value' => count($pins), 'sub' => 'Machine / Punches मध्ये दिसलेले'],
    ['label' => 'जोडलेले', 'value' => $mapped, 'tone' => 'ok'],
    ['label' => 'न जोडलेले PIN', 'value' => count($pins) - $mapped, 'sub' => 'यांचे Punches हजेरीत जात नाहीत', 'tone' => count($pins) - $mapped ? 'warn' : 'ok'],
    ['label' => 'PIN नसलेले कर्मचारी', 'value' => count($freeStaff), 'sub' => 'सक्रिय', 'tone' => $freeStaff ? 'warn' : 'ok'],
]); ?>

<form method="POST" class="panel mb-3">
  <input type="hidden" name="action" value="series">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">🏫 College / School नुसार PIN Series</div><div class="sub">प्रत्येक Campus ला वेगळे नंबर — PIN पाहताच कळतं कोणत्या संस्थेचा; Machine वर फक्त त्याच Campus चे कर्मचारी व त्यांचीच हजेरी</div></div>
    <button class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">जतन</button>
  </div>
  <div class="table-responsive"><table class="rtable" data-no-filter><thead><tr><th>Campus</th><th>PIN Series</th><th>PIN दिलेले</th><th>पुढचा PIN</th><th>Machines</th></tr></thead><tbody>
    <?php foreach (array_merge($campusList, [['id' => 0, 'name' => 'Campus नसलेले कर्मचारी']]) as $c):
      $cid = (int)$c['id']; $start = $seriesMap[$cid] ?? 9000;
      $cnt = count(array_filter($staff, fn($s) => (int)$s['campus_id'] === $cid && $s['biometric_pin'] !== null && $s['biometric_pin'] !== ''));
      $dc = $pdo->prepare("SELECT COUNT(*) FROM bio_devices WHERE organization_id = ? AND status = 'active' AND " . ($cid ? 'campus_id = ' . $cid : 'campus_id IS NULL'));
      $dc->execute([$orgId]); ?>
      <tr><td><b><?= h($c['name']) ?></b></td>
        <td><input type="number" name="series[<?= $cid ?>]" value="<?= (int)$start ?>" min="1000" step="1000" class="form-control-custom" style="width:110px;"> <small style="color:var(--text-dim);"><?= $start + 1 ?>–<?= $start + 999 ?></small></td>
        <td><?= $cnt ?></td><td class="font-mono" style="font-weight:700;color:var(--rail);"><?= h(bioNextPin($pdo, $orgId, $cid ?: null)) ?></td>
        <td style="font-size:12px;"><?= (int)$dc->fetchColumn() ?: '<span style="color:var(--text-dim);">' . ($cid ? 'नाही' : 'सगळ्यांसाठी') . '</span>' ?></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
</form>

<div class="panel mb-3">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">🖐️ Machine वरचे PIN</div><div class="sub">💡 = नावावरून सुचवलेला कर्मचारी (% जुळणी)</div></div>
    <?php if (array_filter($suggest, fn($x) => $x[1] >= 85)): ?>
      <form method="POST"><input type="hidden" name="action" value="auto"><button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;">✨ नावावरून आपोआप जोडा (<?= count(array_filter($suggest, fn($x) => $x[1] >= 85)) ?>)</button></form>
    <?php endif; ?>
  </div>
  <?php if (!$pins): ?><div class="sm-empty" style="padding:18px;">अजून Machine कडून PIN आलेले नाहीत — Machine मंजूर करा व "सगळे Logs व कर्मचारी पुन्हा मागवा" आदेश द्या.</div><?php else: ?>
  <div class="table-responsive"><table class="rtable" data-no-filter><thead><tr><th>PIN</th><th>Machine वरचं नाव</th><th>Punches</th><th>आपला कर्मचारी</th></tr></thead><tbody>
  <?php foreach ($pins as $pin => $p): $m = $byPin[$pin] ?? null; $sg = $suggest[$pin] ?? null; ?>
    <tr>
      <td class="font-mono" style="font-weight:700;"><?= h($pin) ?></td>
      <td><?= h($p['mname'] ?: '—') ?><div style="font-size:11px;color:var(--text-dim);"><?= h($p['devs'] ?: '') ?><?= $p['fp'] ? ' · 👆' . (int)$p['fp'] : '' ?><?= $p['face'] ? ' · 🙂' : '' ?></div></td>
      <td style="font-size:12px;"><?= (int)$p['punches'] ?><div style="color:var(--text-dim);"><?= $p['last'] ? date('d/m h:i A', strtotime($p['last'])) : '' ?></div></td>
      <td>
        <?php if ($m): ?>
          <form method="POST" class="d-flex align-items-center gap-2"><input type="hidden" name="action" value="unmap"><input type="hidden" name="user_id" value="<?= (int)$m['id'] ?>">
            <span style="color:#16A34A;font-weight:600;">✓ <?= h($m['name']) ?></span><small style="color:var(--text-dim);"><?= h($m['dept'] ?: '') ?><?= $m['campus'] ? ' · 🏫 ' . h($m['campus']) : '' ?></small>
            <?php if (isset($wrongCampus[$pin])): ?><span class="bio-chip" style="background:#FEF3C7;color:#92400E;" title="<?= h($wrongCampus[$pin]['devs']) ?>">⚠ <?= (int)$wrongCampus[$pin]['n'] ?> Punch दुसऱ्या Campus च्या Machine वर — हजेरीत नाहीत</span><?php endif; ?>
            <button class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:2px 8px;" onclick="return confirm('हा PIN जोड काढायचा?');">काढा</button></form>
        <?php else: ?>
          <form method="POST" class="d-flex align-items-center gap-2 flex-wrap"><input type="hidden" name="action" value="map"><input type="hidden" name="pin" value="<?= h($pin) ?>">
            <select name="user_id" class="form-select-custom" style="width:auto;max-width:260px;font-size:12.5px;">
              <option value="">— कर्मचारी निवडा —</option>
              <?php foreach ($freeStaff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $sg && (int)$sg[0]['id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?><?= $s['dept'] ? ' — ' . h($s['dept']) : '' ?></option><?php endforeach; ?>
            </select>
            <?php if ($sg): ?><span class="bio-chip" style="background:#FEF3C7;color:#92400E;">💡 <?= (int)$sg[1] ?>%</span><?php endif; ?>
            <button class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;">जोडा</button></form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<?php if ($freeStaff): ?>
<form method="POST" class="panel">
  <input type="hidden" name="action" value="assign">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">👤 PIN नसलेले कर्मचारी (<?= count($freeStaff) ?>)</div><div class="sub">निवडा → प्रत्येकाला त्याच्या Campus च्या Series मधला पुढचा PIN, व त्याच Campus च्या Machine वर नाव; मग Machine वर बोट/चेहरा नोंदवा</div></div>
    <div class="d-flex gap-2"><label style="font-size:12.5px;"><input type="checkbox" onclick="document.querySelectorAll('.bio-u').forEach(c=>c.checked=this.checked)"> सगळे</label>
      <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;">PIN द्या व Machine वर पाठवा</button></div>
  </div>
  <div style="padding:10px 18px 14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:6px;">
    <?php foreach ($freeStaff as $s): ?><label style="font-size:12.5px;display:flex;gap:6px;align-items:flex-start;"><input type="checkbox" class="bio-u" name="uids[]" value="<?= (int)$s['id'] ?>"><span><?= h($s['name']) ?><br><small style="color:var(--text-dim);"><?= h($s['dept'] ?: '—') ?><?= $s['campus'] ? ' · 🏫 ' . h($s['campus']) : '' ?><?= $s['employee_code'] ? ' · ' . h($s['employee_code']) : '' ?></small></span></label><?php endforeach; ?>
  </div>
</form>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
