<?php
$pageTitle = 'खर्च-कल विश्लेषण';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_GET['department_id'] ?? '');

// सध्याचा डेटा (renewal_items) + इतिहासातला डेटा (renewal_history) — एकत्र करून
// वर्ष-निहाय एकूण खर्च काढतो.
$sql = "
    SELECT YEAR(expiry_date) AS yr, item_type, SUM(renewal_cost) AS total_cost, COUNT(*) AS cnt
    FROM renewal_items r
    WHERE r.organization_id = ? AND renewal_cost IS NOT NULL" . ($filterDeptId ? " AND r.department_id = ?" : "") . "
    GROUP BY YEAR(expiry_date), item_type
";
$params = [$orgId];
if ($filterDeptId) { $params[] = $filterDeptId; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$currentRows = $stmt->fetchAll();

$histRows = [];
try {
    $histSql = "
        SELECT YEAR(h.old_expiry_date) AS yr, r.item_type, SUM(h.old_renewal_cost) AS total_cost, COUNT(*) AS cnt
        FROM renewal_history h JOIN renewal_items r ON r.id = h.renewal_item_id
        WHERE r.organization_id = ? AND h.old_renewal_cost IS NOT NULL" . ($filterDeptId ? " AND r.department_id = ?" : "") . "
        GROUP BY YEAR(h.old_expiry_date), r.item_type
    ";
    $histStmt = $pdo->prepare($histSql);
    $histStmt->execute($params);
    $histRows = $histStmt->fetchAll();
} catch (PDOException $e) { /* renewal_history not migrated yet */ }

// वर्ष-निहाय बेरीज (Type-निहाय + Total दोन्ही)
$yearlyTotals = [];
$typeYearly = [];
foreach (array_merge($currentRows, $histRows) as $r) {
    $yr = (int)$r['yr'];
    $yearlyTotals[$yr] = ($yearlyTotals[$yr] ?? 0) + (float)$r['total_cost'];
    $typeYearly[$r['item_type']][$yr] = ($typeYearly[$r['item_type']][$yr] ?? 0) + (float)$r['total_cost'];
}
ksort($yearlyTotals);
$years = array_keys($yearlyTotals);

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
$campusesForCascade = [];
if (!isDeptScoped()) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

// पुढच्या वर्षाचा साधा अंदाज — मागच्या वर्षांची सरासरी वाढ बघून
$forecast = null;
if (count($years) >= 2) {
    $sortedYears = $years;
    sort($sortedYears);
    $lastYear = end($sortedYears);
    $prevYear = $sortedYears[count($sortedYears) - 2];
    $growth = $yearlyTotals[$prevYear] > 0 ? ($yearlyTotals[$lastYear] - $yearlyTotals[$prevYear]) / $yearlyTotals[$prevYear] : 0;
    $forecast = round($yearlyTotals[$lastYear] * (1 + $growth));
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💰 नूतनीकरण — खर्च-कल विश्लेषण</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>नूतनीकरण यादी</a>
</div>

<?php if (!isDeptScoped()): ?>
<form method="GET" class="mb-3 d-flex gap-2">
  <?php if (count($campusesForCascade) > 1): ?>
    <select id="campusSelect" class="form-select-custom" style="width:auto;" onchange="loadDeptsCascade_costAn(this.value)">
      <option value="">-- College/School --</option>
      <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
  <select name="department_id" id="departmentSelect" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">-- सगळे विभाग --</option>
    <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>" <?= (string)$filterDeptId === (string)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
  </select>
  <?php if (count($campusesForCascade) > 1): ?>
  <script>
  function loadDeptsCascade_costAn(campusId) {
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
</form>
<?php endif; ?>

<?php if ($forecast !== null): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:16px 20px;">
    💡 <strong>पुढच्या वर्षाचा अंदाज:</strong> मागील वर्षांच्या कलावरून, पुढच्या वर्षी अंदाजे <strong>₹<?= number_format($forecast, 0) ?></strong> खर्च अपेक्षित आहे (Budget नियोजनासाठी उपयोगी).
  </div>
<?php endif; ?>

<div class="panel mb-4">
  <div class="panel-head"><div class="title">वर्ष-निहाय एकूण खर्च</div></div>
  <div style="padding:18px 20px;"><canvas id="yearlyTrendChart" height="90"></canvas></div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">प्रकार-निहाय, वर्ष-निहाय तपशील</div></div>
  <table class="rtable">
    <thead><tr><th>प्रकार</th><?php foreach ($years as $y): ?><th><?= $y ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php if (empty($typeYearly)): ?><tr><td colspan="<?= count($years) + 1 ?>" style="text-align:center;color:var(--text-dim);padding:24px;">अजून पुरेसा खर्च-डेटा नाही</td></tr><?php endif; ?>
      <?php foreach ($typeYearly as $type => $yearData): ?>
        <tr>
          <td style="font-weight:600;"><?= h($type) ?></td>
          <?php foreach ($years as $y): ?><td class="font-mono"><?= isset($yearData[$y]) ? '₹' . number_format($yearData[$y], 0) : '—' ?></td><?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('yearlyTrendChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($years) ?>,
      datasets: [{ label: 'एकूण खर्च (₹)', data: <?= json_encode(array_values($yearlyTotals)) ?>, backgroundColor: '#1B8A83', borderRadius: 6 }]
    },
    options: {
      plugins: { legend: { display: false } },
      scales: { y: { beginAtZero: true, ticks: { callback: function (v) { return '₹' + v.toLocaleString('en-IN'); } } } }
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
