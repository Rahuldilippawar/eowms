<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$hostelId = (int)($_GET['hostel_id'] ?? $_POST['hostel_id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

if (!hasRole('super_admin') && !isManagementOrAbove() && $userDeptCode !== 'HOS') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला विद्यार्थी हजेरी नोंदवण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$stmt = $pdo->prepare("SELECT * FROM hostels WHERE id = ? AND organization_id = ?");
$stmt->execute([$hostelId, $orgId]);
$hostel = $stmt->fetch();
if (!$hostel) { die('वसतिगृह सापडले नाही.'); }

$date = $_GET['date'] ?? date('Y-m-d');
$session = $_GET['session'] ?? 'morning';
if (!in_array($session, ['morning', 'afternoon', 'evening'], true)) { $session = 'morning'; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postDate = $_POST['attendance_date'] ?? '';
    $postSession = $_POST['session'] ?? '';
    $statuses = $_POST['status'] ?? [];
    $remarks = $_POST['remark'] ?? [];

    $upsert = $pdo->prepare("
        INSERT INTO hostel_student_attendance (hostel_id, student_id, attendance_date, session, status, remark, marked_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE status = VALUES(status), remark = VALUES(remark), marked_by = VALUES(marked_by)
    ");
    foreach ($statuses as $sid => $status) {
        $sid = (int)$sid;
        $status = $status === 'present' ? 'present' : 'absent';
        $remark = trim($remarks[$sid] ?? '') ?: null;
        $upsert->execute([$hostelId, $sid, $postDate, $postSession, $status, $status === 'absent' ? $remark : null, $user['id']]);
    }

    logActivity('वसतिगृह विद्यार्थी हजेरी नोंदवली', 'hostel', $hostelId, "$postDate — $postSession — " . count($statuses) . ' विद्यार्थी');
    $_SESSION['flash'] = 'हजेरी यशस्वीरित्या जतन झाली.';
    header("Location: attendance.php?hostel_id=$hostelId&date=$postDate&session=$postSession");
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM hostel_students WHERE hostel_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$hostelId]);
$students = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT student_id, status, remark FROM hostel_student_attendance WHERE hostel_id = ? AND attendance_date = ? AND session = ?");
$stmt->execute([$hostelId, $date, $session]);
$existing = [];
foreach ($stmt->fetchAll() as $row) { $existing[$row['student_id']] = $row; }

$sessionLabels = ['morning' => '🌅 सकाळ', 'afternoon' => '☀️ दुपार', 'evening' => '🌆 संध्याकाळ'];

$pageTitle = 'विद्यार्थी हजेरी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($hostel['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">विद्यार्थी हजेरी</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= date('d M Y — l', strtotime($date)) ?></div>
  </div>
  <a href="dashboard.php?id=<?= $hostelId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वसतिगृह</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center justify-content-between mb-3 gap-2">
  <div class="period-toggle">
    <?php foreach ($sessionLabels as $key => $label): ?>
      <a href="?hostel_id=<?= $hostelId ?>&date=<?= $date ?>&session=<?= $key ?>" class="period-btn <?= $session === $key ? 'active' : '' ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET">
    <input type="hidden" name="hostel_id" value="<?= $hostelId ?>">
    <input type="hidden" name="session" value="<?= $session ?>">
    <input type="date" name="date" value="<?= $date ?>" class="form-control-custom" style="width:auto;" onchange="this.form.submit()">
  </form>
</div>

<form method="POST">
  <input type="hidden" name="attendance_date" value="<?= $date ?>">
  <input type="hidden" name="session" value="<?= $session ?>">

  <div class="panel">
    <div class="panel-head"><div class="title"><?= $sessionLabels[$session] ?> सत्र — हजेरी</div><div class="sub"><?= count($students) ?> विद्यार्थी</div></div>
    <table class="rtable">
      <thead><tr><th>नाव</th><th>वर्ग</th><th style="width:200px;">हजेरी</th><th>टिप्पणी</th></tr></thead>
      <tbody>
        <?php if (empty($students)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:20px;">कोणतेही विद्यार्थी सापडले नाहीत</td></tr>
        <?php endif; ?>
        <?php foreach ($students as $s):
          $curStatus = $existing[$s['id']]['status'] ?? 'present';
          $curRemark = $existing[$s['id']]['remark'] ?? '';
        ?>
          <tr>
            <td style="font-weight:500;"><?= h($s['name']) ?></td>
            <td><?= h($s['class_grade'] ?? '—') ?></td>
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
            <td><input type="text" name="remark[<?= $s['id'] ?>]" id="remark_<?= $s['id'] ?>" class="form-control-custom" style="<?= $curStatus === 'present' ? 'display:none;' : '' ?>" value="<?= h($curRemark) ?>" placeholder="कारण (ऐच्छिक)"></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if (!empty($students)): ?>
    <div class="d-flex justify-content-end gap-2 mt-3 mb-4">
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 26px;font-weight:600;">हजेरी जतन करा</button>
    </div>
  <?php endif; ?>
</form>

<script>
function toggleRemark(sid, show) {
  document.getElementById('remark_' + sid).style.display = show ? 'block' : 'none';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
