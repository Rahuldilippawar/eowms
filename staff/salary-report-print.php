<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');

if (!isManagementOrAbove()) {
    die('तुम्हाला पगार अहवाल पाहण्याची परवानगी नाही.');
}

$orgId = activeOrgId();
$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$stmt = $pdo->prepare("
    SELECT u.*, d.name_local AS dept_name
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1
    ORDER BY d.name_local, u.name
");
$stmt->execute([$orgId]);
$staff = $stmt->fetchAll();

$grouped = [];
foreach ($staff as $s) {
    $key = $s['dept_name'] ?? 'विभाग नसलेले';
    $grouped[$key][] = $s;
}
$totalSalary = array_sum(array_column($staff, 'salary'));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>पगार अहवाल</title>
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
  .meta{text-align:center;font-size:12px;color:var(--text-dim);margin-top:4px;}
  h3{font-family:'Space Grotesk';font-size:14px;color:var(--ink);margin:22px 0 8px;display:flex;justify-content:space-between;}
  table{width:100%;border-collapse:collapse;margin-top:6px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10.5px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:12px;}
  .total-row td{font-weight:700;background:#F8F9FB;}
  @media print{ .toolbar{display:none;} body{background:#fff;} .doc{margin:0;} }
</style>
</head>
<body>
<div class="toolbar">
  <a href="salary-report.php" style="color:#fff;text-decoration:none;font-size:13px;">← मागे</a>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>
<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= htmlspecialchars($org['logo_path']) ?>" style="height:56px;"></div><?php endif; ?>
  <div class="org-name"><?= htmlspecialchars($org['name'] ?? '') ?></div>
  <div class="report-title">पगार अहवाल (Salary Report) — गोपनीय</div>
  <div class="meta">एकूण कर्मचारी: <?= count($staff) ?> · एकूण मासिक पगार: ₹<?= number_format($totalSalary, 0) ?></div>

  <?php foreach ($grouped as $deptName => $deptStaff): ?>
    <h3><span><?= htmlspecialchars($deptName) ?></span><span>विभाग एकूण: ₹<?= number_format(array_sum(array_column($deptStaff, 'salary')), 0) ?></span></h3>
    <table>
      <thead><tr><th>नाव</th><th>पदनाम</th><th>रुजू दिनांक</th><th>पगार (₹)</th></tr></thead>
      <tbody>
        <?php foreach ($deptStaff as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['name']) ?></td>
            <td><?= htmlspecialchars($s['designation'] ?? '—') ?></td>
            <td><?= $s['joining_date'] ? date('d M Y', strtotime($s['joining_date'])) : '—' ?></td>
            <td><?= $s['salary'] !== null ? number_format($s['salary'], 0) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endforeach; ?>

  <table style="margin-top:14px;"><tr class="total-row"><td colspan="3">एकूण मासिक पगार (सर्व विभाग)</td><td>₹<?= number_format($totalSalary, 0) ?></td></tr></table>

  <div style="margin-top:24px;font-size:11px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>
</body>
</html>
