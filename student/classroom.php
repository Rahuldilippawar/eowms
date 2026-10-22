<?php
require_once __DIR__ . '/header.php';

$classroomId = (int)($_GET['id'] ?? 0);
if (!studentHasClassroomAccess($pdo, $student['id'], $classroomId)) {
    http_response_code(403);
    die('तुम्ही या वर्गात नाही आहात.');
}

$classStmt = $pdo->prepare("SELECT * FROM virtual_classrooms WHERE id = ?");
$classStmt->execute([$classroomId]);
$classroom = $classStmt->fetch();

$materialsStmt = $pdo->prepare("SELECT * FROM classroom_materials WHERE classroom_id = ? ORDER BY created_at DESC");
$materialsStmt->execute([$classroomId]);
$materials = $materialsStmt->fetchAll();

$assignStmt = $pdo->prepare("
    SELECT ca.*, (SELECT COUNT(*) FROM classroom_assignment_submissions WHERE assignment_id = ca.id AND student_id = ?) AS submitted
    FROM classroom_assignments ca WHERE ca.classroom_id = ? ORDER BY ca.due_date DESC
");
$assignStmt->execute([$student['id'], $classroomId]);
$assignments = $assignStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT cs.subject_name, u.name FROM classroom_staff cs JOIN users u ON u.id = cs.user_id WHERE cs.classroom_id = ? ORDER BY cs.subject_name");
$staffStmt->execute([$classroomId]);
$teachers = $staffStmt->fetchAll();

$announcementsStmt = $pdo->prepare("SELECT ca.*, u.name AS poster_name FROM classroom_announcements ca LEFT JOIN users u ON u.id = ca.created_by WHERE ca.classroom_id = ? ORDER BY ca.created_at DESC LIMIT 5");
$announcementsStmt->execute([$classroomId]);
$announcements = $announcementsStmt->fetchAll();

$attSummaryStmt = $pdo->prepare("
    SELECT SUM(status = 'present') AS present_count, COUNT(*) AS total FROM classroom_attendance
    WHERE classroom_id = ? AND student_id = ?
");
$attSummaryStmt->execute([$classroomId, $student['id']]);
$attSummary = $attSummaryStmt->fetch();

$examsStmt = $pdo->prepare("
    SELECT ce.name, ce.subject_name, ce.max_marks, em.marks_obtained
    FROM classroom_exams ce LEFT JOIN classroom_exam_marks em ON em.exam_id = ce.id AND em.student_id = ?
    WHERE ce.classroom_id = ? ORDER BY ce.exam_date DESC
");
$examsStmt->execute([$student['id'], $classroomId]);
$examMarks = $examsStmt->fetchAll();

$myQuestionsStmt = $pdo->prepare("
    SELECT cq.*, (SELECT reply_text FROM classroom_question_replies WHERE question_id = cq.id ORDER BY id DESC LIMIT 1) AS latest_reply
    FROM classroom_questions cq WHERE cq.classroom_id = ? AND cq.student_id = ? ORDER BY cq.created_at DESC
");
$myQuestionsStmt->execute([$classroomId, $student['id']]);
$myQuestions = $myQuestionsStmt->fetchAll();

$dayNamesStudent = ['रविवार', 'सोमवार', 'मंगळवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
$ttStmt = $pdo->prepare("SELECT ct.*, u.name AS teacher_name FROM classroom_timetable ct LEFT JOIN users u ON u.id = ct.teacher_id WHERE ct.classroom_id = ? ORDER BY ct.day_of_week, ct.period_no");
$ttStmt->execute([$classroomId]);
$timetable = $ttStmt->fetchAll();
?>

<div class="page-title">🏫 <?= h($classroom['name']) ?></div>
<p class="sub"><?= h($classroom['academic_year']) ?></p>

<?php if (!empty($teachers)): ?>
  <div class="section-label">शिक्षक</div>
  <div class="panel" style="padding:14px 18px;margin-bottom:20px;">
    <?php foreach ($teachers as $t): ?>
      <div style="font-size:13px;margin-bottom:4px;"><strong><?= h($t['subject_name']) ?></strong> — <?= h($t['name']) ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($announcements)): ?>
  <div class="section-label">📢 सूचना</div>
  <div class="panel" style="margin-bottom:20px;">
    <?php foreach ($announcements as $ann): ?>
      <div style="padding:12px 18px;border-bottom:1px solid var(--line);font-size:12.5px;">
        <strong><?= h($ann['title']) ?></strong>
        <div style="color:var(--text-dim);margin-top:3px;"><?= h($ann['message']) ?></div>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;"><?= h($ann['poster_name']) ?> · <?= date('d M', strtotime($ann['created_at'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($attSummary && $attSummary['total'] > 0): ?>
  <div class="section-label">📊 माझी Attendance</div>
  <div class="panel" style="padding:16px 18px;margin-bottom:20px;">
    <div style="font-size:20px;font-weight:700;color:var(--rail);"><?= round(($attSummary['present_count'] / $attSummary['total']) * 100) ?>%</div>
    <div style="font-size:11.5px;color:var(--text-dim);"><?= $attSummary['present_count'] ?>/<?= $attSummary['total'] ?> दिवस हजर</div>
  </div>
<?php endif; ?>

<?php if (!empty($examMarks)): ?>
  <div class="section-label">📈 माझे Marks</div>
  <div class="panel" style="margin-bottom:20px;">
    <?php foreach ($examMarks as $em): ?>
      <div style="display:flex;justify-content:space-between;padding:10px 18px;border-bottom:1px solid var(--line);font-size:12.5px;">
        <span><?= h($em['name']) ?> (<?= h($em['subject_name']) ?>)</span>
        <strong><?= $em['marks_obtained'] !== null ? $em['marks_obtained'] . '/' . $em['max_marks'] : 'प्रलंबित' ?></strong>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!empty($timetable)): ?>
  <div class="section-label">🗓️ Time Table</div>
  <div class="panel" style="padding:14px 18px;margin-bottom:20px;">
    <?php $currentDay = null; foreach ($timetable as $tt): if ($currentDay !== $tt['day_of_week']): $currentDay = $tt['day_of_week']; ?>
      <div style="font-weight:700;font-size:12px;margin:10px 0 4px;"><?= $dayNamesStudent[$currentDay] ?></div>
    <?php endif; ?>
      <div style="font-size:12px;padding:3px 0;">P<?= $tt['period_no'] ?> — <?= h($tt['subject_name']) ?><?= $tt['teacher_name'] ? ' (' . h($tt['teacher_name']) . ')' : '' ?></div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="section-label">📝 Assignments</div>
<div class="panel">
  <?php if (empty($assignments)): ?><div style="padding:20px;text-align:center;color:var(--text-dim);font-size:13px;">अजून कुठलाही Assignment नाही</div><?php endif; ?>
  <?php foreach ($assignments as $a): ?>
    <a href="assignment.php?id=<?= $a['id'] ?>" class="list-row">
      <div>
        <div class="lr-title"><?= h($a['title']) ?><?= $a['submitted'] ? ' ✅' : '' ?></div>
        <div class="lr-sub"><?= h($a['subject_name'] ?: '') ?></div>
      </div>
      <div class="lr-due"><?= $a['submitted'] ? 'Submit झालं' : 'मुदत: ' . date('d M', strtotime($a['due_date'])) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="section-label" style="margin-top:20px;">📚 Study Material</div>
<div class="panel">
  <?php if (empty($materials)): ?><div style="padding:20px;text-align:center;color:var(--text-dim);font-size:13px;">अजून कुठलंही Material नाही</div><?php endif; ?>
  <?php foreach ($materials as $m): ?>
    <a href="material-download.php?id=<?= $m['id'] ?>" class="list-row">
      <div>
        <div class="lr-title">📄 <?= h($m['title']) ?></div>
        <div class="lr-sub"><?= h($m['subject_name'] ?: '') ?></div>
      </div>
      <div class="lr-due"><?= date('d M', strtotime($m['created_at'])) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="section-label" style="margin-top:20px;">💬 शंका विचारा</div>
<div class="panel" style="padding:16px 18px;margin-bottom:16px;">
  <form method="POST" action="ask-question.php">
    <input type="hidden" name="classroom_id" value="<?= $classroomId ?>">
    <div class="row g-2">
      <div class="col-4"><input type="text" name="subject_name" style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;" placeholder="विषय (ऐच्छिक)"></div>
      <div class="col-6"><input type="text" name="question_text" style="width:100%;padding:9px 12px;border:1.5px solid var(--line);border-radius:9px;font-family:inherit;font-size:13px;" placeholder="तुमचा प्रश्न" required></div>
      <div class="col-2"><button type="submit" class="btn-primary w-100">विचारा</button></div>
    </div>
  </form>
</div>

<?php if (!empty($myQuestions)): ?>
<div class="panel" style="margin-bottom:16px;">
  <?php foreach ($myQuestions as $q): ?>
    <div style="padding:12px 18px;border-bottom:1px solid var(--line);font-size:12.5px;">
      <div><strong><?= h($q['question_text']) ?></strong></div>
      <?php if ($q['latest_reply']): ?>
        <div style="background:var(--rail-soft);border-radius:9px;padding:8px 12px;margin-top:6px;">💬 <?= h($q['latest_reply']) ?></div>
      <?php else: ?>
        <span style="display:inline-block;background:#FBEBD6;color:var(--amber);font-size:10px;padding:2px 8px;border-radius:6px;">⏳ उत्तराची वाट बघतोय</span>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/footer.php'; ?>
