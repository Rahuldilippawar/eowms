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
$canReply = $isMgmt || $isHodOfDept || $isAssignedTeacher;
if (!$canReply) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $questionId = (int)($_POST['question_id'] ?? 0);
    $replyText = trim($_POST['reply_text'] ?? '');
    // प्रश्न याच Classroom चा आहे ना — तपासतो
    $qCheck = $pdo->prepare("SELECT id FROM classroom_questions WHERE id = ? AND classroom_id = ?");
    $qCheck->execute([$questionId, $classroomId]);
    if ($replyText && $qCheck->fetch()) {
        $pdo->prepare("INSERT INTO classroom_question_replies (question_id, replied_by, reply_text) VALUES (?, ?, ?)")->execute([$questionId, $user['id'], $replyText]);
        $pdo->prepare("UPDATE classroom_questions SET status = 'answered' WHERE id = ?")->execute([$questionId]);
        $qStmt = $pdo->prepare("SELECT student_id, question_text FROM classroom_questions WHERE id = ?");
        $qStmt->execute([$questionId]);
        $q = $qStmt->fetch();
        if ($q) { notifyStudent($pdo, $q['student_id'], '💬 तुमच्या प्रश्नाला उत्तर मिळालं', $q['question_text'], 'classroom.php?id=' . $classroomId); }
        $_SESSION['flash'] = 'उत्तर पाठवलं गेलं.';
    }
    header('Location: classroom-questions.php?id=' . $classroomId);
    exit;
}

$pageTitle = 'शंका/Q&A';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$questionsStmt = $pdo->prepare("
    SELECT cq.*, s.name AS student_name, s.roll_no,
      (SELECT reply_text FROM classroom_question_replies WHERE question_id = cq.id ORDER BY id DESC LIMIT 1) AS latest_reply
    FROM classroom_questions cq JOIN students s ON s.id = cq.student_id
    WHERE cq.classroom_id = ? ORDER BY (cq.status = 'open') DESC, cq.created_at DESC
");
$questionsStmt->execute([$classroomId]);
$questions = $questionsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h($classroom['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💬 शंका/Q&A</div>
  <a href="classroom-view.php?id=<?= $classroomId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Classroom</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <?php if (empty($questions)): ?><div style="padding:30px;text-align:center;color:var(--text-dim);">अजून कुठलाही प्रश्न आलेला नाही</div><?php endif; ?>
  <?php foreach ($questions as $q): ?>
    <div style="padding:16px 20px;border-bottom:1px solid var(--line);">
      <div class="d-flex justify-content-between">
        <div style="font-weight:600;font-size:13px;"><?= h($q['student_name']) ?> (<?= h($q['roll_no']) ?>)<?= $q['subject_name'] ? ' · ' . h($q['subject_name']) : '' ?></div>
        <span class="status-chip <?= $q['status'] === 'answered' ? 'done' : 'pending' ?>"><?= $q['status'] === 'answered' ? '✅ उत्तर दिलं' : '⏳ प्रलंबित' ?></span>
      </div>
      <div style="font-size:13px;margin:6px 0;"><?= nl2br(h($q['question_text'])) ?></div>
      <?php if ($q['latest_reply']): ?>
        <div style="background:var(--rail-soft);border-radius:9px;padding:10px 14px;font-size:12.5px;margin-top:8px;">💬 <?= h($q['latest_reply']) ?></div>
      <?php else: ?>
        <form method="POST" class="d-flex gap-2 mt-2">
          <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
          <input type="text" name="reply_text" class="form-control-custom" placeholder="उत्तर लिहा..." required>
          <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11.5px;padding:6px 16px;white-space:nowrap;">पाठवा</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
