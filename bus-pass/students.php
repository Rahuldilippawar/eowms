<?php
// 👥 बस विद्यार्थी — बस पास विभागाची स्वतःची यादी (विद्यार्थी विभागापासून वेगळी): शोध, वर्ग, पास स्थिती, Excel, विद्यार्थी विभागातून आणणे
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';
$user = currentUser();
$orgId = activeOrgId();
if (!canViewBusPass()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बस पास पाहण्याची परवानगी नाही.</div>'); }
if (!busStudentsReady($pdo)) { header('Location: index.php'); exit; }
$canManage = canBusPass();
$today = date('Y-m-d');

$campusRows = $pdo->prepare("SELECT id, name, city FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusRows->execute([$orgId]);
$campusRows = $campusRows->fetchAll(PDO::FETCH_UNIQUE);

// विद्यार्थी विभागातून आणणे — फक्त ज्यांना तो विभाग दिसतो (त्यांच्या दृश्यमर्यादेतले विद्यार्थी)
$stuScope = studentsReady($pdo) ? studentVisibleScope($pdo) : [];
$canPull = $canManage && $stuScope !== [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    if ($action === 'pull' && $canPull) {
        $p = [$orgId];
        $sql = "SELECT s.* FROM students s WHERE s.organization_id = ? AND s.is_active = 1" . studentScopeSql($stuScope, $p);
        foreach (['campus' => 's.campus_id', 'year' => 's.academic_year'] as $k => $col) { if (($_POST['p_' . $k] ?? '') !== '') { $sql .= " AND $col = ?"; $p[] = $_POST['p_' . $k]; } }
        if (($_POST['p_campus'] ?? '') === '') { $_SESSION['flash'] = '⚠️ वर्ग निवडा.'; header('Location: students.php#pull'); exit; }
        $sql .= " AND COALESCE(s.class_name,'') = ? AND COALESCE(s.division,'') = ?"; $p[] = (string)($_POST['p_class'] ?? ''); $p[] = (string)($_POST['p_div'] ?? '');
        $st = $pdo->prepare($sql . " LIMIT 3000");
        $st->execute($p);
        $to = mb_substr(trim((string)($_POST['p_to'] ?? '')), 0, 120);
        $n = 0; $had = 0;
        $pdo->beginTransaction();
        foreach ($st->fetchAll() as $s) {
            $row = ['student_id' => (int)$s['id'], 'campus_id' => $s['campus_id'], 'name' => $s['name'], 'admission_no' => $s['admission_no'], 'roll_no' => $s['roll_no'], 'class_name' => $s['class_name'],
                'division' => $s['division'], 'course' => $s['course'], 'academic_year' => $s['academic_year'], 'gender' => $s['gender'], 'date_of_birth' => $s['date_of_birth'], 'mobile' => $s['mobile'],
                'parent_mobile' => $s['parent_mobile'], 'address' => $s['address'], 'blood_group' => $s['blood_group']];
            if (busStudentFind($pdo, $orgId, $row)) { $had++; continue; }
            $row['to_place'] = $to !== '' ? $to : (busDefaultTo($pdo, $orgId, (int)$s['campus_id']) ?: null);
            busStudentSave($pdo, $orgId, 0, $row, $user['id']);
            $n++;
        }
        $pdo->commit();
        if ($n) logActivity('बस विद्यार्थी — विद्यार्थी विभागातून आणले', 'organization', $orgId, $n . ' विद्यार्थी');
        $_SESSION['flash'] = '✓ ' . $n . ' विद्यार्थी बस यादीत आले.' . ($had ? ' ' . $had . ' आधीच यादीत होते.' : '');
        header('Location: students.php?' . http_build_query(array_filter(['campus' => $_POST['p_campus'] ?? '', 'year' => $_POST['p_year'] ?? '', 'class' => $_POST['p_class'] ?? '', 'div' => $_POST['p_div'] ?? '']))); exit;
    }
}

$f = ['campus' => (int)($_GET['campus'] ?? 0), 'year' => trim((string)($_GET['year'] ?? '')), 'class' => trim((string)($_GET['class'] ?? '')), 'div' => trim((string)($_GET['div'] ?? '')),
    'q' => trim((string)($_GET['q'] ?? '')), 'pass' => in_array($_GET['pass'] ?? '', ['with', 'without', 'expired'], true) ? $_GET['pass'] : '', 'status' => ($_GET['status'] ?? '') === 'left' ? 'left' : 'active'];
$years = $pdo->prepare("SELECT DISTINCT academic_year FROM bus_students WHERE organization_id = ? AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
$years->execute([$orgId]);
$years = $years->fetchAll(PDO::FETCH_COLUMN);
if ($f['year'] === '' && $years && !isset($_GET['year'])) $f['year'] = $years[0];
$qs = function (array $over = []) use ($f) { $q = array_filter(array_merge(['campus' => $f['campus'] ?: '', 'year' => $f['year'], 'class' => $f['class'], 'div' => $f['div'], 'q' => $f['q'], 'pass' => $f['pass'], 'status' => $f['status'] === 'left' ? 'left' : ''], $over), fn($v) => $v !== '' && $v !== null); return 'students.php' . ($q ? '?' . http_build_query($q) : ''); };

$lastPass = "(SELECT CONCAT_WS('|', p.valid_till, p.status, p.pass_no, p.id) FROM student_bus_passes p WHERE p.bus_student_id = s.id ORDER BY (p.status = 'active') DESC, p.valid_till DESC, p.id DESC LIMIT 1)";
$base = " FROM bus_students s LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.organization_id = ? AND s.is_active = " . ($f['status'] === 'left' ? '0' : '1');
$bp = [$orgId];
if ($f['campus']) { $base .= " AND s.campus_id = ?"; $bp[] = $f['campus']; }
if ($f['year'] !== '') { $base .= " AND s.academic_year = ?"; $bp[] = $f['year']; }
$where = $base; $wp = $bp;
if ($f['class'] !== '') { $where .= " AND COALESCE(s.class_name,'') = ?"; $wp[] = $f['class']; }
if ($f['div'] !== '') { $where .= " AND COALESCE(s.division,'') = ?"; $wp[] = $f['div']; }
if ($f['q'] !== '') { $where .= " AND (s.name LIKE ? OR s.admission_no = ? OR s.roll_no = ? OR s.from_place LIKE ? OR s.route_name LIKE ? OR s.parent_mobile LIKE ? OR s.mobile LIKE ?)"; $like = '%' . $f['q'] . '%'; array_push($wp, $like, $f['q'], $f['q'], $like, $like, $like, $like); }
$hasValid = "EXISTS(SELECT 1 FROM student_bus_passes p WHERE p.bus_student_id = s.id AND p.status = 'active' AND p.valid_till >= CURDATE())";
if ($f['pass'] === 'with') $where .= " AND $hasValid";
elseif ($f['pass'] === 'without') $where .= " AND NOT $hasValid";
elseif ($f['pass'] === 'expired') $where .= " AND NOT $hasValid AND EXISTS(SELECT 1 FROM student_bus_passes p WHERE p.bus_student_id = s.id AND p.status = 'active')";

// 📊 Excel (CSV)
if (($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare("SELECT s.*, c.name campus_name, $lastPass lastp $where ORDER BY s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.name");
    $st->execute($wp);
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bus-students-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Gr.No.', 'Roll No', 'नाव', 'College/School', 'शैक्षणिक वर्ष', 'वर्ग', 'तुकडी', 'शाखा', 'कुठून', 'कुठपर्यंत', 'मार्ग / बस', 'पालक मोबाईल', 'मोबाईल', 'पास क्र.', 'पास valid पर्यंत', 'पास स्थिती']);
    while ($r = $st->fetch()) {
        $lp = $r['lastp'] ? explode('|', $r['lastp']) : null;
        fputcsv($out, [$r['admission_no'], $r['roll_no'], $r['name'], $r['campus_name'], $r['academic_year'], $r['class_name'], $r['division'], $r['course'], $r['from_place'], $r['to_place'], $r['route_name'],
            $r['parent_mobile'], $r['mobile'], $lp[2] ?? '', $lp ? date('d/m/Y', strtotime($lp[0])) : '', $lp ? ($lp[1] === 'cancelled' ? 'रद्द' : ($lp[0] >= date('Y-m-d') ? 'चालू' : 'संपला')) : 'पास नाही']);
    }
    fclose($out); exit;
}

$kpi = $pdo->prepare("SELECT COUNT(*) n, SUM($hasValid) withp, SUM(s.from_place IS NULL OR s.from_place = '') nofrom, SUM(s.parent_mobile IS NULL AND s.mobile IS NULL) nomob $base");
$kpi->execute($bp);
$kpi = $kpi->fetch();
$cst = $pdo->prepare("SELECT COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n, SUM($hasValid) withp $base GROUP BY cl, dv ORDER BY CAST(cl AS UNSIGNED), cl, dv");
$cst->execute($bp);
$classes = $cst->fetchAll();
$total = $pdo->prepare("SELECT COUNT(*) $where");
$total->execute($wp);
$total = (int)$total->fetchColumn();
$per = 50; $pages = max(1, (int)ceil($total / $per)); $page = min($pages, max(1, (int)($_GET['pg'] ?? 1)));
$st = $pdo->prepare("SELECT s.*, c.name campus_name, $lastPass lastp $where ORDER BY s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.roll_no, s.name LIMIT $per OFFSET " . (($page - 1) * $per));
$st->execute($wp);
$rows = $st->fetchAll();

// विद्यार्थी विभागातून आणण्यासाठी वर्ग
$pullClasses = [];
if ($canPull) {
    $pp = [$orgId];
    $q = $pdo->prepare("SELECT s.campus_id, s.academic_year, COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n,
            SUM(EXISTS(SELECT 1 FROM bus_students b WHERE b.student_id = s.id)) inbus
        FROM students s WHERE s.organization_id = ? AND s.is_active = 1" . studentScopeSql($stuScope, $pp) . " GROUP BY s.campus_id, s.academic_year, cl, dv ORDER BY s.academic_year DESC, CAST(cl AS UNSIGNED), cl, dv LIMIT 200");
    $q->execute($pp);
    $pullClasses = $q->fetchAll();
}

$pageTitle = 'बस विद्यार्थी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .cl-chips{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
  .cl-chips a{font-size:12.5px;padding:5px 11px;border-radius:99px;border:1px solid var(--line);text-decoration:none;color:inherit;background:var(--surface);}
  .cl-chips a.on{background:var(--rail);border-color:var(--rail);color:#fff;}
  .cl-chips a small{opacity:.75;margin-left:3px;}
  .bs-act{display:flex;gap:6px;flex-wrap:wrap;}
  .bs-act a{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;white-space:nowrap;}
  .bs-act a.pri{background:var(--rail);border-color:var(--rail);color:#fff;}
  .bs-t td,.bs-t th{vertical-align:middle;font-size:13px;}
  .bs-av{width:34px;height:40px;border-radius:7px;object-fit:cover;background:var(--canvas);display:inline-flex;align-items:center;justify-content:center;font-weight:700;color:var(--rail);font-size:13px;vertical-align:middle;}
  .bp-st{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;white-space:nowrap;}
  .bp-st.ok{background:#DCFCE7;color:#166534;} .bp-st.exp{background:#FEE2E2;color:#991B1B;} .bp-st.none{background:#E5E7EB;color:#374151;} .bp-st.soon{background:#FEF3C7;color:#92400E;}
  @media (max-width:767px){
    .bs-t thead{display:none;} .bs-t,.bs-t tbody{display:block;width:100%;} .bs-t tr{display:block;border-bottom:1px solid var(--line);padding:8px 4px;}
    .bs-t td{display:inline-block;border:0 !important;padding:2px 6px !important;} .bs-t td.nm{display:block;} .bs-t td.cp{display:none;}
    .bs-act{width:100%;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;}
  }
</style>

<div class="page-eyebrow">🚌 विद्यार्थी वाहतूक</div>
<?php $bpTab = 'students'; require __DIR__ . '/_tabs.php'; ?>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">👥 बस विद्यार्थी</div>
  <div class="bs-act">
    <?php if ($canManage): ?>
      <a href="student.php" class="pri">＋ विद्यार्थी जोडा</a>
      <a href="import.php">📥 Excel / CSV Import</a>
      <?php if ($canPull): ?><a href="#pull">🎓 विद्यार्थी विभागातून</a><?php endif; ?>
    <?php endif; ?>
    <a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin:-2px 0 12px;">ही यादी फक्त बस पाससाठी आहे — विद्यार्थी विभागापासून वेगळी. इथे जोडलं / बदललं तरी विद्यार्थी विभागात काही बदलत नाही.</p>

<form method="GET" class="d-flex flex-wrap gap-2 mb-2" data-no-smart-filter>
  <?php if (count($campusRows) > 1): ?>
  <select name="campus" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व College / School</option>
    <?php foreach ($campusRows as $cid => $c): ?><option value="<?= (int)$cid ?>" <?= $f['campus'] === (int)$cid ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
  </select>
  <?php endif; ?>
  <select name="year" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व वर्षे</option>
    <?php foreach ($years as $y): ?><option value="<?= h($y) ?>" <?= $f['year'] === $y ? 'selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?>
  </select>
  <select name="pass" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सर्व</option>
    <option value="with" <?= $f['pass'] === 'with' ? 'selected' : '' ?>>✅ चालू पास असलेले</option>
    <option value="without" <?= $f['pass'] === 'without' ? 'selected' : '' ?>>⛔ चालू पास नसलेले</option>
    <option value="expired" <?= $f['pass'] === 'expired' ? 'selected' : '' ?>>⌛ पास संपलेले</option>
  </select>
  <select name="status" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">सक्रिय</option><option value="left" <?= $f['status'] === 'left' ? 'selected' : '' ?>>बस सोडलेले</option>
  </select>
  <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" style="width:auto;min-width:200px;" placeholder="नाव, Gr.No., गाव, मार्ग, मोबाईल…">
  <?php foreach (['class', 'div'] as $k): if ($f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= h($f[$k]) ?>"><?php endif; endforeach; ?>
</form>

<?= smartTiles([
    ['label' => 'बस विद्यार्थी', 'value' => (int)$kpi['n'], 'sub' => $f['year'] !== '' ? h($f['year']) : 'सर्व वर्षे', 'tone' => 'ok'],
    ['label' => 'चालू पास', 'value' => (int)$kpi['withp'], 'sub' => (int)$kpi['n'] ? round((int)$kpi['withp'] * 100 / (int)$kpi['n']) . '%' : '—', 'tone' => 'ok', 'href' => $qs(['pass' => 'with'])],
    ['label' => 'पास नाही', 'value' => (int)$kpi['n'] - (int)$kpi['withp'], 'sub' => 'पास द्या', 'tone' => (int)$kpi['n'] - (int)$kpi['withp'] ? 'warn' : 'ok', 'href' => $qs(['pass' => 'without'])],
    ['label' => '"कुठून" भरलेलं नाही', 'value' => (int)$kpi['nofrom'], 'sub' => (int)$kpi['nofrom'] ? 'पास देताना भरावं लागेल' : 'सगळ्यांचं आहे', 'tone' => (int)$kpi['nofrom'] ? 'warn' : 'ok'],
]) ?>

<?php if ($classes): ?>
<div class="cl-chips">
  <a href="<?= h($qs(['class' => '', 'div' => ''])) ?>" class="<?= $f['class'] === '' && $f['div'] === '' ? 'on' : '' ?>">सर्व वर्ग</a>
  <?php foreach ($classes as $c): $on = $f['class'] === $c['cl'] && $f['div'] === $c['dv']; ?>
    <a href="<?= h($qs(['class' => $c['cl'], 'div' => $c['dv']])) ?>" class="<?= $on ? 'on' : '' ?>"><?= h(($c['cl'] !== '' ? $c['cl'] : '—') . ($c['dv'] !== '' ? ' - ' . $c['dv'] : '')) ?><small>🚌 <?= (int)$c['withp'] ?>/<?= (int)$c['n'] ?></small></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel mb-3">
  <?php if (!$rows): ?>
    <div style="padding:30px;text-align:center;color:var(--text-dim);font-size:13px;">
      <?= $f['q'] !== '' || $f['pass'] !== '' ? 'या अटींनुसार कोणी नाही.' : 'अजून बस विद्यार्थी नाहीत.' ?>
      <?php if ($canManage && $f['q'] === ''): ?><div class="mt-2 d-flex justify-content-center gap-2 flex-wrap"><a href="import.php" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">📥 Excel / CSV Import</a><a href="student.php" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">＋ विद्यार्थी जोडा</a></div><?php endif; ?>
    </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm bs-t mb-0">
      <thead><tr><th></th><th>विद्यार्थी</th><th>कुठून → कुठपर्यंत</th><th>पास</th><th class="cp">College</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): $lp = $r['lastp'] ? explode('|', $r['lastp']) : null;
        $ph = !empty($r['photo_path']) && eowmsPhotoExists($r['photo_path']) ? $r['photo_path'] : '';
        if (!$lp) { $stc = 'none'; $stl = 'पास नाही'; }
        elseif ($lp[1] === 'cancelled') { $stc = 'none'; $stl = 'रद्द'; }
        elseif ($lp[0] < $today) { $stc = 'exp'; $stl = 'संपला ' . date('d/m/Y', strtotime($lp[0])); }
        elseif ($lp[0] <= date('Y-m-d', strtotime('+7 days'))) { $stc = 'soon'; $stl = 'संपतो ' . date('d/m', strtotime($lp[0])); }
        else { $stc = 'ok'; $stl = 'valid ' . date('d/m/Y', strtotime($lp[0])); } ?>
        <tr>
          <td><?php if ($ph): ?><img src="../<?= h($ph) ?>" class="bs-av" alt="" loading="lazy"><?php else: ?><span class="bs-av"><?= h(mb_strtoupper(mb_substr($r['name'], 0, 1))) ?></span><?php endif; ?></td>
          <td class="nm"><a href="student.php?id=<?= (int)$r['id'] ?>" style="color:inherit;text-decoration:none;"><b><?= h($r['name']) ?></b></a>
            <div style="font-size:11.5px;color:var(--text-dim);"><?= h(trim($r['class_name'] . ($r['division'] ? ' - ' . $r['division'] : '') . ($r['course'] ? ' · ' . $r['course'] : ''))) ?><?= $r['admission_no'] ? ' · Gr. ' . h($r['admission_no']) : '' ?><?= $r['student_id'] ? ' · 🎓' : '' ?></div></td>
          <td><b><?= h($r['from_place'] ?: '—') ?></b> → <?= h($r['to_place'] ?: '—') ?><?= $r['route_name'] ? '<div style="font-size:11px;color:var(--text-dim);">' . h($r['route_name']) . '</div>' : '' ?></td>
          <td><span class="bp-st <?= $stc ?>"><?= h($stl) ?></span><?= $lp && $lp[1] === 'active' ? ' <a href="index.php?print=1&amp;ids=' . (int)$lp[3] . '" target="_blank" title="Print" style="text-decoration:none;">🖨️</a>' : '' ?></td>
          <td class="cp" style="font-size:12px;"><?= h($r['campus_name'] ?? '') ?></td>
          <td style="white-space:nowrap;"><a href="student.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:3px 9px;"><?= $canManage ? '✎' : 'पहा' ?></a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="d-flex justify-content-center gap-1 flex-wrap" style="padding:10px;">
      <?php for ($i = 1; $i <= $pages; $i++): ?><a href="<?= h($qs(['pg' => $i])) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;<?= $i === $page ? 'background:var(--rail);color:#fff;' : '' ?>"><?= $i ?></a><?php endfor; ?>
    </div>
  <?php endif; ?>
  <?php endif; ?>
</div>

<?php if ($canPull && $pullClasses): ?>
<div class="panel" id="pull">
  <div class="panel-head"><div class="title">🎓 विद्यार्थी विभागातून आणा</div><div class="sub">आधीच यादीत असलेले वगळले जातात. नंतर इथली माहिती स्वतंत्रपणे बदलता येते.</div></div>
  <form method="POST" style="padding:14px 18px;" data-no-smart-filter onsubmit="return confirm('निवडलेल्या वर्गाचे विद्यार्थी बस यादीत आणायचे?');">
    <input type="hidden" name="action" value="pull">
    <div class="row g-2 align-items-end">
      <div class="col-md-6"><label class="form-label-custom">वर्ग (विद्यार्थी विभागातला)</label>
        <select id="pullPick" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($pullClasses as $pc): $left = (int)$pc['n'] - (int)$pc['inbus']; ?>
            <option value="<?= h(json_encode([$pc['campus_id'], $pc['academic_year'], $pc['cl'], $pc['dv']])) ?>" <?= $left ? '' : 'disabled' ?>><?= h(($campusRows[$pc['campus_id']]['name'] ?? '') . ' · ' . $pc['academic_year'] . ' · ' . ($pc['cl'] !== '' ? $pc['cl'] : '—') . ($pc['dv'] !== '' ? ' - ' . $pc['dv'] : '')) ?> — <?= $left ? $left . ' नवीन' : 'सगळे आधीच आहेत' ?></option>
          <?php endforeach; ?>
        </select></div>
      <div class="col-md-3"><label class="form-label-custom">🏫 कुठपर्यंत (ऐच्छिक)</label><input type="text" name="p_to" class="form-control-custom" maxlength="120" placeholder="रिकामं = त्या College चं गाव"></div>
      <div class="col-md-3"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:10px;font-weight:600;">⬇ बस यादीत आणा</button></div>
    </div>
    <input type="hidden" name="p_campus"><input type="hidden" name="p_year"><input type="hidden" name="p_class"><input type="hidden" name="p_div">
  </form>
</div>
<script>
  (function () {
    var s = document.getElementById('pullPick'); if (!s) return;
    s.addEventListener('change', function () {
      var v = s.value ? JSON.parse(s.value) : ['', '', '', ''], f = s.form;
      f.p_campus.value = v[0]; f.p_year.value = v[1]; f.p_class.value = v[2]; f.p_div.value = v[3];
    });
  })();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
