<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin') || hasPermission('manage_academics');

$assignmentId = (int)($_GET['id'] ?? 0);
$assignStmt = $pdo->prepare("SELECT ca.*, vc.name AS classroom_name, vc.department_id FROM classroom_assignments ca JOIN virtual_classrooms vc ON vc.id = ca.classroom_id WHERE ca.id = ? AND vc.organization_id = ?");
$assignStmt->execute([$assignmentId, $orgId]);
$assignment = $assignStmt->fetch();
if (!$assignment) { die('Assignment सापडलं नाही.'); }

$isHodOfDept = hasCategory('hod') && (int)($user['department_id'] ?? 0) === (int)$assignment['department_id'];
$isAssignedTeacherStmt = $pdo->prepare("SELECT COUNT(*) c FROM classroom_staff WHERE classroom_id = ? AND user_id = ?");
$isAssignedTeacherStmt->execute([$assignment['classroom_id'], $user['id']]);
$isAssignedTeacher = (int)$isAssignedTeacherStmt->fetch()['c'] > 0;
$canGrade = $isMgmt || $isHodOfDept || $isAssignedTeacher;
if (!$canGrade) { http_response_code(403); die('तुम्हाला हे Grade करण्याची परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submissionId = (int)($_POST['submission_id'] ?? 0);
    $marks = ($_POST['marks_obtained'] ?? '') !== '' ? (float)$_POST['marks_obtained'] : null;
    $feedback = trim($_POST['feedback'] ?? '');
    $pdo->prepare("UPDATE classroom_assignment_submissions SET marks_obtained = ?, feedback = ?, graded_by = ?, graded_at = NOW() WHERE id = ? AND assignment_id = ?")
        ->execute([$marks, $feedback, $user['id'], $submissionId, $assignmentId]);
    $_SESSION['flash'] = 'Grade जतन झालं.';
    header('Location: assignment-submissions.php?id=' . $assignmentId);
    exit;
}

$pageTitle = 'Assignment Submissions';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$rosterStmt = $pdo->prepare("
    SELECT s.id, s.roll_no, s.name, sub.id AS submission_id, sub.file_path, sub.original_filename, sub.submission_note, sub.submitted_at, sub.marks_obtained, sub.feedback
    FROM classroom_students cs JOIN students s ON s.id = cs.student_id
    LEFT JOIN classroom_assignment_submissions sub ON sub.student_id = s.id AND sub.assignment_id = ?
    WHERE cs.classroom_id = ? ORDER BY s.roll_no
");
$rosterStmt->execute([$assignmentId, $assignment['classroom_id']]);
$rosterWithSubmissions = $rosterStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h($assignment['classroom_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📝 <?= h($assignment['title']) ?></div>
  <a href="classroom-view.php?id=<?= $assignment['classroom_id'] ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Classroom</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">मुदत: <?= date('d M Y', strtotime($assignment['due_date'])) ?><?= $assignment['max_marks'] ? ' · कमाल गुण: ' . $assignment['max_marks'] : '' ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Roll No</th><th>नाव</th><th>Submission</th><th>Grade करा</th></tr></thead>
    <tbody>
      <?php foreach ($rosterWithSubmissions as $r): ?>
        <tr>
          <td class="font-mono"><?= h($r['roll_no']) ?></td>
          <td style="font-weight:600;"><?= h($r['name']) ?></td>
          <td>
            <?php if ($r['submission_id']): ?>
              <?php if ($r['file_path']): ?><a href="submission-download.php?id=<?= $r['submission_id'] ?>" style="color:var(--rail);font-weight:600;font-size:12px;">📎 <?= h($r['original_filename']) ?></a><br><?php endif; ?>
              <span style="font-size:10.5px;color:var(--text-dim);"><?= date('d M, h:i A', strtotime($r['submitted_at'])) ?></span>
            <?php else: ?>
              <span class="status-chip overdue">Submit नाही केलं</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($r['submission_id']): ?>
              <form method="POST" class="d-flex gap-1 align-items-center">
                <input type="hidden" name="submission_id" value="<?= $r['submission_id'] ?>">
                <input type="number" step="0.5" name="marks_obtained" class="form-control-custom" style="width:60px;font-size:12px;" value="<?= h($r['marks_obtained']) ?>" placeholder="गुण">
                <input type="text" name="feedback" class="form-control-custom" style="width:110px;font-size:12px;" value="<?= h($r['feedback']) ?>" placeholder="Feedback">
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:6px;font-size:10.5px;padding:5px 10px;white-space:nowrap;">जतन</button>
              </form>
            <?php else: ?>
              —
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
