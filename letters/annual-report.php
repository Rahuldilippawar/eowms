<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
requirePermission('manage_letters_org_wide', '../');
$user = currentUser();
$orgId = activeOrgId();

$fyLabel = $_GET['fy'] ?? getFinancialYearLabel();
[$fyStart, $fyEnd] = financialYearLabelToRange($fyLabel);
$fyStartTs = strtotime($fyStart);

$pageTitle = 'पत्रव्यवहार — वार्षिक/मासिक अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// एकूण आकडे — या आर्थिक वर्षाचे (एप्रिल-मार्च)
$totalStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ?");
$totalStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$totalLetters = $totalStmt->fetch()['c'];

$completedStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ? AND status = 'completed'");
$completedStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$completedLetters = $completedStmt->fetch()['c'];

$pendingStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ? AND status IN ('in_progress','returned')");
$pendingStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$pendingLetters = $pendingStmt->fetch()['c'];

// सरासरी किती दिवसांत पूर्ण झालं (Turnaround Time)
$turnaroundStmt = $pdo->prepare("
    SELECT AVG(DATEDIFF(updated_at, created_at)) avg_days FROM letters
    WHERE organization_id = ? AND created_at BETWEEN ? AND ? AND status = 'completed'
");
$turnaroundStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$avgTurnaround = round($turnaroundStmt->fetch()['avg_days'] ?? 0, 1);

// महिना-निहाय आकडे (Bar Chart साठी) — आर्थिक वर्षानुसार एप्रिल ते मार्च क्रमाने
$monthlyLabelsFY = ['एप्रि','मे','जून','जुलै','ऑग','सप्टें','ऑक्टो','नोव्हें','डिसें','जाने','फेब्रु','मार्च'];
$monthlyCounts = array_fill(0, 12, 0);
$monthlyStmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ? GROUP BY ym");
$monthlyStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$monthlyRaw = $monthlyStmt->fetchAll();
foreach ($monthlyRaw as $row) {
    $rowTs = strtotime($row['ym'] . '-01');
    $monthsSinceFyStart = ((int)date('Y', $rowTs) - (int)date('Y', $fyStartTs)) * 12 + ((int)date('n', $rowTs) - (int)date('n', $fyStartTs));
    if ($monthsSinceFyStart >= 0 && $monthsSinceFyStart < 12) { $monthlyCounts[$monthsSinceFyStart] = (int)$row['c']; }
}

// विभाग-निहाय तुलना
$deptStmt = $pdo->prepare("
    SELECT d.name_local AS dept_name, c.name AS campus_name, COUNT(l.id) AS total,
      SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) AS completed,
      AVG(CASE WHEN l.status = 'completed' THEN DATEDIFF(l.updated_at, l.created_at) ELSE NULL END) AS avg_days
    FROM letters l JOIN departments d ON d.id = l.department_id LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE l.organization_id = ? AND l.created_at BETWEEN ? AND ?
    GROUP BY d.id, d.name_local, c.name ORDER BY total DESC
");
$deptStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$deptStats = $deptStmt->fetchAll();

// प्रकार-निहाय विभाजन
$catStmt = $pdo->prepare("
    SELECT COALESCE(c.name_local, 'इतर') AS cat_name, COUNT(l.id) AS cnt
    FROM letters l LEFT JOIN letter_categories c ON c.id = l.category_id
    WHERE l.organization_id = ? AND l.created_at BETWEEN ? AND ?
    GROUP BY c.id, c.name_local HAVING cnt > 0 ORDER BY cnt DESC
");
$catStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
$catStats = $catStmt->fetchAll();

$currentFyStart = (int)date('Y', strtotime(getFinancialYearRange()[0]));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 पत्रव्यवहार — वार्षिक/मासिक अहवाल</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व पत्रं</a>
</div>

<form method="GET" class="mb-4">
  <select name="fy" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php for ($fy = $currentFyStart; $fy >= $currentFyStart - 5; $fy--):
      $optLabel = sprintf('%02d-%02d', $fy % 100, ($fy + 1) % 100);
    ?>
      <option value="<?= $optLabel ?>" <?= $fyLabel === $optLabel ? 'selected' : '' ?>>आर्थिक वर्ष <?= $optLabel ?></option>
    <?php endfor; ?>
  </select>
</form>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण पत्रं</div><div class="val"><?= $totalLetters ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">पूर्ण झालेली</div><div class="val" style="color:var(--rail);"><?= $completedLetters ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">प्रलंबित</div><div class="val" style="color:var(--amber);"><?= $pendingLetters ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">सरासरी निकाल-वेळ</div><div class="val"><?= $avgTurnaround ?> <span style="font-size:13px;">दिवस</span></div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title">महिना-निहाय पत्रसंख्या</div><div class="sub">आर्थिक वर्ष <?= h($fyLabel) ?> (एप्रिल-मार्च)</div></div>
      <div style="padding:18px 20px;"><canvas id="monthlyChart" height="90"></canvas></div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">प्रकार-निहाय विभाजन</div></div>
      <div style="padding:16px 20px;">
        <?php if (!empty($catStats)): ?>
          <div style="position:relative;height:200px;"><canvas id="catChart"></canvas></div>
        <?php else: ?>
          <p style="font-size:12.5px;color:var(--text-dim);text-align:center;padding:40px 0;">या आर्थिक वर्षी कुठलीही पत्रं नाहीत</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">🏢 विभाग-निहाय तुलना</div></div>
  <table class="rtable">
    <thead><tr><th>विभाग</th><th>College/School</th><th>एकूण</th><th>पूर्ण</th><th>प्रलंबित</th><th>सरासरी निकाल-वेळ</th></tr></thead>
    <tbody>
      <?php if (empty($deptStats)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">या आर्थिक वर्षी कुठलीही पत्रं नाहीत</td></tr><?php endif; ?>
      <?php foreach ($deptStats as $ds): ?>
        <tr>
          <td style="font-weight:600;"><?= h($ds['dept_name']) ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($ds['campus_name'] ?: '🏛️ Trust-स्तरीय') ?></td>
          <td class="font-mono"><?= $ds['total'] ?></td>
          <td class="font-mono" style="color:var(--rail);"><?= $ds['completed'] ?></td>
          <td class="font-mono" style="color:var(--amber);"><?= $ds['total'] - $ds['completed'] ?></td>
          <td class="font-mono"><?= $ds['avg_days'] !== null ? round($ds['avg_days'], 1) . ' दिवस' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<script>
new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($monthlyLabelsFY, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{ data: <?= json_encode($monthlyCounts) ?>, backgroundColor: '#1B8A83', borderRadius: 6, maxBarThickness: 36 }]
  },
  options: {
    plugins: { legend: { display: false } },
    scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { stepSize: 1 } } }
  }
});
<?php if (!empty($catStats)): ?>
new Chart(document.getElementById('catChart'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($catStats, 'cat_name'), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      data: <?= json_encode(array_map('intval', array_column($catStats, 'cnt'))) ?>,
      backgroundColor: ['#1B8A83','#C97F1D','#B23A48','#6B7080','#3B6FC9','#8A5CD6','#2FA37A','#D4A017','#5D8AA8','#A0522D'],
      borderWidth: 2, borderColor: '#fff'
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 10 }, boxWidth: 9, padding: 6 } } },
    cutout: '60%'
  }
});
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
