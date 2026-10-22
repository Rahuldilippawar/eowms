<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_departed') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $departureDate = ($_POST['departure_date'] ?? '') ?: date('Y-m-d');
        $departureReason = trim($_POST['departure_reason'] ?? '');
        $currentStatus = trim($_POST['current_status'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($studentId) {
            try {
                $pdo->prepare("INSERT INTO hostel_alumni (student_id, departure_date, departure_reason, current_status, contact_number, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$studentId, $departureDate, $departureReason, $currentStatus, $contactNumber, $notes, $user['id']]);
                $pdo->prepare("UPDATE hostel_students SET is_active = 0 WHERE id = ?")->execute([$studentId]);
                $pdo->prepare("UPDATE hostel_room_allocations SET status = 'vacated', vacated_date = ? WHERE student_id = ? AND status = 'active'")->execute([$departureDate, $studentId]);
                logActivity('विद्यार्थी वसतिगृह सोडला (Alumni)', 'hostel_alumni', $studentId);
                $_SESSION['flash'] = 'नोंदवलं गेलं — विद्यार्थी आता माजी-विद्यार्थी यादीत आहे.';
            } catch (PDOException $e) {
                $_SESSION['form_error'] = 'ही नोंद आधीच अस्तित्वात आहे.';
            }
        }
    } elseif ($action === 'update_alumni') {
        $alumniId = (int)($_POST['alumni_id'] ?? 0);
        $currentStatus = trim($_POST['current_status'] ?? '');
        $contactNumber = trim($_POST['contact_number'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $pdo->prepare("UPDATE hostel_alumni SET current_status = ?, contact_number = ?, notes = ? WHERE id = ?")
            ->execute([$currentStatus, $contactNumber, $notes, $alumniId]);
        $_SESSION['flash'] = 'माहिती अद्ययावत केली.';
    }
    header('Location: alumni.php');
    exit;
}

$pageTitle = 'माजी-विद्यार्थी (Alumni)';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$activeStudentsStmt = $pdo->prepare("SELECT hs.id, hs.name, h.name AS hostel_name FROM hostel_students hs JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ? AND hs.is_active = 1 ORDER BY hs.name");
$activeStudentsStmt->execute([$orgId]);
$activeStudents = $activeStudentsStmt->fetchAll();

$alumniStmt = $pdo->prepare("
    SELECT a.*, hs.name AS student_name, hs.school_name, h.name AS hostel_name
    FROM hostel_alumni a JOIN hostel_students hs ON hs.id = a.student_id JOIN hostels h ON h.id = hs.hostel_id
    WHERE h.organization_id = ? ORDER BY a.departure_date DESC
");
$alumniStmt->execute([$orgId]);
$alumni = $alumniStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎓 माजी-विद्यार्थी (Alumni)</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वसतिगृह</a>
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
  <div class="panel-head"><div class="title">विद्यार्थ्याने वसतिगृह सोडलं — नोंदवा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="mark_departed">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">विद्यार्थी</label>
        <select name="student_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($activeStudents as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['hostel_name']) ?>)</option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label-custom">सोडल्याचा दिनांक</label><input type="date" name="departure_date" class="form-control-custom" value="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-3"><label class="form-label-custom">कारण</label><input type="text" name="departure_reason" class="form-control-custom" placeholder="उदा. शिक्षण पूर्ण झालं"></div>
      <div class="col-md-3"><label class="form-label-custom">संपर्क क्रमांक</label><input type="text" name="contact_number" class="form-control-custom"></div>
      <div class="col-12"><label class="form-label-custom">सध्या काय करतो/करते</label><input type="text" name="current_status" class="form-control-custom" placeholder="उदा. महाविद्यालयात शिक्षण घेत आहे"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">नोंदवा</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">माजी-विद्यार्थी यादी (<?= count($alumni) ?>)</div></div>
  <table class="rtable">
    <thead><tr><th>नाव</th><th>Hostel</th><th>सोडल्याचा दिनांक</th><th>कारण</th><th>सध्याची स्थिती</th><th>संपर्क</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($alumni)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($alumni as $a): ?>
        <tr>
          <td style="font-weight:600;"><?= h($a['student_name']) ?><?php if ($a['school_name']): ?><div style="font-size:10px;color:var(--text-dim);"><?= h($a['school_name']) ?></div><?php endif; ?></td>
          <td><?= h($a['hostel_name']) ?></td>
          <td><?= date('d M Y', strtotime($a['departure_date'])) ?></td>
          <td><?= h($a['departure_reason'] ?: '—') ?></td>
          <td><?= h($a['current_status'] ?: '—') ?></td>
          <td><?= h($a['contact_number'] ?: '—') ?></td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#updateModal<?= $a['id'] ?>">Update</button>
          </td>
        </tr>
        <div class="modal fade" id="updateModal<?= $a['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="action" value="update_alumni"><input type="hidden" name="alumni_id" value="<?= $a['id'] ?>">
              <div class="modal-body" style="padding:20px;">
                <div style="font-weight:600;margin-bottom:10px;"><?= h($a['student_name']) ?> — माहिती Update करा</div>
                <label class="form-label-custom">सध्याची स्थिती</label>
                <input type="text" name="current_status" class="form-control-custom mb-2" value="<?= h($a['current_status']) ?>">
                <label class="form-label-custom">संपर्क</label>
                <input type="text" name="contact_number" class="form-control-custom mb-2" value="<?= h($a['contact_number']) ?>">
                <label class="form-label-custom">टिप्पणी</label>
                <textarea name="notes" class="form-control-custom" rows="2"><?= h($a['notes']) ?></textarea>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
