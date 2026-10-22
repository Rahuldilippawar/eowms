<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// संगीत विभागाचाच Staff, किंवा 'manage_music' Permission असलेला, किंवा
// Management-श्रेणीतला — यांनाच Instruments/Students/इ. Manage करता येईल.
$musicDeptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$musicDeptCheck->execute([$user['department_id'] ?? 0]);
$isMusicDept = ($musicDeptCheck->fetch()['code'] ?? '') === 'MUS';
if (!$isMusicDept && !hasPermission('manage_music') && !isManagementOrAbove()) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला संगीत विभाग व्यवस्थापित करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add_student') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        $guardianName = trim($_POST['guardian_name'] ?? '');
        if ($name) {
            $pdo->prepare("INSERT INTO music_students (organization_id, name, contact, guardian_name, admission_date, created_by) VALUES (?, ?, ?, ?, CURDATE(), ?)")
                ->execute([$orgId, $name, $contact, $guardianName, $user['id']]);
            logActivity('संगीत विद्यार्थी जोडला', 'music_student', $pdo->lastInsertId(), $name);
            $_SESSION['flash'] = "\"$name\" यशस्वीरित्या नोंदवला गेला.";
        }
    } elseif ($action === 'enroll') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $teacherId = ($_POST['teacher_id'] ?? '') !== '' ? (int)$_POST['teacher_id'] : null;
        if ($studentId && $subject) {
            $pdo->prepare("INSERT INTO music_enrollments (student_id, subject, teacher_id, start_date) VALUES (?, ?, ?, CURDATE())")
                ->execute([$studentId, $subject, $teacherId]);
            $_SESSION['flash'] = 'नोंदणी झाली.';
        }
    } elseif ($action === 'mark_attendance') {
        $enrollmentId = (int)($_POST['enrollment_id'] ?? 0);
        $attDate = ($_POST['attendance_date'] ?? '') ?: date('Y-m-d');
        $status = $_POST['status'] ?? 'present';
        $pdo->prepare("INSERT INTO music_attendance (enrollment_id, attendance_date, status, marked_by) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)")
            ->execute([$enrollmentId, $attDate, $status, $user['id']]);
        $_SESSION['flash'] = 'हजेरी नोंदवली गेली.';
    }
    header('Location: students.php');
    exit;
}

$pageTitle = 'विद्यार्थी व हजेरी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$studentsStmt = $pdo->prepare("
    SELECT ms.*,
      (SELECT GROUP_CONCAT(CONCAT(subject, ' (', COALESCE(u.name, 'शिक्षक नेमलेला नाही'), ')') SEPARATOR ', ') FROM music_enrollments me LEFT JOIN users u ON u.id = me.teacher_id WHERE me.student_id = ms.id AND me.status = 'active') AS enrollments
    FROM music_students ms WHERE ms.organization_id = ? AND ms.is_active = 1 ORDER BY ms.name
");
$studentsStmt->execute([$orgId]);
$students = $studentsStmt->fetchAll();

$teachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$teachersStmt->execute([$orgId]);
$teachers = $teachersStmt->fetchAll();

// आजची हजेरी घेण्यासाठी — Active Enrollments
$todayAttStmt = $pdo->prepare("
    SELECT me.id AS enrollment_id, ms.name AS student_name, me.subject,
      (SELECT status FROM music_attendance WHERE enrollment_id = me.id AND attendance_date = CURDATE()) AS today_status
    FROM music_enrollments me JOIN music_students ms ON ms.id = me.student_id
    WHERE ms.organization_id = ? AND me.status = 'active' ORDER BY ms.name
");
$todayAttStmt->execute([$orgId]);
$todayEnrollments = $todayAttStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎓 विद्यार्थी व हजेरी</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संगीत विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">नवीन विद्यार्थी नोंदवा</div></div>
      <form method="POST" style="padding:18px 24px;">
        <input type="hidden" name="action" value="add_student">
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">संपर्क</label>
        <input type="text" name="contact" class="form-control-custom mb-2">
        <label class="form-label-custom">पालकाचं नाव</label>
        <input type="text" name="guardian_name" class="form-control-custom mb-3">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;">नोंदवा</button>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">वाद्य/गायन शिकवायला नोंदणी करा (Enroll)</div></div>
      <form method="POST" style="padding:18px 24px;">
        <input type="hidden" name="action" value="enroll">
        <label class="form-label-custom">विद्यार्थी</label>
        <select name="student_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">विषय (गायन/वाद्य)</label>
        <input type="text" name="subject" class="form-control-custom mb-2" placeholder="उदा. तबला, गायन" required>
        <label class="form-label-custom">शिक्षक</label>
        <select name="teacher_id" class="form-select-custom mb-3">
          <option value="">-- ऐच्छिक --</option>
          <?php foreach ($teachers as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;">Enroll करा</button>
      </form>
    </div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📋 आजची हजेरी (<?= date('d M Y') ?>)</div></div>
  <table class="rtable">
    <thead><tr><th>विद्यार्थी</th><th>विषय</th><th>हजेरी</th></tr></thead>
    <tbody>
      <?php if (empty($todayEnrollments)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:20px;">अजून कोणीही Enroll झालेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($todayEnrollments as $e): ?>
        <tr>
          <td style="font-weight:600;"><?= h($e['student_name']) ?></td>
          <td><?= h($e['subject']) ?></td>
          <td>
            <form method="POST" class="d-flex gap-2">
              <input type="hidden" name="action" value="mark_attendance">
              <input type="hidden" name="enrollment_id" value="<?= $e['enrollment_id'] ?>">
              <input type="hidden" name="attendance_date" value="<?= date('Y-m-d') ?>">
              <button type="submit" name="status" value="present" class="btn btn-sm" style="background:<?= $e['today_status'] === 'present' ? 'var(--rail)' : 'transparent' ?>;color:<?= $e['today_status'] === 'present' ? '#fff' : 'var(--rail)' ?>;border:1px solid var(--rail);border-radius:7px;font-size:11px;padding:4px 12px;">✓ हजर</button>
              <button type="submit" name="status" value="absent" class="btn btn-sm" style="background:<?= $e['today_status'] === 'absent' ? 'var(--rose)' : 'transparent' ?>;color:<?= $e['today_status'] === 'absent' ? '#fff' : 'var(--rose)' ?>;border:1px solid var(--rose);border-radius:7px;font-size:11px;padding:4px 12px;">✗ गैरहजर</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">सर्व विद्यार्थी</div></div>
  <table class="rtable">
    <thead><tr><th>नाव</th><th>संपर्क</th><th>पालक</th><th>काय शिकतोय</th><th>प्रवेश दिनांक</th></tr></thead>
    <tbody>
      <?php if (empty($students)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही विद्यार्थी नोंदवलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($students as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?></td>
          <td><?= h($s['contact'] ?: '—') ?></td>
          <td><?= h($s['guardian_name'] ?: '—') ?></td>
          <td style="font-size:11.5px;"><?= h($s['enrollments'] ?: '—') ?></td>
          <td><?= date('d M Y', strtotime($s['admission_date'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
