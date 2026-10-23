<?php
// विद्यार्थ्यांनी (Student Portal मधून) शिक्षकांना दिलेला Feedback — Anonymous
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin');
$canManage = $isMgmt || hasCategory('hod') || hasPermission('manage_academics');

// कोणत्या शिक्षकांचा Feedback पाहता येईल — Management: संस्थेतले सगळे नेमलेले शिक्षक; HOD: स्वतःच्या विभागाच्या Classrooms चे
$teacherSql = "SELECT DISTINCT u.id, u.name FROM classroom_staff cs JOIN virtual_classrooms vc ON vc.id = cs.classroom_id JOIN users u ON u.id = cs.user_id WHERE vc.organization_id = ?";
$teacherParams = [$orgId];
if (!$isMgmt) { $teacherSql .= " AND vc.department_id = ?"; $teacherParams[] = (int)($user['department_id'] ?? 0); }
$teacherStmt = $pdo->prepare($teacherSql . " ORDER BY u.name");
$teacherStmt->execute($teacherParams);
$allTeachers = $canManage ? $teacherStmt->fetchAll() : [];
$allowedIds = array_map('intval', array_column($allTeachers, 'id'));

$viewTeacherId = $canManage && !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : (int)$user['id'];
if ($viewTeacherId !== (int)$user['id'] && !in_array($viewTeacherId, $allowedIds, true)) { http_response_code(403); die('परवानगी नाही.'); }

$viewTeacherStmt = $pdo->prepare("SELECT name FROM users WHERE id = ? AND organization_id = ?");
$viewTeacherStmt->execute([$viewTeacherId, $orgId]);
$viewTeacher = $viewTeacherStmt->fetch();
if (!$viewTeacher) { http_response_code(404); die('सापडलं नाही.'); }

// शैक्षणिक वर्ष जूनपासून सुरू — जानेवारी-मे मध्ये मागच्या वर्षाचंच Academic Year
$fyStartYear = (int)date('Y') - ((int)date('n') < 6 ? 1 : 0);
$fy = $fyStartYear . '-' . substr((string)($fyStartYear + 1), 2);

$feedbackStmt = $pdo->prepare("SELECT rating, comment, submitted_at FROM teacher_feedback WHERE teacher_id = ? AND academic_year = ? ORDER BY submitted_at DESC");
$feedbackStmt->execute([$viewTeacherId, $fy]);
$feedbackList = $feedbackStmt->fetchAll();
$avgRating = count($feedbackList) > 0 ? round(array_sum(array_column($feedbackList, 'rating')) / count($feedbackList), 1) : 0;

$pageTitle = 'विद्यार्थी Feedback';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?> · <?= h($fy) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💬 <?= h($viewTeacher['name']) ?> — विद्यार्थी Feedback</div>
  <a href="classrooms.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Classrooms</a>
</div>

<?php if ($canManage && !empty($allTeachers)): ?>
<form method="GET" class="mb-4 mt-2">
  <select name="teacher_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <?php if (!in_array((int)$user['id'], $allowedIds, true)): ?><option value="<?= (int)$user['id'] ?>">-- शिक्षक निवडा --</option><?php endif; ?>
    <?php foreach ($allTeachers as $t): ?><option value="<?= $t['id'] ?>" <?= $viewTeacherId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php endif; ?>

<div class="row g-3 mb-4 mt-1">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Feedback</div><div class="val"><?= count($feedbackList) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">सरासरी Rating</div><div class="val" style="color:var(--amber);">⭐ <?= $avgRating ?>/5</div></div></div>
</div>

<div class="panel">
  <?php if (empty($feedbackList)): ?>
    <div class="empty-state"><div class="icon"><i class="fa-solid fa-star"></i></div><div class="title">अजून कुठलंही Feedback आलेलं नाही</div></div>
  <?php endif; ?>
  <?php foreach ($feedbackList as $fb): ?>
    <div style="padding:14px 20px;border-bottom:1px solid var(--line);">
      <div style="color:var(--amber);font-size:14px;"><?= str_repeat('★', (int)$fb['rating']) . str_repeat('☆', 5 - (int)$fb['rating']) ?></div>
      <?php if ($fb['comment']): ?><div style="font-size:12.5px;margin-top:4px;"><?= h($fb['comment']) ?></div><?php endif; ?>
      <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;"><?= date('d M Y', strtotime($fb['submitted_at'])) ?> — गुप्त (Anonymous)</div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
