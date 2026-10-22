<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();
$isTeacher = ($user['teaching_mode'] ?? '') === 'Teaching';

$viewTeacherId = $canManage && !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : $user['id'];
if ($viewTeacherId !== (int)$user['id'] && !$canManage) { http_response_code(403); die('परवानगी नाही.'); }

$pageTitle = 'Teacher Feedback';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$fy = date('Y') . '-' . substr(date('Y') + 1, 2);

$viewTeacherStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND organization_id = ?");
$viewTeacherStmt->execute([$viewTeacherId, $orgId]);
$viewTeacher = $viewTeacherStmt->fetch();
if (!$viewTeacher) { die('सापडलं नाही.'); }

$feedbackStmt = $pdo->prepare("SELECT * FROM teacher_feedback WHERE teacher_id = ? AND academic_year = ? ORDER BY submitted_at DESC");
$feedbackStmt->execute([$viewTeacherId, $fy]);
$feedbackList = $feedbackStmt->fetchAll();

$avgRating = count($feedbackList) > 0 ? round(array_sum(array_column($feedbackList, 'rating')) / count($feedbackList), 1) : 0;

if ($canManage) {
    $teachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
    $teachersStmt->execute([$orgId]);
    $allTeachers = $teachersStmt->fetchAll();
}
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💬 <?= h($viewTeacher['name']) ?> — Feedback</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if ($canManage): ?>
<form method="GET" class="mb-4">
  <select name="teacher_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php foreach ($allTeachers as $t): ?><option value="<?= $t['id'] ?>" <?= $viewTeacherId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Feedback</div><div class="val"><?= count($feedbackList) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">सरासरी Rating</div><div class="val" style="color:var(--amber);">⭐ <?= $avgRating ?>/5</div></div></div>
</div>

<div class="panel">
  <?php if (empty($feedbackList)): ?>
    <div class="empty-state"><div class="icon"><i class="fa-solid fa-star"></i></div><div class="title">अजून कुठलंही Feedback आलेलं नाही</div></div>
  <?php endif; ?>
  <?php foreach ($feedbackList as $fb): ?>
    <div style="padding:14px 20px;border-bottom:1px solid var(--line);">
      <div style="color:var(--amber);font-size:14px;"><?= str_repeat('★', $fb['rating']) . str_repeat('☆', 5 - $fb['rating']) ?></div>
      <?php if ($fb['comment']): ?><div style="font-size:12.5px;margin-top:4px;"><?= h($fb['comment']) ?></div><?php endif; ?>
      <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;"><?= date('d M Y', strtotime($fb['submitted_at'])) ?> — गुप्त (Anonymous)</div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
