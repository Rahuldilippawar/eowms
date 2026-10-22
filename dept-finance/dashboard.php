<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'dept_payment_ledger', '../');
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'विभाग Financial Dashboard';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$accessibleDeptId = deptFinanceAccessibleDeptId($user);
$filterDeptId = $accessibleDeptId ?: (int)($_GET['department_id'] ?? 0);

// ---------------- प्रत्येक विभागासाठी — तिन्ही Categories चा एकूण-खर्च ----------------
function fetchCategoryTotals($pdo, $orgId, $filterDeptId) {
    $out = [];

    // 1. Material Purchase
    $sql1 = "SELECT department_id, SUM(total_amount) total FROM dept_material_purchases WHERE organization_id = ?";
    $params1 = [$orgId];
    if ($filterDeptId) { $sql1 .= " AND department_id = ?"; $params1[] = $filterDeptId; }
    $sql1 .= " GROUP BY department_id";
    $stmt1 = $pdo->prepare($sql1); $stmt1->execute($params1);
    foreach ($stmt1->fetchAll() as $r) { $out[$r['department_id']]['material'] = (float)$r['total']; }

    // 2. Renewals (फक्त Cost असलेले)
    try {
        $sql2 = "SELECT department_id, SUM(renewal_cost) total FROM renewal_items WHERE organization_id = ? AND renewal_cost IS NOT NULL";
        $params2 = [$orgId];
        if ($filterDeptId) { $sql2 .= " AND department_id = ?"; $params2[] = $filterDeptId; }
        $sql2 .= " GROUP BY department_id";
        $stmt2 = $pdo->prepare($sql2); $stmt2->execute($params2);
        foreach ($stmt2->fetchAll() as $r) { $out[$r['department_id']]['renewal'] = (float)$r['total']; }
    } catch (PDOException $e) {}

    // 3. Maintenance
    try {
        $sql3 = "SELECT department_id, SUM(cost) total FROM maintenance_logs WHERE organization_id = ? AND cost IS NOT NULL";
        $params3 = [$orgId];
        if ($filterDeptId) { $sql3 .= " AND department_id = ?"; $params3[] = $filterDeptId; }
        $sql3 .= " GROUP BY department_id";
        $stmt3 = $pdo->prepare($sql3); $stmt3->execute($params3);
        foreach ($stmt3->fetchAll() as $r) { $out[$r['department_id']]['maintenance'] = (float)$r['total']; }
    } catch (PDOException $e) {}

    return $out;
}

function fetchCategoryPaid($pdo, $orgId, $filterDeptId) {
    $sql = "SELECT department_id, source_type, SUM(amount) total FROM dept_payment_ledger WHERE organization_id = ?";
    $params = [$orgId];
    if ($filterDeptId) { $sql .= " AND department_id = ?"; $params[] = $filterDeptId; }
    $sql .= " GROUP BY department_id, source_type";
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $out = [];
    $map = ['material_purchase' => 'material', 'renewal' => 'renewal', 'maintenance' => 'maintenance'];
    foreach ($stmt->fetchAll() as $r) { $out[$r['department_id']][$map[$r['source_type']]] = (float)$r['total']; }
    return $out;
}

$totals = fetchCategoryTotals($pdo, $orgId, $filterDeptId);
$paid = fetchCategoryPaid($pdo, $orgId, $filterDeptId);

$deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$allDeptsRaw = $deptsStmt->fetchAll();
$deptNames = [];
foreach ($allDeptsRaw as $d) { $deptNames[$d['id']] = $d['name_local'] ?: $d['name']; }

if (!$accessibleDeptId) {
    $campusDeptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $campusDeptsStmt->execute([$orgId]);
    $allDepts = $campusDeptsStmt->fetchAll();
}

// एकूण (सगळे विभाग किंवा Filter केलेला)
$grandMaterial = array_sum(array_column($totals, 'material'));
$grandRenewal = array_sum(array_column($totals, 'renewal'));
$grandMaintenance = array_sum(array_column($totals, 'maintenance'));
$grandSpent = $grandMaterial + $grandRenewal + $grandMaintenance;

$grandPaidMaterial = array_sum(array_column($paid, 'material'));
$grandPaidRenewal = array_sum(array_column($paid, 'renewal'));
$grandPaidMaintenance = array_sum(array_column($paid, 'maintenance'));
$grandPaid = $grandPaidMaterial + $grandPaidRenewal + $grandPaidMaintenance;
$grandDue = $grandSpent - $grandPaid;

// Department-निहाय Table साठी — सगळे Dept-IDs एकत्र (ज्यांना काहीतरी Record आहे)
$deptIdsInvolved = array_unique(array_merge(array_keys($totals), array_keys($paid)));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 विभाग Financial Dashboard</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Material Purchase Ledger</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">🛒 Material Purchase + 📋 Renewals/Subscriptions + 🔧 Maintenance — सगळं एकत्र</p>

<?php if (!$accessibleDeptId): ?>
<form method="GET" class="mb-3">
  <select name="department_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">-- सगळे विभाग --</option>
    <?php foreach ($allDepts as $d): ?><option value="<?= $d['id'] ?>" <?= $filterDeptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">💰 एकूण खर्च</div><div class="val">₹<?= number_format($grandSpent, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ एकूण Paid</div><div class="val" style="color:var(--rail);">₹<?= number_format($grandPaid, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ एकूण बाकी</div><div class="val" style="color:<?= $grandDue > 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($grandDue, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">Payment % पूर्ण</div><div class="val"><?= $grandSpent > 0 ? round(($grandPaid / $grandSpent) * 100) : 0 ?>%</div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">📂 प्रकारानुसार खर्च</div></div>
      <div style="padding:16px 20px;"><canvas id="categoryChart" height="180"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">💳 Paid vs Due</div></div>
      <div style="padding:16px 20px;"><canvas id="paidDueChart" height="180"></canvas></div>
    </div>
  </div>
</div>

<?php if (!$accessibleDeptId): ?>
<div class="panel">
  <div class="panel-head"><div class="title">🏢 विभाग-निहाय तपशील</div></div>
  <table class="rtable">
    <thead><tr><th>विभाग</th><th>🛒 Material</th><th>📋 Renewals</th><th>🔧 Maintenance</th><th>एकूण</th><th>Paid</th><th>बाकी</th></tr></thead>
    <tbody>
      <?php if (empty($deptIdsInvolved)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही Record नाही</td></tr><?php endif; ?>
      <?php foreach ($deptIdsInvolved as $did):
        $m = $totals[$did]['material'] ?? 0; $r = $totals[$did]['renewal'] ?? 0; $mt = $totals[$did]['maintenance'] ?? 0;
        $spent = $m + $r + $mt;
        $pd = ($paid[$did]['material'] ?? 0) + ($paid[$did]['renewal'] ?? 0) + ($paid[$did]['maintenance'] ?? 0);
        $due = $spent - $pd;
      ?>
        <tr>
          <td style="font-weight:600;"><?= h($deptNames[$did] ?? ('Dept #' . $did)) ?></td>
          <td class="font-mono">₹<?= number_format($m, 0) ?></td>
          <td class="font-mono">₹<?= number_format($r, 0) ?></td>
          <td class="font-mono">₹<?= number_format($mt, 0) ?></td>
          <td class="font-mono" style="font-weight:700;">₹<?= number_format($spent, 0) ?></td>
          <td class="font-mono" style="color:var(--rail);">₹<?= number_format($pd, 0) ?></td>
          <td class="font-mono" style="color:<?= $due > 0 ? 'var(--rose)' : 'var(--rail)' ?>;font-weight:700;">₹<?= number_format($due, 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
      labels: ['🛒 Material Purchase', '📋 Renewals/Subscriptions', '🔧 Maintenance'],
      datasets: [{ data: [<?= $grandMaterial ?>, <?= $grandRenewal ?>, <?= $grandMaintenance ?>], backgroundColor: ['#1B8A83','#C97F1D','#B23A48'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, cutout: '55%' }
  });

  new Chart(document.getElementById('paidDueChart'), {
    type: 'bar',
    data: {
      labels: ['Material', 'Renewals', 'Maintenance'],
      datasets: [
        { label: 'Paid', data: [<?= $grandPaidMaterial ?>, <?= $grandPaidRenewal ?>, <?= $grandPaidMaintenance ?>], backgroundColor: '#1B8A83', borderRadius: 6 },
        { label: 'Due', data: [<?= max(0, $grandMaterial - $grandPaidMaterial) ?>, <?= max(0, $grandRenewal - $grandPaidRenewal) ?>, <?= max(0, $grandMaintenance - $grandPaidMaintenance) ?>], backgroundColor: '#B23A48', borderRadius: 6 }
      ]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, scales: { x: { stacked: true }, y: { stacked: true } } }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
