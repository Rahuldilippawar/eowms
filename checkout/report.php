<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'Checkout Report';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// 1. सगळ्यात जास्त वापरलेल्या वस्तू (Top 8)
$topItemsStmt = $pdo->prepare("
    SELECT ci.name, COUNT(cl.id) AS borrow_count
    FROM checkout_logs cl JOIN checkout_items ci ON ci.id = cl.item_id
    WHERE ci.organization_id = ? GROUP BY ci.id, ci.name ORDER BY borrow_count DESC LIMIT 8
");
$topItemsStmt->execute([$orgId]);
$topItems = $topItemsStmt->fetchAll();

// 2. सगळ्यात जास्त Borrow करणारे (Top 8)
$topBorrowersStmt = $pdo->prepare("
    SELECT u.name, COUNT(cl.id) AS borrow_count
    FROM checkout_logs cl JOIN checkout_items ci ON ci.id = cl.item_id JOIN users u ON u.id = cl.borrowed_by
    WHERE ci.organization_id = ? GROUP BY u.id, u.name ORDER BY borrow_count DESC LIMIT 8
");
$topBorrowersStmt->execute([$orgId]);
$topBorrowers = $topBorrowersStmt->fetchAll();

// 3. Available vs Out — सध्याची स्थिती
$statusStmt = $pdo->prepare("
    SELECT COUNT(*) AS total,
      SUM(CASE WHEN EXISTS (SELECT 1 FROM checkout_logs cl WHERE cl.item_id = ci.id AND cl.actual_return_at IS NULL) THEN 1 ELSE 0 END) AS out_count
    FROM checkout_items ci WHERE ci.organization_id = ? AND ci.is_active = 1
");
$statusStmt->execute([$orgId]);
$statusData = $statusStmt->fetch();
$availableCount = $statusData['total'] - $statusData['out_count'];

// 4. गेल्या 6 महिन्यांचा Monthly Trend
$trendStmt = $pdo->prepare("
    SELECT DATE_FORMAT(cl.checkout_at, '%Y-%m') AS ym, COUNT(*) c
    FROM checkout_logs cl JOIN checkout_items ci ON ci.id = cl.item_id
    WHERE ci.organization_id = ? AND cl.checkout_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym
");
$trendStmt->execute([$orgId]);
$trendRaw = $trendStmt->fetchAll();
$trendMap = [];
foreach ($trendRaw as $t) { $trendMap[$t['ym']] = $t['c']; }
$trendLabels = []; $trendValues = [];
for ($i = 5; $i >= 0; $i--) {
    $ym = date('Y-m', strtotime("-$i months"));
    $trendLabels[] = date('M', strtotime($ym . '-01'));
    $trendValues[] = $trendMap[$ym] ?? 0;
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 Checkout Report</div>
  <div class="d-flex gap-2 no-print">
    <button onclick="window.print()" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">🖨️ Print/PDF</button>
    <a href="export.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;">📥 CSV Export</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
  </div>
</div>
<div class="print-only" style="display:none;text-align:center;margin-bottom:16px;">
  <div style="font-family:'Space Grotesk',sans-serif;font-weight:700;font-size:16px;"><?= h(activeOrgName()) ?></div>
  <div style="font-size:12px;color:#666;">Checkout / Borrowing Report — <?= date('d M Y') ?></div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🏆 सगळ्यात जास्त वापरलेल्या वस्तू</div></div>
      <div style="padding:16px 20px;"><canvas id="topItemsChart" height="200"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">🟢 सध्याची स्थिती</div></div>
      <div style="padding:16px 20px;display:flex;justify-content:center;"><canvas id="statusChart" height="180" style="max-width:260px;"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">👤 सगळ्यात जास्त Borrow करणारे</div></div>
      <div style="padding:16px 20px;"><canvas id="topBorrowersChart" height="200"></canvas></div>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">📈 गेल्या 6 महिन्यांचा Trend</div></div>
      <div style="padding:16px 20px;"><canvas id="trendChart" height="140"></canvas></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('topItemsChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($topItems, 'name'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('intval', array_column($topItems, 'borrow_count'))) ?>, backgroundColor: '#1B8A83', borderRadius: 6 }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('topBorrowersChart'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_column($topBorrowers, 'name'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map('intval', array_column($topBorrowers, 'borrow_count'))) ?>, backgroundColor: '#C97F1D', borderRadius: 6 }]
    },
    options: { indexAxis: 'y', plugins: { legend: { display: false } } }
  });

  new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
      labels: ['🟢 उपलब्ध', '🔴 सध्या बाहेर'],
      datasets: [{ data: [<?= (int)$availableCount ?>, <?= (int)$statusData['out_count'] ?>], backgroundColor: ['#1B8A83', '#B23A48'], borderWidth: 2, borderColor: '#fff' }]
    },
    options: { plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 11 } } } }, cutout: '60%' }
  });

  new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: <?= json_encode($trendLabels) ?>,
      datasets: [{ data: <?= json_encode($trendValues) ?>, borderColor: '#1B8A83', backgroundColor: 'rgba(27,138,131,.1)', fill: true, tension: 0.35, pointRadius: 3 }]
    },
    options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
  });
});
</script>

<style>
@media print {
  .no-print, .topbar, .rail-sidebar { display: none !important; }
  .print-only { display: block !important; }
  .main-wrap { margin-left: 0 !important; }
  body { background: #fff !important; }
  .panel { box-shadow: none !important; border: 1px solid #ddd !important; break-inside: avoid; }
  canvas { max-width: 100% !important; }
}
</style>
<div class="no-print" style="font-size:11px;color:var(--text-dim);margin-top:20px;padding:10px 14px;background:var(--canvas);border-radius:8px;">
  💡 PDF हवं असेल तर — Print Dialog मध्ये "Save as PDF" निवडा, आणि Charts बरोबर दिसण्यासाठी <strong>"Background graphics"</strong> चालू ठेवा.
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
