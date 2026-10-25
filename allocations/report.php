<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'Issue / Return Report';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// Type-निहाय Breakdown (सध्या Active असलेले)
$typeBreakdownStmt = $pdo->prepare("
    SELECT at.name, COUNT(aa.id) c FROM asset_allocations aa JOIN asset_allocation_types at ON at.id = aa.type_id
    WHERE aa.organization_id = ? AND aa.unallocated_date IS NULL GROUP BY at.id, at.name ORDER BY c DESC
");
$typeBreakdownStmt->execute([$orgId]);
$typeBreakdown = $typeBreakdownStmt->fetchAll();

// Campus-निहाय
$campusBreakdownStmt = $pdo->prepare("
    SELECT COALESCE(c.name, '—') AS name, COUNT(aa.id) cnt FROM asset_allocations aa LEFT JOIN campuses c ON c.id = aa.campus_id
    WHERE aa.organization_id = ? AND aa.unallocated_date IS NULL GROUP BY c.id, c.name ORDER BY cnt DESC
");
$campusBreakdownStmt->execute([$orgId]);
$campusBreakdown = $campusBreakdownStmt->fetchAll();

// Employee-निहाय — कोणाकडे किती वस्तू
$employeeBreakdownStmt = $pdo->prepare("
    SELECT u.name, COUNT(aa.id) cnt FROM asset_allocations aa JOIN users u ON u.id = aa.user_id
    WHERE aa.organization_id = ? AND aa.unallocated_date IS NULL GROUP BY u.id, u.name ORDER BY cnt DESC LIMIT 12
");
$employeeBreakdownStmt->execute([$orgId]);
$employeeBreakdown = $employeeBreakdownStmt->fetchAll();

// सगळ्यात जास्त Issue झालेल्या वस्तू (संपूर्ण History — जुन्या Checkout सह)
$topItemsStmt = $pdo->prepare("
    SELECT aa.asset_label AS name, COUNT(*) AS cnt FROM asset_allocations aa
    WHERE aa.organization_id = ? AND aa.asset_label IS NOT NULL GROUP BY aa.asset_label ORDER BY cnt DESC LIMIT 8
");
$topItemsStmt->execute([$orgId]);
$topItems = $topItemsStmt->fetchAll();

// गेल्या 6 महिन्यांचा Monthly Trend
$trendStmt = $pdo->prepare("
    SELECT DATE_FORMAT(COALESCE(aa.allocated_at, aa.allocated_date), '%Y-%m') AS ym, COUNT(*) c
    FROM asset_allocations aa WHERE aa.organization_id = ? AND COALESCE(aa.allocated_at, aa.allocated_date) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym
");
$trendStmt->execute([$orgId]);
$trendMap = [];
foreach ($trendStmt->fetchAll() as $t) { $trendMap[$t['ym']] = (int)$t['c']; }
$trendLabels = []; $trendValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
    $trendLabels[] = date('M', strtotime($ym . '-01'));
    $trendValues[] = $trendMap[$ym] ?? 0;
}

// मुदत संपलेली उसनं (Overdue Loans)
$overdueStmt = $pdo->prepare("
    SELECT aa.asset_label, aa.due_at, u.name AS user_name FROM asset_allocations aa JOIN users u ON u.id = aa.user_id
    WHERE aa.organization_id = ? AND aa.unallocated_date IS NULL AND aa.due_at IS NOT NULL AND aa.due_at < NOW() ORDER BY aa.due_at
");
$overdueStmt->execute([$orgId]);
$overdueLoans = $overdueStmt->fetchAll();
$canExport = hasPermission('manage_asset_allocations') || hasPermission('manage_checkout') || isManagementOrAbove();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 Issue / Return Report</div>
  <div class="d-flex gap-2 no-print">
    <button onclick="window.print()" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">🖨️ Print/PDF</button>
    <?php if ($canExport): ?><a href="export.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;">📥 CSV Export</a><?php endif; ?>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🏷️ Type-निहाय विभाजन</div></div>
      <div style="padding:16px 20px;"><canvas id="typeChart" height="180"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">🏫 College/School-निहाय</div></div>
      <div style="padding:16px 20px;"><canvas id="campusChart" height="180"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">👤 कोणाकडे सगळ्यात जास्त वस्तू</div></div>
      <table class="rtable">
        <thead><tr><th>नाव</th><th>एकूण वस्तू</th></tr></thead>
        <tbody>
          <?php if (empty($employeeBreakdown)): ?><tr><td colspan="2" style="text-align:center;color:var(--text-dim);padding:20px;">Data नाही</td></tr><?php endif; ?>
          <?php foreach ($employeeBreakdown as $e): ?>
            <tr><td style="font-weight:600;"><?= h($e['name']) ?></td><td class="font-mono"><?= $e['cnt'] ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🏆 सगळ्यात जास्त Issue झालेल्या वस्तू</div></div>
      <div style="padding:16px 20px;"><canvas id="topItemsChart" height="200"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📈 गेल्या 6 महिन्यांचा Trend</div></div>
      <div style="padding:16px 20px;"><canvas id="trendChart" height="140"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">⚠️ मुदत संपलेली उसनं</div></div>
      <table class="rtable">
        <thead><tr><th>वस्तू</th><th>कोणाकडे</th><th>मुदत</th></tr></thead>
        <tbody>
          <?php if (empty($overdueLoans)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:16px;">काहीही नाही 🎉</td></tr><?php endif; ?>
          <?php foreach ($overdueLoans as $o): ?><tr><td style="font-weight:600;"><?= h($o['asset_label']) ?></td><td><?= h($o['user_name']) ?></td><td class="font-mono" style="color:var(--rose);"><?= date('d M, h:i A', strtotime($o['due_at'])) ?></td></tr><?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>@media print { .no-print, .topbar, .rail-sidebar { display: none !important; } .main-wrap { margin-left: 0 !important; } }</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('typeChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_column($typeBreakdown, 'name'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('intval', array_column($typeBreakdown, 'c'))) ?>, backgroundColor: ['#1B8A83','#C97F1D','#B23A48','#8A5CD6','#2255AA','#666B7D'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, cutout: '55%' }
  });

  new Chart(document.getElementById('topItemsChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($topItems, 'name'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('intval', array_column($topItems, 'cnt'))) ?>, backgroundColor: '#1B8A83', borderRadius: 6 }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
  });
  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendLabels) ?>,
      datasets: [{ data: <?= json_encode($trendValues) ?>, borderColor: '#1B8A83', backgroundColor: 'rgba(27,138,131,.1)', fill: true, tension: 0.35, pointRadius: 3 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
  });

  new Chart(document.getElementById('campusChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($campusBreakdown, 'name'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('intval', array_column($campusBreakdown, 'cnt'))) ?>, backgroundColor: '#1B8A83', borderRadius: 6 }]
    },
    options: { plugins: { legend: { display: false } } }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
