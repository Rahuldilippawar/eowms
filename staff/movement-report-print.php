<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');

if (!isManagementOrAbove()) {
    die('तुम्हाला हा अहवाल पाहण्याची परवानगी नाही.');
}

$orgId = activeOrgId();
$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

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

$stmt = $pdo->prepare("
    SELECT u.name, d.name_local AS dept_name, u.joining_date
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.joining_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    ORDER BY u.joining_date DESC
");
$stmt->execute([$orgId]);
$recentJoins = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT u.name, d.name_local AS dept_name, u.left_date
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.left_date IS NOT NULL
    ORDER BY u.left_date DESC
");
$stmt->execute([$orgId]);
$recentLeaves = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>कर्मचारी Movement अहवाल</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .doc{max-width:850px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:20px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-top:6px;color:var(--rail);}
  h3{font-family:'Space Grotesk';font-size:14px;color:var(--ink);margin:22px 0 8px;}
  table{width:100%;border-collapse:collapse;margin-top:6px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10.5px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:12px;}
  @media print{ .toolbar{display:none;} body{background:#fff;} .doc{margin:0;} }
</style>
</head>
<body>
<div class="toolbar">
  <a href="movement-report.php" style="color:#fff;text-decoration:none;font-size:13px;">← मागे</a>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>
<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= htmlspecialchars($org['logo_path']) ?>" style="height:56px;"></div><?php endif; ?>
  <div class="org-name"><?= htmlspecialchars($org['name'] ?? '') ?></div>
  <div class="report-title">कर्मचारी Movement अहवाल — मागील १२ महिने</div>

  <h3>महिना-निहाय सारांश</h3>
  <table>
    <thead><tr><th>महिना</th><th>रुजू झालेले</th><th>सोडलेले</th></tr></thead>
    <tbody>
      <?php for ($i = 0; $i < count($months); $i++): ?>
        <tr><td><?= $months[$i] ?></td><td><?= $joinCounts[$i] ?></td><td><?= $leaveCounts[$i] ?></td></tr>
      <?php endfor; ?>
    </tbody>
  </table>

  <h3>अलीकडे रुजू झालेले</h3>
  <table>
    <thead><tr><th>नाव</th><th>विभाग</th><th>दिनांक</th></tr></thead>
    <tbody>
      <?php foreach ($recentJoins as $r): ?>
        <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['dept_name'] ?? '—') ?></td><td><?= date('d M Y', strtotime($r['joining_date'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($recentJoins)): ?><tr><td colspan="3">कोणतीही नोंद नाही</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>सोडून गेलेले</h3>
  <table>
    <thead><tr><th>नाव</th><th>विभाग</th><th>दिनांक</th></tr></thead>
    <tbody>
      <?php foreach ($recentLeaves as $r): ?>
        <tr><td><?= htmlspecialchars($r['name']) ?></td><td><?= htmlspecialchars($r['dept_name'] ?? '—') ?></td><td><?= date('d M Y', strtotime($r['left_date'])) ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($recentLeaves)): ?><tr><td colspan="3">कोणतीही नोंद नाही</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:24px;font-size:11px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>
</body>
</html>
