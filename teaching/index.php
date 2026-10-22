<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$isTeacher = ($user['teaching_mode'] ?? '') === 'Teaching';
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();

if (!$isTeacher && !$canManage) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">हे Teaching-Staff साठीचं Module आहे. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$pageTitle = 'Teaching Module';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// Quick Stats — स्वतःच्या (Teacher असेल तर) किंवा संस्थेच्या (Management असेल तर)
$fy = date('Y') . '-' . substr(date('Y') + 1, 2);
if ($isTeacher && !$canManage) {
    $periodsStmt = $pdo->prepare("SELECT COUNT(*) c FROM teacher_timetable WHERE teacher_id = ? AND academic_year = ?");
    $periodsStmt->execute([$user['id'], $fy]);
    $myPeriods = $periodsStmt->fetch()['c'];

    $syllabusStmt = $pdo->prepare("SELECT COALESCE(AVG(chapters_completed / NULLIF(total_chapters,0)) * 100, 0) pct FROM syllabus_trackers WHERE teacher_id = ? AND academic_year = ?");
    $syllabusStmt->execute([$user['id'], $fy]);
    $avgSyllabus = round($syllabusStmt->fetch()['pct'], 1);

    $upcomingDutyStmt = $pdo->prepare("SELECT COUNT(*) c FROM exam_duty_allocations WHERE teacher_id = ? AND duty_date >= CURDATE()");
    $upcomingDutyStmt->execute([$user['id']]);
    $upcomingDuty = $upcomingDutyStmt->fetch()['c'];
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">👨‍🏫 Teaching Module</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Lectures, Timetable, Syllabus, Substitute — शिक्षकांसाठी खास</p>

<?php if ($isTeacher && !$canManage): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">आठवड्याचे Periods</div><div class="val"><?= $myPeriods ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">Syllabus सरासरी</div><div class="val" style="color:var(--rail);"><?= $avgSyllabus ?>%</div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">पुढची Exam Duty</div><div class="val" style="color:var(--amber);"><?= $upcomingDuty ?></div></div></div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-4">
    <a href="timetable.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-calendar-week" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">📅 माझं Timetable</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">आठवड्याचं Lecture-Schedule</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="substitute.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-people-arrows" style="font-size:22px;color:var(--amber);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">🔄 Substitute / Cover-Class</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">कोण Free आहे, कव्हर कोण करेल</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="syllabus.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-book-open" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">📖 Syllabus Tracker</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">किती Chapters पूर्ण झाले</div>
    </a>
  </div>
  <div class="col-md-4">
    <a href="qualifications.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-graduation-cap" style="font-size:22px;color:var(--amber);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">🎓 शैक्षणिक पात्रता</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">NET/SET/PhD, Research Papers</div>
    </a>
  </div>
  <?php if ($canManage): ?>
  <div class="col-md-4">
    <a href="class-teacher.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-chalkboard-user" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">👨‍🏫 Class Teacher नेमणूक</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">प्रत्येक Class चा जबाबदार शिक्षक</div>
    </a>
  </div>
  <?php endif; ?>
  <div class="col-md-4">
    <a href="exam-duty.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-user-shield" style="font-size:22px;color:var(--rose);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">📝 Exam Duty</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Invigilation Allocation</div>
    </a>
  </div>
  <?php if ($canManage): ?>
  <div class="col-md-4">
    <a href="load-report.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-chart-column" style="font-size:22px;color:var(--amber);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">⏱️ Teaching Load Report</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">प्रत्येक शिक्षकाचे आठवड्याचे Periods</div>
    </a>
  </div>
  <?php endif; ?>
  <div class="col-md-4">
    <a href="feedback.php" class="panel" style="display:block;padding:22px;text-decoration:none;color:inherit;">
      <i class="fa-solid fa-star" style="font-size:22px;color:var(--rail);"></i>
      <div style="font-weight:700;font-size:14px;margin-top:10px;">💬 Feedback</div>
      <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Student/Parent Rating</div>
    </a>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
