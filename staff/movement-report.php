<?php
$pageTitle = 'कर्मचारी Movement अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

if (!isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला हा अहवाल पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$orgId = activeOrgId();

// Last 12 months — count joins and leaves per month
$months = [];
$joinCounts = [];
$leaveCounts = [];
for ($i = 11; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $months[] = date('M Y', strtotime($monthStart));

    $j = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND joining_date BETWEEN ? AND ?");
    $j->execute([$orgId, $monthStart, $monthEnd]);
    $joinCounts[] = (int)$j->fetch()['c'];

    $l = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND left_date BETWEEN ? AND ?");
    $l->execute([$orgId, $monthStart, $monthEnd]);
    $leaveCounts[] = (int)$l->fetch()['c'];
}

// Detailed lists
$stmt = $pdo->prepare("
    SELECT u.name, u.designation, d.name_local AS dept_name, u.joining_date
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.joining_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ORDER BY u.joining_date DESC
");
$stmt->execute([$orgId]);
$recentJoins = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.name, u.designation, d.name_local AS dept_name, u.left_date
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.left_date IS NOT NULL
    ORDER BY u.left_date DESC
");
$stmt->execute([$orgId]);
$recentLeaves = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 कर्मचारी Movement अहवाल</div>
  <a href="movement-report-print.php" target="_blank" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-file-pdf me-1"></i>PDF डाउनलोड करा</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;">कोणत्या महिन्यात किती कर्मचारी रुजू झाले, किती सोडून गेले</p>

<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="panel">
      <div class="panel-head"><div class="title">मागील १२ महिने — रुजू vs सोडलेले</div></div>
      <div style="padding:18px 20px;"><canvas id="movementChart" height="90"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">अलीकडे रुजू झालेले</div></div>
      <table class="rtable">
        <thead><tr><th>नाव</th><th>विभाग</th><th>दिनांक</th></tr></thead>
        <tbody>
          <?php if (empty($recentJoins)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:18px;">कोणतीही नोंद नाही</td></tr><?php endif; ?>
          <?php foreach ($recentJoins as $r): ?>
            <tr><td style="font-weight:500;"><?= h($r['name']) ?></td><td><?= h($r['dept_name'] ?? '—') ?></td><td class="font-mono"><?= date('d M Y', strtotime($r['joining_date'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">सोडून गेलेले</div></div>
      <table class="rtable">
        <thead><tr><th>नाव</th><th>विभाग</th><th>दिनांक</th></tr></thead>
        <tbody>
          <?php if (empty($recentLeaves)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:18px;">कोणतीही नोंद नाही</td></tr><?php endif; ?>
          <?php foreach ($recentLeaves as $r): ?>
            <tr><td style="font-weight:500;"><?= h($r['name']) ?></td><td><?= h($r['dept_name'] ?? '—') ?></td><td class="font-mono" style="color:var(--rose);"><?= date('d M Y', strtotime($r['left_date'])) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('movementChart'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($months) ?>,
    datasets: [
      { label: 'रुजू झालेले', data: <?= json_encode($joinCounts) ?>, backgroundColor: '#1B8A83', borderRadius: 5 },
      { label: 'सोडलेले', data: <?= json_encode($leaveCounts) ?>, backgroundColor: '#B23A48', borderRadius: 5 }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 12 }, boxWidth: 10 } } },
    scales: {
      x: { grid: { display: false } },
      y: { beginAtZero: true, ticks: { stepSize: 1 }, grid: { color: '#EEF0F4' } }
    }
  }
});
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
