<?php
// 📋 जुन्या Software ची विभागनिहाय कर्मचारी यादी (Employee List .xls / .xlsx / .csv) → E-Admin
//   1) File + Campus निवडा  2) Preview: नवीन विभाग, नवीन / अद्ययावत कर्मचारी, आधीचे काढायचे, इशारे
//   3) खात्री → एकाच वेळी: विभाग तयार, कर्मचारी जोडा/अद्ययावत (Emp Id ने ओळखून), आधीचे निष्क्रिय/हटवा,
//      Emp Id = Biometric PIN, HOD नेमणूक; नवीन Login (Username/Password) ची यादी Download
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/sheet-reader.php';
require_once __DIR__ . '/../includes/emp-list-import.php';
$orgId = activeOrgId();
$me = currentUser();

$cs = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? ORDER BY name"); $cs->execute([$orgId]);
$campuses = $cs->fetchAll(PDO::FETCH_KEY_PAIR);

// नवीन Login ची यादी — एकदाच Download (Session मधून)
if (($_GET['download'] ?? '') === 'credentials') {
    $cred = $_SESSION['emplist_credentials'] ?? [];
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="karmachari-login-' . date('Y-m-d') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Emp Id', 'नाव', 'विभाग', 'पद', 'Mobile', 'Login (Username)', 'Password (पहिल्या Login ला बदलावा लागेल)']);
    foreach ($cred as $c) fputcsv($o, [$c['empid'], $c['name'], $c['dept'], $c['desg'], $c['mobile'], $c['login'], $c['password']]);
    exit;
}

$step = 'upload'; $error = null; $result = null;
$P = $_SESSION['emplist_import'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['act'] ?? '';
    if ($act === 'preview') {
        $campusId = (int)($_POST['campus_id'] ?? 0);
        if (!isset($campuses[$campusId])) { $error = 'Campus / College निवडा.'; }
        elseif (empty($_FILES['list_file']['tmp_name']) || ($_FILES['list_file']['error'] ?? 1) !== UPLOAD_ERR_OK) { $error = 'यादीची File निवडा.'; }
        else {
            try {
                $parsed = empListParse(eowmsReadSheet($_FILES['list_file']['tmp_name'], $_FILES['list_file']['name']));
                if (!$parsed['employees']) throw new RuntimeException('यादीत एकही कर्मचारी सापडला नाही.');
                $_SESSION['emplist_import'] = $P = ['token' => bin2hex(random_bytes(8)), 'campus_id' => $campusId, 'file' => mb_substr((string)$_FILES['list_file']['name'], 0, 120)] + $parsed;
                $step = 'preview';
            } catch (Throwable $e) { $error = $e->getMessage(); }
        }
    } elseif ($act === 'apply' && $P && hash_equals($P['token'], (string)($_POST['token'] ?? ''))) {
        $result = empListApply($pdo, $orgId, $me, $P, [
            'old' => in_array($_POST['old_action'] ?? '', ['keep', 'deactivate', 'delete'], true) ? $_POST['old_action'] : 'deactivate',
            'pin' => !empty($_POST['pin_from_empid']),
            'hod' => !empty($_POST['make_hod']),
        ]);
        unset($_SESSION['emplist_import']); $P = null;
        $_SESSION['emplist_credentials'] = $result['credentials'];
        $step = 'done';
    } elseif ($act === 'cancel') {
        unset($_SESSION['emplist_import']); $P = null;
    }
}
if ($step === 'upload' && $P && ($_GET['step'] ?? '') === 'preview') { $step = 'preview'; }

/** प्रत्यक्ष बदल */
function empListApply(PDO $pdo, $orgId, array $me, array $P, array $opt) {
    $campusId = (int)$P['campus_id'];
    $R = ['created' => 0, 'updated' => 0, 'depts_new' => [], 'removed' => 0, 'remove_failed' => [], 'pins' => 0, 'hods' => 0, 'credentials' => [], 'notes' => []];
    $domain = empListDomain($pdo, $orgId);
    $hasCol = function ($c) use ($pdo) { static $cols = null; if ($cols === null) { $cols = array_column($pdo->query("SHOW COLUMNS FROM users")->fetchAll(), 'Field'); } return in_array($c, $cols, true); };

    $pdo->beginTransaction();
    try {
        // 1) विभाग — असलेला वापरा, नसेल तर तयार करा
        $ds = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND campus_id = ?"); $ds->execute([$orgId, $campusId]);
        $campusDepts = $ds->fetchAll();
        $usedCodes = array_map('strtoupper', array_column($campusDepts, 'code'));
        $deptId = [];
        foreach (array_keys($P['depts']) as $dn) {
            $d = empListFindDept($campusDepts, $dn);
            if (!$d) {
                $hint = empListDeptHints($dn);
                $base = $hint[0] ?? strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', implode('', array_map(fn($w) => $w[0] ?? '', preg_split('/\s+/', $dn))) . preg_replace('/[^A-Za-z]/', '', $dn)), 0, 4));
                $code = $base ?: 'DPT'; $k = 2;
                while (in_array(strtoupper($code), $usedCodes, true)) { $code = substr($base, 0, 3) . $k++; }
                $usedCodes[] = strtoupper($code);
                $pdo->prepare("INSERT INTO departments (organization_id, campus_id, name, name_local, code, is_active) VALUES (?, ?, ?, ?, ?, 1)")
                    ->execute([$orgId, $campusId, $dn, $hint[1] ?? null, $code]);
                $d = ['id' => (int)$pdo->lastInsertId(), 'name' => $dn, 'name_local' => $hint[1] ?? null, 'code' => $code, 'hod_id' => null];
                $campusDepts[] = $d; $R['depts_new'][] = $dn . ' (' . $code . ')';
            } elseif (empty($d['is_active'] ?? 1)) {
                $pdo->prepare("UPDATE departments SET is_active = 1 WHERE id = ?")->execute([$d['id']]);
            }
            $deptId[$dn] = (int)$d['id'];
        }

        // 2) कर्मचारी — Emp Id (कर्मचारी कोड) ने ओळख, नसेल तर Email ने; नाहीतर नवीन
        $byCode = $pdo->prepare("SELECT * FROM users WHERE employee_code = ?");
        $byEmail = $pdo->prepare("SELECT * FROM users WHERE email = ?");
        $pinOwner = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND biometric_pin = ? AND id <> ?");
        $touched = [];
        foreach ($P['employees'] as $e) {
            $did = $deptId[$e['dept']] ?? null;
            $u = null;
            if ($e['empid'] !== '') { $byCode->execute([$e['empid']]); $u = $byCode->fetch() ?: null; if ($u && (int)$u['organization_id'] !== (int)$orgId) { $R['notes'][] = "{$e['name']}: Emp Id {$e['empid']} दुसऱ्या संस्थेत वापरात — कोडशिवाय जोडला"; $u = null; $e['empid_conflict'] = true; } }
            if (!$u && $e['email']) { $byEmail->execute([$e['email']]); $x = $byEmail->fetch(); if ($x && (int)$x['organization_id'] === (int)$orgId && $x['role'] !== 'super_admin') $u = $x; }
            $role = $opt['hod'] && $e['is_hod'] ? 'hod' : 'staff';
            $fields = [
                'campus_id' => $campusId, 'department_id' => $did, 'name' => $e['name'], 'designation' => $e['designation'], 'gender' => $e['gender'],
                'date_of_birth' => $e['dob'], 'mobile' => $e['mobile'], 'whatsapp_number' => $e['whatsapp'], 'aadhar_number' => $e['aadhar'],
                'bank_account_no' => $e['bank_acc'], 'ifsc_code' => $e['ifsc'], 'bank_name' => $e['bank_name'], 'branch_name' => $e['branch_name'],
                'permanent_address' => $e['village'], 'local_address' => $e['current'], 'joining_date' => $e['doj'],
            ];
            $fields = array_filter($fields, fn($v, $k) => $hasCol($k), ARRAY_FILTER_USE_BOTH);
            if ($u) {
                // असलेला — नवीन माहिती भरा (रिकामी माहिती आधीची पुसत नाही), सक्रिय करा
                $set = []; $vals = [];
                foreach ($fields as $k => $v) {
                    if (in_array($k, ['campus_id', 'department_id', 'name'], true)) { $set[] = "`$k` = ?"; $vals[] = $v; }
                    else { $set[] = "`$k` = COALESCE(?, `$k`)"; $vals[] = $v; }
                }
                $set[] = 'is_active = 1';
                if ($e['empid'] !== '' && empty($e['empid_conflict'])) { $set[] = 'employee_code = ?'; $vals[] = $e['empid']; }
                if ($role === 'hod' && in_array($u['role'], ['staff', 'hod'], true)) { $set[] = "role = 'hod'"; }
                $vals[] = $u['id'];
                $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ?")->execute($vals);
                $uid = (int)$u['id']; $R['updated']++;
            } else {
                // नवीन — Login: यादीतला Email (वापरात नसेल तर), नाहीतर Emp Id@संस्था-Domain
                $login = null;
                if ($e['email']) { $byEmail->execute([$e['email']]); if (!$byEmail->fetch()) $login = $e['email']; }
                if (!$login) {
                    $baseL = ($e['empid'] !== '' && empty($e['empid_conflict']) ? 'emp' . preg_replace('/[^A-Za-z0-9]/', '', $e['empid']) : 'emp' . substr(bin2hex(random_bytes(3)), 0, 6)) . '@' . $domain;
                    $login = $baseL; $k = 2;
                    while (true) { $byEmail->execute([$login]); if (!$byEmail->fetch()) break; $login = str_replace('@', '-' . $k++ . '@', $baseL); }
                }
                $pw = empListPassword();
                $cols = array_merge(['organization_id' => $orgId, 'employee_code' => ($e['empid'] !== '' && empty($e['empid_conflict'])) ? $e['empid'] : null, 'email' => $login,
                    'password' => password_hash($pw, PASSWORD_DEFAULT), 'must_change_password' => 1, 'role' => $role, 'is_active' => 1], $fields);
                $pdo->prepare("INSERT INTO users (`" . implode('`, `', array_keys($cols)) . "`) VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")->execute(array_values($cols));
                $uid = (int)$pdo->lastInsertId(); $R['created']++;
                $R['credentials'][] = ['empid' => $e['empid'], 'name' => $e['name'], 'dept' => $e['dept'], 'desg' => $e['designation'], 'mobile' => $e['mobile'], 'login' => $login, 'password' => $pw];
            }
            $touched[$uid] = true;
            // Emp Id = Biometric Machine PIN (जुन्या Machine वर तोच PIN असतो)
            if ($opt['pin'] && $hasCol('biometric_pin') && preg_match('/^\d{1,9}$/', $e['empid'])) {
                $pinOwner->execute([$orgId, $e['empid'], $uid]);
                if (!$pinOwner->fetch()) { $pdo->prepare("UPDATE users SET biometric_pin = ? WHERE id = ?")->execute([$e['empid'], $uid]); $R['pins']++; }
                else { $R['notes'][] = "{$e['name']}: PIN {$e['empid']} आधीच दुसऱ्याला — PIN दिला नाही"; }
            }
            // विभागप्रमुख
            if ($opt['hod'] && $e['is_hod'] && $did) { $pdo->prepare("UPDATE departments SET hod_id = ? WHERE id = ?")->execute([$uid, $did]); $R['hods']++; }
        }
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('emp list import: ' . $ex->getMessage());
        return $R + ['fatal' => 'Import पूर्ण झालं नाही — काहीही बदललं नाही. कारण: ' . mb_substr($ex->getMessage(), 0, 200)];
    }

    // 3) आधीचे कर्मचारी (या Campus चे, यादीत नसलेले) — Management / Super Admin / स्वतः सोडून
    if ($opt['old'] !== 'keep') {
        foreach (empListOldStaff($pdo, $orgId, $campusId, array_keys($touched), (int)$me['id']) as $o) {
            if ($opt['old'] === 'delete') {
                if (staffHardDelete($pdo, $o['id'])) $R['removed']++; else { $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$o['id']]); $R['remove_failed'][] = $o['name']; }
            } else { $pdo->prepare("UPDATE users SET is_active = 0 WHERE id = ?")->execute([$o['id']]); $R['removed']++; }
        }
    }
    logActivity('कर्मचारी यादी आयात केली', 'user', null, "{$R['created']} नवीन, {$R['updated']} अद्ययावत, " . count($R['depts_new']) . " नवीन विभाग, {$R['removed']} आधीचे " . ($opt['old'] === 'delete' ? 'हटवले' : 'निष्क्रिय'));
    return $R;
}

/** या Campus चे आधीचे कर्मचारी जे यादीत नाहीत (Management / Super Admin / स्वतः सोडून) */
function empListOldStaff(PDO $pdo, $orgId, $campusId, array $keepIds, $meId) {
    $st = $pdo->prepare("SELECT u.id, u.name, u.employee_code, u.designation, d.name dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN role_definitions rd ON rd.role_key = u.role
        WHERE u.organization_id = ? AND u.campus_id = ? AND u.is_active = 1 AND u.role <> 'super_admin' AND COALESCE(rd.category, 'staff') NOT IN ('management', 'super_admin') AND u.id <> ? ORDER BY d.name, u.name");
    $st->execute([$orgId, $campusId, $meId]);
    return array_values(array_filter($st->fetchAll(), fn($u) => !isset(array_flip($keepIds)[$u['id']])));
}

$pageTitle = 'कर्मचारी यादी आयात';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow">Super Admin · <?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
  <div class="page-title mb-0">📋 कर्मचारी यादी आयात (विभागनिहाय)</div>
  <a href="import.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>आयात पान</a>
</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">जुन्या Software ची "Employee List" (Establishment Department) जशीच्या तशी — .xls / .xlsx / .csv. विभाग नसेल तर आपोआप तयार होतो.</div>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title">1️⃣ File व Campus निवडा</div><div class="sub">आधी Preview दिसेल — खात्री केल्याशिवाय काहीही बदलत नाही</div></div>
      <form method="POST" enctype="multipart/form-data" style="padding:18px 22px;">
        <?= csrfField() ?><input type="hidden" name="act" value="preview">
        <label class="form-label-custom">कोणत्या Campus / College चे कर्मचारी?</label>
        <select name="campus_id" class="form-select-custom mb-3" required>
          <option value="">— निवडा —</option>
          <?php foreach ($campuses as $id => $n): ?><option value="<?= (int)$id ?>"><?= h($n) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">कर्मचारी यादीची File (.xls / .xlsx / .csv)</label>
        <div class="upload-box mb-3"><input type="file" name="list_file" accept=".xls,.xlsx,.csv" required style="width:100%;"></div>
        <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-eye me-1"></i>Preview पाहा</button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">ℹ️ काय होईल</div></div>
      <ul style="font-size:12.5px;color:var(--text-dim);padding:14px 20px 14px 36px;margin:0;line-height:1.7;">
        <li>यादीतला प्रत्येक <b>विभाग</b> त्या Campus मध्ये शोधतो — नसेल तर <b>नवीन तयार</b> (मराठी नाव व Code सह).</li>
        <li>कर्मचारी <b>Emp Id</b> ने ओळखतो — आधीच असेल तर माहिती <b>अद्ययावत</b>, नसेल तर <b>नवीन</b>.</li>
        <li>नाव, पद, Gender, जन्मतारीख, Mobile, Email, Aadhar, बँक खाते, IFSC, शाखा, बँक, गावचा व सध्याचा पत्ता — सगळं भरतो.</li>
        <li><b>Emp Id = Biometric PIN</b> — जुन्या Machine वरचे ठसे तसेच चालतील.</li>
        <li>"Head of Department" असलेले — त्या विभागाचे <b>HOD</b>.</li>
        <li>यादीत नसलेले <b>आधीचे कर्मचारी</b> — निष्क्रिय / कायमचे हटवा / तसेच ठेवा (Preview मध्ये निवडा). Management व Super Admin ला हात लावत नाही.</li>
        <li>नवीन कर्मचाऱ्यांचे Login (Emp Id ने) व Password — शेवटी Excel मध्ये Download.</li>
      </ul>
    </div>
  </div>
</div>

<?php elseif ($step === 'preview' && $P):
  $campusId = (int)$P['campus_id'];
  $ds = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND campus_id = ?"); $ds->execute([$orgId, $campusId]); $campusDepts = $ds->fetchAll();
  $codes = array_values(array_filter(array_column($P['employees'], 'empid'))); $existing = [];
  if ($codes) { $q = $pdo->prepare("SELECT employee_code, id, organization_id, is_active FROM users WHERE employee_code IN (" . implode(',', array_fill(0, count($codes), '?')) . ")"); $q->execute($codes); foreach ($q->fetchAll() as $x) { if ((int)$x['organization_id'] === (int)$orgId) $existing[$x['employee_code']] = $x; } }
  $keep = array_map(fn($x) => (int)$x['id'], $existing);
  $old = empListOldStaff($pdo, $orgId, $campusId, $keep, (int)$me['id']);
  $newCount = count(array_filter($P['employees'], fn($e) => $e['empid'] === '' || !isset($existing[$e['empid']])));
  $newDepts = array_filter(array_keys($P['depts']), fn($d) => !empListFindDept($campusDepts, $d));
?>
  <?php smartTiles([
      ['label' => 'यादीतले कर्मचारी', 'value' => count($P['employees']), 'sub' => $P['file']],
      ['label' => '🆕 नवीन जोडले जातील', 'value' => $newCount, 'tone' => 'ok'],
      ['label' => '🔄 अद्ययावत होतील', 'value' => count($P['employees']) - $newCount, 'sub' => 'Emp Id ने ओळखले'],
      ['label' => '🏢 विभाग', 'value' => count($P['depts']), 'sub' => count($newDepts) . ' नवीन तयार होतील'],
      ['label' => '👋 आधीचे (यादीत नाहीत)', 'value' => count($old), 'tone' => $old ? 'warn' : '', 'sub' => $campuses[$campusId] ?? ''],
  ]); ?>
  <?php if ($P['warnings']): ?><div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;"><b>⚠️ <?= count($P['warnings']) ?> गोष्टी तपासा</b> (बाकी Import होईल):<ul class="mb-0 mt-1"><?php foreach (array_slice($P['warnings'], 0, 20) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

  <form method="POST" class="panel mb-3" style="padding:16px 20px;border:2px solid var(--rail);">
    <?= csrfField() ?><input type="hidden" name="act" value="apply"><input type="hidden" name="token" value="<?= h($P['token']) ?>">
    <div style="font-weight:700;margin-bottom:8px;">2️⃣ खात्री करा — <?= h($campuses[$campusId] ?? '') ?></div>
    <div class="row g-3">
      <div class="col-md-6">
        <div class="form-label-custom">यादीत नसलेले आधीचे <?= count($old) ?> कर्मचारी:</div>
        <label class="d-block" style="font-size:13px;"><input type="radio" name="old_action" value="deactivate" checked> <b>निष्क्रिय करा</b> <small style="color:var(--text-dim);">(शिफारस — Login बंद, यादीतून गायब; जुनी हजेरी/नोंदी सुरक्षित; हवं तर परत चालू करता येतात)</small></label>
        <label class="d-block" style="font-size:13px;"><input type="radio" name="old_action" value="delete"> <b style="color:var(--rose);">कायमचे हटवा</b> <small style="color:var(--text-dim);">(त्यांच्या सगळ्या नोंदींसह — 30 दिवस Recycle Bin मध्ये)</small></label>
        <label class="d-block" style="font-size:13px;"><input type="radio" name="old_action" value="keep"> तसेच ठेवा</label>
      </div>
      <div class="col-md-6">
        <label class="d-block" style="font-size:13px;"><input type="checkbox" name="pin_from_empid" value="1" checked> <b>Emp Id हाच Biometric Machine PIN</b> <small style="color:var(--text-dim);">(जुन्या Machine वर नोंदलेले ठसे तसेच चालतील)</small></label>
        <label class="d-block" style="font-size:13px;"><input type="checkbox" name="make_hod" value="1" checked> <b>"Head of Department" → त्या विभागाचे HOD</b></label>
      </div>
    </div>
    <div class="d-flex gap-2 mt-3 flex-wrap">
      <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 22px;font-weight:700;" onclick="return confirm('<?= count($P['employees']) ?> कर्मचारी आयात करायचे? यादीत नसलेल्या आधीच्या कर्मचाऱ्यांवर निवडलेली कृती होईल.');"><i class="fa-solid fa-check me-1"></i>आयात करा</button>
      <button name="act" value="cancel" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;" formnovalidate>रद्द करा</button>
    </div>
  </form>

  <div class="row g-3">
    <div class="col-lg-4">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">🏢 विभाग</div></div>
        <table class="rtable"><thead><tr><th>विभाग</th><th class="text-end">कर्मचारी</th><th></th></tr></thead><tbody>
          <?php foreach ($P['depts'] as $dn => $n): $d = empListFindDept($campusDepts, $dn); $hint = empListDeptHints($dn); ?>
            <tr><td><b><?= h($dn) ?></b><?php if ($d): ?><div style="font-size:11px;color:var(--text-dim);">→ <?= h($d['name']) ?> (<?= h($d['code']) ?>)</div><?php elseif ($hint): ?><div style="font-size:11px;color:var(--text-dim);"><?= h($hint[1]) ?></div><?php endif; ?></td>
              <td class="text-end font-mono"><?= (int)$n ?></td><td><?= $d ? '<span class="status-chip done">आहे</span>' : '<span class="status-chip pending">🆕 नवीन</span>' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
    </div>
    <div class="col-lg-8">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">👥 कर्मचारी (<?= count($P['employees']) ?>)</div></div>
        <div style="max-height:520px;overflow:auto;">
        <table class="rtable" style="font-size:12.5px;"><thead><tr><th>Emp Id</th><th>नाव</th><th>विभाग / पद</th><th>Mobile</th><th>जन्मतारीख</th><th></th></tr></thead><tbody>
          <?php foreach ($P['employees'] as $e): $isOld = $e['empid'] !== '' && isset($existing[$e['empid']]); ?>
            <tr><td class="font-mono"><?= h($e['empid'] ?: '—') ?></td><td><b><?= h($e['name']) ?></b><?= $e['gender'] ? '<div style="font-size:11px;color:var(--text-dim);">' . h($e['gender'] === 'Male' ? 'पुरुष' : 'स्त्री') . ($e['aadhar'] ? ' · Aadhar ✓' : '') . ($e['bank_acc'] ? ' · बँक ✓' : '') . '</div>' : '' ?></td>
              <td><?= h($e['dept']) ?><div style="font-size:11px;color:var(--text-dim);"><?= h($e['designation'] ?: '—') ?><?= $e['is_hod'] ? ' · 👑 HOD' : '' ?></div></td>
              <td class="font-mono"><?= h($e['mobile'] ?: '—') ?></td><td class="font-mono"><?= $e['dob'] ? date('d-m-Y', strtotime($e['dob'])) : '—' ?></td>
              <td><?= $isOld ? '<span class="status-chip done">🔄 अद्ययावत</span>' : '<span class="status-chip pending">🆕 नवीन</span>' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
        </div>
      </div>
    </div>
  </div>
  <?php if ($old): ?>
  <div class="panel mt-3">
    <div class="panel-head"><div class="title">👋 यादीत नसलेले आधीचे कर्मचारी (<?= count($old) ?>)</div><div class="sub">वर निवडलेली कृती यांच्यावर होईल</div></div>
    <div style="padding:10px 18px;display:flex;flex-wrap:wrap;gap:6px;">
      <?php foreach ($old as $o): ?><span class="status-chip" style="background:var(--canvas);font-size:11.5px;"><?= h($o['name']) ?><?= $o['dept'] ? ' · ' . h($o['dept']) : '' ?></span><?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

<?php elseif ($step === 'done' && $result): ?>
  <?php if (!empty($result['fatal'])): ?>
    <div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($result['fatal']) ?></div>
  <?php else: ?>
    <?php smartTiles([
        ['label' => '🆕 नवीन कर्मचारी', 'value' => $result['created'], 'tone' => 'ok'],
        ['label' => '🔄 अद्ययावत', 'value' => $result['updated']],
        ['label' => '🏢 नवीन विभाग', 'value' => count($result['depts_new'])],
        ['label' => '👋 आधीचे काढले', 'value' => $result['removed']],
        ['label' => '🖐️ Biometric PIN दिले', 'value' => $result['pins'], 'sub' => $result['hods'] . ' HOD नेमले'],
    ]); ?>
    <?php if ($result['depts_new']): ?><div class="alert alert-info" style="border-radius:10px;font-size:12.5px;">🏢 नवीन विभाग: <?= h(implode(', ', $result['depts_new'])) ?></div><?php endif; ?>
    <?php if ($result['remove_failed']): ?><div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;">हे कायमचे हटवता आले नाहीत (त्यांच्या नोंदी अडकल्या) — त्याऐवजी निष्क्रिय केले: <?= h(implode(', ', $result['remove_failed'])) ?></div><?php endif; ?>
    <?php if ($result['notes']): ?><div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;"><ul class="mb-0"><?php foreach ($result['notes'] as $n): ?><li><?= h($n) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <?php if ($result['credentials']): ?>
      <div class="panel" style="border:1.5px solid var(--rail);">
        <div class="panel-head"><div class="title">🔑 नवीन कर्मचाऱ्यांचे Login (<?= count($result['credentials']) ?>)</div>
          <div class="sub">Login ला Emp Id (किंवा हा Username) व Password — पहिल्या Login ला Password बदलावा लागेल. <b>ही यादी आत्ताच Download करा</b> — परत दिसणार नाही.</div></div>
        <div style="padding:10px 18px;"><a href="?download=credentials" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;"><i class="fa-solid fa-file-excel me-1"></i>Login यादी Download (Excel)</a></div>
        <div style="max-height:420px;overflow:auto;">
          <table class="rtable font-mono" style="font-size:12px;"><thead><tr><th>Emp Id</th><th>नाव</th><th>विभाग</th><th>Username</th><th>Password</th></tr></thead><tbody>
            <?php foreach ($result['credentials'] as $c): ?><tr><td><?= h($c['empid']) ?></td><td><?= h($c['name']) ?></td><td><?= h($c['dept']) ?></td><td><?= h($c['login']) ?></td><td style="font-weight:700;color:var(--rail);"><?= h($c['password']) ?></td></tr><?php endforeach; ?>
          </tbody></table>
        </div>
      </div>
    <?php endif; ?>
    <div class="mt-3 d-flex gap-2 flex-wrap"><a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">👥 कर्मचारी यादी पाहा</a><a href="../departments/index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">🏢 विभाग पाहा</a><a href="../biometric/pins.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;">🖐️ Biometric PIN</a></div>
  <?php endif; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
