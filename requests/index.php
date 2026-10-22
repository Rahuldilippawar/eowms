<?php
$pageTitle = 'कामाच्या विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$status = $_GET['status'] ?? 'pending';

// Management-tier users need the specific permission to see requests across
// ALL departments; without it (and without their own department), they see none.
if (!isDeptScoped() && !hasPermission('manage_requests_org_wide')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">' . h(t('s300')) . ' <a href="../index.php">' . h(t('s16')) . '</a></div>');
}

// लवचिक Department-Visibility — फक्त परवानगी असलेले विभाग दिसतील
$visibleDeptIds = getVisibleDepartmentIds('manage_requests_org_wide');
$requestedDeptId = $_GET['department_id'] ?? '';
if ($visibleDeptIds === null) {
    $filterDeptId = $requestedDeptId;
} else {
    $filterDeptId = ($requestedDeptId !== '' && in_array((int)$requestedDeptId, $visibleDeptIds, true)) ? $requestedDeptId : ($user['department_id'] ?? '');
}

$dbNotReady = false;
$requests = [];
try {
    $sql = "SELECT r.*, d.name_local AS dept_name, u.name AS completed_by_name, rd.name_local AS requesting_dept_name, au.name AS assigned_to_name
            FROM work_requests r
            JOIN departments d ON d.id = r.department_id
            LEFT JOIN users u ON u.id = r.completed_by
            LEFT JOIN departments rd ON rd.id = r.requesting_department_id
            LEFT JOIN users au ON au.id = r.assigned_to_user_id
            WHERE r.organization_id = ?";
    $params = [$orgId];
    if ($filterDeptId) {
        $sql .= " AND r.department_id = ?"; $params[] = $filterDeptId;
    } elseif ($visibleDeptIds !== null) {
        $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
        $sql .= " AND r.department_id IN ($ph)"; $params = array_merge($params, $visibleDeptIds);
    }
    if ($status !== 'all') { $sql .= " AND r.status = ?"; $params[] = $status; }
    $sql .= " ORDER BY r.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $requests = $stmt->fetchAll();
} catch (PDOException $e) {
    // work_requests चे नवीन Columns (requested_by_user_id, requesting_department_id)
    // अजून Database मध्ये तयार झालेले नाहीत — म्हणजे
    // database/migration-internal-work-requests.sql अजून चालवलेली नाही.
    $dbNotReady = true;
}

if ($dbNotReady) {
?>
  <div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
  <div class="page-title mb-3">📋 कामाच्या विनंत्या</div>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:18px 22px;">
    ⚠️ <strong>Database अजून पूर्णपणे तयार नाही.</strong> "आंतर-विभागीय कामाची विनंती" या Feature साठी लागणारे नवीन Columns अजून Database मध्ये तयार झालेले नाहीत.<br><br>
    <strong>उपाय:</strong> phpMyAdmin मध्ये जाऊन <span class="font-mono" style="background:#fff;padding:2px 8px;border-radius:5px;">database/migration-internal-work-requests.sql</span> ही File चालवा, मग हे पान Refresh करा.
  </div>
  <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php
}


// Record that this staff member has now "seen" each of these requests —
// visible later to the external requester via track-request.php
if (!empty($requests)) {
    try {
        $viewUpsert = $pdo->prepare("INSERT INTO work_request_views (request_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE viewed_at = NOW()");
        foreach ($requests as $req) {
            $viewUpsert->execute([$req['id'], $user['id']]);
        }
    } catch (PDOException $e) {
        // work_request_views table not migrated yet — skip silently
    }
}

$counts = [];
foreach (['all','pending','in_progress','completed'] as $s) {
    $q = "SELECT COUNT(*) c FROM work_requests WHERE organization_id = ?";
    $countParams = [$orgId];
    if ($filterDeptId) {
        $q .= " AND department_id = ?"; $countParams[] = $filterDeptId;
    } elseif ($visibleDeptIds !== null) {
        $ph2 = implode(',', array_fill(0, count($visibleDeptIds), '?'));
        $q .= " AND department_id IN ($ph2)"; $countParams = array_merge($countParams, $visibleDeptIds);
    }
    if ($s !== 'all') { $q .= " AND status = ?"; $countParams[] = $s; }
    $st = $pdo->prepare($q);
    $st->execute($countParams);
    $counts[$s] = $st->fetch()['c'];
}

$deptSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
$deptParams2 = [$orgId];
if ($visibleDeptIds !== null && !empty($visibleDeptIds)) {
    $ph4 = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $deptSql .= " AND id IN ($ph4)";
    $deptParams2 = array_merge($deptParams2, $visibleDeptIds);
}
$deptSql .= " ORDER BY name_local";
$stmt = $pdo->prepare($deptSql);
$stmt->execute($deptParams2);
$departments = $stmt->fetchAll();
$campusesForCascade = [];
if ($visibleDeptIds === null) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s301')) ?></div>
  <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-chart-column me-1"></i>Report</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;"><?= h(t('s302')) ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s303')) ?></div><div class="val"><?= $counts['all'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s7')) ?></div><div class="val" style="color:var(--amber);"><?= $counts['pending'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s9')) ?></div><div class="val" style="color:var(--rail);"><?= $counts['in_progress'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s10')) ?></div><div class="val" style="color:var(--rail);"><?= $counts['completed'] ?></div></div></div>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <a href="?status=all&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $status === 'all' ? 'active' : '' ?>"><?= h(t('s19')) ?></a>
    <a href="?status=pending&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $status === 'pending' ? 'active' : '' ?>"><?= h(t('s7')) ?></a>
    <a href="?status=in_progress&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $status === 'in_progress' ? 'active' : '' ?>"><?= h(t('s9')) ?></a>
    <a href="?status=completed&department_id=<?= h($filterDeptId) ?>" class="period-btn <?= $status === 'completed' ? 'active' : '' ?>"><?= h(t('s10')) ?></a>
  </div>
  <form method="GET" class="d-flex gap-2">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_reqIdx(this.value)">
        <option value="">-- College/School --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value=""><?= h(t('s18')) ?></option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= (string)$filterDeptId === (string)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_reqIdx(campusId) {
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
  </form>
</div>

<div class="row g-3">
  <?php if (empty($requests)): ?>
    <div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s304')) ?></div></div>
  <?php endif; ?>
  <?php foreach ($requests as $req): ?>
    <div class="col-md-6">
      <div class="panel h-100">
        <div style="padding:18px 20px;">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="font-display" style="font-weight:600;font-size:14.5px;"><?= h($req['dept_name']) ?></div>
            <span class="status-chip <?= $statusChip[$req['status']] ?>"><?= $statusLabels[$req['status']] ?></span>
          </div>
          <div class="font-mono" style="font-size:11px;color:var(--text-dim);margin-bottom:8px;"><?= h($req['request_code']) ?> · <?= date('d M Y, h:i A', strtotime($req['created_at'])) ?></div>
          <p style="font-size:13.5px;margin-bottom:8px;"><?= nl2br(h($req['work_description'])) ?></p>
          <div style="font-size:12px;color:var(--text-dim);margin-bottom:12px;">
            <i class="fa-solid fa-user me-1"></i><?= h(t('s305')) ?> <strong><?= h($req['requester_name']) ?></strong>
            <?php if ($req['requester_contact']): ?> · <?= h($req['requester_contact']) ?><?php endif; ?>
            <?php if ($req['requested_by_user_id']): ?>
              <span class="status-chip done" style="font-size:10px;margin-left:6px;">🏢 अंतर्गत — <?= h($req['requesting_dept_name'] ?? '') ?> विभागाकडून</span>
            <?php endif; ?>
            <?php if ($req['assigned_to_name']): ?>
              <span class="status-chip pending" style="font-size:10px;margin-left:6px;">👤 विशेषतः: <?= h($req['assigned_to_name']) ?></span>
            <?php endif; ?>
          </div>

          <?php if ($req['status'] !== 'completed'): ?>
            <form method="POST" action="update-status.php">
              <input type="hidden" name="id" value="<?= $req['id'] ?>">
              <div class="d-flex gap-2 mb-2">
                <select name="status" class="form-select-custom" style="width:auto;">
                  <option value="pending" <?= $req['status'] === 'pending' ? 'selected' : '' ?>><?= h(t('s7')) ?></option>
                  <option value="in_progress" <?= $req['status'] === 'in_progress' ? 'selected' : '' ?>><?= h(t('s9')) ?></option>
                  <option value="completed"><?= h(t('s10')) ?></option>
                </select>
                <input type="number" step="0.01" name="cost_incurred" class="form-control-custom" placeholder="झालेला खर्च (₹, असल्यास)" style="max-width:180px;">
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:6px 16px;"><?= h(t('s39')) ?></button>
              </div>
              <input type="text" name="remark" class="form-control-custom" placeholder="टिप्पणी (ऐच्छिक — requester ला दिसेल)" style="font-size:12.5px;">
            </form>
          <?php else: ?>
            <div style="font-size:12px;color:var(--rail);font-weight:600;">
              <i class="fa-solid fa-circle-check me-1"></i><?= h($req['completed_by_name'] ?? '') ?> <?= h(t('s306')) ?> <?= $req['completed_at'] ? date('d M Y, h:i A', strtotime($req['completed_at'])) : '' ?> रोजी पूर्ण केले
            </div>
            <?php if ($req['completion_remarks']): ?><div style="font-size:12px;color:var(--text-dim);margin-top:4px;">📝 <?= h($req['completion_remarks']) ?></div><?php endif; ?>
            <?php if ($req['cost_incurred']): ?><div style="font-size:12px;color:var(--amber);margin-top:2px;">💰 झालेला खर्च: ₹<?= number_format($req['cost_incurred'], 0) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
