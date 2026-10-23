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
$canMark = $isMgmt || $isHodOfDept || $isAssignedTeacher;
if (!$canMark) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $date = $_POST['attendance_date'] ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) { $date = date('Y-m-d'); }
    $subjectName = trim($_POST['subject_name'] ?? '') ?: null;
    $statuses = is_array($_POST['status'] ?? null) ? $_POST['status'] : [];
    // फक्त याच Classroom मधले विद्यार्थी
    $rosterIdStmt = $pdo->prepare("SELECT student_id FROM classroom_students WHERE classroom_id = ?");
    $rosterIdStmt->execute([$classroomId]);
    $validIds = array_map('intval', $rosterIdStmt->fetchAll(PDO::FETCH_COLUMN));
    // विषय रिकामा असेल तर NULL — Unique Key NULL ला पकडत नाही, म्हणून आधी असलेली नोंद (<=>) शोधून Update करतो
    $findStmt = $pdo->prepare("SELECT id FROM classroom_attendance WHERE classroom_id = ? AND student_id = ? AND attendance_date = ? AND subject_name <=> ? LIMIT 1");
    $updStmt = $pdo->prepare("UPDATE classroom_attendance SET status = ?, marked_by = ? WHERE id = ?");
    $insStmt = $pdo->prepare("INSERT INTO classroom_attendance (classroom_id, student_id, attendance_date, subject_name, status, marked_by) VALUES (?, ?, ?, ?, ?, ?)");
    $count = 0;
    foreach ($statuses as $studentId => $status) {
        $studentId = (int)$studentId;
        if (!in_array($studentId, $validIds, true) || !in_array($status, ['present', 'absent'], true)) { continue; }
        $findStmt->execute([$classroomId, $studentId, $date, $subjectName]);
        $existingId = $findStmt->fetchColumn();
        if ($existingId) {
            $updStmt->execute([$status, $user['id'], $existingId]);
        } else {
            try {
                $insStmt->execute([$classroomId, $studentId, $date, $subjectName, $status, $user['id']]);
            } catch (PDOException $e) { continue; } // दुसऱ्या Classroom मध्ये याच विषयाची नोंद — वगळतो
        }
        $count++;
    }
    $_SESSION['flash'] = "$count विद्यार्थ्यांची हजेरी जतन झाली.";
    header('Location: classroom-attendance.php?id=' . $classroomId . '&date=' . $date . '&subject_name=' . urlencode($subjectName ?? ''));
    exit;
}

$pageTitle = 'Classroom Attendance';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$date = $_GET['date'] ?? date('Y-m-d');
$subjectName = $_GET['subject_name'] ?? '';

$rosterStmt = $pdo->prepare("
    SELECT s.id, s.roll_no, s.name, ca.status FROM classroom_students cs JOIN students s ON s.id = cs.student_id
    LEFT JOIN classroom_attendance ca ON ca.student_id = s.id AND ca.attendance_date = ? AND ca.subject_name " . ($subjectName ? "= ?" : "IS NULL") . " AND ca.classroom_id = ?
    WHERE cs.classroom_id = ? ORDER BY s.roll_no
");
$params = $subjectName ? [$date, $subjectName, $classroomId, $classroomId] : [$date, $classroomId, $classroomId];
$rosterStmt->execute($params);
$roster = $rosterStmt->fetchAll();

$presentCount = count(array_filter($roster, fn($r) => $r['status'] === 'present' || $r['status'] === null));
?>

<div class="page-eyebrow"><?= h($classroom['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 Attendance</div>
  <a href="classroom-view.php?id=<?= $classroomId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Classroom</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="d-flex gap-2 mb-4 flex-wrap">
  <input type="hidden" name="id" value="<?= $classroomId ?>">
  <input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($date) ?>">
  <input type="text" name="subject_name" class="form-control-custom" style="width:auto;" placeholder="विषय (ऐच्छिक)" value="<?= h($subjectName) ?>">
  <button type="submit" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">Load करा</button>
</form>

<form method="POST">
  <input type="hidden" name="attendance_date" value="<?= h($date) ?>">
  <input type="hidden" name="subject_name" value="<?= h($subjectName) ?>">
  <div class="panel">
    <div class="panel-head d-flex justify-content-between">
      <div class="title"><?= date('d M Y', strtotime($date)) ?><?= $subjectName ? ' · ' . h($subjectName) : '' ?></div>
      <div class="sub"><?= $presentCount ?>/<?= count($roster) ?> हजर</div>
    </div>
    <table class="rtable">
      <thead><tr><th>Roll No</th><th>नाव</th><th>हजेरी</th></tr></thead>
      <tbody>
        <?php foreach ($roster as $r): $current = $r['status'] ?? 'present'; ?>
          <tr>
            <td class="font-mono"><?= h($r['roll_no']) ?></td>
            <td style="font-weight:600;"><?= h($r['name']) ?></td>
            <td>
              <label style="margin-right:14px;font-size:12.5px;"><input type="radio" name="status[<?= $r['id'] ?>]" value="present" <?= $current === 'present' ? 'checked' : '' ?>> ✅ हजर</label>
              <label style="font-size:12.5px;"><input type="radio" name="status[<?= $r['id'] ?>]" value="absent" <?= $current === 'absent' ? 'checked' : '' ?>> ❌ गैरहजर</label>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (empty($roster)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:20px;">या Classroom मध्ये कुठलाही विद्यार्थी नाही</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if (!empty($roster)): ?>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 28px;font-weight:600;">💾 जतन करा</button>
  <?php endif; ?>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
