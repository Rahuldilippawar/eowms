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
    if (($_POST['action'] ?? '') === 'book') {
        $locationId = (int)($_POST['location_id'] ?? 0);
        $bookingDate = $_POST['booking_date'] ?? '';
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';
        $purpose = trim($_POST['purpose'] ?? '');

        // खोली याच संस्थेची, तारीख/वेळ योग्य — संपण्याची वेळ सुरुवातीनंतरच
        $locChk = $pdo->prepare("SELECT COUNT(*) FROM maintenance_locations WHERE id = ? AND organization_id = ? AND is_active = 1");
        $locChk->execute([$locationId, $orgId]);
        $validTimes = preg_match('/^\d{4}-\d{2}-\d{2}$/', $bookingDate) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $startTime) && preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $endTime);
        if (!(int)$locChk->fetchColumn() || !$validTimes || strtotime($endTime) <= strtotime($startTime)) {
            $_SESSION['form_error'] = 'कृपया योग्य खोली, दिनांक व वेळ निवडा — संपण्याची वेळ सुरू होण्याच्या वेळेनंतर हवी.';
            header('Location: room-booking.php');
            exit;
        }

        // वेळेचा संघर्ष (Conflict) आहे का तपासतो
        $conflictStmt = $pdo->prepare("
            SELECT COUNT(*) c FROM music_room_bookings
            WHERE location_id = ? AND booking_date = ? AND status = 'confirmed'
              AND start_time < ? AND end_time > ?
        ");
        $conflictStmt->execute([$locationId, $bookingDate, $endTime, $startTime]);
        $hasConflict = $conflictStmt->fetch()['c'] > 0;

        if ($hasConflict) {
            $_SESSION['form_error'] = 'या वेळेत ही खोली आधीच Book झालेली आहे — कृपया दुसरी वेळ निवडा.';
        } elseif ($locationId && $bookingDate && $startTime && $endTime) {
            $pdo->prepare("INSERT INTO music_room_bookings (organization_id, location_id, booked_by, booking_date, start_time, end_time, purpose) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $locationId, $user['id'], $bookingDate, $startTime, $endTime, $purpose]);
            logActivity('सराव-खोली Book केली', 'music_room_booking', $pdo->lastInsertId());
            $_SESSION['flash'] = 'Booking यशस्वी झाली.';
        }
    } elseif (($_POST['action'] ?? '') === 'cancel') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $pdo->prepare("UPDATE music_room_bookings SET status = 'cancelled' WHERE id = ? AND booked_by = ?")->execute([$bookingId, $user['id']]);
        $_SESSION['flash'] = 'Booking रद्द केली.';
    }
    header('Location: room-booking.php');
    exit;
}

$pageTitle = 'सराव-खोली Booking';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$locStmt = $pdo->prepare("SELECT ml.*, b.name AS building_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.organization_id = ? AND ml.is_active = 1 ORDER BY b.name, ml.name");
$locStmt->execute([$orgId]);
$locations = $locStmt->fetchAll();

$bookingsStmt = $pdo->prepare("
    SELECT mb.*, ml.name AS room_name, b.name AS building_name, u.name AS booked_by_name
    FROM music_room_bookings mb JOIN maintenance_locations ml ON ml.id = mb.location_id LEFT JOIN buildings b ON b.id = ml.building_id LEFT JOIN users u ON u.id = mb.booked_by
    WHERE mb.organization_id = ? AND mb.status = 'confirmed' AND mb.booking_date >= CURDATE()
    ORDER BY mb.booking_date, mb.start_time
");
$bookingsStmt->execute([$orgId]);
$bookings = $bookingsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📅 सराव-खोली Booking</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संगीत विभाग</a>
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
  <div class="panel-head"><div class="title">नवीन Booking करा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="book">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">खोली</label>
        <select name="location_id" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($locations as $loc): ?><option value="<?= $loc['id'] ?>"><?= h($loc['building_name'] ? $loc['building_name'] . ' — ' : '') ?><?= h($loc['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><label class="form-label-custom">दिनांक</label><input type="date" name="booking_date" class="form-control-custom" value="<?= date('Y-m-d') ?>" min="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-2"><label class="form-label-custom">सुरुवात वेळ</label><input type="time" name="start_time" class="form-control-custom" required></div>
      <div class="col-md-2"><label class="form-label-custom">शेवट वेळ</label><input type="time" name="end_time" class="form-control-custom" required></div>
      <div class="col-md-2"><label class="form-label-custom">कारण</label><input type="text" name="purpose" class="form-control-custom" placeholder="उदा. तबला सराव"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Book करा</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">येणाऱ्या Bookings</div></div>
  <table class="rtable">
    <thead><tr><th>खोली</th><th>दिनांक</th><th>वेळ</th><th>कारण</th><th>कोणी Book केलं</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($bookings)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही Booking नाही</td></tr><?php endif; ?>
      <?php foreach ($bookings as $b): ?>
        <tr>
          <td style="font-weight:600;"><?= h($b['building_name'] ? $b['building_name'] . ' — ' : '') ?><?= h($b['room_name']) ?></td>
          <td><?= date('d M Y', strtotime($b['booking_date'])) ?></td>
          <td class="font-mono"><?= date('h:i A', strtotime($b['start_time'])) ?> - <?= date('h:i A', strtotime($b['end_time'])) ?></td>
          <td><?= h($b['purpose'] ?: '—') ?></td>
          <td><?= h($b['booked_by_name']) ?></td>
          <td>
            <?php if ((int)$b['booked_by'] === (int)$user['id']): ?>
              <form method="POST" onsubmit="return confirm('ही Booking रद्द करायची?');"><input type="hidden" name="action" value="cancel"><input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11px;padding:4px 10px;">रद्द करा</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
