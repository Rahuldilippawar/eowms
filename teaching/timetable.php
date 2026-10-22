<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();

$viewTeacherId = $canManage && !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : $user['id'];
if ($viewTeacherId !== (int)$user['id'] && !$canManage) { http_response_code(403); die('परवानगी नाही.'); }

$fy = date('Y') . '-' . substr(date('Y') + 1, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $canEditThis = $canManage || (int)($_POST['teacher_id'] ?? 0) === (int)$user['id'];
    if ($canEditThis) {
        if ($action === 'add') {
            $teacherId = (int)($_POST['teacher_id'] ?? 0);
            $dayOfWeek = (int)($_POST['day_of_week'] ?? 1);
            $periodNo = (int)($_POST['period_no'] ?? 1);
            $subject = trim($_POST['subject_name'] ?? '');
            $classSection = trim($_POST['class_section'] ?? '');
            $startTime = ($_POST['start_time'] ?? '') ?: null;
            $endTime = ($_POST['end_time'] ?? '') ?: null;
            if ($teacherId && $subject && $classSection) {
                $teacherCampusStmt = $pdo->prepare("SELECT campus_id FROM users WHERE id = ?");
                $teacherCampusStmt->execute([$teacherId]);
                $teacherCampusId = $teacherCampusStmt->fetch()['campus_id'] ?? null;
                $pdo->prepare("
                    INSERT INTO teacher_timetable (organization_id, campus_id, teacher_id, day_of_week, period_no, start_time, end_time, subject_name, class_section, academic_year)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$orgId, $teacherCampusId, $teacherId, $dayOfWeek, $periodNo, $startTime, $endTime, $subject, $classSection, $fy]);
            }
        } elseif ($action === 'delete') {
            $ttId = (int)($_POST['tt_id'] ?? 0);
            $pdo->prepare("DELETE FROM teacher_timetable WHERE id = ? AND organization_id = ?")->execute([$ttId, $orgId]);
        }
    }
    header('Location: timetable.php' . ($canManage ? '?teacher_id=' . $viewTeacherId : ''));
    exit;
}

$pageTitle = 'शिक्षक Timetable';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$viewTeacherStmt = $pdo->prepare("SELECT name, designation FROM users WHERE id = ? AND organization_id = ?");
$viewTeacherStmt->execute([$viewTeacherId, $orgId]);
$viewTeacher = $viewTeacherStmt->fetch();
if (!$viewTeacher) { die('शिक्षक सापडला नाही.'); }

$ttStmt = $pdo->prepare("SELECT * FROM teacher_timetable WHERE teacher_id = ? AND academic_year = ? ORDER BY day_of_week, period_no");
$ttStmt->execute([$viewTeacherId, $fy]);
$entries = $ttStmt->fetchAll();

$grid = [];
foreach ($entries as $e) { $grid[$e['day_of_week']][$e['period_no']] = $e; }
$days = [2 => 'सोमवार', 3 => 'मंगळवार', 4 => 'बुधवार', 5 => 'गुरुवार', 6 => 'शुक्रवार', 7 => 'शनिवार'];
$maxPeriod = max(array_merge([6], array_column($entries, 'period_no')));

if ($canManage) {
    $teachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
    $teachersStmt->execute([$orgId]);
    $allTeachers = $teachersStmt->fetchAll();
}
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📅 <?= h($viewTeacher['name']) ?> — Timetable</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if ($canManage): ?>
<form method="GET" class="mb-4">
  <select name="teacher_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php foreach ($allTeachers as $t): ?><option value="<?= $t['id'] ?>" <?= $viewTeacherId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php endif; ?>

<div class="panel" style="overflow-x:auto;">
  <table class="rtable" style="font-size:11.5px;">
    <thead><tr><th>Period</th><?php foreach ($days as $lbl): ?><th><?= $lbl ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php for ($p = 1; $p <= $maxPeriod; $p++): ?>
        <tr>
          <td style="font-weight:700;">P<?= $p ?></td>
          <?php foreach (array_keys($days) as $d): $cell = $grid[$d][$p] ?? null; ?>
            <td style="min-width:110px;">
              <?php if ($cell): ?>
                <div style="font-weight:600;"><?= h($cell['subject_name']) ?></div>
                <div style="color:var(--text-dim);"><?= h($cell['class_section']) ?></div>
                <?php if ($viewTeacherId === (int)$user['id'] || $canManage): ?>
                  <form method="POST" onsubmit="return confirm('काढायचं?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="tt_id" value="<?= $cell['id'] ?>">
                    <button type="submit" style="border:none;background:none;color:var(--rose);font-size:10px;padding:0;">✕</button>
                  </form>
                <?php endif; ?>
              <?php elseif ($viewTeacherId === (int)$user['id'] || $canManage): ?>
                <button type="button" style="border:1px dashed var(--line);background:none;border-radius:6px;font-size:10px;color:var(--text-dim);padding:8px;width:100%;" data-bs-toggle="modal" data-bs-target="#addModal" onclick="document.getElementById('addDay').value='<?= $d ?>';document.getElementById('addPeriod').value='<?= $p ?>';">+ जोडा</button>
              <?php endif; ?>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endfor; ?>
    </tbody>
  </table>
</div>

<?php if ($viewTeacherId === (int)$user['id'] || $canManage): ?>
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="teacher_id" value="<?= $viewTeacherId ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Period जोडा</div>
        <div class="row g-2 mb-2">
          <div class="col-6">
            <label class="form-label-custom">वार</label>
            <select name="day_of_week" id="addDay" class="form-select-custom">
              <?php foreach ($days as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-6"><label class="form-label-custom">Period क्र.</label><input type="number" name="period_no" id="addPeriod" class="form-control-custom" min="1" required></div>
        </div>
        <label class="form-label-custom">विषय</label>
        <input type="text" name="subject_name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">वर्ग/Section</label>
        <input type="text" name="class_section" class="form-control-custom mb-2" placeholder="उदा. 10th A" required>
        <div class="row g-2">
          <div class="col-6"><label class="form-label-custom">सुरू</label><input type="time" name="start_time" class="form-control-custom"></div>
          <div class="col-6"><label class="form-label-custom">संपेल</label><input type="time" name="end_time" class="form-control-custom"></div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
