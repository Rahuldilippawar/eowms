<?php
require_once __DIR__ . '/../includes/student-auth.php';
require_once __DIR__ . '/header.php';

$myClassroomsStmt = $pdo->prepare("
    SELECT vc.* FROM classroom_students cs JOIN virtual_classrooms vc ON vc.id = cs.classroom_id
    WHERE cs.student_id = ? AND vc.is_active = 1
");
$myClassroomsStmt->execute([$student['id']]);
$myClassrooms = $myClassroomsStmt->fetchAll();
$classroomIds = array_column($myClassrooms, 'id');

$pendingAssignments = [];
$recentMaterials = [];
if (!empty($classroomIds)) {
    $ph = implode(',', array_fill(0, count($classroomIds), '?'));
    $assignStmt = $pdo->prepare("
        SELECT ca.*, vc.name AS classroom_name,
          (SELECT COUNT(*) FROM classroom_assignment_submissions WHERE assignment_id = ca.id AND student_id = ?) AS submitted
        FROM classroom_assignments ca JOIN virtual_classrooms vc ON vc.id = ca.classroom_id
        WHERE ca.classroom_id IN ($ph) ORDER BY ca.due_date
    ");
    $assignStmt->execute(array_merge([$student['id']], $classroomIds));
    $allAssignments = $assignStmt->fetchAll();
    $pendingAssignments = array_filter($allAssignments, fn($a) => !$a['submitted']);

    $matStmt = $pdo->prepare("
        SELECT cm.*, vc.name AS classroom_name FROM classroom_materials cm JOIN virtual_classrooms vc ON vc.id = cm.classroom_id
        WHERE cm.classroom_id IN ($ph) ORDER BY cm.created_at DESC LIMIT 6
    ");
    $matStmt->execute($classroomIds);
    $recentMaterials = $matStmt->fetchAll();
}
?>

<div class="page-title">🏠 माझं Dashboard</div>
<p class="sub">नमस्कार, <?= h($student['name']) ?>!</p>
<a href="give-feedback.php" style="display:inline-block;background:#DCEFEC;color:#1B8A83;font-size:12px;font-weight:700;padding:7px 16px;border-radius:20px;text-decoration:none;margin-bottom:16px;">💬 शिक्षकांना Feedback द्या →</a>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert-success"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (empty($myClassrooms)): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">तुम्हाला अजून कुठल्याही Virtual Classroom मध्ये टाकलेलं नाही — तुमच्या Department शी संपर्क साधा.</div>
<?php else: ?>

<div class="section-label">माझे वर्ग</div>
<div class="card-grid">
  <?php foreach ($myClassrooms as $c): ?>
    <a href="classroom.php?id=<?= $c['id'] ?>" class="classroom-card">
      <div class="cc-name"><?= h($c['name']) ?></div>
      <div class="cc-year"><?= $c['academic_year'] ?></div>
    </a>
  <?php endforeach; ?>
</div>

<?php if (!empty($pendingAssignments)): ?>
  <div class="section-label" style="margin-top:24px;">⚠️ प्रलंबित Assignments</div>
  <div class="panel">
    <?php foreach ($pendingAssignments as $a): ?>
      <a href="assignment.php?id=<?= $a['id'] ?>" class="list-row">
        <div>
          <div class="lr-title"><?= h($a['title']) ?></div>
          <div class="lr-sub"><?= h($a['classroom_name']) ?><?= $a['subject_name'] ? ' · ' . h($a['subject_name']) : '' ?></div>
        </div>
        <div class="lr-due">मुदत: <?= date('d M', strtotime($a['due_date'])) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($recentMaterials)): ?>
  <div class="section-label" style="margin-top:24px;">📚 अलीकडचं Study Material</div>
  <div class="panel">
    <?php foreach ($recentMaterials as $m): ?>
      <a href="material-download.php?id=<?= $m['id'] ?>" class="list-row">
        <div>
          <div class="lr-title">📄 <?= h($m['title']) ?></div>
          <div class="lr-sub"><?= h($m['classroom_name']) ?><?= $m['subject_name'] ? ' · ' . h($m['subject_name']) : '' ?></div>
        </div>
        <div class="lr-due"><?= date('d M', strtotime($m['created_at'])) ?></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
