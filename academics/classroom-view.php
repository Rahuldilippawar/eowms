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
$canManageRoster = $isMgmt || $isHodOfDept; // विद्यार्थी/शिक्षक Allocation — फक्त HOD/Management
$canTeach = $canManageRoster || $isAssignedTeacher; // Material/Assignment — HOD किंवा नेमलेला शिक्षकही

if (!$canManageRoster && !$isAssignedTeacher) { http_response_code(403); die('तुम्हाला हा Classroom बघण्याची परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'allocate_student' && $canManageRoster) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        // विद्यार्थी याच संस्था/College/विभागाचा व Active असला पाहिजे
        $studChk = $pdo->prepare("SELECT id FROM students WHERE id = ? AND organization_id = ? AND campus_id = ? AND department_id = ? AND is_active = 1");
        $studChk->execute([$studentId, $orgId, $classroom['campus_id'], $classroom['department_id']]);
        if (!$studChk->fetch()) {
            $_SESSION['flash'] = 'अवैध विद्यार्थी — या Classroom च्या College/विभागातला नाही.';
            header('Location: classroom-view.php?id=' . $classroomId);
            exit;
        }
        try {
            $pdo->prepare("INSERT INTO classroom_students (classroom_id, student_id, allocated_by) VALUES (?, ?, ?)")->execute([$classroomId, $studentId, $user['id']]);
            $_SESSION['flash'] = 'विद्यार्थी जोडला गेला.';
        } catch (PDOException $e) {
            $_SESSION['flash'] = 'हा विद्यार्थी आधीच या Classroom मध्ये आहे.';
        }
    } elseif ($action === 'remove_student' && $canManageRoster) {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $pdo->prepare("DELETE FROM classroom_students WHERE classroom_id = ? AND student_id = ?")->execute([$classroomId, $studentId]);
        $_SESSION['flash'] = 'विद्यार्थी काढला गेला.';
    } elseif ($action === 'assign_teacher' && $canManageRoster) {
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $subjectName = trim($_POST['subject_name'] ?? '');
        $teacherChk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
        $teacherChk->execute([$teacherId, $orgId]);
        if ($teacherId && $subjectName && $teacherChk->fetch()) {
            $pdo->prepare("INSERT INTO classroom_staff (classroom_id, user_id, subject_name, assigned_by) VALUES (?, ?, ?, ?)")->execute([$classroomId, $teacherId, $subjectName, $user['id']]);
            notifyUser($teacherId, $orgId, 'नवीन Classroom नेमणूक', "{$classroom['name']} — $subjectName", 'academics/classroom-view.php?id=' . $classroomId);
            $_SESSION['flash'] = 'शिक्षक नेमले गेले.';
        }
    } elseif ($action === 'remove_teacher' && $canManageRoster) {
        $staffLinkId = (int)($_POST['staff_link_id'] ?? 0);
        $pdo->prepare("DELETE FROM classroom_staff WHERE id = ? AND classroom_id = ?")->execute([$staffLinkId, $classroomId]);
        $_SESSION['flash'] = 'शिक्षक काढले गेले.';
    } elseif ($action === 'upload_material' && $canTeach) {
        $title = trim($_POST['title'] ?? '');
        $subjectName = trim($_POST['subject_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title && !empty($_FILES['material_file']['tmp_name']) && $_FILES['material_file']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'ppt', 'pptx'];
            $ext = strtolower(pathinfo($_FILES['material_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true)) {
                $uploadDir = __DIR__ . '/../assets/uploads/classroom-materials';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $safeName = 'mat-' . $classroomId . '-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['material_file']['tmp_name'], $uploadDir . '/' . $safeName)) {
                    $pdo->prepare("INSERT INTO classroom_materials (classroom_id, uploaded_by, subject_name, title, description, file_path, original_filename) VALUES (?, ?, ?, ?, ?, ?, ?)")
                        ->execute([$classroomId, $user['id'], $subjectName, $title, $description, 'assets/uploads/classroom-materials/' . $safeName, $_FILES['material_file']['name']]);
                    notifyClassroomStudents($pdo, $classroomId, '📄 नवीन Study Material', $title, 'classroom.php?id=' . $classroomId);
                    $_SESSION['flash'] = 'Material Upload झालं.';
                }
            }
        }
    } elseif ($action === 'create_assignment' && $canTeach) {
        $title = trim($_POST['a_title'] ?? '');
        $subjectName = trim($_POST['a_subject_name'] ?? '');
        $description = trim($_POST['a_description'] ?? '');
        $dueDate = $_POST['due_date'] ?? '';
        $maxMarks = ($_POST['max_marks'] ?? '') !== '' ? (float)$_POST['max_marks'] : null;
        $attachmentPath = null;

        if (!empty($_FILES['a_attachment']['tmp_name']) && $_FILES['a_attachment']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'];
            $ext = strtolower(pathinfo($_FILES['a_attachment']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true)) {
                $uploadDir = __DIR__ . '/../assets/uploads/classroom-assignments';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $safeName = 'asn-' . $classroomId . '-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['a_attachment']['tmp_name'], $uploadDir . '/' . $safeName)) {
                    $attachmentPath = 'assets/uploads/classroom-assignments/' . $safeName;
                }
            }
        }

        if ($title && $dueDate) {
            $pdo->prepare("INSERT INTO classroom_assignments (classroom_id, created_by, subject_name, title, description, attachment_path, due_date, max_marks) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$classroomId, $user['id'], $subjectName, $title, $description, $attachmentPath, $dueDate, $maxMarks]);
            // सगळ्या विद्यार्थ्यांना कळवतो
            notifyClassroomStudents($pdo, $classroomId, '📝 नवीन Assignment', "$title — मुदत: $dueDate", 'classroom.php?id=' . $classroomId);
            $_SESSION['flash'] = 'Assignment तयार झालं — विद्यार्थ्यांना दिसेल.';
        }
    } elseif ($action === 'post_announcement' && $canTeach) {
        $annTitle = trim($_POST['ann_title'] ?? '');
        $annMessage = trim($_POST['ann_message'] ?? '');
        if ($annTitle && $annMessage) {
            $pdo->prepare("INSERT INTO classroom_announcements (classroom_id, created_by, title, message) VALUES (?, ?, ?, ?)")
                ->execute([$classroomId, $user['id'], $annTitle, $annMessage]);
            notifyClassroomStudents($pdo, $classroomId, '📢 ' . $annTitle, $annMessage, 'classroom.php?id=' . $classroomId);
            $_SESSION['flash'] = 'सूचना पाठवली गेली.';
        }
    }
    header('Location: classroom-view.php?id=' . $classroomId);
    exit;
}

$pageTitle = $classroom['name'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$rosterStmt = $pdo->prepare("SELECT s.* FROM classroom_students cs JOIN students s ON s.id = cs.student_id WHERE cs.classroom_id = ? ORDER BY s.roll_no");
$rosterStmt->execute([$classroomId]);
$roster = $rosterStmt->fetchAll();
$rosterIds = array_column($roster, 'id');

$availableStudentsStmt = $pdo->prepare("
    SELECT * FROM students WHERE organization_id = ? AND campus_id = ? AND department_id = ? AND is_active = 1
    " . (!empty($rosterIds) ? "AND id NOT IN (" . implode(',', array_fill(0, count($rosterIds), '?')) . ")" : "") . "
    ORDER BY roll_no
");
$availableStudentsStmt->execute(array_merge([$orgId, $classroom['campus_id'], $classroom['department_id']], $rosterIds));
$availableStudents = $availableStudentsStmt->fetchAll();

$teachersStmt = $pdo->prepare("SELECT cs.id AS link_id, cs.subject_name, u.name, u.id AS user_id FROM classroom_staff cs JOIN users u ON u.id = cs.user_id WHERE cs.classroom_id = ? ORDER BY cs.subject_name");
$teachersStmt->execute([$classroomId]);
$classroomTeachers = $teachersStmt->fetchAll();

$allStaffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$allStaffStmt->execute([$orgId]);
$allStaff = $allStaffStmt->fetchAll();

$announcementsStmt = $pdo->prepare("SELECT ca.*, u.name AS poster_name FROM classroom_announcements ca LEFT JOIN users u ON u.id = ca.created_by WHERE ca.classroom_id = ? ORDER BY ca.created_at DESC LIMIT 5");
$announcementsStmt->execute([$classroomId]);
$announcements = $announcementsStmt->fetchAll();

$materialsStmt = $pdo->prepare("SELECT cm.*, u.name AS uploader_name FROM classroom_materials cm LEFT JOIN users u ON u.id = cm.uploaded_by WHERE cm.classroom_id = ? ORDER BY cm.created_at DESC");
$materialsStmt->execute([$classroomId]);
$materials = $materialsStmt->fetchAll();

$assignmentsStmt = $pdo->prepare("
    SELECT ca.*, (SELECT COUNT(*) FROM classroom_assignment_submissions WHERE assignment_id = ca.id) AS submission_count
    FROM classroom_assignments ca WHERE ca.classroom_id = ? ORDER BY ca.due_date DESC
");
$assignmentsStmt->execute([$classroomId]);
$assignments = $assignmentsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏫 <?= h($classroom['name']) ?></div>
  <a href="classrooms.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व Classrooms</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:12px;"><?= h($classroom['academic_year']) ?> · <?= h($classroom['year_of_study']) ?>th Year</p>

<div class="d-flex gap-2 flex-wrap mb-4">
  <a href="classroom-attendance.php?id=<?= $classroomId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">📊 Attendance</a>
  <a href="classroom-exams.php?id=<?= $classroomId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">📈 Marks/Exam</a>
  <a href="classroom-timetable.php?id=<?= $classroomId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">🗓️ Time Table</a>
  <a href="classroom-questions.php?id=<?= $classroomId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">💬 शंका/Q&A</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">

    <?php if ($canManageRoster): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">👨‍🎓 विद्यार्थी Allocation (<?= count($roster) ?>)</div></div>
      <div style="padding:14px 20px;">
        <form method="POST" class="d-flex gap-2 mb-3">
          <input type="hidden" name="action" value="allocate_student">
          <select name="student_id" class="form-select-custom" required>
            <option value="">-- विद्यार्थी निवडा --</option>
            <?php foreach ($availableStudents as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['roll_no']) ?> — <?= h($s['name']) ?></option><?php endforeach; ?>
          </select>
          <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;padding:8px 16px;white-space:nowrap;">+ जोडा</button>
        </form>
        <?php foreach ($roster as $s): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:12.5px;">
            <span><?= h($s['roll_no']) ?> — <?= h($s['name']) ?></span>
            <form method="POST"><input type="hidden" name="action" value="remove_student"><input type="hidden" name="student_id" value="<?= $s['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕</button>
            </form>
          </div>
        <?php endforeach; ?>
        <?php if (empty($roster)): ?><p style="font-size:12px;color:var(--text-dim);">अजून कुठलाही विद्यार्थी नाही</p><?php endif; ?>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">👨‍🏫 शिक्षक नेमणूक</div></div>
      <div style="padding:14px 20px;">
        <form method="POST" class="row g-2 mb-3">
          <input type="hidden" name="action" value="assign_teacher">
          <div class="col-6">
            <select name="teacher_id" class="form-select-custom" required>
              <option value="">-- शिक्षक --</option>
              <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-4"><input type="text" name="subject_name" class="form-control-custom" placeholder="विषय" required></div>
          <div class="col-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">+</button></div>
        </form>
        <?php foreach ($classroomTeachers as $t): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:12.5px;">
            <span><strong><?= h($t['subject_name']) ?></strong> — <?= h($t['name']) ?></span>
            <form method="POST"><input type="hidden" name="action" value="remove_teacher"><input type="hidden" name="staff_link_id" value="<?= $t['link_id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>

  <div class="col-lg-6">

    <?php if ($canTeach): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📢 सूचना पाठवा</div></div>
      <form method="POST" style="padding:14px 20px;">
        <input type="hidden" name="action" value="post_announcement">
        <input type="text" name="ann_title" class="form-control-custom mb-2" placeholder="Title" required>
        <textarea name="ann_message" class="form-control-custom mb-2" rows="2" placeholder="सूचनेचा मजकूर" required></textarea>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:9px;">पाठवा — सगळ्या विद्यार्थ्यांना कळेल</button>
      </form>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📄 Material Upload करा</div></div>
      <form method="POST" enctype="multipart/form-data" style="padding:14px 20px;">
        <input type="hidden" name="action" value="upload_material">
        <input type="text" name="title" class="form-control-custom mb-2" placeholder="Title" required>
        <input type="text" name="subject_name" class="form-control-custom mb-2" placeholder="विषय">
        <textarea name="description" class="form-control-custom mb-2" rows="2" placeholder="वर्णन (ऐच्छिक)"></textarea>
        <input type="file" name="material_file" class="form-control-custom mb-2" required>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:9px;">Upload करा</button>
      </form>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📝 Assignment तयार करा</div></div>
      <form method="POST" enctype="multipart/form-data" style="padding:14px 20px;">
        <input type="hidden" name="action" value="create_assignment">
        <input type="text" name="a_title" class="form-control-custom mb-2" placeholder="Title" required>
        <input type="text" name="a_subject_name" class="form-control-custom mb-2" placeholder="विषय">
        <textarea name="a_description" class="form-control-custom mb-2" rows="2" placeholder="सूचना"></textarea>
        <div class="row g-2 mb-2">
          <div class="col-6"><input type="date" name="due_date" class="form-control-custom" required></div>
          <div class="col-6"><input type="number" step="0.5" name="max_marks" class="form-control-custom" placeholder="कमाल गुण"></div>
        </div>
        <input type="file" name="a_attachment" class="form-control-custom mb-2">
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:9px;">तयार करा</button>
      </form>
    </div>
    <?php endif; ?>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📢 अलीकडच्या सूचना</div></div>
      <div style="max-height:160px;overflow-y:auto;">
        <?php foreach ($announcements as $ann): ?>
          <div style="padding:9px 20px;border-bottom:1px solid var(--line);font-size:12.5px;">
            <strong><?= h($ann['title']) ?></strong>
            <div style="color:var(--text-dim);"><?= h($ann['message']) ?></div>
            <div style="font-size:10.5px;color:var(--text-dim);"><?= h($ann['poster_name']) ?> · <?= date('d M, h:i A', strtotime($ann['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($announcements)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून काही सूचना नाही</p><?php endif; ?>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📚 सगळं Material (<?= count($materials) ?>)</div></div>
      <div style="max-height:220px;overflow-y:auto;">
        <?php foreach ($materials as $m): ?>
          <div style="padding:9px 20px;border-bottom:1px solid var(--line);font-size:12.5px;">
            <strong><?= h($m['title']) ?></strong> <?= $m['subject_name'] ? '· ' . h($m['subject_name']) : '' ?>
            <div style="font-size:10.5px;color:var(--text-dim);"><?= h($m['uploader_name']) ?> · <?= date('d M', strtotime($m['created_at'])) ?></div>
          </div>
        <?php endforeach; ?>
        <?php if (empty($materials)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून काही नाही</p><?php endif; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><div class="title">📝 सगळे Assignments (<?= count($assignments) ?>)</div></div>
      <div style="max-height:220px;overflow-y:auto;">
        <?php foreach ($assignments as $a): ?>
          <a href="assignment-submissions.php?id=<?= $a['id'] ?>" style="display:block;padding:9px 20px;border-bottom:1px solid var(--line);font-size:12.5px;text-decoration:none;color:inherit;">
            <strong><?= h($a['title']) ?></strong> <?= $a['subject_name'] ? '· ' . h($a['subject_name']) : '' ?>
            <div style="font-size:10.5px;color:var(--text-dim);">मुदत: <?= date('d M', strtotime($a['due_date'])) ?> · <?= $a['submission_count'] ?>/<?= count($roster) ?> Submit</div>
          </a>
        <?php endforeach; ?>
        <?php if (empty($assignments)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 20px;">अजून काही नाही</p><?php endif; ?>
      </div>
    </div>

  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
