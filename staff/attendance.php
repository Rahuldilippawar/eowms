<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
$canManage = $isMgmt || hasPermission('manage_attendance');

$pageTitle = 'Staff Attendance';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$month = $_GET['month'] ?? date('Y-m');

$sql = "
    SELECT sa.*, u.name, u.designation, d.name_local AS dept_name
    FROM staff_attendance sa JOIN users u ON u.id = sa.user_id LEFT JOIN departments d ON d.id = u.department_id
    WHERE sa.organization_id = ? AND DATE_FORMAT(sa.attendance_date, '%Y-%m') = ?
";
$params = [$orgId, $month];
if (!$isMgmt) { $sql .= " AND u.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
$sql .= " ORDER BY sa.attendance_date DESC, u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

$byDate = [];
foreach ($records as $r) { $byDate[$r['attendance_date']][] = $r; }

$statusLabels = ['present' => '✅ हजर', 'absent' => '❌ गैरहजर', 'half_day' => '🟡 अर्धा दिवस', 'holiday' => '🎉 सुट्टी', 'on_leave' => '🏖️ रजेवर'];
$statusChips = ['present' => 'done', 'absent' => 'overdue', 'half_day' => 'pending', 'holiday' => 'done', 'on_leave' => 'pending'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">👆 Staff Attendance</div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="attendance-calendar.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📆 Calendar View</a>
    <a href="attendance-summary.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📊 मासिक Summary</a>
    <a href="regularization.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📝 दुरुस्ती विनंत्या</a>
    <?php if ($canManage): ?>
      <a href="attendance-monthly-grid.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📋 Monthly Grid</a>
      <a href="attendance-manual.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">✏️ Manual Entry</a>
    <?php endif; ?>
    <?php if (hasRole('super_admin')): ?>
      <a href="shift-settings.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">⏰ Shift Settings</a>
      <a href="leave-types.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">🏖️ रजा-प्रकार</a>
      <a href="leave-balances.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📊 रजा-शिल्लक</a>
      <a href="holidays.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">🎉 सुट्ट्या</a>
      <a href="../biometric/devices.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">⚙️ Device Setup</a>
    <?php endif; ?>
  </div>
</div>

<form method="GET" class="mb-4">
  <input type="month" name="month" class="form-control-custom" style="width:auto;" value="<?= h($month) ?>" onchange="this.form.submit()">
</form>

<?php if (empty($byDate)): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">या महिन्यात कुठलीही Attendance नोंदवली गेलेली नाही.</div>
<?php endif; ?>

<?php foreach ($byDate as $date => $dayRecords):
  $presentCount = count(array_filter($dayRecords, fn($r) => in_array($r['status'], ['present', 'half_day'])));
?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title"><?= date('d M Y, l', strtotime($date)) ?></div><div class="sub"><?= $presentCount ?> जण उपस्थित</div></div>
    <table class="rtable">
      <thead><tr><th>नाव</th><th>विभाग</th><th>पहिला Punch</th><th>शेवटचा Punch</th><th>स्थिती</th></tr></thead>
      <tbody>
        <?php foreach ($dayRecords as $r): ?>
          <tr style="<?= $r['status'] === 'absent' ? 'background:var(--rose-soft);' : '' ?>">
            <td style="font-weight:600;"><?= h($r['name']) ?></td>
            <td><?= h($r['dept_name'] ?: '—') ?></td>
            <td class="font-mono" style="color:var(--rail);"><?= $r['first_punch_time'] ? date('h:i A', strtotime($r['first_punch_time'])) : '—' ?></td>
            <td class="font-mono" style="color:var(--amber);"><?= $r['last_punch_time'] ? date('h:i A', strtotime($r['last_punch_time'])) : '—' ?></td>
            <td>
              <span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span>
              <?php if (!empty($r['is_late'])): ?><span class="status-chip pending" style="font-size:9.5px;">⏰ उशीर</span><?php endif; ?>
              <?php if (!empty($r['is_early_leave'])): ?><span class="status-chip pending" style="font-size:9.5px;">🚪 लवकर गेला</span><?php endif; ?>
              <?php if ($r['source'] === 'manual'): ?><div style="font-size:9.5px;color:var(--text-dim);">Manual<?= $r['remarks'] ? ' — ' . h($r['remarks']) : '' ?></div><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
