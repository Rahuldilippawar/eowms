<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/classroom-helpers.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin') || hasPermission('manage_academics');

$classroomId = (int)($_GET['id'] ?? 0);
$classStmt = $pdo->prepare("SELECT * FROM virtual_classrooms WHERE id = ? AND organization_id = ?");
$classStmt->execute([$classroomId, $orgId]);
$classroom = $classStmt->fetch();
if (!$classroom) { die('Classroom सापडलं नाही.'); }

$isHodOfDept = hasCategory('hod') && (int)($user['department_id'] ?? 0) === (int)$classroom['department_id'];
$canManage = $isMgmt || $isHodOfDept;
if (!$canManage) { http_response_code(403); die('परवानगी नाही — फक्त HOD/Management Time Table बदलू शकतात.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $day = (int)($_POST['day_of_week'] ?? 0);
    $period = (int)($_POST['period_no'] ?? 0);
    $startTime = ($_POST['start_time'] ?? '') ?: null;
    $endTime = ($_POST['end_time'] ?? '') ?: null;
    $subjectName = trim($_POST['subject_name'] ?? '');
    $teacherId = ($_POST['teacher_id'] ?? '') !== '' ? (int)$_POST['teacher_id'] : null;

    if ($subjectName) {
        $pdo->prepare("
            INSERT INTO classroom_timetable (classroom_id, day_of_week, period_no, start_time, end_time, subject_name, teacher_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE start_time = VALUES(start_time), end_time = VALUES(end_time), subject_name = VALUES(subject_name), teacher_id = VALUES(teacher_id)
        ")->execute([$classroomId, $day, $period, $startTime, $endTime, $subjectName, $teacherId]);
        $_SESSION['flash'] = 'Time Table Update झाली.';
    } else {
        $pdo->prepare("DELETE FROM classroom_timetable WHERE classroom_id = ? AND day_of_week = ? AND period_no = ?")->execute([$classroomId, $day, $period]);
        $_SESSION['flash'] = 'Slot रिकामा केला.';
    }
    header('Location: classroom-timetable.php?id=' . $classroomId);
    exit;
}

$pageTitle = 'Time Table';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$ttStmt = $pdo->prepare("SELECT ct.*, u.name AS teacher_name FROM classroom_timetable ct LEFT JOIN users u ON u.id = ct.teacher_id WHERE ct.classroom_id = ?");
$ttStmt->execute([$classroomId]);
$slots = [];
foreach ($ttStmt->fetchAll() as $s) { $slots[$s['day_of_week']][$s['period_no']] = $s; }

$teachersStmt = $pdo->prepare("SELECT cs.user_id, u.name FROM classroom_staff cs JOIN users u ON u.id = cs.user_id WHERE cs.classroom_id = ? GROUP BY cs.user_id, u.name");
$teachersStmt->execute([$classroomId]);
$classroomTeachersForTT = $teachersStmt->fetchAll();

$dayNames = ['रविवार', 'सोमवार', 'मंगळवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
$workDays = [1, 2, 3, 4, 5, 6]; // सोम-शनि
$periods = range(1, 7);
?>

<div class="page-eyebrow"><?= h($classroom['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🗓️ Time Table</div>
  <a href="classroom-view.php?id=<?= $classroomId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Classroom</a>
</div>
<p style="font-size:12px;color:var(--text-dim);margin-bottom:16px;">प्रत्येक Slot वर क्लिक करून विषय/शिक्षक भरा — रिकामं ठेवलं तर Slot मोकळा राहील.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div style="overflow-x:auto;">
  <table style="border-collapse:collapse;width:100%;font-size:11.5px;">
    <thead>
      <tr>
        <th style="padding:8px;border:1px solid var(--line);background:#F3F4F7;">Period</th>
        <?php foreach ($workDays as $d): ?><th style="padding:8px;border:1px solid var(--line);background:#F3F4F7;min-width:120px;"><?= $dayNames[$d] ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($periods as $p): ?>
        <tr>
          <td style="padding:8px;border:1px solid var(--line);text-align:center;font-weight:700;">P<?= $p ?></td>
          <?php foreach ($workDays as $d):
            $slot = $slots[$d][$p] ?? null;
          ?>
            <td style="padding:0;border:1px solid var(--line);cursor:pointer;" data-bs-toggle="modal" data-bs-target="#slotModal<?= $d ?>_<?= $p ?>">
              <div style="padding:8px;<?= $slot ? 'background:var(--rail-soft);' : '' ?>">
                <?php if ($slot): ?>
                  <div style="font-weight:600;"><?= h($slot['subject_name']) ?></div>
                  <div style="font-size:10px;color:var(--text-dim);"><?= h($slot['teacher_name'] ?: '') ?></div>
                <?php else: ?>
                  <span style="color:var(--text-dim);">+ रिकामा</span>
                <?php endif; ?>
              </div>
            </td>
          <?php endforeach; ?>
        </tr>
        <?php foreach ($workDays as $d):
          $slot = $slots[$d][$p] ?? null;
        ?>
            <div class="modal fade" id="slotModal<?= $d ?>_<?= $p ?>" tabindex="-1">
              <div class="modal-dialog modal-sm"><div class="modal-content" style="border-radius:14px;">
                <form method="POST">
                  <input type="hidden" name="day_of_week" value="<?= $d ?>">
                  <input type="hidden" name="period_no" value="<?= $p ?>">
                  <div class="modal-body" style="padding:18px;">
                    <div style="font-weight:600;margin-bottom:10px;"><?= $dayNames[$d] ?> — Period <?= $p ?></div>
                    <label class="form-label-custom">विषय</label>
                    <input type="text" name="subject_name" class="form-control-custom mb-2" value="<?= h($slot['subject_name'] ?? '') ?>">
                    <label class="form-label-custom">शिक्षक</label>
                    <select name="teacher_id" class="form-select-custom mb-2">
                      <option value="">-- निवडा --</option>
                      <?php foreach ($classroomTeachersForTT as $t): ?><option value="<?= $t['user_id'] ?>" <?= isset($slot['teacher_id']) && (int)$slot['teacher_id'] === (int)$t['user_id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
                    </select>
                    <div class="row g-2">
                      <div class="col-6"><label class="form-label-custom">सुरू</label><input type="time" name="start_time" class="form-control-custom" value="<?= h($slot['start_time'] ?? '') ?>"></div>
                      <div class="col-6"><label class="form-label-custom">संपली</label><input type="time" name="end_time" class="form-control-custom" value="<?= h($slot['end_time'] ?? '') ?>"></div>
                    </div>
                  </div>
                  <div class="modal-footer" style="border-top:1px solid var(--line);padding:10px;">
                    <button type="submit" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
                  </div>
                </form>
              </div></div>
            </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
