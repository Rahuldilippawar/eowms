<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_health') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $bloodGroup = trim($_POST['blood_group'] ?? '');
        $allergies = trim($_POST['allergies'] ?? '');
        $medication = trim($_POST['regular_medication'] ?? '');
        $emgName = trim($_POST['emergency_contact_name'] ?? '');
        $emgPhone = trim($_POST['emergency_contact_phone'] ?? '');
        $notes = trim($_POST['medical_notes'] ?? '');

        $pdo->prepare("
            INSERT INTO hostel_student_health (student_id, blood_group, allergies, regular_medication, emergency_contact_name, emergency_contact_phone, medical_notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE blood_group=VALUES(blood_group), allergies=VALUES(allergies), regular_medication=VALUES(regular_medication), emergency_contact_name=VALUES(emergency_contact_name), emergency_contact_phone=VALUES(emergency_contact_phone), medical_notes=VALUES(medical_notes)
        ")->execute([$studentId, $bloodGroup, $allergies, $medication, $emgName, $emgPhone, $notes]);
        $_SESSION['flash'] = 'आरोग्य-माहिती जतन झाली.';
    } elseif ($action === 'add_incident') {
        $studentId = (int)($_POST['student_id'] ?? 0);
        $incidentDate = ($_POST['incident_date'] ?? '') ?: date('Y-m-d');
        $description = trim($_POST['description'] ?? '');
        $actionTaken = trim($_POST['action_taken'] ?? '');
        if ($studentId && $description) {
            $pdo->prepare("INSERT INTO hostel_health_incidents (student_id, incident_date, description, action_taken, reported_by) VALUES (?, ?, ?, ?, ?)")
                ->execute([$studentId, $incidentDate, $description, $actionTaken, $user['id']]);
            $_SESSION['flash'] = 'नोंद जतन झाली.';
        }
    }
    header('Location: health.php?student_id=' . ($_POST['student_id'] ?? ''));
    exit;
}

$pageTitle = 'आरोग्य/वैद्यकीय नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$studentsStmt = $pdo->prepare("SELECT hs.id, hs.name, h.name AS hostel_name FROM hostel_students hs JOIN hostels h ON h.id = hs.hostel_id WHERE h.organization_id = ? AND hs.is_active = 1 ORDER BY hs.name");
$studentsStmt->execute([$orgId]);
$students = $studentsStmt->fetchAll();

$selectedId = (int)($_GET['student_id'] ?? ($students[0]['id'] ?? 0));
$health = null;
$incidents = [];
if ($selectedId) {
    $hStmt = $pdo->prepare("SELECT * FROM hostel_student_health WHERE student_id = ?");
    $hStmt->execute([$selectedId]);
    $health = $hStmt->fetch();

    $iStmt = $pdo->prepare("SELECT i.*, u.name AS reporter_name FROM hostel_health_incidents i LEFT JOIN users u ON u.id = i.reported_by WHERE student_id = ? ORDER BY incident_date DESC");
    $iStmt->execute([$selectedId]);
    $incidents = $iStmt->fetchAll();
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏥 आरोग्य/वैद्यकीय नोंद</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वसतिगृह</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="mb-3">
  <select name="student_id" class="form-select-custom" style="max-width:320px;" onchange="this.form.submit()">
    <?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>" <?= $selectedId === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?> (<?= h($s['hostel_name']) ?>)</option><?php endforeach; ?>
  </select>
</form>

<?php if ($selectedId): ?>
<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">मूलभूत आरोग्य-माहिती</div></div>
      <form method="POST" style="padding:18px 24px;">
        <input type="hidden" name="action" value="save_health"><input type="hidden" name="student_id" value="<?= $selectedId ?>">
        <div class="row g-2 mb-2">
          <div class="col-4"><label class="form-label-custom">रक्तगट</label><input type="text" name="blood_group" class="form-control-custom" value="<?= h($health['blood_group'] ?? '') ?>" placeholder="उदा. O+"></div>
          <div class="col-8"><label class="form-label-custom">Emergency संपर्क नाव</label><input type="text" name="emergency_contact_name" class="form-control-custom" value="<?= h($health['emergency_contact_name'] ?? '') ?>"></div>
        </div>
        <label class="form-label-custom">Emergency फोन</label>
        <input type="text" name="emergency_contact_phone" class="form-control-custom mb-2" value="<?= h($health['emergency_contact_phone'] ?? '') ?>">
        <label class="form-label-custom">Allergy</label>
        <textarea name="allergies" class="form-control-custom mb-2" rows="2"><?= h($health['allergies'] ?? '') ?></textarea>
        <label class="form-label-custom">नियमित औषधं</label>
        <textarea name="regular_medication" class="form-control-custom mb-2" rows="2"><?= h($health['regular_medication'] ?? '') ?></textarea>
        <label class="form-label-custom">इतर टिप्पणी</label>
        <textarea name="medical_notes" class="form-control-custom mb-3" rows="2"><?= h($health['medical_notes'] ?? '') ?></textarea>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;">जतन करा</button>
      </form>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">आजारपण/घटनांची नोंद</div></div>
      <form method="POST" style="padding:18px 24px;">
        <input type="hidden" name="action" value="add_incident"><input type="hidden" name="student_id" value="<?= $selectedId ?>">
        <div class="row g-2 mb-2">
          <div class="col-4"><input type="date" name="incident_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-8"><input type="text" name="description" class="form-control-custom" placeholder="काय झालं" required></div>
        </div>
        <textarea name="action_taken" class="form-control-custom mb-2" rows="2" placeholder="काय उपाययोजना केली"></textarea>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;">नोंदवा</button>
      </form>
      <div style="max-height:280px;overflow-y:auto;padding:0 24px 16px;">
        <?php if (empty($incidents)): ?><p style="font-size:12px;color:var(--text-dim);">कुठलीही नोंद नाही</p><?php endif; ?>
        <?php foreach ($incidents as $inc): ?>
          <div style="border-left:2px solid var(--rail);padding:6px 0 6px 12px;margin-bottom:8px;">
            <div style="font-size:12.5px;font-weight:600;"><?= date('d M Y', strtotime($inc['incident_date'])) ?> — <?= h($inc['description']) ?></div>
            <?php if ($inc['action_taken']): ?><div style="font-size:11px;color:var(--text-dim);">उपाय: <?= h($inc['action_taken']) ?></div><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
