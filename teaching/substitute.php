<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();
if (!$canManage) { http_response_code(403); die('परवानगी नाही — हे फक्त व्यवस्थापनासाठी आहे.'); }

$fy = date('Y') . '-' . substr(date('Y') + 1, 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'assign') {
        $originalTimetableId = (int)($_POST['original_timetable_id'] ?? 0);
        $originalTeacherId = (int)($_POST['original_teacher_id'] ?? 0);
        $substituteTeacherId = (int)($_POST['substitute_teacher_id'] ?? 0);
        $date = $_POST['substitute_date'] ?? date('Y-m-d');
        $periodNo = (int)($_POST['period_no'] ?? 0);
        $subject = trim($_POST['subject_name'] ?? '');
        $classSection = trim($_POST['class_section'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if ($originalTeacherId && $substituteTeacherId && $subject) {
            $pdo->prepare("
                INSERT INTO substitute_assignments (organization_id, original_timetable_id, original_teacher_id, substitute_teacher_id, substitute_date, period_no, subject_name, class_section, reason, assigned_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$orgId, $originalTimetableId ?: null, $originalTeacherId, $substituteTeacherId, $date, $periodNo, $subject, $classSection, $reason, $user['id']]);
            $newId = $pdo->lastInsertId();
            notifyUser($substituteTeacherId, $orgId, '🔄 Substitute Class नेमून दिला', "$subject — $classSection — " . date('d M', strtotime($date)), 'teaching/substitute.php');
            logActivity('Substitute Class नेमला', 'substitute_assignments', $newId, $subject);
            $_SESSION['flash'] = 'Substitute Assign झाला.';
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("DELETE FROM substitute_assignments WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        $_SESSION['flash'] = 'काढलं गेलं.';
    }
    header('Location: substitute.php?date=' . urlencode($_GET['date'] ?? date('Y-m-d')));
    exit;
}

$pageTitle = 'Substitute / Cover-Class';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$selectedDate = $_GET['date'] ?? date('Y-m-d');
$dayOfWeek = (int)date('N', strtotime($selectedDate)) + 1; // PHP N: 1=Mon..7=Sun -> आपला day_of_week: 1=Sun..7=Sat
if ($dayOfWeek === 8) { $dayOfWeek = 1; }

// आज कोण-कोणाला Class आहेत (या दिवसाचं Timetable)
$scheduledStmt = $pdo->prepare("
    SELECT tt.*, u.name AS teacher_name
    FROM teacher_timetable tt JOIN users u ON u.id = tt.teacher_id
    WHERE tt.organization_id = ? AND tt.day_of_week = ? AND tt.academic_year = ?
    ORDER BY tt.period_no, u.name
");
$scheduledStmt->execute([$orgId, $dayOfWeek, $fy]);
$scheduled = $scheduledStmt->fetchAll();

// आज कोणाला आधीच Substitute नेमलेला आहे
$existingSubStmt = $pdo->prepare("
    SELECT sa.*, ot.name AS original_name, st.name AS substitute_name
    FROM substitute_assignments sa
    JOIN users ot ON ot.id = sa.original_teacher_id JOIN users st ON st.id = sa.substitute_teacher_id
    WHERE sa.organization_id = ? AND sa.substitute_date = ?
");
$existingSubStmt->execute([$orgId, $selectedDate]);
$existingSubs = [];
foreach ($existingSubStmt->fetchAll() as $es) { $existingSubs[$es['period_no'] . '|' . $es['original_teacher_id']] = $es; }

$allTeachersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND teaching_mode = 'Teaching' AND is_active = 1 ORDER BY name");
$allTeachersStmt->execute([$orgId]);
$allTeachers = $allTeachersStmt->fetchAll();
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔄 Substitute / Cover-Class</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="mb-4">
  <input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($selectedDate) ?>" onchange="this.form.submit()">
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Period</th><th>मूळ शिक्षक</th><th>विषय</th><th>वर्ग</th><th>Substitute</th></tr></thead>
    <tbody>
      <?php if (empty($scheduled)): ?><tr><td colspan="5"><div class="empty-state"><div class="icon"><i class="fa-solid fa-calendar-day"></i></div><div class="title">या दिवशी कुठलंही Timetable Entry नाही</div></div></td></tr><?php endif; ?>
      <?php foreach ($scheduled as $s):
        $key = $s['period_no'] . '|' . $s['teacher_id'];
        $existing = $existingSubs[$key] ?? null;
      ?>
        <tr>
          <td style="font-weight:700;">P<?= $s['period_no'] ?></td>
          <td><?= h($s['teacher_name']) ?></td>
          <td><?= h($s['subject_name']) ?></td>
          <td><?= h($s['class_section']) ?></td>
          <td>
            <?php if ($existing): ?>
              <span class="status-chip done">✅ <?= h($existing['substitute_name']) ?></span>
              <form method="POST" style="display:inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $existing['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕</button>
              </form>
            <?php else: ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--amber);color:var(--amber);border-radius:7px;font-size:11px;padding:5px 12px;"
                data-bs-toggle="modal" data-bs-target="#assignModal"
                onclick="document.getElementById('subOrigTeacher').value='<?= $s['teacher_id'] ?>';document.getElementById('subOrigTt').value='<?= $s['id'] ?>';document.getElementById('subPeriod').value='<?= $s['period_no'] ?>';document.getElementById('subSubject').value='<?= h($s['subject_name']) ?>';document.getElementById('subClass').value='<?= h($s['class_section']) ?>';loadFreeTeachers(<?= $s['period_no'] ?>, <?= $s['teacher_id'] ?>);">
                + Substitute नेमा
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="assignModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="assign">
      <input type="hidden" name="original_teacher_id" id="subOrigTeacher">
      <input type="hidden" name="original_timetable_id" id="subOrigTt">
      <input type="hidden" name="period_no" id="subPeriod">
      <input type="hidden" name="subject_name" id="subSubject">
      <input type="hidden" name="class_section" id="subClass">
      <input type="hidden" name="substitute_date" value="<?= h($selectedDate) ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Substitute नेमा</div>
        <label class="form-label-custom">या Period ला — कोण Free आहे</label>
        <select name="substitute_teacher_id" id="freeTeachersSelect" class="form-select-custom mb-2" required>
          <option value="">लोड होत आहे...</option>
        </select>
        <label class="form-label-custom">कारण (ऐच्छिक)</label>
        <input type="text" name="reason" class="form-control-custom" placeholder="उदा. रजेवर आहेत">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">नेमा</button>
      </div>
    </form>
  </div></div>
</div>

<script>
const allTeachers = <?= json_encode($allTeachers, JSON_UNESCAPED_UNICODE) ?>;
function loadFreeTeachers(periodNo, excludeTeacherId) {
  fetch('ajax-free-teachers.php?period_no=' + periodNo + '&day_of_week=<?= $dayOfWeek ?>&exclude=' + excludeTeacherId + '&date=<?= h($selectedDate) ?>')
    .then(function (r) { return r.json(); })
    .then(function (data) {
      const sel = document.getElementById('freeTeachersSelect');
      sel.innerHTML = '<option value="">-- निवडा --</option>';
      (data.free || []).forEach(function (t) {
        const o = document.createElement('option'); o.value = t.id; o.textContent = t.name + ' (Free)';
        sel.appendChild(o);
      });
      (data.busy || []).forEach(function (t) {
        const o = document.createElement('option'); o.value = t.id; o.textContent = t.name + ' (या वेळी व्यस्त — तरी निवडता येईल)';
        o.style.color = '#B23A48';
        sel.appendChild(o);
      });
    });
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
