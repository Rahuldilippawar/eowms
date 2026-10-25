<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin');
$canManage = $isMgmt || hasCategory('hod') || hasPermission('manage_academics');
// नेमलेले शिक्षक (HOD नसलेले) — फक्त "माझे वर्ग" पाहू शकतात
$myClassStmt = $pdo->prepare("SELECT COUNT(*) FROM classroom_staff cs JOIN virtual_classrooms vc ON vc.id = cs.classroom_id WHERE cs.user_id = ? AND vc.organization_id = ?");
$myClassStmt->execute([$user['id'], $orgId]);
$isTeacherOnly = !$canManage && (int)$myClassStmt->fetchColumn() > 0;
if (!$canManage && !$isTeacherOnly) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }
    $campusId = (int)($_POST['campus_id'] ?? 0);
    $departmentId = (int)($_POST['department_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $yearOfStudy = (int)($_POST['year_of_study'] ?? 1);
    $academicYear = trim($_POST['academic_year'] ?? '');

    if (!$isMgmt && (int)$departmentId !== (int)($user['department_id'] ?? 0)) {
        http_response_code(403);
        die('तुम्ही फक्त स्वतःच्या विभागासाठी Classroom तयार करू शकता.');
    }

    if ($campusId && $departmentId && $name && $academicYear) {
        $pdo->prepare("INSERT INTO virtual_classrooms (organization_id, campus_id, department_id, name, year_of_study, academic_year, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $campusId, $departmentId, $name, $yearOfStudy, $academicYear, $user['id']]);
        $_SESSION['flash'] = "'$name' Classroom तयार झालं.";
    }
    header('Location: classrooms.php');
    exit;
}

$pageTitle = 'Virtual Classrooms';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sql = "
    SELECT vc.*, c.name AS campus_name, d.name_local AS dept_name,
      (SELECT COUNT(*) FROM classroom_students WHERE classroom_id = vc.id) AS student_count,
      (SELECT COUNT(*) FROM classroom_staff WHERE classroom_id = vc.id) AS staff_count
    FROM virtual_classrooms vc JOIN campuses c ON c.id = vc.campus_id JOIN departments d ON d.id = vc.department_id
    WHERE vc.organization_id = ? AND vc.is_active = 1
";
$params = [$orgId];
if ($isTeacherOnly) { $sql .= " AND vc.id IN (SELECT classroom_id FROM classroom_staff WHERE user_id = ?)"; $params[] = $user['id']; }
elseif (!$isMgmt) { $sql .= " AND vc.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
$sql .= " ORDER BY vc.academic_year DESC, vc.name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$classrooms = $stmt->fetchAll();

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campusesForClassroom = $campusStmt->fetchAll();

$deptStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1" . (!$isMgmt ? " AND id = " . (int)($user['department_id'] ?? 0) : "") . " ORDER BY name_local");
$deptStmt->execute([$orgId]);
$deptsForClassroom = $deptStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏫 <?= $isTeacherOnly ? 'माझे वर्ग / My Classrooms' : 'Virtual Classrooms' ?></div>
  <div class="d-flex gap-3" style="align-self:center;">
    <a href="teacher-feedback.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-star me-1"></i>विद्यार्थी Feedback</a>
    <?php if (!$isTeacherOnly): ?>
    <a href="students.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;"><i class="fa-solid fa-arrow-left me-1"></i>विद्यार्थी Master</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!$isTeacherOnly): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Classroom तयार करा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-2">
      <div class="col-md-3"><input type="text" name="name" class="form-control-custom" placeholder="उदा. FY B.Com - A" required></div>
      <div class="col-md-2">
        <select name="campus_id" class="form-select-custom" required>
          <option value="">-- College --</option>
          <?php foreach ($campusesForClassroom as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="department_id" class="form-select-custom" required>
          <option value="">-- Course/विभाग --</option>
          <?php foreach ($deptsForClassroom as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <select name="year_of_study" class="form-select-custom">
          <option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option>
        </select>
      </div>
      <div class="col-md-2"><input type="text" name="academic_year" class="form-control-custom" placeholder="2026-27" required></div>
      <div class="col-md-1"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;">+</button></div>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($classrooms)): ?><div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कुठलाही Classroom तयार केलेला नाही</div></div><?php endif; ?>
  <?php foreach ($classrooms as $c): ?>
    <div class="col-md-4">
      <a href="classroom-view.php?id=<?= $c['id'] ?>" class="panel" style="display:block;padding:18px;text-decoration:none;color:inherit;">
        <div style="font-weight:700;font-size:14px;"><?= h($c['name']) ?></div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:10px;"><?= h($c['campus_name']) ?> · <?= h($c['dept_name']) ?> · <?= h($c['academic_year']) ?></div>
        <div style="font-size:12px;"><i class="fa-solid fa-user-graduate"></i> <?= $c['student_count'] ?> विद्यार्थी &nbsp; <i class="fa-solid fa-chalkboard-user"></i> <?= $c['staff_count'] ?> शिक्षक</div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
