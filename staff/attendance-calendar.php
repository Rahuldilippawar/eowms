<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
$canManage = $isMgmt || hasPermission('manage_attendance');

$pageTitle = 'Attendance Calendar';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$targetUserId = (int)($_GET['user_id'] ?? $user['id']);
$month = $_GET['month'] ?? date('Y-m');

// फक्त स्वतःचं, किंवा (HOD/Management असेल तर) दुसऱ्या कोणाचंही Calendar बघता येईल
if ($targetUserId !== (int)$user['id'] && !$canManage) {
    http_response_code(403);
    die('तुम्ही फक्त स्वतःचं Calendar बघू शकता.');
}

$staffOptions = [];
if ($canManage) {
    $sql = "SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1";
    $params = [$orgId];
    if (!$isMgmt) { $sql .= " AND department_id = ?"; $params[] = $user['department_id'] ?? 0; }
    $sql .= " ORDER BY name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $staffOptions = $stmt->fetchAll();
}

$targetStmt = $pdo->prepare("SELECT name, designation FROM users WHERE id = ? AND organization_id = ?");
$targetStmt->execute([$targetUserId, $orgId]);
$targetUser = $targetStmt->fetch();
if (!$targetUser) { die('व्यक्ती सापडली नाही.'); }

$attStmt = $pdo->prepare("SELECT * FROM staff_attendance WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?");
$attStmt->execute([$targetUserId, $month]);
$attByDate = [];
foreach ($attStmt->fetchAll() as $a) { $attByDate[$a['attendance_date']] = $a; }

$firstDay = strtotime($month . '-01');
$daysInMonth = (int)date('t', $firstDay);
$startWeekday = (int)date('w', $firstDay); // 0=रविवार

$statusColors = ['present' => '#DCEFEC', 'absent' => '#F7E2E3', 'half_day' => '#FBEBD6', 'holiday' => '#E6E9F5', 'on_leave' => '#F0E6F5'];
$statusTextColors = ['present' => '#1B8A83', 'absent' => '#B23A48', 'half_day' => '#C97F1D', 'holiday' => '#5D5FA6', 'on_leave' => '#8A5CD6'];
$statusIcons = ['present' => '✅', 'absent' => '❌', 'half_day' => '🟡', 'holiday' => '🎉', 'on_leave' => '🏖️'];
$dayNamesShort = ['र', 'सो', 'मं', 'बु', 'गु', 'शु', 'श'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📆 <?= h($targetUser['name']) ?> — Attendance Calendar</div>
  <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
</div>

<form method="GET" class="d-flex gap-2 mb-4 flex-wrap">
  <?php if ($canManage): ?>
    <select name="user_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <?php foreach ($staffOptions as $s): ?><option value="<?= $s['id'] ?>" <?= $targetUserId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
    </select>
  <?php endif; ?>
  <input type="month" name="month" class="form-control-custom" style="width:auto;" value="<?= h($month) ?>" onchange="this.form.submit()">
</form>

<div class="panel" style="padding:20px;">
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:10px;">
    <?php foreach ($dayNamesShort as $dn): ?><div style="text-align:center;font-size:11.5px;font-weight:700;color:var(--text-dim);"><?= $dn ?></div><?php endforeach; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:8px;">
    <?php for ($i = 0; $i < $startWeekday; $i++): ?><div></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysInMonth; $d++):
      $dateStr = $month . '-' . str_pad($d, 2, '0', STR_PAD_LEFT);
      $rec = $attByDate[$dateStr] ?? null;
      $bg = $rec ? $statusColors[$rec['status']] : '#F7F8FA';
      $fg = $rec ? $statusTextColors[$rec['status']] : '#8A90AC';
    ?>
      <div style="background:<?= $bg ?>;border-radius:9px;padding:8px 6px;text-align:center;min-height:64px;<?= $dateStr === date('Y-m-d') ? 'box-shadow:0 0 0 2px var(--rail);' : '' ?>">
        <div style="font-size:12px;font-weight:700;color:<?= $fg ?>;"><?= $d ?></div>
        <?php if ($rec): ?>
          <div style="font-size:14px;"><?= $statusIcons[$rec['status']] ?></div>
          <?php if (!empty($rec['is_late'])): ?><div style="font-size:8.5px;color:var(--amber);">उशीर</div><?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endfor; ?>
  </div>

  <div class="d-flex gap-3 flex-wrap mt-4" style="font-size:11.5px;">
    <?php foreach ($statusIcons as $key => $icon): ?>
      <span><?= $icon ?> <?= ['present'=>'हजर','absent'=>'गैरहजर','half_day'=>'अर्धा दिवस','holiday'=>'सुट्टी','on_leave'=>'रजा'][$key] ?></span>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
