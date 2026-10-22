<?php
$pageTitle = 'Dead Stock Register';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasPermission('manage_dead_stock_master');

// Staff/HOD only ever see their OWN department; Management/Super Admin can
// pick any department (or view all together)
if (!$isMgmt) {
    $deptId = (int)($user['department_id'] ?? 0);
} else {
    $deptId = (int)($_GET['department_id'] ?? 0);
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();

$sql = "
    SELECT ds.*, d.name_local AS dept_name, u.name AS added_by_name
    FROM dead_stock_items ds
    JOIN departments d ON d.id = ds.department_id
    LEFT JOIN users u ON u.id = ds.added_by
    WHERE ds.organization_id = ?
";
$params = [$orgId];
if (!$isMgmt) {
    $sql .= " AND ds.department_id = ?";
    $params[] = $deptId;
} elseif ($deptId) {
    $sql .= " AND ds.department_id = ?";
    $params[] = $deptId;
}
$sql .= " ORDER BY d.name_local, ds.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$conditionChip = ['चांगली' => 'done', 'दुरुस्तीयोग्य' => 'pending', 'खराब' => 'overdue', 'भंगार' => 'overdue'];
$totalValue = array_sum(array_column($items, 'purchase_cost'));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">📦 Dead Stock Register</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">विभाग-निहाय टिकाऊ मालमत्ता नोंदवही (फर्निचर, उपकरणे, यंत्रसामग्री)</div>
  </div>
  <div class="d-flex gap-2">
    <a href="create.php<?= $deptId ? '?department_id=' . $deptId : '' ?>" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन नोंद</a>
    <?php if (hasPermission('manage_dead_stock_master')): ?>
      <a href="item-master.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-list-check me-1"></i>वस्तू यादी</a>
    <?php endif; ?>
    <a href="report.php?department_id=<?= $deptId ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF अहवाल</a>
    <a href="export-csv.php?department_id=<?= $deptId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($isMgmt): ?>
<div class="mb-3">
  <form method="GET" class="d-flex gap-2">
    <?php if (count($campusesForCascade) > 1): ?>
      <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_dsIdx(this.value)">
        <option value="">-- College/School --</option>
        <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
      </select>
    <?php endif; ?>
    <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <option value="">-- सर्व विभाग --</option>
      <?php foreach ($departments as $d): ?>
        <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_dsIdx(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true;
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<option value="">-- सर्व विभाग --</option>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>
  </form>

</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण वस्तू</div><div class="val"><?= count($items) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण किंमत</div><div class="val" style="color:var(--rail);">₹<?= number_format($totalValue, 0) ?></div></div></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead>
      <tr>
        <th>Code</th><th>वस्तू</th><?php if ($isMgmt && !$deptId): ?><th>विभाग</th><?php endif; ?><th>संख्या</th><th>किंमत</th><th>स्थिती</th><th>ठिकाण</th><th>नोंद केली</th>
        <?php if (hasPermission('manage_dead_stock_master')): ?><th></th><?php endif; ?>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($items)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;">कोणतीही नोंद सापडली नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($items as $it): ?>
        <tr>
          <td class="font-mono"><?= h($it['item_code']) ?></td>
          <td style="font-weight:500;">
            <?= h($it['item_name']) ?>
            <?php if (empty($it['is_new_purchase'])): ?><span style="font-size:9.5px;color:var(--text-dim);background:var(--canvas);border-radius:10px;padding:1px 8px;margin-left:4px;">आधीचं</span><?php endif; ?>
            <?= $it['category'] ? '<div style="font-size:11px;color:var(--text-dim);">' . h($it['category']) . '</div>' : '' ?>
          </td>
          <?php if ($isMgmt && !$deptId): ?><td><?= h($it['dept_name']) ?></td><?php endif; ?>
          <td class="font-mono"><?= $it['quantity'] ?> <?= h($it['unit'] ?? '') ?></td>
          <td class="font-mono"><?= $it['purchase_cost'] ? '₹' . number_format($it['purchase_cost'], 0) : '—' ?></td>
          <td><span class="status-chip <?= $conditionChip[$it['condition']] ?? 'pending' ?>"><?= h($it['condition']) ?></span></td>
          <td><?= h($it['location'] ?? '—') ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($it['added_by_name'] ?? '—') ?></td>
          <?php if (hasPermission('manage_dead_stock_master')): ?>
            <td class="d-flex gap-2">
              <a href="edit.php?id=<?= $it['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;">Edit</a>
              <form method="POST" action="delete.php" onsubmit="return confirm('ही नोंद कायमची डिलीट करायची?');">
                <input type="hidden" name="id" value="<?= $it['id'] ?>">
                <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;">Delete</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
