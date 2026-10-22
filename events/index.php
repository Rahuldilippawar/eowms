<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_events') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $eventDate = $_POST['event_date'] ?? '';
    $startTime = ($_POST['start_time'] ?? '') ?: null;
    $endTime = ($_POST['end_time'] ?? '') ?: null;
    $venue = trim($_POST['venue'] ?? '') ?: null;
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $responsibleUserId = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;
    $budgetEstimated = ($_POST['budget_estimated'] ?? '') !== '' ? (float)$_POST['budget_estimated'] : null;

    if ($name && $eventDate) {
        $pdo->prepare("
            INSERT INTO events (organization_id, campus_id, name, description, event_date, start_time, end_time, venue, responsible_user_id, budget_estimated, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$orgId, $campusId, $name, $description, $eventDate, $startTime, $endTime, $venue, $responsibleUserId, $budgetEstimated, $user['id']]);
        $newEventId = $pdo->lastInsertId();
        if ($responsibleUserId) {
            notifyUser($responsibleUserId, $orgId, '🎉 नवीन कार्यक्रमाची जबाबदारी', "$name — " . date('d M Y', strtotime($eventDate)), 'events/view.php?id=' . $newEventId);
        }
        logActivity('कार्यक्रम तयार केला', 'events', $newEventId, $name);
        $_SESSION['flash'] = 'कार्यक्रम तयार झाला.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'कार्यक्रम व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$statusFilter = $_GET['status'] ?? 'all';
$sql = "
    SELECT e.*, c.name AS campus_name, u.name AS responsible_name
    FROM events e LEFT JOIN campuses c ON c.id = e.campus_id LEFT JOIN users u ON u.id = e.responsible_user_id
    WHERE e.organization_id = ?
";
$params = [$orgId];
if ($statusFilter !== 'all') { $sql .= " AND e.status = ?"; $params[] = $statusFilter; }
$sql .= " ORDER BY e.event_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$events = $stmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campusesForEvent = $campusesStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaffForEvent = $staffStmt->fetchAll();

$statusLabels = ['planned' => '📅 नियोजित', 'ongoing' => '🔴 सुरू आहे', 'completed' => '✅ पूर्ण झाला', 'cancelled' => '❌ रद्द'];
$statusChips = ['planned' => 'pending', 'ongoing' => 'pending', 'completed' => 'done', 'cancelled' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎉 कार्यक्रम व्यवस्थापन</div>
  <?php if ($canManage): ?>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#createEventModal">+ नवीन कार्यक्रम</button>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex gap-2 mb-4">
  <?php foreach (['all' => 'सगळे'] + $statusLabels as $val => $lbl): ?>
    <a href="?status=<?= $val ?>" class="btn" style="background:<?= $statusFilter === $val ? 'var(--rail)' : '#fff' ?>;color:<?= $statusFilter === $val ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <?php if (empty($events)): ?><div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कुठलाही कार्यक्रम नाही</div></div><?php endif; ?>
  <?php foreach ($events as $e): ?>
    <div class="col-md-4">
      <a href="view.php?id=<?= $e['id'] ?>" class="panel" style="display:block;padding:18px;text-decoration:none;color:inherit;">
        <div class="d-flex justify-content-between align-items-start mb-1">
          <div style="font-weight:700;font-size:14px;"><?= h($e['name']) ?></div>
          <span class="status-chip <?= $statusChips[$e['status']] ?>" style="font-size:10px;"><?= $statusLabels[$e['status']] ?></span>
        </div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:8px;">📅 <?= date('d M Y', strtotime($e['event_date'])) ?><?= $e['venue'] ? ' · 📍 ' . h($e['venue']) : '' ?></div>
        <?php if ($e['responsible_name']): ?><div style="font-size:12px;">👤 <?= h($e['responsible_name']) ?></div><?php endif; ?>
        <?php if ($e['campus_name']): ?><div style="font-size:11px;color:var(--text-dim);margin-top:4px;">🏫 <?= h($e['campus_name']) ?></div><?php endif; ?>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="createEventModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन कार्यक्रम तयार करा</div>
        <label class="form-label-custom">कार्यक्रमाचं नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">वर्णन</label>
        <textarea name="description" class="form-control-custom mb-2" rows="2"></textarea>
        <div class="row g-2 mb-2">
          <div class="col-md-4"><label class="form-label-custom">दिनांक</label><input type="date" name="event_date" class="form-control-custom" required></div>
          <div class="col-md-4"><label class="form-label-custom">सुरू</label><input type="time" name="start_time" class="form-control-custom"></div>
          <div class="col-md-4"><label class="form-label-custom">संपेल</label><input type="time" name="end_time" class="form-control-custom"></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">ठिकाण</label><input type="text" name="venue" class="form-control-custom"></div>
          <div class="col-md-6">
            <label class="form-label-custom">College/School</label>
            <select name="campus_id" class="form-select-custom">
              <option value="">-- सगळ्यांसाठी --</option>
              <?php foreach ($campusesForEvent as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label-custom">जबाबदार व्यक्ती</label>
            <select name="responsible_user_id" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaffForEvent as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label-custom">अंदाजित Budget</label><input type="number" step="0.01" name="budget_estimated" class="form-control-custom"></div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">तयार करा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
