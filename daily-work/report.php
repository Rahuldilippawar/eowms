<?php
$pageTitle = 'कामाचा अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$period = $_GET['period'] ?? 'weekly';
$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_GET['department_id'] ?? '');

switch ($period) {
    case 'monthly':
        $start = date('Y-m-01');
        $end = date('Y-m-t');
        break;
    case 'yearly':
        $start = date('Y-01-01');
        $end = date('Y-12-31');
        break;
    default:
        $start = date('Y-m-d', strtotime('monday this week'));
        $end = date('Y-m-d', strtotime('sunday this week'));
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1" . (isDeptScoped() ? " AND id = ?" : "") . " ORDER BY name_local");
$stmt->execute(isDeptScoped() ? [$orgId, $user['department_id']] : [$orgId]);
$departments = $stmt->fetchAll();
$campusesForCascade = [];
if (!isDeptScoped()) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

// Department-wise summary
$summary = [];
foreach ($departments as $dept) {
    $totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id=? AND work_date BETWEEN ? AND ?");
    $totalStmt->execute([$dept['id'], $start, $end]);
    $total = $totalStmt->fetch()['c'];

    $doneStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id=? AND work_date BETWEEN ? AND ? AND status='completed'");
    $doneStmt->execute([$dept['id'], $start, $end]);
    $done = $doneStmt->fetch()['c'];

    $summary[] = [
        'name' => $dept['name_local'] ?: $dept['name'],
        'total' => $total,
        'done' => $done,
        'pct' => $total > 0 ? round(($done / $total) * 100) : 0,
    ];
}

// Detailed entries list
$sql = "SELECT wr.*, d.name_local AS dept_name,
          (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS emp_name,
          (SELECT GROUP_CONCAT(CONCAT(cf.field_label, ': ', cfv.field_value) SEPARATOR ' | ')
           FROM custom_field_values cfv
           JOIN custom_fields cf ON cf.field_key = cfv.field_key AND cf.organization_id = wr.organization_id
           WHERE cfv.work_record_id = wr.id) AS custom_fields_display
        FROM work_records wr
        JOIN departments d ON d.id = wr.department_id
        WHERE wr.work_date BETWEEN ? AND ?";
$params = [$start, $end];
if ($filterDeptId) {
    $sql .= " AND wr.department_id = ?";
    $params[] = $filterDeptId;
}
$sql .= " ORDER BY wr.work_date DESC LIMIT 100";
$entriesStmt = $pdo->prepare($sql);
try {
    $entriesStmt->execute($params);
    $entries = $entriesStmt->fetchAll();
} catch (PDOException $e) {
    // custom_fields/custom_field_values not migrated yet — retry without that subquery
    $fallbackSql = "SELECT wr.*, d.name_local AS dept_name,
          (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS emp_name,
          NULL AS custom_fields_display
        FROM work_records wr
        JOIN departments d ON d.id = wr.department_id
        WHERE wr.work_date BETWEEN ? AND ?" . ($filterDeptId ? " AND wr.department_id = ?" : "") . " ORDER BY wr.work_date DESC LIMIT 100";
    $entriesStmt = $pdo->prepare($fallbackSql);
    $entriesStmt->execute($params);
    $entries = $entriesStmt->fetchAll();
}

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'overdue', 'in_progress' => 'pending', 'completed' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s231')) ?></div>
  <a href="material-usage-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-boxes-stacked me-1"></i>📦 साहित्य वापर-अहवाल</a>
</div>

<div class="mode-tabs mb-4" style="display:flex;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:5px;width:fit-content;">
  <a href="entry.php" class="mode-tab" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--text-dim);text-decoration:none;"><?= h(t('s89')) ?></a>
  <a href="report.php" class="mode-tab active" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;background:var(--ink);color:#fff;text-decoration:none;"><?= h(t('s90')) ?></a>
</div>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <a href="?period=weekly" class="period-btn <?= $period === 'weekly' ? 'active' : '' ?>"><?= h(t('s232')) ?></a>
    <a href="?period=monthly" class="period-btn <?= $period === 'monthly' ? 'active' : '' ?>"><?= h(t('s233')) ?></a>
    <a href="?period=yearly" class="period-btn <?= $period === 'yearly' ? 'active' : '' ?>"><?= h(t('s234')) ?></a>
  </div>
  <form method="GET" class="d-flex gap-2">
    <input type="hidden" name="period" value="<?= h($period) ?>">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_dwRep(this.value)">
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
    function loadDeptsCascade_dwRep(campusId) {
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
  <a href="export-csv.php?period=<?= h($period) ?>&department_id=<?= h($filterDeptId) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
</div>

<div class="panel">
  <div class="panel-head"><div class="title"><?= h(t('s235')) ?></div><div class="sub"><?= h($start) ?> — <?= h($end) ?></div></div>
  <table class="rtable">
    <thead><tr><th><?= h(t('s1')) ?></th><th><?= h(t('s26')) ?></th><th><?= h(t('s42')) ?></th><th><?= h(t('s43')) ?></th><th><?= h(t('s2')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($summary as $row):
        $pct = $row['pct'];
        $statusClass = $pct >= 85 ? 'done' : ($pct >= 65 ? 'pending' : 'overdue');
        $statusText = $pct >= 85 ? 'चांगले' : ($pct >= 65 ? 'सुधारणा आवश्यक' : 'विलंबित');
        $barColor = $pct >= 85 ? 'var(--rail)' : ($pct >= 65 ? 'var(--amber)' : 'var(--rose)');
      ?>
        <tr>
          <td style="font-weight:500;"><?= h($row['name']) ?></td>
          <td class="font-mono"><?= $row['total'] ?></td>
          <td class="font-mono"><?= $row['done'] ?></td>
          <td><span class="bar-track"><span class="bar-fill" style="width:<?= $pct ?>%;background:<?= $barColor ?>;"></span></span><?= $pct ?>%</td>
          <td><span class="status-chip <?= $statusClass ?>"><?= $statusText ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel mt-3">
  <div class="panel-head"><div class="title"><?= h(t('s236')) ?></div><div class="sub"><?= h(t('s237')) ?></div></div>
  <table class="rtable">
    <thead><tr><th><?= h(t('s13')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s238')) ?></th><th><?= h(t('s6')) ?></th><th><?= h(t('s21')) ?></th><th><?= h(t('s2')) ?></th></tr></thead>
    <tbody>
      <?php if (empty($entries)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s44')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($entries as $entry): ?>
        <tr>
          <td class="font-mono"><?= date('d M', strtotime($entry['work_date'])) ?></td>
          <td><?= h($entry['dept_name']) ?></td>
          <td style="color:var(--text-dim);"><?= $entry['requested_by'] ? h($entry['requested_by']) : '<span style="font-size:11px;">🔁 नियमित</span>' ?></td>
          <td><?= h($entry['emp_name']) ?></td>
          <td>
            <?= h($entry['title']) ?>
            <?php if (!empty($entry['custom_fields_display'])): ?>
              <div style="font-size:11px;color:var(--text-dim);margin-top:2px;"><?= h($entry['custom_fields_display']) ?></div>
            <?php endif; ?>
          </td>
          <td><span class="status-chip <?= $statusChip[$entry['status']] ?>"><?= $statusLabels[$entry['status']] ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
