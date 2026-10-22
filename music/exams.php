<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// संगीत विभागाचाच Staff, किंवा 'manage_music' Permission असलेला, किंवा
// Management-श्रेणीतला — यांनाच Instruments/Students/इ. Manage करता येईल.
$musicDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$musicDeptCheck->execute([$user['department_id'] ?? 0]);
$isMusicDept = ($musicDeptCheck->fetch()['code'] ?? '') === 'MUS';
if (!$isMusicDept && !hasPermission('manage_music') && !isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला संगीत विभाग व्यवस्थापित करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $studentId = (int)($_POST['student_id'] ?? 0);
    $examBoard = trim($_POST['exam_board'] ?? '');
    $gradeLevel = trim($_POST['grade_level'] ?? '');
    $subject = trim($_POST['subject'] ?? '');
    $examDate = $_POST['exam_date'] ?? '';

    if ($studentId && $examBoard && $examDate) {
        $pdo->prepare("INSERT INTO music_exams (student_id, exam_board, grade_level, subject, exam_date, created_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$studentId, $examBoard, $gradeLevel, $subject, $examDate, $user['id']]);
        logActivity('संगीत परीक्षा नोंदवली', 'music_exam', $pdo->lastInsertId());
        $_SESSION['flash'] = 'परीक्षा नोंदवली गेली.';
    } else {
        $_SESSION['form_error'] = 'कृपया विद्यार्थी, Exam Board, आणि दिनांक भरा.';
    }
    header('Location: exams.php');
    exit;
}

if (isset($_GET['update_result'])) {
    $examId = (int)$_GET['update_result'];
    $result = $_GET['result'] ?? 'pending';
    $marks = $_GET['marks'] ?? null;
    $pdo->prepare("UPDATE music_exams SET result = ?, marks = ? WHERE id = ?")->execute([$result, $marks, $examId]);
    header('Location: exams.php');
    exit;
}

$pageTitle = 'परीक्षा/Grade Tracking';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$studentsStmt = $pdo->prepare("SELECT id, name FROM music_students WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$studentsStmt->execute([$orgId]);
$students = $studentsStmt->fetchAll();

$examsStmt = $pdo->prepare("
    SELECT me.*, ms.name AS student_name
    FROM music_exams me JOIN music_students ms ON ms.id = me.student_id
    WHERE ms.organization_id = ? ORDER BY me.exam_date DESC
");
$examsStmt->execute([$orgId]);
$exams = $examsStmt->fetchAll();

$resultLabels = ['pending' => '⏳ प्रलंबित', 'pass' => '✅ Pass', 'distinction' => '🏆 Distinction', 'fail' => '❌ Fail'];
$resultChips = ['pending' => 'pending', 'pass' => 'done', 'distinction' => 'done', 'fail' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📜 परीक्षा/Grade Tracking</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संगीत विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन परीक्षा नोंदवा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">विद्यार्थी</label>
        <select name="student_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label-custom">Exam Board</label><input type="text" name="exam_board" class="form-control-custom" placeholder="उदा. गांधर्व महाविद्यालय" required></div>
      <div class="col-md-2"><label class="form-label-custom">Grade/Level</label><input type="text" name="grade_level" class="form-control-custom" placeholder="उदा. प्रथमा"></div>
      <div class="col-md-2"><label class="form-label-custom">विषय</label><input type="text" name="subject" class="form-control-custom" placeholder="उदा. तबला"></div>
      <div class="col-md-2"><label class="form-label-custom">परीक्षा दिनांक</label><input type="date" name="exam_date" class="form-control-custom" required></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">नोंदवा</button>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>विद्यार्थी</th><th>Exam Board</th><th>Grade</th><th>विषय</th><th>दिनांक</th><th>निकाल</th></tr></thead>
    <tbody>
      <?php if (empty($exams)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही परीक्षा नोंदवलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($exams as $e): ?>
        <tr>
          <td style="font-weight:600;"><?= h($e['student_name']) ?></td>
          <td><?= h($e['exam_board']) ?></td>
          <td><?= h($e['grade_level'] ?: '—') ?></td>
          <td><?= h($e['subject'] ?: '—') ?></td>
          <td><?= date('d M Y', strtotime($e['exam_date'])) ?></td>
          <td>
            <span class="status-chip <?= $resultChips[$e['result']] ?>"><?= $resultLabels[$e['result']] ?></span>
            <?php if ($e['marks']): ?><div style="font-size:10px;color:var(--text-dim);">Marks: <?= h($e['marks']) ?></div><?php endif; ?>
            <button type="button" class="btn btn-sm mt-1" style="border:1px solid var(--line);border-radius:6px;font-size:10px;padding:2px 8px;" data-bs-toggle="modal" data-bs-target="#resultModal<?= $e['id'] ?>">निकाल टाका</button>
          </td>
        </tr>
        <div class="modal fade" id="resultModal<?= $e['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="GET">
              <input type="hidden" name="update_result" value="<?= $e['id'] ?>">
              <div class="modal-body" style="padding:20px;">
                <label class="form-label-custom">निकाल</label>
                <select name="result" class="form-select-custom mb-3">
                  <?php foreach ($resultLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $e['result'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                </select>
                <label class="form-label-custom">Marks</label>
                <input type="text" name="marks" class="form-control-custom" value="<?= h($e['marks']) ?>">
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
