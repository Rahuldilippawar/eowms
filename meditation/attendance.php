<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

// Super Admin, or any staff/HOD belonging to the "ध्यान विभाग" (Meditation
// department, code = MED), can mark satsang attendance.
$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

if (!hasRole('super_admin') && $userDeptCode !== 'MED') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला सत्संग हजेरी नोंदवण्याची परवानगी नाही — फक्त Super Admin किंवा "ध्यान विभाग" चा स्टाफ/HOD करू शकतो. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$eventId = (int)($_GET['id'] ?? $_POST['event_id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM satsang_events WHERE id = ? AND organization_id = ?");
$stmt->execute([$eventId, $orgId]);
$event = $stmt->fetch();
if (!$event) { die('सत्संग कार्यक्रम सापडला नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $statuses = $_POST['status'] ?? [];   // [user_id => 'present'/'absent']
    $remarks = $_POST['remark'] ?? [];    // [user_id => remark text]

    $upsert = $pdo->prepare("
        INSERT INTO satsang_attendance (satsang_event_id, user_id, status, remark, marked_by)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), remark = VALUES(remark), marked_by = VALUES(marked_by)
    ");
    foreach ($statuses as $uid => $status) {
        $uid = (int)$uid;
        $status = $status === 'present' ? 'present' : 'absent';
        $remark = trim($remarks[$uid] ?? '') ?: null;
        $upsert->execute([$eventId, $uid, $status, $status === 'absent' ? $remark : null, $user['id']]);
    }

    logActivity('सत्संग हजेरी नोंदवली', 'satsang_event', $eventId, count($statuses) . ' कर्मचाऱ्यांची हजेरी अपडेट केली');
    $_SESSION['flash'] = 'हजेरी यशस्वीरित्या जतन झाली.';
    header('Location: attendance.php?id=' . $eventId);
    exit;
}

$pageTitle = 'सत्संग हजेरी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// All organization employees, grouped by department
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation, d.id AS dept_id, d.name_local AS dept_name
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1
    ORDER BY d.name_local, u.name
");
$stmt->execute([$orgId]);
$allStaff = $stmt->fetchAll();

$grouped = [];
foreach ($allStaff as $s) {
    $key = $s['dept_name'] ?? 'विभाग नसलेले';
    $grouped[$key][] = $s;
}

// Existing attendance for this event
$stmt = $pdo->prepare("SELECT user_id, status, remark FROM satsang_attendance WHERE satsang_event_id = ?");
$stmt->execute([$eventId]);
$existing = [];
foreach ($stmt->fetchAll() as $row) { $existing[$row['user_id']] = $row; }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">सत्संग हजेरी — <?= date('d M Y — l', strtotime($event['event_date'])) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">संपूर्ण संस्थेचे कर्मचारी — विभाग-निहाय</div>
  </div>
  <div class="d-flex gap-2">
    <a href="attendance-report.php?id=<?= $eventId ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF अहवाल</a>
    <a href="attendance-export-csv.php?id=<?= $eventId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
    <a href="event.php?id=<?= $eventId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Duty Chart</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="POST">
  <input type="hidden" name="event_id" value="<?= $eventId ?>">

  <?php foreach ($grouped as $deptName => $staffList): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title"><?= h($deptName) ?></div><div class="sub"><?= count($staffList) ?> कर्मचारी</div></div>
      <table class="rtable">
        <thead><tr><th>नाव</th><th>पदनाम</th><th style="width:200px;">हजेरी</th><th>टिप्पणी (अनुपस्थित असल्यास)</th></tr></thead>
        <tbody>
          <?php foreach ($staffList as $s):
            $curStatus = $existing[$s['id']]['status'] ?? 'present';
            $curRemark = $existing[$s['id']]['remark'] ?? '';
          ?>
            <tr>
              <td style="font-weight:500;"><?= h($s['name']) ?></td>
              <td><?= h($s['designation'] ?? '—') ?></td>
              <td>
                <div class="d-flex gap-2">
                  <label style="display:flex;align-items:center;gap:4px;font-size:12.5px;">
                    <input type="radio" name="status[<?= $s['id'] ?>]" value="present" <?= $curStatus === 'present' ? 'checked' : '' ?> onchange="toggleRemark(<?= $s['id'] ?>, false)"> उपस्थित
                  </label>
                  <label style="display:flex;align-items:center;gap:4px;font-size:12.5px;">
                    <input type="radio" name="status[<?= $s['id'] ?>]" value="absent" <?= $curStatus === 'absent' ? 'checked' : '' ?> onchange="toggleRemark(<?= $s['id'] ?>, true)"> अनुपस्थित
                  </label>
                </div>
              </td>
              <td>
                <input type="text" name="remark[<?= $s['id'] ?>]" id="remark_<?= $s['id'] ?>" class="form-control-custom" style="<?= $curStatus === 'present' ? 'display:none;' : '' ?>" value="<?= h($curRemark) ?>" placeholder="कारण (ऐच्छिक)">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>

  <div class="d-flex justify-content-end gap-2 mb-4">
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 26px;font-weight:600;">हजेरी जतन करा</button>
  </div>
</form>

<script>
function toggleRemark(userId, show) {
  document.getElementById('remark_' + userId).style.display = show ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
