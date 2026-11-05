<?php
// 🎓 E-Campus कामांचं वाटप (Super Admin) — कोणत्या कर्मचाऱ्याला कोणतं E-Campus काम; Excel/CSV मधून एकदम आयात
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
requireMigratedTable($pdo, 'ecampus_progress', '../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
require_once __DIR__ . '/../includes/sheet-reader.php';
$me = currentUser();
$orgId = activeOrgId();
$flash = $_SESSION['ec_flash'] ?? null; unset($_SESSION['ec_flash']);
$error = null;
$back = function ($msg) { $_SESSION['ec_flash'] = $msg; header('Location: manage.php'); exit; };
$tmpDir = __DIR__ . '/../storage/tmp';

$staffStmt = $pdo->prepare("SELECT id, name, email, designation, employee_code FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$staff = $staffStmt->fetchAll();
$staffIds = array_column($staff, 'id');

/** नाव जुळवण्यासाठी — Shri/Mr./Mrs./Saheb वगैरे काढून */
$normName = function ($s) {
    $s = mb_strtolower(trim((string)$s));
    $s = preg_replace('/\b(shri|smt|mr|mrs|ms|miss|dr|sau|saheb|sir)\b\.?/u', ' ', $s);
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
};
$staffByKey = [];
foreach ($staff as $s) {
    foreach ([$s['email'], $s['employee_code'], $normName($s['name'])] as $k) { if ($k) { $staffByKey[mb_strtolower($k)] = $s; } }
}
$matchStaff = function ($raw) use ($staffByKey, $normName) {
    $raw = trim((string)$raw);
    return $staffByKey[mb_strtolower($raw)] ?? $staffByKey[$normName($raw)] ?? null;
};

/** Excel मधल्या ओळी → नेमणुका (Header कुठल्याही ओळीत असू शकतो; Columns नावाने ओळखतो) */
$parseImport = function (array $rows) use ($matchStaff) {
    $hi = null; $map = [];
    foreach ($rows as $i => $r) {
        $line = mb_strtolower(implode(' | ', array_map('strval', $r)));
        if (preg_match('/staff|कर्मचारी/u', $line) && preg_match('/task|काम/u', $line)) { $hi = $i; break; }
    }
    if ($hi === null) { return [null, 'Header ओळ सापडली नाही — "Staff Name" आणि "Assigned Task" असे Columns हवेत.']; }
    foreach ($rows[$hi] as $c => $h) {
        $h = mb_strtolower((string)$h);
        $k = match (true) {
            (bool)preg_match('/wing|category|विंग/u', $h) => 'wing',
            (bool)preg_match('/section|department|विभाग/u', $h) => 'section',
            (bool)preg_match('/staff|कर्मचारी|email/u', $h) => 'staff',
            (bool)preg_match('/designation|पद/u', $h) => 'designation',
            (bool)preg_match('/assigned|task|काम/u', $h) => 'task',
            (bool)preg_match('/note|remark|नोंद/u', $h) => 'notes',
            (bool)preg_match('/%|percent|complete|टक्के/u', $h) => 'percent',
            default => null,
        };
        if ($k && !isset($map[$k])) { $map[$k] = $c; }
    }
    foreach (['staff', 'task'] as $need) { if (!isset($map[$need])) { return [null, '"' . $need . '" Column सापडला नाही.']; } }
    $items = []; $lastWing = ''; $lastSection = '';
    foreach (array_slice($rows, $hi + 1) as $j => $r) {
        $g = fn($k) => isset($map[$k]) ? trim(preg_replace('/\s+/u', ' ', (string)($r[$map[$k]] ?? ''))) : '';
        $staffRaw = $g('staff'); $task = $g('task');
        if ($staffRaw === '' && $task === '') { continue; }
        if (preg_match('/^(sr\.?|wing|staff)/i', $staffRaw)) { continue; } // पुन्हा आलेला Header (दुसरं पान)
        $wing = $g('wing') ?: $lastWing; $section = $g('section') ?: $lastSection;
        $lastWing = $wing; $lastSection = $section;
        $pctRaw = $g('percent');
        $pct = $pctRaw === '' ? null : (is_numeric(str_replace('%', '', $pctRaw)) ? (float)str_replace('%', '', $pctRaw) : null);
        if ($pct !== null && $pct > 0 && $pct <= 1 && !str_contains($pctRaw, '%')) { $pct *= 100; } // Excel मध्ये 97% = 0.97
        $u = $matchStaff($staffRaw);
        $items[] = ['line' => $hi + $j + 2, 'wing' => $wing ?: 'इतर', 'section' => $section ?: '—', 'staffRaw' => $staffRaw, 'user' => $u, 'designation' => $g('designation'),
            'task' => $task, 'notes' => $g('notes'), 'percent' => $pct === null ? null : (int)round($pct),
            'view_only' => (bool)preg_match('/only\s*visible|view\s*only|फक्त\s*पाहण्या/i', $task)];
    }
    return [$items, null];
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $uid = (int)($_POST['user_id'] ?? 0);
        $wing = trim($_POST['wing'] ?? ''); $section = trim($_POST['section'] ?? ''); $task = trim($_POST['task_description'] ?? '');
        if (!in_array($uid, array_map('intval', $staffIds), true) || $wing === '' || $section === '' || $task === '') {
            $error = 'Wing, Section, कर्मचारी आणि काम — चारही भरा.';
        } else {
            $vals = [mb_substr($wing, 0, 120), mb_substr($section, 0, 160), $uid, mb_substr(trim($_POST['designation'] ?? ''), 0, 160) ?: null, $task,
                ($_POST['task_type'] ?? '') === 'one_time' ? 'one_time' : 'monthly', ($_POST['access_type'] ?? '') === 'view_only' ? 'view_only' : 'work', (int)($_POST['sort_order'] ?? 0)];
            if ($id) {
                $pdo->prepare("UPDATE ecampus_tasks SET wing = ?, section = ?, user_id = ?, designation = ?, task_description = ?, task_type = ?, access_type = ?, sort_order = ? WHERE id = ? AND organization_id = ?")
                    ->execute(array_merge($vals, [$id, $orgId]));
                logActivity('E-Campus काम बदललं', 'ecampus_task', $id, "$section — $task");
                $back('✅ काम अद्ययावत केलं.');
            }
            $pdo->prepare("INSERT INTO ecampus_tasks (wing, section, user_id, designation, task_description, task_type, access_type, sort_order, organization_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute(array_merge($vals, [$orgId, $me['id']]));
            logActivity('E-Campus काम नेमलं', 'ecampus_task', (int)$pdo->lastInsertId(), "$section — $task");
            $back('✅ काम नेमलं — त्या कर्मचाऱ्याला "माझी E-Campus कामं" मध्ये दिसेल.');
        }
    } elseif ($a === 'remove') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE ecampus_tasks SET is_active = 0 WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        logActivity('E-Campus काम काढलं', 'ecampus_task', $id);
        $back('काम काढलं (जुना इतिहास अहवालात राहतो).');
    } elseif ($a === 'import_preview') {
        $up = $_FILES['file'] ?? null;
        try {
            if (!$up || $up['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($up['tmp_name'])) { throw new RuntimeException('File मिळाली नाही.'); }
            $rows = eowmsReadSheet($up['tmp_name'], $up['name']);
            [$items, $err] = $parseImport($rows);
            if ($err) { throw new RuntimeException($err); }
            if (!is_dir($tmpDir)) { @mkdir($tmpDir, 0755, true); }
            $tok = bin2hex(random_bytes(16));
            file_put_contents("$tmpDir/ecimport-$tok.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
            $_SESSION['ecimport'] = ['token' => $tok, 'org' => $orgId, 'name' => mb_substr($up['name'], 0, 120)];
            header('Location: manage.php?import=1#import');
            exit;
        } catch (Throwable $e) { $error = $e->getMessage(); }
    } elseif ($a === 'import_apply') {
        $tok = preg_replace('/[^a-f0-9]/', '', $_SESSION['ecimport']['token'] ?? '');
        $file = "$tmpDir/ecimport-$tok.json";
        if ($tok && is_file($file) && (int)$_SESSION['ecimport']['org'] === (int)$orgId) {
            [$items] = $parseImport(json_decode(file_get_contents($file), true) ?: []);
            $withProgress = !empty($_POST['with_progress']);
            $n = 0; $p = 0;
            $pdo->beginTransaction();
            foreach ($items ?: [] as $i => $it) {
                $uid = (int)($_POST['map'][$i] ?? ($it['user']['id'] ?? 0));
                if (!$uid || !in_array($uid, array_map('intval', $staffIds), true) || $it['task'] === '') { continue; }
                $pdo->prepare("INSERT INTO ecampus_tasks (organization_id, wing, section, user_id, designation, task_description, task_type, access_type, sort_order, created_by) VALUES (?, ?, ?, ?, ?, ?, 'monthly', ?, ?, ?)")
                    ->execute([$orgId, mb_substr($it['wing'], 0, 120), mb_substr($it['section'], 0, 160), $uid, $it['designation'] ?: null, $it['task'], $it['view_only'] ? 'view_only' : 'work', $it['line'], $me['id']]);
                $tid = (int)$pdo->lastInsertId();
                $n++;
                if ($withProgress && !$it['view_only'] && ($it['percent'] !== null || $it['notes'] !== '')) { ecampusSave($pdo, $tid, date('Y-m'), (int)$it['percent'], $it['notes'], $me['id']); $p++; }
            }
            $pdo->commit();
            @unlink($file);
            unset($_SESSION['ecimport']);
            logActivity('E-Campus कामं आयात केली', 'organization', $orgId, "$n कामं, $p प्रगती");
            $back("✅ $n कामं नेमली" . ($p ? ", $p कामांची चालू महिन्याची प्रगती भरली" : '') . '.');
        }
        $error = 'Preview जुना झाला — File पुन्हा निवडा.';
    }
}

$tasks = $pdo->prepare("SELECT t.*, u.name staff_name, u.designation user_designation FROM ecampus_tasks t JOIN users u ON u.id = t.user_id WHERE t.organization_id = ? AND t.is_active = 1 ORDER BY t.sort_order, t.wing, t.section, u.name");
$tasks->execute([$orgId]);
$tasks = $tasks->fetchAll();
$edit = null;
if (!empty($_GET['edit'])) { foreach ($tasks as $t) { if ((int)$t['id'] === (int)$_GET['edit']) { $edit = $t; } } }
$wings = array_values(array_unique(array_column($tasks, 'wing')));
$sections = array_values(array_unique(array_column($tasks, 'section')));

$importItems = null;
if (isset($_GET['import']) && !empty($_SESSION['ecimport']['token'])) {
    $f = $tmpDir . '/ecimport-' . preg_replace('/[^a-f0-9]/', '', $_SESSION['ecimport']['token']) . '.json';
    if (is_file($f)) { [$importItems] = $parseImport(json_decode(file_get_contents($f), true) ?: []); }
}

$pageTitle = 'E-Campus कामांचं वाटप';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2">
  <div class="page-title mb-0">🎓 E-Campus — कामांचं वाटप</div>
  <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-chart-pie me-1"></i>Dashboard</a>
</div>
<p style="font-size:13px;color:var(--text-dim);margin:4px 0 14px;">ज्या कर्मचाऱ्याला काम नेमाल, फक्त त्यालाच ते "माझी E-Campus कामं" मध्ये दिसेल. तो दर महिन्याला % आणि नोंद भरेल.</p>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>

<?php if ($importItems !== null): ?>
<div class="panel mb-3" id="import" style="border:2px solid var(--rail);">
  <div class="panel-head"><div class="title">🔍 आयात तपासणी — <?= h($_SESSION['ecimport']['name'] ?? '') ?></div><div class="sub">कर्मचारी जुळवा आणि "आयात करा" दाबा</div></div>
  <form method="POST" style="padding:10px 16px 16px;">
    <input type="hidden" name="action" value="import_apply">
    <div style="overflow-x:auto;">
    <table class="rtable">
      <thead><tr><th>Wing / Section</th><th>File मधलं नाव</th><th>कर्मचारी (App मधला)</th><th>काम</th><th>%</th></tr></thead>
      <tbody>
      <?php foreach ($importItems as $i => $it): ?>
        <tr>
          <td style="font-size:12.5px;"><b><?= h($it['wing']) ?></b><div style="color:var(--text-dim);"><?= h($it['section']) ?></div></td>
          <td style="font-size:12.5px;"><?= h($it['staffRaw']) ?><div style="color:var(--text-dim);"><?= h($it['designation']) ?></div></td>
          <td><select name="map[<?= $i ?>]" class="form-select-custom" style="min-width:200px;<?= $it['user'] ? '' : 'border-color:var(--rose);' ?>">
              <option value="0">— वगळा —</option>
              <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= $it['user'] && (int)$it['user']['id'] === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name'] . ($s['designation'] ? ' — ' . $s['designation'] : '')) ?></option><?php endforeach; ?>
            </select><?= $it['user'] ? '' : '<div style="font-size:11px;color:var(--rose);">आपोआप जुळलं नाही — निवडा</div>' ?></td>
          <td style="font-size:12.5px;max-width:360px;white-space:normal;"><?= h(mb_strimwidth($it['task'], 0, 160, '…')) ?><?= $it['view_only'] ? ' <span style="font-size:11px;color:#2563EB;font-weight:700;">फक्त Access</span>' : '' ?></td>
          <td><?= $it['percent'] === null ? '—' : (int)$it['percent'] . '%' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <label class="d-flex align-items-center gap-2 mt-3" style="font-size:13px;"><input type="checkbox" name="with_progress" value="1" checked style="accent-color:var(--rail);width:16px;height:16px;"> File मधले % आणि नोंदी चालू महिन्याची प्रगती म्हणून भरा (<?= h(ecampusPeriodLabel(date('Y-m'))) ?>)</label>
    <div class="d-flex gap-2 mt-3"><button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;padding:9px 20px;">आयात करा</button><a href="manage.php" style="font-size:13px;align-self:center;">रद्द करा</a></div>
  </form>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head"><div class="title"><?= $edit ? '✏️ काम बदला' : '➕ नवीन काम नेमा' ?></div></div>
      <form method="POST" style="padding:16px 20px 20px;">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label-custom">Wing / Category</label><input name="wing" list="ecWings" class="form-control-custom" required value="<?= h($edit['wing'] ?? '') ?>" placeholder="उदा. AMRIT - Office"></div>
          <div class="col-md-6"><label class="form-label-custom">Section / Department</label><input name="section" list="ecSections" class="form-control-custom" required value="<?= h($edit['section'] ?? '') ?>" placeholder="उदा. Amrit Office - Degree"></div>
          <div class="col-md-6"><label class="form-label-custom">कर्मचारी</label>
            <select name="user_id" class="form-select-custom" required><option value="">— निवडा —</option>
              <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>" <?= (int)($edit['user_id'] ?? 0) === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name'] . ($s['designation'] ? ' — ' . $s['designation'] : '')) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label-custom">पद (अहवालात दिसणारं)</label><input name="designation" class="form-control-custom" value="<?= h($edit['designation'] ?? '') ?>" placeholder="रिकामं = Profile मधलं पद"></div>
          <div class="col-12"><label class="form-label-custom">नेमलेलं काम</label><textarea name="task_description" class="form-control-custom" rows="2" required placeholder="उदा. 1) Admission student data (2026-27) 2) Fees updation"><?= h($edit['task_description'] ?? '') ?></textarea></div>
          <div class="col-md-4"><label class="form-label-custom">प्रकार</label><select name="task_type" class="form-select-custom"><option value="monthly">दरमहा (प्रत्येक महिना 0% पासून)</option><option value="one_time" <?= ($edit['task_type'] ?? '') === 'one_time' ? 'selected' : '' ?>>एकदाच (प्रगती पुढे जाते)</option></select></div>
          <div class="col-md-4"><label class="form-label-custom">Access</label><select name="access_type" class="form-select-custom"><option value="work">काम करायचं</option><option value="view_only" <?= ($edit['access_type'] ?? '') === 'view_only' ? 'selected' : '' ?>>फक्त पाहण्याचा Access</option></select></div>
          <div class="col-md-4"><label class="form-label-custom">क्रम</label><input type="number" name="sort_order" class="form-control-custom" value="<?= (int)($edit['sort_order'] ?? (count($tasks) + 1)) ?>"></div>
        </div>
        <div class="d-flex gap-2 mt-3"><button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;padding:9px 20px;"><i class="fa-solid fa-floppy-disk me-1"></i>जतन करा</button><?php if ($edit): ?><a href="manage.php" style="font-size:13px;align-self:center;">रद्द करा</a><?php endif; ?></div>
        <datalist id="ecWings"><?php foreach ($wings as $w): ?><option value="<?= h($w) ?>"><?php endforeach; ?></datalist>
        <datalist id="ecSections"><?php foreach ($sections as $w): ?><option value="<?= h($w) ?>"><?php endforeach; ?></datalist>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">📥 Excel मधून एकदम आयात</div><div class="sub">तुमचा मासिक E-Campus Report (.xlsx / CSV) थेट चालतो</div></div>
      <form method="POST" enctype="multipart/form-data" style="padding:16px 20px 20px;">
        <input type="hidden" name="action" value="import_preview">
        <div class="upload-box mb-2"><input type="file" name="file" accept=".xlsx,.csv" required style="width:100%;"></div>
        <button class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-weight:600;padding:8px 18px;"><i class="fa-solid fa-magnifying-glass me-1"></i>तपासा</button>
        <ul style="font-size:12px;color:var(--text-dim);margin:10px 0 0;padding-left:16px;line-height:1.7;">
          <li>Columns: Wing / Category, Section / Department, Staff Name, Designation, Assigned Task, Progress Notes, % Complete</li>
          <li>कर्मचारी नाव / Email / Employee ID ने आपोआप जुळतो; न जुळलेले तुम्ही निवडू शकता</li>
          <li>"Only Visible Access" असलेली ओळ = फक्त पाहण्याचा Access</li>
        </ul>
      </form>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">नेमलेली कामं (<?= count($tasks) ?>)</div></div>
  <?php if (!$tasks): ?><div style="padding:24px;color:var(--text-dim);font-size:13px;">अजून एकही काम नेमलेलं नाही.</div><?php else: ?>
  <div style="overflow-x:auto;">
  <table class="rtable">
    <thead><tr><th>#</th><th>Wing / Section</th><th>कर्मचारी</th><th>काम</th><th>प्रकार</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($tasks as $t): ?>
      <tr>
        <td><?= (int)$t['sort_order'] ?></td>
        <td style="font-size:12.5px;"><b><?= h($t['wing']) ?></b><div style="color:var(--text-dim);"><?= h($t['section']) ?></div></td>
        <td style="font-size:12.5px;"><b><?= h($t['staff_name']) ?></b><div style="color:var(--text-dim);"><?= h($t['designation'] ?: $t['user_designation']) ?></div></td>
        <td style="font-size:12.5px;max-width:380px;white-space:normal;"><?= h(mb_strimwidth($t['task_description'], 0, 180, '…')) ?></td>
        <td style="font-size:12px;"><?= $t['access_type'] === 'view_only' ? '<span style="color:#2563EB;font-weight:700;">फक्त Access</span>' : ($t['task_type'] === 'monthly' ? 'दरमहा' : 'एकदाच') ?></td>
        <td style="white-space:nowrap;">
          <a href="?edit=<?= (int)$t['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;">बदला</a>
          <form method="POST" style="display:inline;" onsubmit="return confirm('हे काम काढायचं? (आधीचा इतिहास अहवालात राहील)');"><input type="hidden" name="action" value="remove"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;font-size:12px;">काढा</button></form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
