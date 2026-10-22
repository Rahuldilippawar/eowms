<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
$canManage = $isMgmt || hasPermission('manage_attendance');

if (!$canManage) {
    http_response_code(403);
    die('तुम्हाला Monthly Attendance Grid Edit करण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_data') {
    $month = $_POST['month'] ?? date('Y-m');
    $cells = $_POST['cell'] ?? []; // ['userId_day' => 'status']
    $updated = 0;

    foreach ($cells as $key => $status) {
        if ($status === '') continue; // रिकामी सोडलेली Cell — काही बदलायचं नाही
        [$targetUserId, $day] = explode('_', $key);
        $targetUserId = (int)$targetUserId;
        $day = str_pad($day, 2, '0', STR_PAD_LEFT);
        $date = $month . '-' . $day;

        // HOD ला फक्त स्वतःच्याच विभागातल्या Staff साठी परवानगी
        if (!$isMgmt) {
            $checkStmt = $pdo->prepare("SELECT department_id FROM users WHERE id = ?");
            $checkStmt->execute([$targetUserId]);
            $targetDept = $checkStmt->fetch()['department_id'] ?? null;
            if ((int)$targetDept !== (int)($user['department_id'] ?? 0)) continue;
        }

        $pdo->prepare("
            INSERT INTO staff_attendance (organization_id, user_id, attendance_date, status, source)
            VALUES (?, ?, ?, ?, 'manual')
            ON DUPLICATE KEY UPDATE status = VALUES(status), source = 'manual'
        ")->execute([$orgId, $targetUserId, $date, $status]);
        $updated++;
    }

    logActivity('Monthly Attendance Grid Update केलं', 'staff_attendance', null, "$month — $updated Cells बदलल्या");
    $_SESSION['flash'] = "✅ $updated नोंदी जतन झाल्या.";
    header('Location: attendance-monthly-grid.php?month=' . $month . '&loaded=1');
    exit;
}

$pageTitle = 'Monthly Attendance Grid';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$month = $_GET['month'] ?? date('Y-m');
$loaded = isset($_GET['loaded']) || isset($_GET['get_data']);

$daysInMonth = (int)date('t', strtotime($month . '-01'));

$staffList = [];
$attGrid = [];
if ($loaded) {
    $sql = "SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1";
    $params = [$orgId];
    if (!$isMgmt) { $sql .= " AND department_id = ?"; $params[] = $user['department_id'] ?? 0; }
    $sql .= " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $staffList = $stmt->fetchAll();

    $attStmt = $pdo->prepare("
        SELECT user_id, DAY(attendance_date) AS d, status FROM staff_attendance
        WHERE organization_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
    ");
    $attStmt->execute([$orgId, $month]);
    foreach ($attStmt->fetchAll() as $a) { $attGrid[$a['user_id']][(int)$a['d']] = $a['status']; }
}

$statusShort = ['present' => 'P', 'absent' => 'A', 'half_day' => 'H', 'holiday' => 'HO', 'on_leave' => 'L'];
$statusColors = ['present' => '#DCEFEC', 'absent' => '#F7E2E3', 'half_day' => '#FBEBD6', 'holiday' => '#E6E9F5', 'on_leave' => '#F0E6F5'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 Monthly Attendance Grid (Muster Roll)</div>
  <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">महिना निवडून "Get Data" दाबा — प्रत्येक Cell वर क्लिक करून बदला — शेवटी "Update Data" दाबून एकाच वेळी सगळं जतन करा.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="d-flex gap-2 align-items-center mb-4 flex-wrap">
  <input type="month" name="month" class="form-control-custom" style="width:auto;" value="<?= h($month) ?>">
  <button type="submit" name="get_data" value="1" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">🔍 Get Data</button>
</form>

<?php if ($loaded): ?>
<form method="POST">
  <input type="hidden" name="action" value="update_data">
  <input type="hidden" name="month" value="<?= h($month) ?>">

  <div style="overflow-x:auto;">
    <table style="border-collapse:collapse;font-size:11px;white-space:nowrap;">
      <thead>
        <tr>
          <th style="position:sticky;left:0;background:#F3F4F7;padding:8px 12px;border:1px solid var(--line);text-align:left;min-width:140px;z-index:2;">नाव</th>
          <?php for ($d = 1; $d <= $daysInMonth; $d++): ?>
            <th style="background:#F3F4F7;padding:6px 4px;border:1px solid var(--line);text-align:center;min-width:34px;"><?= $d ?></th>
          <?php endfor; ?>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($staffList)): ?>
          <tr><td colspan="<?= $daysInMonth + 1 ?>" style="text-align:center;color:var(--text-dim);padding:20px;border:1px solid var(--line);">Staff सापडले नाहीत</td></tr>
        <?php endif; ?>
        <?php foreach ($staffList as $s): ?>
          <tr>
            <td style="position:sticky;left:0;background:#fff;padding:6px 12px;border:1px solid var(--line);font-weight:600;z-index:1;"><?= h($s['name']) ?></td>
            <?php for ($d = 1; $d <= $daysInMonth; $d++):
              $currentStatus = $attGrid[$s['id']][$d] ?? '';
              $bg = $currentStatus ? $statusColors[$currentStatus] : '#fff';
            ?>
              <td style="border:1px solid var(--line);padding:0;text-align:center;background:<?= $bg ?>;">
                <select name="cell[<?= $s['id'] ?>_<?= $d ?>]" style="width:34px;border:none;background:none;font-size:10px;text-align:center;padding:5px 0;">
                  <option value="" <?= $currentStatus === '' ? 'selected' : '' ?>>—</option>
                  <?php foreach ($statusShort as $val => $short): ?>
                    <option value="<?= $val ?>" <?= $currentStatus === $val ? 'selected' : '' ?>><?= $short ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            <?php endfor; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
    <div style="font-size:11px;color:var(--text-dim);">P=हजर · A=गैरहजर · H=अर्धा दिवस · HO=सुट्टी · L=रजा · —=न बदलणारी (जशी आहे तशीच राहील)</div>
    <?php if (!empty($staffList)): ?>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:10px 28px;font-weight:600;">💾 Update Data</button>
    <?php endif; ?>
  </div>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
