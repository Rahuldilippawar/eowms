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
$isAssignedTeacherStmt = $pdo->prepare("SELECT COUNT(*) c FROM classroom_staff WHERE classroom_id = ? AND user_id = ?");
$isAssignedTeacherStmt->execute([$classroomId, $user['id']]);
$isAssignedTeacher = (int)$isAssignedTeacherStmt->fetch()['c'] > 0;
$canManage = $isMgmt || $isHodOfDept || $isAssignedTeacher;
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'create_exam') {
        $name = trim($_POST['name'] ?? '');
        $subjectName = trim($_POST['subject_name'] ?? '');
        $maxMarks = (float)($_POST['max_marks'] ?? 0);
        $examDate = ($_POST['exam_date'] ?? '') ?: null;
        if ($name && $subjectName && $maxMarks > 0) {
            $pdo->prepare("INSERT INTO classroom_exams (classroom_id, name, subject_name, max_marks, exam_date, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$classroomId, $name, $subjectName, $maxMarks, $examDate, $user['id']]);
            $_SESSION['flash'] = 'Exam तयार झाला.';
        }
        header('Location: classroom-exams.php?id=' . $classroomId);
        exit;
    } elseif ($action === 'save_marks') {
        $examId = (int)($_POST['exam_id'] ?? 0);
        $marksArr = $_POST['marks'] ?? [];
        foreach ($marksArr as $studentId => $marks) {
            $marksVal = $marks !== '' ? (float)$marks : null;
            $pdo->prepare("
                INSERT INTO classroom_exam_marks (exam_id, student_id, marks_obtained, entered_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE marks_obtained = VALUES(marks_obtained), entered_by = VALUES(entered_by)
            ")->execute([$examId, (int)$studentId, $marksVal, $user['id']]);
        }
        $_SESSION['flash'] = 'गुण जतन झाले.';
        header('Location: classroom-exams.php?id=' . $classroomId . '&exam_id=' . $examId);
        exit;
    }
}

$pageTitle = 'Marks/Exam';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$examsStmt = $pdo->prepare("SELECT * FROM classroom_exams WHERE classroom_id = ? ORDER BY exam_date DESC, id DESC");
$examsStmt->execute([$classroomId]);
$exams = $examsStmt->fetchAll();

$selectedExamId = (int)($_GET['exam_id'] ?? ($exams[0]['id'] ?? 0));
$selectedExam = null;
$marksRoster = [];
if ($selectedExamId) {
    foreach ($exams as $e) { if ((int)$e['id'] === $selectedExamId) { $selectedExam = $e; break; } }
    if ($selectedExam) {
        $marksStmt = $pdo->prepare("
            SELECT s.id, s.roll_no, s.name, em.marks_obtained FROM classroom_students cs JOIN students s ON s.id = cs.student_id
            LEFT JOIN classroom_exam_marks em ON em.student_id = s.id AND em.exam_id = ?
            WHERE cs.classroom_id = ? ORDER BY s.roll_no
        ");
        $marksStmt->execute([$selectedExamId, $classroomId]);
        $marksRoster = $marksStmt->fetchAll();
    }
}
?>

<div class="page-eyebrow"><?= h($classroom['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📈 Marks/Exam</div>
  <a href="classroom-view.php?id=<?= $classroomId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Classroom</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">नवीन Exam तयार करा</div></div>
      <form method="POST" style="padding:14px 20px;">
        <input type="hidden" name="action" value="create_exam">
        <input type="text" name="name" class="form-control-custom mb-2" placeholder="उदा. Unit Test 1" required>
        <input type="text" name="subject_name" class="form-control-custom mb-2" placeholder="विषय" required>
        <input type="number" step="0.5" name="max_marks" class="form-control-custom mb-2" placeholder="कमाल गुण" required>
        <input type="date" name="exam_date" class="form-control-custom mb-2">
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:9px;">तयार करा</button>
      </form>
    </div>
    <div class="panel">
      <div class="panel-head"><div class="title">सगळे Exams</div></div>
      <?php foreach ($exams as $e): ?>
        <a href="classroom-exams.php?id=<?= $classroomId ?>&exam_id=<?= $e['id'] ?>" style="display:block;padding:10px 20px;border-bottom:1px solid var(--line);font-size:12.5px;text-decoration:none;color:<?= (int)$e['id'] === $selectedExamId ? 'var(--rail)' : 'inherit' ?>;font-weight:<?= (int)$e['id'] === $selectedExamId ? '700' : '400' ?>;">
          <?= h($e['name']) ?> — <?= h($e['subject_name']) ?>
          <div style="font-size:10.5px;color:var(--text-dim);">कमाल: <?= $e['max_marks'] ?><?= $e['exam_date'] ? ' · ' . date('d M', strtotime($e['exam_date'])) : '' ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (empty($exams)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून कुठलाही Exam नाही</p><?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if ($selectedExam): ?>
      <form method="POST">
        <input type="hidden" name="action" value="save_marks">
        <input type="hidden" name="exam_id" value="<?= $selectedExamId ?>">
        <div class="panel">
          <div class="panel-head"><div class="title"><?= h($selectedExam['name']) ?> — <?= h($selectedExam['subject_name']) ?></div><div class="sub">कमाल गुण: <?= $selectedExam['max_marks'] ?></div></div>
          <table class="rtable">
            <thead><tr><th>Roll No</th><th>नाव</th><th>गुण</th></tr></thead>
            <tbody>
              <?php foreach ($marksRoster as $m): ?>
                <tr>
                  <td class="font-mono"><?= h($m['roll_no']) ?></td>
                  <td style="font-weight:600;"><?= h($m['name']) ?></td>
                  <td><input type="number" step="0.5" name="marks[<?= $m['id'] ?>]" class="form-control-custom" style="width:90px;" value="<?= h($m['marks_obtained']) ?>" max="<?= $selectedExam['max_marks'] ?>"></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 28px;font-weight:600;">💾 गुण जतन करा</button>
      </form>
    <?php else: ?>
      <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">डावीकडून Exam निवडा, किंवा नवीन तयार करा</div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
