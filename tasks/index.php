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
if (!in_array($status, ['all', 'pending', 'in_progress', 'completed', 'cancelled'], true)) { $status = 'all'; }

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

$sql = "SELECT t.*, d.name_local AS dept_name, u1.name AS assignee_name, u2.name AS assigner_name
        FROM tasks t
        JOIN departments d ON d.id = t.department_id
        JOIN users u1 ON u1.id = t.assigned_to
        JOIN users u2 ON u2.id = t.assigned_by" . $filterSql;
$params = $filterParams;
if ($status !== 'all') { $sql .= " AND t.status = ?"; $params[] = $status; }
$sql .= " ORDER BY t.target_date";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasks = $stmt->fetchAll();

$counts = ['all' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'cancelled' => 0];
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

<?php if ($counts['all'] > 0): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">टास्क स्थिती</div></div>
      <div style="padding:18px 20px;"><canvas id="taskStatusChart" height="140"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <a href="<?= h($statusQs('all')) ?>" class="period-btn <?= $status === 'all' ? 'active' : '' ?>"><?= h(t('s19')) ?></a>
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

<div class="panel">
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
    </tbody>
  </table>
</div>

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
