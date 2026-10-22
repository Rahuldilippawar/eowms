<?php
$pageTitle = 'पगार अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

if (!isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला पगार अहवाल पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$orgId = activeOrgId();

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
$countWithSalary = count(array_filter($staff, fn($s) => $s['salary'] !== null));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💰 पगार अहवाल</div>
  <a href="salary-report-print.php" target="_blank" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-file-pdf me-1"></i>PDF डाउनलोड करा</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;">फक्त Management व Super Admin ला दृश्यमान — गोपनीय माहिती</p>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-4"><div class="kpi-mini"><div class="lbl">एकूण कर्मचारी</div><div class="val"><?= count($staff) ?></div></div></div>
  <div class="col-6 col-lg-4"><div class="kpi-mini"><div class="lbl">पगार नोंदवलेले</div><div class="val"><?= $countWithSalary ?></div></div></div>
  <div class="col-6 col-lg-4"><div class="kpi-mini"><div class="lbl">एकूण मासिक पगार (₹)</div><div class="val" style="color:var(--rail);"><?= number_format($totalSalary, 0) ?></div></div></div>
</div>

<?php foreach ($grouped as $deptName => $deptStaff): ?>
  <div class="panel mb-3">
    <div class="panel-head d-flex justify-content-between align-items-center">
      <div class="title"><?= h($deptName) ?></div>
      <div class="sub">विभाग एकूण: ₹<?= number_format(array_sum(array_column($deptStaff, 'salary')), 0) ?></div>
    </div>
    <table class="rtable">
      <thead><tr><th>नाव</th><th>पदनाम</th><th>रुजू दिनांक</th><th>पगार (₹)</th></tr></thead>
      <tbody>
        <?php foreach ($deptStaff as $s): ?>
          <tr>
            <td style="font-weight:500;"><?= h($s['name']) ?></td>
            <td><?= h($s['designation'] ?? '—') ?></td>
            <td class="font-mono"><?= $s['joining_date'] ? date('d M Y', strtotime($s['joining_date'])) : '—' ?></td>
            <td class="font-mono" style="font-weight:600;color:var(--rail);"><?= $s['salary'] !== null ? number_format($s['salary'], 0) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
