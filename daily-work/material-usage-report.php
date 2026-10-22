<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'साहित्य वापर-अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_GET['department_id'] ?? '');
$fromDate = $_GET['from_date'] ?? date('Y-m-01');
$toDate = $_GET['to_date'] ?? date('Y-m-d');

$usageRows = [];
try {
    $sql = "
        SELECT wr.department_id, d.name_local AS dept_name, si.item_name, si.unit,
          SUM(wrm.quantity_used) AS total_used, COUNT(DISTINCT wr.id) AS work_count
        FROM work_record_materials wrm
        JOIN work_records wr ON wr.id = wrm.work_record_id
        JOIN departments d ON d.id = wr.department_id
        JOIN store_items si ON si.id = wrm.item_id
        WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ?
    ";
    $params = [$orgId, $fromDate, $toDate];
    if ($filterDeptId) { $sql .= " AND wr.department_id = ?"; $params[] = $filterDeptId; }
    $sql .= " GROUP BY wr.department_id, si.id, si.item_name, si.unit ORDER BY d.name_local, si.item_name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usageRows = $stmt->fetchAll();
} catch (PDOException $e) { /* work_record_materials table not migrated yet */ }

// विभाग-गटवार करतो
$grouped = [];
foreach ($usageRows as $r) {
    $grouped[$r['dept_name']][] = $r;
}

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
$campusesForCascade = [];
if (!isDeptScoped()) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

// प्रत्येक कामाचा तपशील (कोणत्या तारखेला, कोणी, काय वापरलं) — Detail View साठी
$detailRows = [];
try {
    $sql2 = "
        SELECT wr.work_date, wr.title, wr.work_code, d.name_local AS dept_name, u.name AS employee_name,
          si.item_name, si.unit, wrm.quantity_used
        FROM work_record_materials wrm
        JOIN work_records wr ON wr.id = wrm.work_record_id
        JOIN departments d ON d.id = wr.department_id
        JOIN store_items si ON si.id = wrm.item_id
        LEFT JOIN users u ON u.id = wr.employee_id
        WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ?
    ";
    $params2 = [$orgId, $fromDate, $toDate];
    if ($filterDeptId) { $sql2 .= " AND wr.department_id = ?"; $params2[] = $filterDeptId; }
    $sql2 .= " ORDER BY wr.work_date DESC LIMIT 200";
    $stmt2 = $pdo->prepare($sql2);
    $stmt2->execute($params2);
    $detailRows = $stmt2->fetchAll();
} catch (PDOException $e) { /* migration not run yet */ }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">📦 साहित्य वापर-अहवाल</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">कोणत्या विभागाने, कोणत्या कामासाठी, Store मधलं काय-काय साहित्य वापरलं — याचा एकत्रित अहवाल.</p>

<form method="GET" class="d-flex gap-2 mb-4 flex-wrap">
  <?php if (!isDeptScoped()): ?>
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_matUsage(this.value)">
        <option value="">-- College/School --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;">
      <option value="">-- सगळे विभाग --</option>
      <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>" <?= (string)$filterDeptId === (string)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_matUsage(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true;
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<option value="">-- सगळे विभाग --</option>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>
  <?php endif; ?>
  <input type="date" name="from_date" class="form-control-custom" style="width:auto;" value="<?= h($fromDate) ?>">
  <input type="date" name="to_date" class="form-control-custom" style="width:auto;" value="<?= h($toDate) ?>">
  <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;">बघा</button>
</form>

<?php if (empty($grouped)): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">या कालावधीत कुठलंही साहित्य-वापर नोंदवलेलं नाही.</div>
<?php else: ?>

<div class="panel mb-4">
  <div class="panel-head"><div class="title">📊 विभाग-निहाय एकत्रित वापर</div></div>
  <?php foreach ($grouped as $deptName => $items): ?>
    <div style="padding:10px 20px 4px;font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;background:var(--canvas);"><?= h($deptName) ?></div>
    <table class="rtable">
      <thead><tr><th>वस्तू</th><th>एकूण वापरलेलं</th><th>किती कामांसाठी</th></tr></thead>
      <tbody>
        <?php foreach ($items as $it): ?>
          <tr>
            <td style="font-weight:500;"><?= h($it['item_name']) ?></td>
            <td class="font-mono" style="font-weight:600;color:var(--rail);"><?= number_format($it['total_used'], 2) ?> <?= h($it['unit']) ?></td>
            <td class="font-mono"><?= $it['work_count'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📋 तपशीलवार नोंदी (अलीकडच्या 200)</div></div>
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>काम</th><th>विभाग</th><th>कर्मचारी</th><th>वापरलेली वस्तू</th><th>प्रमाण</th></tr></thead>
    <tbody>
      <?php foreach ($detailRows as $r): ?>
        <tr>
          <td style="font-size:11.5px;"><?= date('d M Y', strtotime($r['work_date'])) ?></td>
          <td><?= h($r['title']) ?> <span class="font-mono" style="font-size:10px;color:var(--text-dim);">(<?= h($r['work_code']) ?>)</span></td>
          <td><?= h($r['dept_name']) ?></td>
          <td><?= h($r['employee_name'] ?? '—') ?></td>
          <td><?= h($r['item_name']) ?></td>
          <td class="font-mono"><?= number_format($r['quantity_used'], 2) ?> <?= h($r['unit']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
