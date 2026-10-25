<?php
$pageTitle = 'कामाच्या विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';

$orgId = activeOrgId();
$status = $_GET['status'] ?? 'open';
if (!in_array($status, ['all', 'open', 'pending', 'in_progress', 'completed', 'overdue', 'cancelled'], true)) { $status = 'open'; }
$category = $_GET['category'] ?? '';
if ($category !== '' && !isset(TICKET_CATEGORIES[$category])) { $category = ''; }
$onlyMine = !empty($_GET['mine']);

// कोण कोणती रांग बघू शकतो:
//  - विभाग-स्तरीय (Staff/HOD) — स्वतःचा + Extra विभाग
//  - Management — फक्त manage_requests_org_wide असेल तर सगळे विभाग
//  - IT रांग (category=it) — manage_it असलेले सगळ्या IT विनंत्या
//  - कोणालाही — स्वतःला नेमलेल्या विनंत्या
$canQueue = hasRole('super_admin') || isDeptScoped() || hasPermission('manage_requests_org_wide');
$canItQueue = hasRole('super_admin') || hasPermission('manage_it');
$visibleDeptIds = $canQueue ? getVisibleDepartmentIds('manage_requests_org_wide') : [];
$requestedDeptId = $_GET['department_id'] ?? '';
$filterDeptId = ($requestedDeptId !== '' && ($visibleDeptIds === null || in_array((int)$requestedDeptId, $visibleDeptIds, true))) ? (int)$requestedDeptId : '';

$scopeSql = " WHERE r.organization_id = ?";
$scopeParams = [$orgId];
if ($category !== '') { $scopeSql .= " AND r.category = ?"; $scopeParams[] = $category; }
if ($onlyMine) {
    $scopeSql .= " AND r.assigned_to_user_id = ?"; $scopeParams[] = $user['id'];
} elseif (($category === 'it' && $canItQueue) || ($canQueue && $visibleDeptIds === null)) {
    // सगळे विभाग
} elseif ($canQueue && $visibleDeptIds) {
    $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $scopeSql .= " AND (r.department_id IN ($ph) OR r.assigned_to_user_id = ?)";
    $scopeParams = array_merge($scopeParams, $visibleDeptIds, [$user['id']]);
} else {
    $scopeSql .= " AND r.assigned_to_user_id = ?"; $scopeParams[] = $user['id'];
}
if ($filterDeptId) { $scopeSql .= " AND r.department_id = ?"; $scopeParams[] = $filterDeptId; }

$dbNotReady = false;
$requests = [];
$counts = ['all' => 0, 'open' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'overdue' => 0, 'cancelled' => 0];
try {
    $statusSql = [
        'all' => '', 'open' => " AND r.status IN ('pending','in_progress')", 'pending' => " AND r.status = 'pending'",
        'in_progress' => " AND r.status = 'in_progress'", 'completed' => " AND r.status IN ('completed','closed')",
        'overdue' => " AND r.status IN ('pending','in_progress') AND r.due_at < NOW()", 'cancelled' => " AND r.status = 'cancelled'",
    ][$status];
    $sql = "SELECT r.*, d.name_local AS dept_name, u.name AS completed_by_name, rd.name_local AS requesting_dept_name, au.name AS assigned_to_name
            FROM work_requests r
            LEFT JOIN departments d ON d.id = r.department_id
            LEFT JOIN users u ON u.id = r.completed_by
            LEFT JOIN departments rd ON rd.id = r.requesting_department_id
            LEFT JOIN users au ON au.id = r.assigned_to_user_id"
         . $scopeSql . $statusSql
         . " ORDER BY (r.status IN ('pending','in_progress')) DESC, FIELD(r.priority,'urgent','high','normal','low'), r.due_at IS NULL, r.due_at, r.created_at DESC LIMIT 300";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scopeParams);
    $requests = $stmt->fetchAll();

    $cStmt = $pdo->prepare("SELECT
        COUNT(*) a,
        SUM(r.status IN ('pending','in_progress')) o,
        SUM(r.status = 'pending') p,
        SUM(r.status = 'in_progress') ip,
        SUM(r.status IN ('completed','closed')) c,
        SUM(r.status IN ('pending','in_progress') AND r.due_at < NOW()) od,
        SUM(r.status = 'cancelled') x
        FROM work_requests r" . $scopeSql);
    $cStmt->execute($scopeParams);
    $cr = $cStmt->fetch();
    $counts = ['all' => (int)$cr['a'], 'open' => (int)$cr['o'], 'pending' => (int)$cr['p'], 'in_progress' => (int)$cr['ip'],
        'completed' => (int)$cr['c'], 'overdue' => (int)$cr['od'], 'cancelled' => (int)$cr['x']];
} catch (PDOException $e) {
    // नवीन Columns (category, priority, due_at…) अजून तयार नाहीत
    $dbNotReady = true;
}

if ($dbNotReady) {
?>
  <div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
  <div class="page-title mb-3">📋 कामाच्या विनंत्या</div>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:18px 22px;">
    ⚠️ <strong>Database अजून पूर्णपणे तयार नाही.</strong> विनंती प्रणालीसाठी लागणारे नवीन Columns अजून तयार झालेले नाहीत.<br><br>
    <strong>उपाय:</strong> Super Admin → System Update → Database Update चालवा, मग हे पान Refresh करा.
  </div>
  <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php
}

// Record that this staff member has now "seen" each of these requests —
// visible later to the external requester via track-request.php
if (!empty($requests)) {
    try {
        $viewUpsert = $pdo->prepare("INSERT INTO work_request_views (request_id, user_id) VALUES (?, ?) ON DUPLICATE KEY UPDATE viewed_at = NOW()");
        foreach ($requests as $req) { $viewUpsert->execute([$req['id'], $user['id']]); }
    } catch (PDOException $e) { /* work_request_views table not migrated yet */ }
}

$departments = [];
$campusesForCascade = [];
if ($canQueue) {
    $deptSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
    $deptParams2 = [$orgId];
    if ($visibleDeptIds !== null) {
        $deptIdsForSelect = $visibleDeptIds ?: [0];
        $ph4 = implode(',', array_fill(0, count($deptIdsForSelect), '?'));
        $deptSql .= " AND id IN ($ph4)";
        $deptParams2 = array_merge($deptParams2, $deptIdsForSelect);
    }
    $stmt = $pdo->prepare($deptSql . " ORDER BY name_local");
    $stmt->execute($deptParams2);
    $departments = $stmt->fetchAll();
    if ($visibleDeptIds === null) {
        $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
        $cStmt->execute([$orgId]);
        $campusesForCascade = $cStmt->fetchAll();
    }
}

// IT रांग — Asset-विशिष्ट दुरुस्ती तक्रारी (it/) वेगळ्याच राहतात, इथून Link
$assetTicketsOpen = null;
if ($category === 'it' && $canItQueue) {
    try {
        $atStmt = $pdo->prepare("SELECT COUNT(*) FROM it_repair_tickets WHERE organization_id = ? AND status != 'resolved'");
        $atStmt->execute([$orgId]);
        $assetTicketsOpen = (int)$atStmt->fetchColumn();
    } catch (PDOException $e) {}
}

$qs = function ($over) use ($status, $category, $filterDeptId, $onlyMine) {
    $p = array_merge(['status' => $status, 'category' => $category, 'department_id' => $filterDeptId, 'mine' => $onlyMine ? 1 : ''], $over);
    return '?' . http_build_query(array_filter($p, fn($v) => $v !== '' && $v !== null));
};
$currentUrl = 'index.php' . $qs([]);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0"><?= $category === 'it' ? '🖥️ IT Requests' : h(t('s301')) ?></div>
  <div class="d-flex gap-2">
    <a href="create.php<?= $category === 'it' ? '?category=it' : '' ?>" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-plus me-1"></i>नवीन</a>
    <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-chart-column me-1"></i>Report</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;"><?= h(t('s302')) ?> · SLA: तातडीचं 8 तास, जास्त 1 दिवस, सर्वसाधारण 3 दिवस, कमी 7 दिवस</p>

<?php if ($assetTicketsOpen !== null): ?>
  <div class="alert" style="background:var(--rail-soft);border-radius:10px;font-size:13px;padding:12px 16px;">
    🛠️ Asset-विशिष्ट दुरुस्ती तक्रारी (QR/Asset पानावरून): <strong><?= $assetTicketsOpen ?></strong> उघड्या —
    <a href="../it/general-requests-manage.php?assets=1" style="color:var(--rail);font-weight:600;">Asset तक्रारी बघा →</a>
  </div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">उघड्या</div><div class="val" style="color:var(--amber);"><?= $counts['open'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⏰ SLA ओलांडलेल्या</div><div class="val" style="color:<?= $counts['overdue'] ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $counts['overdue'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s9')) ?></div><div class="val" style="color:var(--rail);"><?= $counts['in_progress'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h(t('s10')) ?></div><div class="val" style="color:var(--rail);"><?= $counts['completed'] ?></div></div></div>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle" style="flex-wrap:wrap;">
    <?php foreach (['open' => 'उघड्या', 'pending' => t('s7'), 'in_progress' => t('s9'), 'overdue' => '⏰ SLA ओलांडलेल्या', 'completed' => t('s10'), 'cancelled' => 'रद्द', 'all' => t('s19')] as $k => $lbl): ?>
      <a href="<?= h($qs(['status' => $k])) ?>" class="period-btn <?= $status === $k ? 'active' : '' ?>"><?= h($lbl) ?> (<?= $counts[$k] ?>)</a>
    <?php endforeach; ?>
  </div>
  <form method="GET" class="d-flex gap-2 flex-wrap">
    <input type="hidden" name="status" value="<?= h($status) ?>">
    <select name="category" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">सगळे प्रकार</option>
      <?php foreach (TICKET_CATEGORIES as $k => $lbl): ?><option value="<?= $k ?>" <?= $category === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
    </select>
    <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;"><input type="checkbox" name="mine" value="1" <?= $onlyMine ? 'checked' : '' ?> onchange="this.form.submit()"> मला नेमलेल्या</label>
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_reqIdx(this.value)">
        <option value="">-- College/School --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <?php if ($departments): ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value=""><?= h(t('s18')) ?></option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= (string)$filterDeptId === (string)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
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
  <?php foreach ($requests as $req):
    $sla = ticketSlaBadge($req);
    $isOpen = in_array($req['status'], TICKET_OPEN_STATUSES, true);
  ?>
    <div class="col-md-6">
      <div class="panel h-100" style="<?= $sla['overdue'] ? 'border-left:3px solid var(--rose);' : '' ?>">
        <div style="padding:18px 20px;">
          <div class="d-flex justify-content-between align-items-start mb-2 gap-2">
            <div>
              <a href="view.php?id=<?= $req['id'] ?>" class="font-display" style="font-weight:600;font-size:14.5px;color:var(--ink);text-decoration:none;"><?= h($req['title'] ?: ($req['dept_name'] ?: 'विनंती')) ?></a>
              <div style="font-size:11px;color:var(--text-dim);"><?= h(TICKET_CATEGORIES[$req['category']] ?? $req['category']) ?> · <?= h($req['dept_name'] ?: ($req['category'] === 'it' ? 'IT' : '—')) ?> · प्राधान्य: <?= h(TICKET_PRIORITIES[$req['priority']] ?? $req['priority']) ?></div>
            </div>
            <span class="status-chip <?= TICKET_STATUS_CHIPS[$req['status']] ?? 'pending' ?>"><?= TICKET_STATUS_LABELS[$req['status']] ?? h($req['status']) ?></span>
          </div>
          <div class="font-mono" style="font-size:11px;color:var(--text-dim);margin-bottom:6px;"><?= h($req['request_code']) ?> · <?= date('d M Y, h:i A', strtotime($req['created_at'])) ?> · वय <?= h(ticketAgeLabel($req['created_at'])) ?></div>
          <?php if ($sla['html']): ?><div class="mb-2"><?= $sla['html'] ?></div><?php endif; ?>
          <p style="font-size:13.5px;margin-bottom:8px;"><?= nl2br(h(mb_strimwidth($req['work_description'], 0, 300, '…'))) ?></p>
          <div style="font-size:12px;color:var(--text-dim);margin-bottom:12px;">
            <i class="fa-solid fa-user me-1"></i><?= h(t('s305')) ?> <strong><?= h($req['requester_name']) ?></strong>
            <?php if ($req['requester_contact']): ?> · <?= h($req['requester_contact']) ?><?php endif; ?>
            <?php if ($req['requested_by_user_id'] && $req['requesting_dept_name']): ?>
              <span class="status-chip done" style="font-size:10px;margin-left:6px;">🏢 <?= h($req['requesting_dept_name']) ?> विभागाकडून</span>
            <?php endif; ?>
            <?php if ($req['assigned_to_name']): ?>
              <span class="status-chip pending" style="font-size:10px;margin-left:6px;">👤 नेमलेले: <?= h($req['assigned_to_name']) ?></span>
            <?php endif; ?>
          </div>

          <?php if ($isOpen && ticketCanManage($req)): ?>
            <form method="POST" action="update-status.php">
              <input type="hidden" name="id" value="<?= $req['id'] ?>">
              <input type="hidden" name="back" value="<?= h($currentUrl) ?>">
              <div class="d-flex gap-2 mb-2">
                <select name="status" class="form-select-custom" style="width:auto;">
                  <option value="pending" <?= $req['status'] === 'pending' ? 'selected' : '' ?>><?= h(t('s7')) ?></option>
                  <option value="in_progress" <?= $req['status'] === 'in_progress' ? 'selected' : '' ?>><?= h(t('s9')) ?></option>
                  <option value="completed"><?= h(t('s10')) ?></option>
                </select>
                <input type="number" step="0.01" name="cost_incurred" class="form-control-custom" placeholder="खर्च (₹)" style="max-width:120px;">
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:6px 16px;"><?= h(t('s39')) ?></button>
              </div>
              <input type="text" name="remark" class="form-control-custom" placeholder="टिप्पणी (ऐच्छिक — requester ला दिसेल)" style="font-size:12.5px;">
            </form>
          <?php elseif (in_array($req['status'], ['completed', 'closed'], true)): ?>
            <div style="font-size:12px;color:var(--rail);font-weight:600;">
              <i class="fa-solid fa-circle-check me-1"></i><?= h($req['completed_by_name'] ?? '') ?> <?= h(t('s306')) ?> <?= $req['completed_at'] ? date('d M Y, h:i A', strtotime($req['completed_at'])) : '' ?> रोजी पूर्ण केले
              <?php if ($req['rating']): ?> · <span style="color:var(--amber);"><?= str_repeat('★', (int)$req['rating']) ?></span><?php endif; ?>
            </div>
            <?php if ($req['completion_remarks']): ?><div style="font-size:12px;color:var(--text-dim);margin-top:4px;">📝 <?= h($req['completion_remarks']) ?></div><?php endif; ?>
            <?php if ($req['cost_incurred']): ?><div style="font-size:12px;color:var(--amber);margin-top:2px;">💰 झालेला खर्च: ₹<?= number_format($req['cost_incurred'], 0) ?></div><?php endif; ?>
          <?php endif; ?>
          <div class="mt-2"><a href="view.php?id=<?= $req['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;">तपशील, टिप्पण्या व नेमणूक →</a></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
