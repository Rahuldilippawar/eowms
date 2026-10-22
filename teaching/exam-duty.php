<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();
$isTeacher = ($user['teaching_mode'] ?? '') === 'Teaching';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    if ($action === 'assign') {
        $examName = trim($_POST['exam_name'] ?? '');
        $dutyDate = $_POST['duty_date'] ?? '';
        $shift = $_POST['shift'] ?? 'morning';
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $roomHall = trim($_POST['room_hall'] ?? '');
        $campusId = (int)($_POST['campus_id'] ?? 0);
        if ($examName && $dutyDate && $teacherId && $campusId) {
            $pdo->prepare("
                INSERT INTO exam_duty_allocations (organization_id, campus_id, exam_name, duty_date, shift, teacher_id, room_hall, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$orgId, $campusId, $examName, $dutyDate, $shift, $teacherId, $roomHall, $user['id']]);
            $newId = $pdo->lastInsertId();
            notifyUser($teacherId, $orgId, '📝 Exam Duty नेमली', "$examName — " . date('d M Y', strtotime($dutyDate)), 'teaching/exam-duty.php');
            logActivity('Exam Duty नेमली', 'exam_duty_allocations', $newId, $examName);
            $_SESSION['flash'] = 'Exam Duty नेमली गेली.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM exam_duty_allocations WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
    }
    header('Location: exam-duty.php');
    exit;
}

$pageTitle = 'Exam Duty';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$filterUpcoming = $_GET['view'] ?? 'upcoming';

if ($canManage) {
    $sql = "
        SELECT eda.*, u.name AS teacher_name, c.name AS campus_name
        FROM exam_duty_allocations eda JOIN users u ON u.id = eda.teacher_id JOIN campuses c ON c.id = eda.campus_id
        WHERE eda.organization_id = ?
    ";
    $params = [$orgId];
} else {
    $sql = "
        SELECT eda.*, u.name AS teacher_name, c.name AS campus_name
        FROM exam_duty_allocations eda JOIN users u ON u.id = eda.teacher_id JOIN campuses c ON c.id = eda.campus_id
        WHERE eda.organization_id = ? AND eda.teacher_id = ?
    ";
    $params = [$orgId, $user['id']];
}
if ($filterUpcoming === 'upcoming') { $sql .= " AND eda.duty_date >= CURDATE()"; }
$sql .= " ORDER BY eda.duty_date, eda.shift";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$duties = $stmt->fetchAll();

if ($canManage) {
    $campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $campusesStmt->execute([$orgId]);
    $campuses = $campusesStmt->fetchAll();
    $teachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
    $teachersStmt->execute([$orgId]);
    $allTeachers = $teachersStmt->fetchAll();

    // Fair Distribution साठी — कोणाला किती Duty सध्या आहेत
    $loadStmt = $pdo->prepare("
        SELECT u.id, u.name, COUNT(eda.id) AS duty_count
        FROM users u LEFT JOIN exam_duty_allocations eda ON eda.teacher_id = u.id AND eda.duty_date >= CURDATE()
        WHERE u.organization_id = ? AND u.teaching_mode = 'Teaching' AND u.is_active = 1
        GROUP BY u.id, u.name ORDER BY duty_count, u.name
    ");
    $loadStmt->execute([$orgId]);
    $teacherLoad = $loadStmt->fetchAll();
}

$shiftLabels = ['morning' => '🌅 सकाळ', 'afternoon' => '🌆 दुपार', 'full_day' => '📅 संपूर्ण दिवस'];
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📝 Exam Duty <?= !$canManage ? '— माझी' : '' ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
  <a href="?view=upcoming" class="btn" style="background:<?= $filterUpcoming === 'upcoming' ? 'var(--rail)' : '#fff' ?>;color:<?= $filterUpcoming === 'upcoming' ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">आगामी</a>
  <a href="?view=all" class="btn" style="background:<?= $filterUpcoming === 'all' ? 'var(--rail)' : '#fff' ?>;color:<?= $filterUpcoming === 'all' ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">सगळे</a>
  <?php if ($canManage): ?>
    <button type="button" class="btn ms-auto" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#assignDutyModal">+ नवीन Duty नेमा</button>
  <?php endif; ?>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Exam</th><th>दिनांक</th><th>Shift</th><?php if ($canManage): ?><th>शिक्षक</th><?php endif; ?><th>College</th><th>Hall/Room</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($duties)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलीही Duty नाही</td></tr><?php endif; ?>
      <?php foreach ($duties as $d): ?>
        <tr>
          <td style="font-weight:600;"><?= h($d['exam_name']) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($d['duty_date'])) ?></td>
          <td><?= $shiftLabels[$d['shift']] ?></td>
          <?php if ($canManage): ?><td><?= h($d['teacher_name']) ?></td><?php endif; ?>
          <td><?= h($d['campus_name']) ?></td>
          <td><?= h($d['room_hall'] ?: '—') ?></td>
          <?php if ($canManage): ?>
            <td><form method="POST"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;" onclick="return confirm('काढायचं?')">✕</button>
            </form></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="assignDutyModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="assign">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Exam Duty नेमा</div>
        <label class="form-label-custom">परीक्षेचं नाव</label>
        <input type="text" name="exam_name" class="form-control-custom mb-2" required>
        <div class="row g-2 mb-2">
          <div class="col-6"><label class="form-label-custom">दिनांक</label><input type="date" name="duty_date" class="form-control-custom" required></div>
          <div class="col-6">
            <label class="form-label-custom">Shift</label>
            <select name="shift" class="form-select-custom">
              <?php foreach ($shiftLabels as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <label class="form-label-custom">College/School</label>
        <select name="campus_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">Hall/Room</label>
        <input type="text" name="room_hall" class="form-control-custom mb-2">
        <label class="form-label-custom">शिक्षक <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(कमी Duty असलेले आधी दाखवलेत — Fair Rotation)</span></label>
        <select name="teacher_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($teacherLoad as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?> (सध्या <?= $t['duty_count'] ?> Duty)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">नेमा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
