<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_cattle', '../', 'animal_category');
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'गौशाळा Report';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// 1. जातीनुसार (Breed) विभाजन
$breedStmt = $pdo->prepare("SELECT COALESCE(NULLIF(breed,''), 'अज्ञात') breed, COUNT(*) c FROM gaushala_cattle WHERE organization_id = ? AND status = 'active' GROUP BY breed ORDER BY c DESC");
$breedStmt->execute([$orgId]);
$breedData = $breedStmt->fetchAll();

// वर्गानुसार विभाजन: Batch वाली गाय / बिना Batch गाय / वासरं / गोऱ्हे / बैल
$catStmt = $pdo->prepare("SELECT animal_category, (tag_number IS NOT NULL AND tag_number <> '') AS is_tagged, COUNT(*) c FROM gaushala_cattle WHERE organization_id = ? AND status = 'active' GROUP BY animal_category, is_tagged");
$catStmt->execute([$orgId]);
$catBuckets = ['Batch वाली गाय' => 0, 'बिना Batch गाय' => 0, 'वासरं' => 0, 'गोऱ्हे' => 0, 'बैल' => 0];
foreach ($catStmt->fetchAll() as $cr) {
    $cat = $cr['animal_category'] ?? 'Cow';
    if ($cat === 'Cow') { $catBuckets[$cr['is_tagged'] ? 'Batch वाली गाय' : 'बिना Batch गाय'] += (int)$cr['c']; }
    elseif ($cat === 'Calf') { $catBuckets['वासरं'] += (int)$cr['c']; }
    elseif ($cat === 'Young Bull') { $catBuckets['गोऱ्हे'] += (int)$cr['c']; }
    else { $catBuckets['बैल'] += (int)$cr['c']; }
}

// 2. गेल्या 30 दिवसांचा Milk-Trend
$milkTrendStmt = $pdo->prepare("
    SELECT gm.record_date, SUM(gm.quantity_liters) total FROM gaushala_milk_records gm
    JOIN gaushala_cattle gc ON gc.id = gm.cattle_id
    WHERE gc.organization_id = ? AND gm.record_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY gm.record_date ORDER BY gm.record_date
");
$milkTrendStmt->execute([$orgId]);
$milkTrendRaw = $milkTrendStmt->fetchAll();
$milkMap = [];
foreach ($milkTrendRaw as $m) { $milkMap[$m['record_date']] = (float)$m['total']; }
$milkLabels = []; $milkValues = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $milkLabels[] = date('d M', strtotime($d));
    $milkValues[] = $milkMap[$d] ?? 0;
}

// 3. सगळ्यात जास्त दूध देणाऱ्या (Top 8)
$topMilkersStmt = $pdo->prepare("
    SELECT gc.tag_number, gc.name, SUM(gm.quantity_liters) total FROM gaushala_milk_records gm
    JOIN gaushala_cattle gc ON gc.id = gm.cattle_id
    WHERE gc.organization_id = ? AND gm.record_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
    GROUP BY gc.id, gc.tag_number, gc.name ORDER BY total DESC LIMIT 8
");
$topMilkersStmt->execute([$orgId]);
$topMilkers = $topMilkersStmt->fetchAll();

// 4. आरोग्य-खर्च — गेल्या 6 महिन्यांचा
$healthCostStmt = $pdo->prepare("
    SELECT DATE_FORMAT(gh.record_date, '%Y-%m') ym, COALESCE(SUM(gh.cost), 0) total FROM gaushala_health_records gh
    JOIN gaushala_cattle gc ON gc.id = gh.cattle_id
    WHERE gc.organization_id = ? AND gh.record_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym
");
$healthCostStmt->execute([$orgId]);
$healthCostRaw = $healthCostStmt->fetchAll();
$healthCostMap = [];
foreach ($healthCostRaw as $h) { $healthCostMap[$h['ym']] = (float)$h['total']; }
$healthLabels = []; $healthValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $healthLabels[] = date('M', strtotime($ym . '-01'));
    $healthValues[] = $healthCostMap[$ym] ?? 0;
}

// 5. Status-निहाय (Active/Sold/Deceased/Donated) विभाजन
$statusStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM gaushala_cattle WHERE organization_id = ? GROUP BY status");
$statusStmt->execute([$orgId]);
$statusData = $statusStmt->fetchAll();
$statusLabelsMap = ['active' => 'सक्रिय', 'sold' => 'विकलं', 'deceased' => 'मृत्यू', 'donated_out' => 'दान दिलं'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 गौशाळा Report</div>
  <div class="d-flex gap-2 no-print">
    <button onclick="window.print()" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">🖨️ Print/PDF</button>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📈 गेल्या 30 दिवसांचा दूध-Trend</div></div>
      <div style="padding:16px 20px;"><canvas id="milkTrendChart" height="140"></canvas></div>
    </div>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🐄 जातीनुसार विभाजन</div></div>
      <div style="padding:16px 20px;"><canvas id="breedChart" height="180"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🐄 वर्गानुसार (Batch / बिना Batch, वासरं, गोऱ्हे)</div></div>
      <div style="padding:16px 20px;"><canvas id="categoryChart" height="180"></canvas></div>
    </div>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🏆 सगळ्यात जास्त दूध देणाऱ्या (30 दिवस)</div></div>
      <div style="padding:16px 20px;"><canvas id="topMilkersChart" height="180"></canvas></div>
    </div>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">💊 आरोग्य-खर्च — गेले 6 महिने</div></div>
      <div style="padding:16px 20px;"><canvas id="healthCostChart" height="140"></canvas></div>
    </div>
  </div>
</div>

<style>@media print { .no-print, .topbar, .rail-sidebar { display: none !important; } .main-wrap { margin-left: 0 !important; } }</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('milkTrendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($milkLabels) ?>,
      datasets: [{ label: 'लिटर', data: <?= json_encode($milkValues) ?>, borderColor: '#1B8A83', backgroundColor: 'rgba(27,138,131,.1)', fill: true, tension: 0.3, pointRadius: 0 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
  });

  new Chart(document.getElementById('breedChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_column($breedData, 'breed'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('intval', array_column($breedData, 'c'))) ?>, backgroundColor: ['#1B8A83','#C97F1D','#B23A48','#8A5CD6','#2255AA','#666B7D'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, cutout: '55%' }
  });

  new Chart(document.getElementById('categoryChart'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_keys($catBuckets), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_values($catBuckets)) ?>, backgroundColor: ['#1B8A83','#C97F1D','#8A5CD6','#2255AA','#666B7D'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, cutout: '55%' }
  });

  new Chart(document.getElementById('topMilkersChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map(fn($m) => ($m['tag_number'] ?: 'बिनTag') . ($m['name'] ? ' (' . $m['name'] . ')' : ''), $topMilkers), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('floatval', array_column($topMilkers, 'total'))) ?>, backgroundColor: '#C97F1D', borderRadius: 6 }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('healthCostChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode($healthLabels) ?>,
      datasets: [{ data: <?= json_encode($healthValues) ?>, backgroundColor: '#B23A48', borderRadius: 6 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: function (v) { return '₹' + v; } } } } }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
