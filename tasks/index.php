<?php
$pageTitle = 'टास्क ट्रॅकर';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
// Tracker — view_task_tracker असणाऱ्यांना; फक्त assign_tasks असणाऱ्यांना स्वतः Assign केलेले टास्क (Sidebar मध्येही हाच नियम)
$canViewTracker = hasPermission('view_task_tracker');
if (!$canViewTracker && !hasPermission('assign_tasks')) {
    requirePermission('view_task_tracker', '../');
}

$orgId = activeOrgId();
$status = $_GET['status'] ?? 'all';
if (!in_array($status, ['all', 'overdue', 'pending', 'in_progress', 'completed', 'cancelled'], true)) { $status = 'all'; }

// लवचिक Department-Visibility — फक्त तेच विभाग दिसतील जे स्वतःचे आहेत,
// किंवा Super Admin ने वेगळ्याने Extra-Access दिलेले आहेत — Org-wide
// Permission असेल तरच सगळे विभाग दिसतील (null = सगळे).
$visibleDeptIds = getVisibleDepartmentIds('view_org_wide_dashboards');
$requestedDeptId = $_GET['department_id'] ?? '';
if ($visibleDeptIds === null) {
    // Org-wide Access — Dropdown मध्ये निवडलेलं (किंवा रिकामं = सगळे) वापरतो
    $filterDeptId = $requestedDeptId;
} else {
    // मर्यादित Access — फक्त परवानगी असलेल्या विभागांपैकीच निवडता येईल;
    // URL मध्ये फेरफार करून बाहेरचा विभाग बघता येऊ नये म्हणून पडताळणी
    $filterDeptId = ($requestedDeptId !== '' && in_array((int)$requestedDeptId, $visibleDeptIds, true)) ? $requestedDeptId : ($user['department_id'] ?? '');
}
$filterAssignedBy = (int)($_GET['assigned_by'] ?? 0) ?: ''; // विशिष्ट व्यक्तीने Assign केलेले — फक्त Org-Wide दृश्यात
$onlyMine = isset($_GET['only_mine']) || !$canViewTracker; // "मी Assign केलेले" — Tracker Permission नसेल तर नेहमी हेच
if (!$canViewTracker) {
    // फक्त स्वतः Assign केलेले — विभाग-मर्यादा लावत नाही (Assign करणारा स्वतःचे सगळे टास्क बघू शकतो)
    $visibleDeptIds = null;
    $filterDeptId = ($requestedDeptId !== '') ? (int)$requestedDeptId : '';
}

$isDeptScopedViewer = false;
$myDeptId = $user['department_id'] ?? 0;

// यादी व स्थिती-मोजणी — दोन्हीसाठी एकच Filter (स्थिती सोडून)
$filterSql = " WHERE t.organization_id = ?";
$filterParams = [$orgId];
if ($filterDeptId) {
    $filterSql .= " AND t.department_id = ?"; $filterParams[] = $filterDeptId;
} elseif ($visibleDeptIds !== null) {
    // "सगळे विभाग" निवडलं असलं तरी — मर्यादित Access असलेल्याला फक्त
    // त्याच्या परवानगीतल्या विभागांपुरतंच दाखवतो, संपूर्ण Organization नाही
    $visibleDeptIdsSafe = $visibleDeptIds ?: [0];
    $ph = implode(',', array_fill(0, count($visibleDeptIdsSafe), '?'));
    $filterSql .= " AND t.department_id IN ($ph)"; $filterParams = array_merge($filterParams, $visibleDeptIdsSafe);
}
if ($onlyMine) {
    $filterSql .= " AND t.assigned_by = ?"; $filterParams[] = $user['id'];
} elseif ($filterAssignedBy) {
    $filterSql .= " AND t.assigned_by = ?"; $filterParams[] = $filterAssignedBy;
}

$selSql = "SELECT t.*, d.name_local AS dept_name, u1.name AS assignee_name, u2.name AS assigner_name";
$fromSql = " FROM tasks t
        JOIN departments d ON d.id = t.department_id
        JOIN users u1 ON u1.id = t.assigned_to
        JOIN users u2 ON u2.id = t.assigned_by" . $filterSql;
$params = $filterParams;
if ($status === 'overdue') {
    $fromSql .= " AND t.status IN ('pending','in_progress') AND t.target_date < CURDATE()";
} elseif ($status !== 'all') { $fromSql .= " AND t.status = ?"; $params[] = $status; }
$isCsv = ($_GET['export'] ?? '') === 'csv';
// ⚡ यादी पानागणिक — हजारो टास्क एकदम (प्रत्येकाचा तपशील-Modal सह) पाठवल्यास पान खूप जड होतं
$tkPer = 50;
$tkTotal = 0; $tkPage = max(1, (int)($_GET['pg'] ?? 1)); $tkPages = 1;
if ($isCsv) {
    $stmt = $pdo->prepare($selSql . $fromSql . " ORDER BY t.target_date");
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
} else {
    $cStmt = $pdo->prepare("SELECT COUNT(*)" . $fromSql);
    $cStmt->execute($params);
    $tkTotal = (int)$cStmt->fetchColumn();
    $tkPages = max(1, (int)ceil($tkTotal / $tkPer));
    $tkPage = min($tkPage, $tkPages);
    // आधी उघडे (मुदतीनुसार), मग पूर्ण/रद्द (नवीन आधी)
    $stmt = $pdo->prepare($selSql . $fromSql . " ORDER BY (t.status IN ('pending','in_progress')) DESC,
        CASE WHEN t.status IN ('pending','in_progress') THEN t.target_date END ASC, t.target_date DESC, t.id DESC
        LIMIT " . $tkPer . " OFFSET " . (($tkPage - 1) * $tkPer));
    $stmt->execute($params);
    $tasks = $stmt->fetchAll();
}

// 📊 Excel — सध्याच्या Filter नुसार (विभाग / स्थिती / मी दिलेले)
if ($isCsv) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tasks-' . $status . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['क्रमांक', 'शीर्षक', 'तपशील', 'विभाग', 'नेमलेलं', 'दिलं', 'प्राधान्य', 'तयार', 'मुदत', 'स्थिती', 'पूर्ण', 'उशीर (दिवस)', 'पडताळले', 'खर्च (₹)', 'टिप्पणी']);
    foreach ($tasks as $t) {
        $done = $t['completed_at'] ? date('Y-m-d', strtotime($t['completed_at'])) : '';
        $late = $done ? max(0, (int)((strtotime($done) - strtotime($t['target_date'])) / 86400)) : (in_array($t['status'], ['pending', 'in_progress'], true) ? max(0, (int)((strtotime(date('Y-m-d')) - strtotime($t['target_date'])) / 86400)) : '');
        fputcsv($out, [$t['task_code'], $t['title'], $t['description'], $t['dept_name'], $t['assignee_name'], $t['assigner_name'], $t['priority'], date('Y-m-d', strtotime($t['created_at'])),
            $t['target_date'], $t['status'], $done, $late, !empty($t['verified_at']) ? 'हो' : '', $t['cost_incurred'], $t['completion_remarks']]);
    }
    fclose($out);
    exit;
}

$counts = ['all' => 0, 'overdue' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
$odStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t" . $filterSql . " AND t.status IN ('pending','in_progress') AND t.target_date < CURDATE()");
$odStmt->execute($filterParams);
$counts['overdue'] = (int)$odStmt->fetchColumn();

// 👥 कामाचा भार — कोणाकडे किती उघडे / मुदत संपलेले Tasks (त्याच Filter प्रमाणे)
$wlStmt = $pdo->prepare("SELECT u1.name, COUNT(*) open_cnt, SUM(t.target_date < CURDATE()) overdue_cnt
    FROM tasks t JOIN users u1 ON u1.id = t.assigned_to" . $filterSql . " AND t.status IN ('pending','in_progress')
    GROUP BY t.assigned_to, u1.name ORDER BY open_cnt DESC, overdue_cnt DESC LIMIT 8");
$wlStmt->execute($filterParams);
$workload = $wlStmt->fetchAll();

// 📊 मागील ३० दिवसांची कामगिरी — त्याच Filter प्रमाणे
$perfStmt = $pdo->prepare("SELECT COUNT(*) done, SUM(DATE(t.completed_at) <= t.target_date) ontime,
        ROUND(AVG(TIMESTAMPDIFF(HOUR, t.created_at, t.completed_at)) / 24, 1) avg_days,
        SUM(t.verified_at IS NULL) unverified
    FROM tasks t" . $filterSql . " AND t.status = 'completed' AND t.completed_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
$perfStmt->execute($filterParams);
$perf30 = $perfStmt->fetch() ?: [];
$dueSoonStmt = $pdo->prepare("SELECT COUNT(*) FROM tasks t" . $filterSql . " AND t.status IN ('pending','in_progress') AND t.target_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)");
$dueSoonStmt->execute($filterParams);
$dueSoon = (int)$dueSoonStmt->fetchColumn();
$countStmt = $pdo->prepare("SELECT t.status, COUNT(*) c FROM tasks t" . $filterSql . " GROUP BY t.status");
$countStmt->execute($filterParams);
foreach ($countStmt->fetchAll() as $cr) {
    $counts[$cr['status']] = (int)$cr['c'];
    $counts['all'] += (int)$cr['c'];
}

// Filter कायम ठेवून स्थिती-Tabs साठी Query String
$keepQs = http_build_query(array_filter([
    'department_id' => $filterDeptId, 'assigned_by' => $onlyMine ? '' : $filterAssignedBy, 'only_mine' => isset($_GET['only_mine']) ? 1 : '',
], fn($v) => $v !== '' && $v !== null));
$statusQs = fn($s) => '?status=' . $s . ($keepQs ? '&' . $keepQs : '');

$deptSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
$deptParams = [$orgId];
if ($visibleDeptIds !== null && !empty($visibleDeptIds)) {
    $ph2 = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $deptSql .= " AND id IN ($ph2)";
    $deptParams = array_merge($deptParams, $visibleDeptIds);
}
$deptSql .= " ORDER BY name_local";
$stmt = $pdo->prepare($deptSql);
$stmt->execute($deptParams);
$departments = $stmt->fetchAll();
$campusesForCascade = [];
if ($visibleDeptIds === null) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([activeOrgId()]);
    $campusesForCascade = $cStmt->fetchAll();
}

// Org-Wide बघणाऱ्यांसाठी — "कोणी Assign केलं" या Filter साठी यादी
$assignerPool = [];
if (!$isDeptScopedViewer && $canViewTracker) {
    $assignerStmt = $pdo->prepare("
        SELECT DISTINCT u.id, u.name FROM tasks t JOIN users u ON u.id = t.assigned_by
        WHERE t.organization_id = ? ORDER BY u.name
    ");
    $assignerStmt->execute([$orgId]);
    $assignerPool = $assignerStmt->fetchAll();
}

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले', 'cancelled' => 'रद्द केले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'cancelled' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s203')) ?></div>
  <div class="d-flex gap-2">
    <a href="my-tasks.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-user-check me-1"></i>माझे टास्क</a>
    <?php if ($canViewTracker): ?>
    <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-chart-column me-1"></i>Report</a>
    <a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 14px;" title="सध्याच्या Filter नुसार">📊 Excel</a>
    <?php endif; ?>
    <?php if (hasPermission('assign_tasks')): ?>
    <a href="assign.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i>नवीन टास्क असाइन करा
    </a>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3 mt-2 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s204')) ?></div><div class="val"><?= $counts['all'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s7')) ?></div><div class="val" style="color:var(--amber);"><?= $counts['pending'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s9')) ?></div><div class="val" style="color:var(--rail);"><?= $counts['in_progress'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s10')) ?></div><div class="val" style="color:var(--rail);"><?= $counts['completed'] ?></div></div></div>
</div>

<?php require_once __DIR__ . '/../includes/smart-insights.php';
$p30done = (int)($perf30['done'] ?? 0);
smartTiles([
    ['label' => '⏰ मुदत संपलेले', 'value' => $counts['overdue'], 'tone' => $counts['overdue'] ? 'bad' : 'ok', 'href' => $statusQs('overdue')],
    ['label' => '३ दिवसांत मुदत', 'value' => $dueSoon, 'tone' => $dueSoon ? 'warn' : ''],
    ['label' => 'वेळेत पूर्ण (३० दिवस)', 'value' => $p30done ? round((int)$perf30['ontime'] * 100 / $p30done) . '%' : '—', 'sub' => $p30done . ' पूर्ण झाले', 'tone' => $p30done && $perf30['ontime'] * 100 / $p30done >= 80 ? 'ok' : 'warn'],
    ['label' => 'सरासरी पूर्णत्व-वेळ', 'value' => $perf30['avg_days'] !== null && $p30done ? $perf30['avg_days'] . ' दिवस' : '—'],
    ['label' => 'पडताळणी बाकी', 'value' => (int)($perf30['unverified'] ?? 0), 'sub' => 'पूर्ण पण तपासलेले नाहीत', 'tone' => !empty($perf30['unverified']) ? 'warn' : ''],
]); ?>

<?php if ($counts['all'] > 0): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">टास्क स्थिती</div></div>
      <div style="padding:18px 20px;"><canvas id="taskStatusChart" height="140"></canvas></div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">👥 कामाचा भार — कोणाकडे किती उघडे Tasks</div><div class="sub">लाल भाग = मुदत संपलेले · जास्त भार असलेल्यांना नवीन Task देताना विचार करा</div></div>
      <div style="padding:14px 20px;">
        <?php if (!$workload): ?><div style="color:var(--text-dim);font-size:13px;padding:10px 0;">✅ कोणाकडेही उघडा Task नाही</div><?php endif; ?>
        <?php $wlMax = $workload ? max(array_map(fn($w) => (int)$w['open_cnt'], $workload)) : 1;
        foreach ($workload as $w): $o = (int)$w['open_cnt']; $od = (int)$w['overdue_cnt']; ?>
          <div class="wl-row">
            <div class="wl-name" title="<?= h($w['name']) ?>"><?= h($w['name']) ?></div>
            <div class="wl-bar"><i style="width:<?= round(($o - $od) * 100 / $wlMax) ?>%;background:var(--rail);"></i><i style="width:<?= round($od * 100 / $wlMax) ?>%;background:var(--rose);"></i></div>
            <div class="wl-num font-mono"><?= $o ?><?= $od ? ' <span style="color:var(--rose);">(' . $od . '⏰)</span>' : '' ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <a href="<?= h($statusQs('all')) ?>" class="period-btn <?= $status === 'all' ? 'active' : '' ?>"><?= h(t('s19')) ?></a>
    <a href="<?= h($statusQs('overdue')) ?>" class="period-btn <?= $status === 'overdue' ? 'active' : '' ?>" style="<?= $counts['overdue'] ? 'color:var(--rose);' : '' ?>">⏰ मुदत संपलेले (<?= $counts['overdue'] ?>)</a>
    <a href="<?= h($statusQs('pending')) ?>" class="period-btn <?= $status === 'pending' ? 'active' : '' ?>"><?= h(t('s7')) ?></a>
    <a href="<?= h($statusQs('in_progress')) ?>" class="period-btn <?= $status === 'in_progress' ? 'active' : '' ?>"><?= h(t('s9')) ?></a>
    <a href="<?= h($statusQs('completed')) ?>" class="period-btn <?= $status === 'completed' ? 'active' : '' ?>"><?= h(t('s10')) ?></a>
    <a href="<?= h($statusQs('cancelled')) ?>" class="period-btn <?= $status === 'cancelled' ? 'active' : '' ?>">रद्द (<?= $counts['cancelled'] ?>)</a>
  </div>
  <form method="GET" class="d-flex gap-2 flex-wrap align-items-center">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <?php if (!$isDeptScopedViewer && $canViewTracker): ?>
      <?php if (count($campusesForCascade) > 1): ?>
        <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_taskIdx(this.value)">
          <option value="">-- College/School --</option>
          <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
        <option value=""><?= h(t('s18')) ?></option>
        <?php foreach ($departments as $dept): ?>
          <option value="<?= $dept['id'] ?>" <?= (string)$filterDeptId === (string)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_taskIdx(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value=""><?= h(t('s18')) ?></option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
      <select name="assigned_by" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
        <option value="">-- कोणीही Assign केलेलं --</option>
        <?php foreach ($assignerPool as $a): ?>
          <option value="<?= $a['id'] ?>" <?= (string)$filterAssignedBy === (string)$a['id'] ? 'selected' : '' ?>><?= h($a['name']) ?> यांनी दिलेले</option>
        <?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($canViewTracker): ?>
    <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;cursor:pointer;background:var(--canvas);padding:8px 14px;border-radius:9px;">
      <input type="checkbox" name="only_mine" value="1" <?= $onlyMine ? 'checked' : '' ?> onchange="this.form.submit()" style="width:16px;height:16px;">
      फक्त मी Assign केलेले
    </label>
    <?php else: ?>
      <span style="font-size:12.5px;color:var(--text-dim);">तुम्ही Assign केलेले टास्क</span>
    <?php endif; ?>
  </form>
</div>

<div class="tk-viewbar">
  <button type="button" data-tkview="list" class="active"><i class="fa-solid fa-list"></i> यादी</button>
  <button type="button" data-tkview="board"><i class="fa-solid fa-table-columns"></i> Board</button>
</div>
<?php
  $todayYmd = date('Y-m-d');
  $cols = ['overdue' => ['⏰ मुदत संपलेले', 'var(--rose)'], 'pending' => ['⏳ प्रलंबित', 'var(--amber)'], 'in_progress' => ['🔧 सुरू आहे', '#3B6FC9'], 'completed' => ['✅ पूर्ण', 'var(--rail)']];
  $board = array_fill_keys(array_keys($cols), []);
  // Board — यादीच्या पानापासून स्वतंत्र, पण प्रत्येक स्तंभात मर्यादित कार्ड (एकूण संख्या SQL ने)
  $boardCap = 40;
  $bc = $pdo->prepare("SELECT SUM(t.status IN ('pending','in_progress') AND t.target_date < CURDATE()) overdue,
      SUM(t.status = 'pending' AND t.target_date >= CURDATE()) pending, SUM(t.status = 'in_progress' AND t.target_date >= CURDATE()) in_progress,
      SUM(t.status = 'completed') completed" . $fromSql);
  $bc->execute($params);
  $boardCnt = array_map('intval', $bc->fetch(PDO::FETCH_ASSOC) ?: []);
  $boardTasks = [];
  foreach (["t.status IN ('pending','in_progress') AND t.target_date < CURDATE() ORDER BY t.target_date",
            "t.status = 'pending' AND t.target_date >= CURDATE() ORDER BY t.target_date",
            "t.status = 'in_progress' AND t.target_date >= CURDATE() ORDER BY t.target_date",
            "t.status = 'completed' ORDER BY t.completed_at DESC"] as $bi => $cond) {
      $bq = $pdo->prepare($selSql . $fromSql . " AND " . $cond . " LIMIT " . ($bi === 3 ? 20 : $boardCap));
      $bq->execute($params);
      foreach ($bq->fetchAll() as $bt) { $boardTasks[] = $bt; }
  }
  foreach ($boardTasks as $bt) {
      $k = in_array($bt['status'], ['pending', 'in_progress'], true) && $bt['target_date'] < $todayYmd ? 'overdue' : $bt['status'];
      if (isset($board[$k])) $board[$k][] = $bt;
  }
  $prioCol = ['urgent' => 'var(--rose)', 'high' => 'var(--amber)', 'normal' => '#3B6FC9', 'low' => 'var(--text-dim)'];
?>
<div class="tk-board" id="tkBoard" hidden>
  <?php foreach ($cols as $k => [$lbl, $col]): $items = $board[$k]; $colTotal = $boardCnt[$k] ?? count($items); ?>
    <div class="tk-col">
      <div class="tk-col-head" style="border-top-color:<?= $col ?>;"><?= $lbl ?> <em><?= $colTotal ?></em></div>
      <div class="tk-col-body">
        <?php if (!$items): ?><div class="tk-empty">—</div><?php endif; ?>
        <?php foreach ($items as $bt):
          $dl = (int)floor((strtotime($bt['target_date']) - strtotime($todayYmd)) / 86400); ?>
          <button type="button" class="tk-card" data-bs-toggle="modal" data-bs-target="#taskDetailModal<?= (int)$bt['id'] ?>" style="border-left-color:<?= $prioCol[$bt['priority']] ?? 'var(--line)' ?>;">
            <div class="tk-card-t"><?= h($bt['title']) ?></div>
            <div class="tk-card-m">
              <span class="tk-av" title="<?= h($bt['assignee_name']) ?>"><?= h(mb_strtoupper(mb_substr($bt['assignee_name'], 0, 1))) ?></span>
              <span><?= h($bt['assignee_name']) ?></span>
              <span class="tk-due <?= $k === 'completed' ? '' : ($dl < 0 ? 'bad' : ($dl <= 1 ? 'warn' : '')) ?>"><?= $k === 'completed' ? '✔ ' . ($bt['completed_at'] ? date('d M', strtotime($bt['completed_at'])) : '') : ($dl < 0 ? abs($dl) . ' दि. उशीर' : ($dl === 0 ? 'आज' : ($dl === 1 ? 'उद्या' : date('d M', strtotime($bt['target_date']))))) ?></span>
            </div>
            <div class="tk-card-d"><?= h($bt['dept_name']) ?></div>
          </button>
        <?php endforeach; ?>
        <?php if ($colTotal > count($items)): ?><a class="tk-empty" style="display:block;text-decoration:none;" href="<?= h($statusQs($k)) ?>">+<?= $colTotal - count($items) ?> आणखी — यादीत पहा</a><?php endif; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<script>
(function () {
  var bar = document.querySelectorAll('[data-tkview]'), board = document.getElementById('tkBoard');
  function show(v) {
    var list = document.getElementById('tkList'); // यादी या Script नंतर येते — म्हणून इथेच शोधतो
    board.hidden = v !== 'board'; if (list) list.hidden = v === 'board';
    bar.forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-tkview') === v); });
    try { localStorage.setItem('eowmsTaskView', v); } catch (e) {}
  }
  bar.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-tkview')); }); });
  // तपशील-Modals यादीच्या Table मध्ये आहेत — Board दिसताना यादी लपते, म्हणून Modals body मध्ये हलवतो
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#tkList .modal').forEach(function (m) { document.body.appendChild(m); });
    try { if (localStorage.getItem('eowmsTaskView') === 'board') show('board'); } catch (e) {}
  });
})();
</script>

<div class="panel" id="tkList">
  <table class="rtable">
    <thead><tr><th><?= h(t('s14')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s6')) ?></th><th><?= h(t('s205')) ?></th><th>Target Date</th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($tasks)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s83')) ?></td></tr>
      <?php endif; ?>
      <?php
        $priorityLabels = ['low' => '⚪ कमी', 'normal' => '🔵 सामान्य', 'high' => '🟠 जास्त', 'urgent' => '🔴 तातडीचे'];
      ?>
      <?php foreach ($tasks as $task):
        $isOverdue = strtotime($task['target_date']) < strtotime(date('Y-m-d')) && !in_array($task['status'], ['completed', 'cancelled'], true);
        $chip = $isOverdue && $task['status'] === 'pending' ? 'overdue' : $statusChip[$task['status']];
        $chipText = $isOverdue ? 'मुदत संपली' : $statusLabels[$task['status']];
        if ($task['status'] === 'completed' && !empty($task['verified_at'])) { $chipText .= ' ✔ पडताळले'; }
        $isMyAssignment = (int)$task['assigned_by'] === (int)$user['id'] || hasRole('super_admin');
      ?>
        <tr>
          <td style="font-weight:500;">
            <?= h($task['title']) ?>
            <div class="font-mono" style="font-size:11px;color:var(--text-dim);"><?= h($task['task_code']) ?></div>
            <?php if (!empty($task['priority']) && $task['priority'] !== 'normal'): ?>
              <span style="font-size:10.5px;"><?= $priorityLabels[$task['priority']] ?? '' ?></span>
            <?php endif; ?>
          </td>
          <td><?= h($task['dept_name']) ?></td>
          <td><?= h($task['assignee_name']) ?></td>
          <td style="font-size:12.5px;color:var(--text-dim);"><?= h($task['assigner_name']) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($task['target_date'])) ?></td>
          <td><span class="status-chip <?= $chip ?>"><?= $chipText ?></span></td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#taskDetailModal<?= $task['id'] ?>">तपशील</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php if ($tkPages > 1):
  $pgUrl = function ($n) { return '?' . http_build_query(array_merge($_GET, ['pg' => $n])); }; ?>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2" style="font-size:12.5px;">
    <span style="color:var(--text-dim);"><?= (($tkPage - 1) * $tkPer) + 1 ?>–<?= min($tkTotal, $tkPage * $tkPer) ?> / <?= $tkTotal ?> टास्क</span>
    <div class="d-flex gap-1">
      <?php if ($tkPage > 1): ?><a class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" href="<?= h($pgUrl($tkPage - 1)) ?>">‹ मागे</a><?php endif; ?>
      <span class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;pointer-events:none;"><?= $tkPage ?> / <?= $tkPages ?></span>
      <?php if ($tkPage < $tkPages): ?><a class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;" href="<?= h($pgUrl($tkPage + 1)) ?>">पुढे ›</a><?php endif; ?>
    </div>
  </div>
<?php endif; ?>
<?php
  // तपशील-Modals — यादीच्या या पानातले + Board वरचे (दुप्पट नाहीत)
  $modalTasks = [];
  foreach (array_merge($tasks, $boardTasks) as $mt) { $modalTasks[$mt['id']] = $mt; }
  foreach ($modalTasks as $task):
        $isOverdue = strtotime($task['target_date']) < strtotime(date('Y-m-d')) && !in_array($task['status'], ['completed', 'cancelled'], true);
        $chip = $isOverdue && $task['status'] === 'pending' ? 'overdue' : $statusChip[$task['status']];
        $chipText = $isOverdue ? 'मुदत संपली' : $statusLabels[$task['status']];
        if ($task['status'] === 'completed' && !empty($task['verified_at'])) { $chipText .= ' ✔ पडताळले'; }
        $isMyAssignment = (int)$task['assigned_by'] === (int)$user['id'] || hasRole('super_admin');
      ?>
        <div class="modal fade" id="taskDetailModal<?= $task['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <div class="modal-body" style="padding:22px 24px;">
              <div class="d-flex justify-content-between align-items-start mb-2">
                <div style="font-weight:700;font-size:15px;"><?= h($task['title']) ?></div>
                <span class="status-chip <?= $chip ?>"><?= $chipText ?></span>
              </div>
              <div class="font-mono" style="font-size:11px;color:var(--text-dim);margin-bottom:12px;"><?= h($task['task_code']) ?> · <?= $priorityLabels[$task['priority']] ?? '' ?></div>

              <?php if ($task['description']): ?>
                <div style="font-size:11px;font-weight:600;color:var(--text-dim);margin-bottom:3px;">तपशील</div>
                <p style="font-size:13px;margin-bottom:14px;"><?= nl2br(h($task['description'])) ?></p>
              <?php endif; ?>

              <div class="row g-2 mb-3" style="font-size:12.5px;">
                <div class="col-6"><span style="color:var(--text-dim);">विभाग:</span> <?= h($task['dept_name']) ?></div>
                <div class="col-6"><span style="color:var(--text-dim);">नेमून दिले:</span> <?= h($task['assignee_name']) ?></div>
                <div class="col-6"><span style="color:var(--text-dim);">Assign केले:</span> <?= h($task['assigner_name']) ?></div>
                <div class="col-6"><span style="color:var(--text-dim);">मुदत:</span> <?= date('d M Y', strtotime($task['target_date'])) ?></div>
              </div>

              <?php if ($task['status'] === 'completed'): ?>
                <div style="background:var(--rail-soft, #DCEFEC);border-radius:9px;padding:12px 14px;">
                  <div style="font-size:12px;font-weight:600;color:var(--rail);"><i class="fa-solid fa-circle-check me-1"></i><?= $task['completed_at'] ? date('d M Y, h:i A', strtotime($task['completed_at'])) : '' ?> रोजी पूर्ण झाले</div>
                  <?php if ($task['completion_remarks']): ?><div style="font-size:12.5px;margin-top:6px;">📝 <?= nl2br(h($task['completion_remarks'])) ?></div><?php endif; ?>
                  <?php if ($task['cost_incurred']): ?><div style="font-size:12.5px;color:var(--amber);margin-top:4px;">💰 झालेला खर्च: ₹<?= number_format($task['cost_incurred'], 0) ?></div><?php endif; ?>
                  <?php if (!empty($task['verified_at'])): ?><div style="font-size:12px;color:var(--rail);margin-top:6px;">✔ <?= date('d M Y', strtotime($task['verified_at'])) ?> रोजी पडताळले</div><?php endif; ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($task['verification_remarks'])): ?>
                <div style="font-size:12px;color:var(--text-dim);margin-top:8px;">💬 Assign करणाऱ्याची टिप्पणी: <?= nl2br(h($task['verification_remarks'])) ?></div>
              <?php endif; ?>

              <?php if ($isMyAssignment && array_key_exists('verified_at', $task)): $backQs = http_build_query(array_filter(['status' => $status !== 'all' ? $status : '', 'only_mine' => isset($_GET['only_mine']) ? 1 : '', 'department_id' => $filterDeptId])); ?>
                <div style="border-top:1px solid var(--line);margin-top:14px;padding-top:12px;">
                  <?php if (in_array($task['status'], ['pending', 'in_progress'], true)): ?>
                    <form method="POST" action="assigner-action.php" class="mb-2">
                      <input type="hidden" name="task_id" value="<?= $task['id'] ?>"><input type="hidden" name="action" value="edit"><input type="hidden" name="back_qs" value="<?= h($backQs) ?>">
                      <label class="form-label-custom">मुदत (Target)</label>
                      <input type="date" name="target_date" class="form-control-custom mb-2" value="<?= h($task['target_date']) ?>" required>
                      <textarea name="description" class="form-control-custom mb-2" rows="2" placeholder="तपशील"><?= h($task['description']) ?></textarea>
                      <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;">बदल जतन करा</button>
                    </form>
                    <form method="POST" action="assigner-action.php" onsubmit="return confirm('हा टास्क रद्द करायचा?')">
                      <input type="hidden" name="task_id" value="<?= $task['id'] ?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="back_qs" value="<?= h($backQs) ?>">
                      <input type="text" name="remark" class="form-control-custom mb-2" placeholder="रद्द करण्याचं कारण (ऐच्छिक)">
                      <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;">टास्क रद्द करा</button>
                    </form>
                  <?php elseif ($task['status'] === 'completed'): ?>
                    <form method="POST" action="assigner-action.php">
                      <input type="hidden" name="task_id" value="<?= $task['id'] ?>"><input type="hidden" name="back_qs" value="<?= h($backQs) ?>">
                      <textarea name="remark" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी (पुन्हा उघडताना आवश्यक)"></textarea>
                      <?php if (empty($task['verified_at'])): ?>
                        <button type="submit" name="action" value="verify" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;">✔ पडताळले</button>
                      <?php endif; ?>
                      <button type="submit" name="action" value="reopen" class="btn btn-sm" style="border:1px solid var(--amber);color:var(--amber);border-radius:7px;font-size:12px;">↺ पुन्हा उघडा</button>
                    </form>
                  <?php endif; ?>
                </div>
              <?php endif; ?>
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--line);">
              <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">बंद करा</button>
            </div>
          </div></div>
        </div>
<?php endforeach; ?>


<?php if ($counts['all'] > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('taskStatusChart'), {
  type: 'doughnut',
  data: {
    labels: ['प्रलंबित', 'सुरू आहे', 'पूर्ण झाले'],
    datasets: [{
      data: [<?= $counts['pending'] ?>, <?= $counts['in_progress'] ?>, <?= $counts['completed'] ?>],
      backgroundColor: ['#C97F1D', '#3B6FC9', '#1B8A83'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 12 }, boxWidth: 12 } } },
    cutout: '62%'
  }
});
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
