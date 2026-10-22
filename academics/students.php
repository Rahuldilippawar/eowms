<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin');
$canManage = $isMgmt || hasCategory('hod') || hasPermission('manage_academics');
if (!$canManage) { http_response_code(403); die('तुम्हाला विद्यार्थी Master पाहण्याची परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $campusId = (int)($_POST['campus_id'] ?? 0);
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $rollNo = trim($_POST['roll_no'] ?? '');
        $admissionNo = trim($_POST['admission_no'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $yearOfStudy = (int)($_POST['year_of_study'] ?? 1);
        $academicYear = trim($_POST['academic_year'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '');
        $parentMobile = trim($_POST['parent_mobile'] ?? '');

        // HOD ला फक्त स्वतःच्याच विभागात विद्यार्थी जोडता येईल
        if (!$isMgmt) {
            $deptCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
            $deptCheck->execute([$departmentId, $orgId]);
            if ((int)$departmentId !== (int)($user['department_id'] ?? 0)) {
                http_response_code(403);
                die('तुम्ही फक्त स्वतःच्या विभागात विद्यार्थी जोडू शकता.');
            }
        }

        if ($campusId && $departmentId && $rollNo && $admissionNo && $name && $academicYear) {
            // Default Password — Admission No — पहिल्या Login वर बदलावाच लागेल
            $defaultPassword = password_hash($admissionNo, PASSWORD_DEFAULT);
            try {
                $pdo->prepare("
                    INSERT INTO students (organization_id, campus_id, department_id, roll_no, admission_no, name, year_of_study, academic_year, mobile, parent_mobile, password_hash, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$orgId, $campusId, $departmentId, $rollNo, $admissionNo, $name, $yearOfStudy, $academicYear, $mobile, $parentMobile, $defaultPassword, $user['id']]);
                $_SESSION['flash'] = "'$name' जोडले गेले. सुरुवातीचा Password = Admission No ($admissionNo).";
            } catch (PDOException $e) {
                $_SESSION['form_error'] = 'हा Roll No किंवा Admission No आधीच वापरलेला आहे.';
            }
        } else {
            $_SESSION['form_error'] = 'कृपया सगळी माहिती भरा.';
        }
    } elseif ($action === 'reset_password') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $studentStmt = $pdo->prepare("SELECT admission_no, department_id FROM students WHERE id = ? AND organization_id = ?");
        $studentStmt->execute([$studentId, $orgId]);
        $targetStudent = $studentStmt->fetch();
        if ($targetStudent && ($isMgmt || (int)$targetStudent['department_id'] === (int)($user['department_id'] ?? 0))) {
            $newHash = password_hash($targetStudent['admission_no'], PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE students SET password_hash = ?, must_change_password = 1, failed_login_attempts = 0, locked_until = NULL WHERE id = ?")
                ->execute([$newHash, $studentId]);
            $_SESSION['flash'] = "Password Reset झाला — नवीन Password = Admission No.";
        }
    } elseif ($action === 'toggle') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $pdo->prepare("UPDATE students SET is_active = NOT is_active WHERE id = ? AND organization_id = ?")->execute([$studentId, $orgId]);
        $_SESSION['flash'] = 'स्थिती बदलली.';
    }
    header('Location: students.php');
    exit;
}

$pageTitle = 'विद्यार्थी Master';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campusesForStudent = $campusStmt->fetchAll();

$sql = "SELECT s.*, c.name AS campus_name, d.name_local AS dept_name FROM students s JOIN campuses c ON c.id = s.campus_id JOIN departments d ON d.id = s.department_id WHERE s.organization_id = ?";
$params = [$orgId];
if (!$isMgmt) { $sql .= " AND s.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
$sql .= " ORDER BY s.academic_year DESC, s.year_of_study, s.roll_no";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$students = $stmt->fetchAll();

$deptStmt = $pdo->prepare("SELECT id, name_local, name, campus_id FROM departments WHERE organization_id = ? AND is_active = 1" . (!$isMgmt ? " AND id = " . (int)($user['department_id'] ?? 0) : "") . " ORDER BY name_local");
$deptStmt->execute([$orgId]);
$deptsForStudent = $deptStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📇 विद्यार्थी Master</div>
  <div class="d-flex gap-2">
    <a href="students-bulk-import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📥 Bulk Import</a>
    <a href="classrooms.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 16px;font-weight:600;">🏫 Virtual Classrooms</a>
  </div>
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
  <div class="panel-head"><div class="title">नवीन विद्यार्थी जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="add">
    <div class="row g-2">
      <div class="col-md-3"><input type="text" name="name" class="form-control-custom" placeholder="विद्यार्थ्याचं नाव" required></div>
      <div class="col-md-2">
        <select name="campus_id" class="form-select-custom" required>
          <option value="">-- College --</option>
          <?php foreach ($campusesForStudent as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <select name="department_id" class="form-select-custom" required>
          <option value="">-- Course/विभाग --</option>
          <?php foreach ($deptsForStudent as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1"><input type="text" name="roll_no" class="form-control-custom" placeholder="Roll No" required></div>
      <div class="col-md-2"><input type="text" name="admission_no" class="form-control-custom" placeholder="Admission No" required></div>
      <div class="col-md-1">
        <select name="year_of_study" class="form-select-custom">
          <option value="1">1st</option><option value="2">2nd</option><option value="3">3rd</option><option value="4">4th</option>
        </select>
      </div>
      <div class="col-md-1"><input type="text" name="academic_year" class="form-control-custom" placeholder="2026-27" required></div>
    </div>
    <div class="row g-2 mt-1">
      <div class="col-md-3"><input type="text" name="mobile" class="form-control-custom" placeholder="Mobile"></div>
      <div class="col-md-3"><input type="text" name="parent_mobile" class="form-control-custom" placeholder="पालकांचा Mobile"></div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;">+ जोडा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>College</th><th>Course</th><th>Year</th><th>Roll No</th><th>Admission No</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($students)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही विद्यार्थी नोंदवलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($students as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?></td>
          <td><?= h($s['campus_name']) ?></td>
          <td><?= h($s['dept_name']) ?></td>
          <td><?= $s['year_of_study'] ?>th · <?= h($s['academic_year']) ?></td>
          <td class="font-mono"><?= h($s['roll_no']) ?></td>
          <td class="font-mono"><?= h($s['admission_no']) ?></td>
          <td><span class="status-chip <?= $s['is_active'] ? 'done' : 'overdue' ?>"><?= $s['is_active'] ? '✅' : '⚪ बंद' ?></span></td>
          <td class="d-flex gap-1">
            <form method="POST"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="student_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:6px;font-size:10px;padding:3px 8px;" onclick="return confirm('Password Reset करायचा?')">🔑</button>
            </form>
            <form method="POST"><input type="hidden" name="action" value="toggle"><input type="hidden" name="student_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:6px;font-size:10px;padding:3px 8px;"><?= $s['is_active'] ? '🚫' : '✅' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
