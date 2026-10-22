<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');

$pageTitle = 'मासिक Attendance Summary';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$month = $_GET['month'] ?? date('Y-m');

$sql = "
    SELECT u.id, u.name, u.designation, d.name_local AS dept_name,
      SUM(CASE WHEN sa.status = 'present' THEN 1 ELSE 0 END) AS present_count,
      SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
      SUM(CASE WHEN sa.status = 'half_day' THEN 1 ELSE 0 END) AS half_day_count,
      SUM(CASE WHEN sa.status = 'on_leave' THEN 1 ELSE 0 END) AS leave_count,
      SUM(CASE WHEN sa.is_late = 1 THEN 1 ELSE 0 END) AS late_count,
      COUNT(sa.id) AS total_marked
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN staff_attendance sa ON sa.user_id = u.id AND DATE_FORMAT(sa.attendance_date, '%Y-%m') = ?
    WHERE u.organization_id = ? AND u.is_active = 1
";
$params = [$month, $orgId];
if (!$isMgmt) { $sql .= " AND u.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
$sql .= " GROUP BY u.id, u.name, u.designation, d.name_local ORDER BY u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$summary = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 मासिक Attendance Summary</div>
  <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
</div>

<form method="GET" class="mb-4">
  <input type="month" name="month" class="form-control-custom" style="width:auto;" value="<?= h($month) ?>" onchange="this.form.submit()">
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>विभाग</th><th>हजर</th><th>गैरहजर</th><th>अर्धा दिवस</th><th>रजा</th><th>उशीर</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($summary)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">Staff सापडले नाहीत</td></tr><?php endif; ?>
      <?php foreach ($summary as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?><div style="font-size:10px;color:var(--text-dim);"><?= h($s['designation'] ?: '') ?></div></td>
          <td><?= h($s['dept_name'] ?: '—') ?></td>
          <td class="font-mono" style="color:var(--rail);font-weight:600;"><?= $s['present_count'] ?></td>
          <td class="font-mono" style="color:var(--rose);font-weight:600;"><?= $s['absent_count'] ?></td>
          <td class="font-mono" style="color:var(--amber);"><?= $s['half_day_count'] ?></td>
          <td class="font-mono"><?= $s['leave_count'] ?></td>
          <td class="font-mono" style="color:<?= $s['late_count'] > 0 ? 'var(--amber)' : 'inherit' ?>;"><?= $s['late_count'] ?></td>
          <td><a href="attendance-certificate.php?user_id=<?= $s['id'] ?>&month=<?= h($month) ?>" target="_blank" style="font-size:11px;color:var(--rail);font-weight:600;">📄 प्रमाणपत्र</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
