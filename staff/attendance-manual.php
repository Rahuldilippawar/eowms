<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
$canManage = $isMgmt || hasPermission('manage_attendance');

if (!$canManage) {
    http_response_code(403);
    die('तुम्हाला Attendance Manually नोंदवण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $userId = (int)($_POST['user_id'] ?? 0);
    $date = $_POST['attendance_date'] ?? date('Y-m-d');
    $status = $_POST['status'] ?? 'present';
    $remarks = mb_substr(trim($_POST['remarks'] ?? ''), 0, 255);

    // Status व दिनांक — फक्त वैध Values स्वीकारतो
    $dateObj = DateTime::createFromFormat('Y-m-d', $date);
    if (!in_array($status, ['present', 'absent', 'half_day', 'holiday', 'on_leave'], true) || !$dateObj || $dateObj->format('Y-m-d') !== $date) {
        http_response_code(400);
        die('अवैध दिनांक किंवा Status.');
    }

    // कर्मचारी याच संस्थेतला असायलाच हवा
    $checkStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND organization_id = ?");
    $checkStmt->execute([$userId, $orgId]);
    $targetRow = $checkStmt->fetch();
    if (!$targetRow) {
        http_response_code(403);
        die('हा कर्मचारी सापडला नाही.');
    }

    // HOD ला फक्त स्वतःच्याच विभागातल्या Staff साठी परवानगी
    if (!$isMgmt) {
        $targetDept = $targetRow['department_id'] ?? null;
        if ((int)$targetDept !== (int)($user['department_id'] ?? 0)) {
            http_response_code(403);
            die('तुम्ही फक्त स्वतःच्या विभागातल्या Staff ची Attendance नोंदवू शकता.');
        }
    }

    $pdo->prepare("
        INSERT INTO staff_attendance (organization_id, user_id, attendance_date, status, source, remarks)
        VALUES (?, ?, ?, ?, 'manual', ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), source = 'manual', remarks = VALUES(remarks)
    ")->execute([$orgId, $userId, $date, $status, $remarks]);

    logActivity('Attendance Manually नोंदवली', 'staff_attendance', $userId, "$date — $status");
    $_SESSION['flash'] = 'Attendance जतन झाली.';
    header('Location: attendance-manual.php?date=' . $date);
    exit;
}

$pageTitle = 'Manual Attendance Entry';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$date = $_GET['date'] ?? date('Y-m-d');

$sql = "
    SELECT u.id, u.name, u.designation, d.name_local AS dept_name, sa.status, sa.remarks, sa.source
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN staff_attendance sa ON sa.user_id = u.id AND sa.attendance_date = ?
    WHERE u.organization_id = ? AND u.is_active = 1
";
$params = [$date, $orgId];
if (!$isMgmt) { $sql .= " AND u.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
$sql .= " ORDER BY u.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$staffList = $stmt->fetchAll();

$statusLabels = ['present' => '✅ हजर', 'absent' => '❌ गैरहजर', 'half_day' => '🟡 अर्धा दिवस', 'holiday' => '🎉 सुट्टी', 'on_leave' => '🏖️ रजेवर'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">✏️ Manual Attendance Entry</div>
  <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="mb-4">
  <input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($date) ?>" onchange="this.form.submit()">
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>विभाग</th><th>सध्याची स्थिती</th><th>बदला</th></tr></thead>
    <tbody>
      <?php foreach ($staffList as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?></td>
          <td><?= h($s['dept_name'] ?: '—') ?></td>
          <td>
            <?= $s['status'] ? $statusLabels[$s['status']] : '⚪ नोंद नाही' ?>
            <?php if ($s['source'] === 'manual'): ?><span style="font-size:10px;color:var(--text-dim);">(Manual)</span><?php endif; ?>
          </td>
          <td>
            <form method="POST" class="d-flex gap-2">
              <input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <input type="hidden" name="attendance_date" value="<?= h($date) ?>">
              <select name="status" class="form-select-custom" style="width:auto;font-size:12px;">
                <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $s['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
              </select>
              <input type="text" name="remarks" class="form-control-custom" style="width:140px;font-size:12px;" placeholder="टिप्पणी" value="<?= h($s['remarks']) ?>">
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:5px 14px;">जतन करा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
