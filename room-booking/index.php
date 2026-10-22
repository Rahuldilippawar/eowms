<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'bookable_rooms', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManageRooms = hasPermission('manage_room_booking') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_room' && $canManageRooms) {
        $name = trim($_POST['name'] ?? '');
        $campusId = (int)($_POST['campus_id'] ?? 0);
        $roomType = trim($_POST['room_type'] ?? '') ?: null;
        $capacity = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;
        if ($name && $campusId) {
            $pdo->prepare("INSERT INTO bookable_rooms (organization_id, campus_id, name, room_type, capacity, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $name, $roomType, $capacity, $user['id']]);
            $_SESSION['flash'] = 'नवीन Room जोडलं गेलं.';
        }
    } elseif ($action === 'book') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $purpose = trim($_POST['purpose'] ?? '');
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';

        if ($roomId && $purpose && $startTime && $endTime && strtotime($endTime) > strtotime($startTime)) {
            // Conflict-Check — याच वेळी दुसरं कोणी Book केलंय का
            $conflictStmt = $pdo->prepare("
                SELECT COUNT(*) c FROM room_bookings
                WHERE room_id = ? AND status = 'confirmed' AND start_time < ? AND end_time > ?
            ");
            $conflictStmt->execute([$roomId, $endTime, $startTime]);
            if ($conflictStmt->fetch()['c'] > 0) {
                $_SESSION['flash'] = '⚠️ या वेळेत हे Room आधीच Book झालेलं आहे — दुसरी वेळ निवडा.';
            } else {
                $pdo->prepare("INSERT INTO room_bookings (room_id, booked_by, purpose, start_time, end_time) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$roomId, $user['id'], $purpose, $startTime, $endTime]);
                $_SESSION['flash'] = 'Booking झालं.';
            }
        } else {
            $_SESSION['flash'] = '⚠️ कृपया सगळी माहिती बरोबर भरा — End Time, Start Time नंतरचं हवं.';
        }
    } elseif ($action === 'cancel_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        $checkStmt = $pdo->prepare("SELECT booked_by FROM room_bookings WHERE id = ?");
        $checkStmt->execute([$bookingId]);
        $booking = $checkStmt->fetch();
        if ($booking && ((int)$booking['booked_by'] === (int)$user['id'] || $canManageRooms)) {
            $pdo->prepare("UPDATE room_bookings SET status = 'cancelled' WHERE id = ?")->execute([$bookingId]);
            $_SESSION['flash'] = 'Booking रद्द झालं.';
        }
    } elseif ($action === 'delete_room' && $canManageRooms) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $pdo->prepare("DELETE FROM bookable_rooms WHERE id = ? AND organization_id = ?")->execute([$roomId, $orgId]);
        $_SESSION['flash'] = 'Room काढलं गेलं.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'Room/Hall Booking';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$selectedDate = $_GET['date'] ?? date('Y-m-d');

$roomsStmt = $pdo->prepare("SELECT br.*, c.name AS campus_name FROM bookable_rooms br JOIN campuses c ON c.id = br.campus_id WHERE br.organization_id = ? AND br.is_active = 1 ORDER BY br.name");
$roomsStmt->execute([$orgId]);
$rooms = $roomsStmt->fetchAll();

$bookingsStmt = $pdo->prepare("
    SELECT rb.*, br.name AS room_name, u.name AS booker_name
    FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id JOIN users u ON u.id = rb.booked_by
    WHERE br.organization_id = ? AND rb.status = 'confirmed' AND DATE(rb.start_time) = ? ORDER BY rb.start_time
");
$bookingsStmt->execute([$orgId, $selectedDate]);
$bookings = $bookingsStmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📐 Room/Hall Booking</div>
  <div class="d-flex gap-2">
    <?php if ($canManageRooms): ?><button type="button" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;" data-bs-toggle="modal" data-bs-target="#addRoomModal">+ नवीन Room</button><?php endif; ?>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#bookModal">+ Book करा</button>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Meeting Room, Auditorium, Lab — Double-Booking आपोआप टाळलं जातं</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<form method="GET" class="mb-4">
  <input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($selectedDate) ?>" onchange="this.form.submit()">
</form>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel">
      <div class="panel-head"><div class="title">📅 <?= date('d M Y', strtotime($selectedDate)) ?> च्या Bookings</div></div>
      <table class="rtable">
        <thead><tr><th>Room</th><th>वेळ</th><th>कारण</th><th>कोणी Book केलं</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($bookings)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">या दिवशी कुठलंही Booking नाही</td></tr><?php endif; ?>
          <?php foreach ($bookings as $b): ?>
            <tr>
              <td style="font-weight:600;"><?= h($b['room_name']) ?></td>
              <td class="font-mono"><?= date('h:i A', strtotime($b['start_time'])) ?> - <?= date('h:i A', strtotime($b['end_time'])) ?></td>
              <td><?= h($b['purpose']) ?></td>
              <td><?= h($b['booker_name']) ?></td>
              <td>
                <?php if ((int)$b['booked_by'] === (int)$user['id'] || $canManageRooms): ?>
                  <form method="POST" onsubmit="return confirm('रद्द करायचं?')"><input type="hidden" name="action" value="cancel_booking"><input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                    <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">रद्द करा</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">🏢 सगळे Rooms</div></div>
      <?php foreach ($rooms as $r): ?>
        <div style="padding:11px 18px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
          <div>
            <div style="font-size:13px;font-weight:600;"><?= h($r['name']) ?></div>
            <div style="font-size:10.5px;color:var(--text-dim);"><?= h($r['room_type'] ?: '') ?><?= $r['capacity'] ? ' · ' . $r['capacity'] . ' जण' : '' ?> · <?= h($r['campus_name']) ?></div>
          </div>
          <?php if ($canManageRooms): ?>
            <form method="POST" onsubmit="return confirm('काढायचं?')"><input type="hidden" name="action" value="delete_room"><input type="hidden" name="room_id" value="<?= $r['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($rooms)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 18px;">अजून कुठलंही Room जोडलेलं नाही</p><?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canManageRooms): ?>
<div class="modal fade" id="addRoomModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_room">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Room जोडा</div>
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">प्रकार</label>
        <input type="text" name="room_type" class="form-control-custom mb-2" placeholder="उदा. Meeting Room, Auditorium">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label-custom">College/School</label><select name="campus_id" class="form-select-custom" required><option value="">-- निवडा --</option><?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label-custom">क्षमता</label><input type="number" name="capacity" class="form-control-custom"></div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<div class="modal fade" id="bookModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="book">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Room Book करा</div>
        <label class="form-label-custom">Room</label>
        <select name="room_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['name']) ?> (<?= h($r['campus_name']) ?>)</option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">कारण</label>
        <input type="text" name="purpose" class="form-control-custom mb-2" required>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label-custom">सुरू</label><input type="datetime-local" name="start_time" class="form-control-custom" required></div>
          <div class="col-md-6"><label class="form-label-custom">संपेल</label><input type="datetime-local" name="end_time" class="form-control-custom" required></div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Book करा</button>
      </div>
    </form>
  </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
