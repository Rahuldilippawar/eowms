<?php
// 🎓 विद्यार्थी यादी — College/School, शैक्षणिक वर्ष, इयत्ता/वर्ग, तुकडी; शोध, Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$scope = studentVisibleScope($pdo);
if ($scope === []) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">विद्यार्थी यादी पाहण्याची परवानगी नाही.</div>'); }
$ready = studentsReady($pdo);
$canManage = canManageStudents();

$campuses = $pdo->prepare("SELECT id, name, college_type FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll(PDO::FETCH_UNIQUE);

$f = [
    'campus' => (int)($_GET['campus'] ?? 0), 'year' => trim((string)($_GET['year'] ?? '')), 'class' => trim((string)($_GET['class'] ?? '')),
    'div' => trim((string)($_GET['div'] ?? '')), 'gender' => in_array($_GET['gender'] ?? '', ['M', 'F', 'O'], true) ? $_GET['gender'] : '',
    'status' => ($_GET['status'] ?? 'active') === 'left' ? 'left' : (($_GET['status'] ?? '') === 'all' ? 'all' : 'active'), 'q' => trim((string)($_GET['q'] ?? '')),
];
$years = [];
if ($ready) {
    $st = $pdo->prepare("SELECT DISTINCT academic_year FROM students WHERE organization_id = ? ORDER BY academic_year DESC");
    $st->execute([$orgId]);
    $years = $st->fetchAll(PDO::FETCH_COLUMN);
}
if ($f['year'] === '' && $years) $f['year'] = $years[0];

// फिल्टर (वर्ग/तुकडी सोडून) — chips साठी; पूर्ण फिल्टर — यादीसाठी
$base = " FROM students s LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.organization_id = ?";
$bp = [$orgId];
$base .= studentScopeSql($scope, $bp);
if ($f['campus']) { $base .= " AND s.campus_id = ?"; $bp[] = $f['campus']; }
if ($f['year'] !== '') { $base .= " AND s.academic_year = ?"; $bp[] = $f['year']; }
if ($f['status'] === 'active') $base .= " AND s.is_active = 1";
elseif ($f['status'] === 'left') $base .= " AND s.is_active = 0";
$where = $base; $wp = $bp;
if ($f['class'] !== '') { $where .= " AND COALESCE(s.class_name,'') = ?"; $wp[] = $f['class']; }
if ($f['div'] !== '') { $where .= " AND COALESCE(s.division,'') = ?"; $wp[] = $f['div']; }
if ($f['gender']) { $where .= " AND s.gender = ?"; $wp[] = $f['gender']; }
if ($f['q'] !== '') {
    $where .= " AND (s.name LIKE ? OR s.admission_no LIKE ? OR s.roll_no = ? OR s.mobile LIKE ? OR s.parent_mobile LIKE ? OR s.father_name LIKE ?)";
    $like = '%' . $f['q'] . '%'; array_push($wp, $like, $like, $f['q'], $like, $like, $like);
}

$rows = []; $total = 0; $kpi = ['n' => 0, 'm' => 0, 'f' => 0, 'nomob' => 0]; $classes = [];
$per = 50; $page = max(1, (int)($_GET['pg'] ?? 1)); $pages = 1;
if ($ready) {
    // 📥 Excel (CSV) — सध्याच्या फिल्टरनुसार
    if (($_GET['export'] ?? '') === 'csv') {
        $st = $pdo->prepare("SELECT s.*, c.name campus_name $where ORDER BY c.name, s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.name");
        $st->execute($wp);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="students-' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['प्रवेश क्र.', 'Roll No', 'नाव', 'College/School', 'शैक्षणिक वर्ष', 'इयत्ता/वर्ग', 'तुकडी', 'शाखा', 'लिंग', 'जन्मतारीख', 'मोबाईल', 'पालक मोबाईल', 'वडिलांचे नाव', 'आईचे नाव', 'प्रवर्ग', 'रक्तगट', 'पत्ता', 'प्रवेश दिनांक', 'स्थिती']);
        while ($r = $st->fetch()) {
            fputcsv($out, [$r['admission_no'], $r['roll_no'], $r['name'], $r['campus_name'], $r['academic_year'], $r['class_name'], $r['division'], $r['course'], studentGenderLabel($r['gender']),
                $r['date_of_birth'], $r['mobile'], $r['parent_mobile'], $r['father_name'], $r['mother_name'], $r['category'], $r['blood_group'], $r['address'], $r['admission_date'], $r['is_active'] ? 'सक्रिय' : 'सोडले']);
        }
        fclose($out); exit;
    }
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(s.gender = 'M') m, SUM(s.gender = 'F') f, SUM(s.mobile IS NULL AND s.parent_mobile IS NULL) nomob $where");
    $st->execute($wp);
    $kpi = array_map('intval', $st->fetch() ?: []);
    $total = $kpi['n'];
    $pages = max(1, (int)ceil($total / $per)); $page = min($page, $pages);
    $st = $pdo->prepare("SELECT s.*, c.name campus_name $where ORDER BY c.name, s.class_name, s.division, CAST(s.roll_no AS UNSIGNED), s.roll_no, s.name LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($wp);
    $rows = $st->fetchAll();
    $st = $pdo->prepare("SELECT COALESCE(s.class_name,'') cls, COALESCE(s.division,'') dv, COUNT(*) n $base GROUP BY cls, dv ORDER BY cls, dv");
    $st->execute($bp);
    $classes = $st->fetchAll();
}
$qs = function (array $over) use ($f) {
    $a = array_merge(['campus' => $f['campus'] ?: null, 'year' => $f['year'] ?: null, 'class' => $f['class'] !== '' ? $f['class'] : null, 'div' => $f['div'] !== '' ? $f['div'] : null,
        'gender' => $f['gender'] ?: null, 'status' => $f['status'] !== 'active' ? $f['status'] : null, 'q' => $f['q'] !== '' ? $f['q'] : null], $over);
    return '?' . http_build_query(array_filter($a, fn($v) => $v !== null && $v !== ''));
};

$pageTitle = 'विद्यार्थी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .stu-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
  .stu-act{display:flex;gap:6px;flex-wrap:wrap;}
  .stu-act a{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;white-space:nowrap;}
  .stu-act a.pri{background:var(--rail);border-color:var(--rail);color:#fff;}
  .stu-k{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:12px;}
  .stu-k > div{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 12px;}
  .stu-k b{display:block;font-size:20px;font-weight:800;line-height:1.15;}
  .stu-k span{font-size:11px;color:var(--text-dim);}
  .stu-f{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:10px;margin-bottom:12px;}
  .stu-f form{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .stu-f select,.stu-f input{width:auto;font-size:12.5px !important;padding:7px 10px !important;}
  .stu-f input[type=search]{flex:1 1 200px;min-width:0;}
  .stu-cls{display:flex;gap:6px;overflow-x:auto;scrollbar-width:none;margin-top:8px;}
  .stu-cls::-webkit-scrollbar{display:none;}
  .stu-cls a{flex:0 0 auto;border:1px solid var(--line);border-radius:999px;padding:5px 11px;font-size:12px;font-weight:600;color:var(--text-dim);text-decoration:none;background:var(--surface);}
  .stu-cls a.on{background:var(--ink);color:#fff;border-color:var(--ink);}
  .stu-cls a em{font-style:normal;opacity:.7;margin-left:3px;}
  .stu-t td .sub{font-size:11px;color:var(--text-dim);}
  .stu-g{display:inline-block;font-size:11px;font-weight:700;border-radius:999px;padding:1px 8px;}
  .stu-g.M{background:#DBEAFE;color:#1D4ED8;} .stu-g.F{background:#FCE7F3;color:#BE185D;} .stu-g.O{background:var(--canvas);color:var(--text-dim);}
  .stu-left{opacity:.6;}
  .stu-pg{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap;font-size:12.5px;padding:10px 4px;}
  @media (max-width:767px){
    .stu-act{width:100%;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;}
    .stu-k{grid-template-columns:repeat(2,1fr);}
    .stu-f select{flex:1 1 45%;}
    .stu-t,.stu-t tbody{display:block;width:100% !important;min-width:0 !important;}
    .stu-t thead{display:none;}
    .stu-t tr{display:block;border-bottom:1px solid var(--line);padding:10px 12px;}
    .stu-t td{display:inline-block;border:0 !important;padding:1px 10px 1px 0 !important;font-size:12.5px;vertical-align:middle;}
    .stu-t td.r{font-weight:700;color:var(--text-dim);}
    .stu-t td.n{display:inline-block;font-size:13.5px;max-width:calc(100% - 40px);}
    .stu-t td.r + td.n + td{display:block;}
    .stu-t td.act{float:right;padding:0 !important;}
    .stu-t td.cp{display:none;}
    .stu-t td .sub{display:inline;margin-left:4px;}
    .stu-t td.n .sub{display:block;margin-left:0;}
  }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="stu-head">
  <div class="page-title mb-0">🎓 विद्यार्थी</div>
  <?php if ($ready): ?>
  <div class="stu-act">
    <?php if ($canManage): ?>
      <a href="student.php" class="pri">＋ नवीन विद्यार्थी</a>
      <a href="import.php">📥 Excel / CSV Import</a>
    <?php endif; ?>
    <a href="<?= h($qs(['export' => 'csv'])) ?>">📊 Excel</a>
  </div>
  <?php endif; ?>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ विद्यार्थी विभागासाठी Database Update बाकी आहे — Super Admin ने <b>System Update → Database Update</b> चालवावं.</div>
<?php else: ?>

<div class="stu-k">
  <div><b><?= $kpi['n'] ?></b><span>🎓 विद्यार्थी<?= $f['status'] === 'left' ? ' (सोडलेले)' : '' ?></span></div>
  <div><b style="color:#1D4ED8;"><?= $kpi['m'] ?></b><span>👦 मुलगे</span></div>
  <div><b style="color:#BE185D;"><?= $kpi['f'] ?></b><span>👧 मुली</span></div>
  <div><b style="color:<?= $kpi['nomob'] ? '#B45309' : '#16A34A' ?>;"><?= $kpi['nomob'] ?></b><span>📵 मोबाईल नाही</span></div>
</div>

<div class="stu-f">
  <form method="GET" data-no-smart-filter>
    <input type="search" name="q" value="<?= h($f['q']) ?>" class="form-control-custom" placeholder="🔍 नाव, प्रवेश क्र., Roll, मोबाईल…">
    <?php if (count($campuses) > 1): ?>
      <select name="campus" class="form-select-custom" onchange="this.form.submit()">
        <option value="">🏫 सर्व College/School</option>
        <?php foreach ($campuses as $cid => $c): ?><option value="<?= $cid ?>" <?= $f['campus'] === (int)$cid ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($years): ?>
      <select name="year" class="form-select-custom" onchange="this.form.submit()">
        <?php foreach ($years as $y): ?><option value="<?= h($y) ?>" <?= $f['year'] === $y ? 'selected' : '' ?>><?= h($y) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="gender" class="form-select-custom" onchange="this.form.submit()">
      <option value="">सर्व</option><option value="M" <?= $f['gender'] === 'M' ? 'selected' : '' ?>>👦 मुलगे</option><option value="F" <?= $f['gender'] === 'F' ? 'selected' : '' ?>>👧 मुली</option>
    </select>
    <select name="status" class="form-select-custom" onchange="this.form.submit()">
      <option value="active">सक्रिय</option><option value="left" <?= $f['status'] === 'left' ? 'selected' : '' ?>>सोडलेले</option><option value="all" <?= $f['status'] === 'all' ? 'selected' : '' ?>>सर्व</option>
    </select>
    <?php if ($f['class'] !== ''): ?><input type="hidden" name="class" value="<?= h($f['class']) ?>"><?php endif; ?>
    <?php if ($f['div'] !== ''): ?><input type="hidden" name="div" value="<?= h($f['div']) ?>"><?php endif; ?>
  </form>
  <?php if (count($classes) > 1): ?>
  <div class="stu-cls" aria-label="इयत्ता / वर्ग">
    <a href="<?= h($qs(['class' => null, 'div' => null, 'pg' => null])) ?>" class="<?= $f['class'] === '' && $f['div'] === '' ? 'on' : '' ?>">सर्व वर्ग</a>
    <?php foreach ($classes as $c): $on = $f['class'] === $c['cls'] && $f['div'] === $c['dv']; ?>
      <a href="<?= h($qs(['class' => $c['cls'] !== '' ? $c['cls'] : null, 'div' => $c['dv'] !== '' ? $c['dv'] : null, 'pg' => null])) ?>" class="<?= $on ? 'on' : '' ?>"><?= h(($c['cls'] !== '' ? $c['cls'] : 'वर्ग नाही') . ($c['dv'] !== '' ? ' - ' . $c['dv'] : '')) ?><em><?= (int)$c['n'] ?></em></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<div class="panel">
  <?php if (!$rows): ?>
    <div style="padding:34px;text-align:center;color:var(--text-dim);">
      🎓 <?= $f['q'] !== '' || $f['class'] !== '' ? 'या फिल्टरमध्ये विद्यार्थी नाहीत.' : 'अजून विद्यार्थी नाहीत.' ?>
      <?php if ($canManage && $f['q'] === ''): ?><div style="margin-top:10px;"><a href="import.php" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;">📥 विद्यार्थी यादी Import करा</a></div><?php endif; ?>
    </div>
  <?php else: ?>
  <table class="rtable stu-t">
    <thead><tr><th>Roll</th><th>नाव</th><th>वर्ग</th><th>लिंग</th><th>जन्मतारीख</th><th>मोबाईल</th><?php if (count($campuses) > 1 && !$f['campus']): ?><th>College/School</th><?php endif; ?><th></th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr class="<?= $r['is_active'] ? '' : 'stu-left' ?>">
          <td class="r"><?= h($r['roll_no'] ?: '—') ?></td>
          <td class="n"><b><?= h($r['name']) ?></b><div class="sub">प्रवेश क्र. <?= h($r['admission_no']) ?><?= $r['father_name'] ? ' · वडील: ' . h($r['father_name']) : '' ?></div></td>
          <td><?= h(trim(($r['class_name'] ?? '') . ($r['division'] ? ' - ' . $r['division'] : '')) ?: '—') ?><?= $r['course'] ? '<div class="sub">' . h($r['course']) . '</div>' : '' ?></td>
          <td><?php if ($r['gender']): ?><span class="stu-g <?= h($r['gender']) ?>"><?= h(studentGenderLabel($r['gender'])) ?></span><?php else: ?>—<?php endif; ?></td>
          <td><?= $r['date_of_birth'] ? h(date('d/m/Y', strtotime($r['date_of_birth']))) : '—' ?></td>
          <td><?= $r['mobile'] ? '<a href="tel:' . h($r['mobile']) . '">' . h($r['mobile']) . '</a>' : '' ?><?= $r['parent_mobile'] ? '<div class="sub">पालक: <a href="tel:' . h($r['parent_mobile']) . '">' . h($r['parent_mobile']) . '</a></div>' : (!$r['mobile'] ? '—' : '') ?></td>
          <?php if (count($campuses) > 1 && !$f['campus']): ?><td class="sub cp"><?= h($r['campus_name']) ?></td><?php endif; ?>
          <td class="act"><a href="student.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:4px 10px;"><?= $canManage ? '✎ बदला' : 'पहा' ?></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>
<?php if ($pages > 1): ?>
  <div class="stu-pg">
    <span style="color:var(--text-dim);"><?= ($page - 1) * $per + 1 ?>–<?= min($total, $page * $per) ?> / <?= $total ?> विद्यार्थी</span>
    <div class="d-flex gap-1">
      <?php if ($page > 1): ?><a class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" href="<?= h($qs(['pg' => $page - 1])) ?>">‹ मागे</a><?php endif; ?>
      <span class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;pointer-events:none;"><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" href="<?= h($qs(['pg' => $page + 1])) ?>">पुढे ›</a><?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
