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
        // Campus याच संस्थेचा असावा
        $campusCheck = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
        $campusCheck->execute([$campusId, $orgId]);
        if (!$campusCheck->fetch()) {
            $_SESSION['flash'] = '⚠️ निवडलेलं College/Campus सापडलं नाही.';
        } elseif ($name) {
            $pdo->prepare("INSERT INTO bookable_rooms (organization_id, campus_id, name, room_type, capacity, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $name, $roomType, $capacity, $user['id']]);
            $_SESSION['flash'] = 'नवीन Room जोडलं गेलं.';
        }
    } elseif ($action === 'book') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $purpose = trim($_POST['purpose'] ?? '');
        $startTime = $_POST['start_time'] ?? '';
        $endTime = $_POST['end_time'] ?? '';

        // वेळ — datetime-local (Y-m-d\TH:i) स्वरूपात; शेवट सुरुवातीनंतर व सुरुवात भूतकाळात नसावी
        $parseTime = function ($t) {
            $d = DateTime::createFromFormat('Y-m-d\TH:i', $t) ?: DateTime::createFromFormat('Y-m-d H:i:s', $t) ?: DateTime::createFromFormat('Y-m-d H:i', $t);
            return $d ? $d->format('Y-m-d H:i:s') : null;
        };
        $startTime = $parseTime($startTime);
        $endTime = $parseTime($endTime);

        // Room याच संस्थेचं व सक्रिय असावं
        $roomCheck = $pdo->prepare("SELECT id FROM bookable_rooms WHERE id = ? AND organization_id = ? AND is_active = 1");
        $roomCheck->execute([$roomId, $orgId]);
        $roomOk = (bool)$roomCheck->fetch();

        if (!$roomOk) {
            $_SESSION['flash'] = '⚠️ निवडलेलं Room सापडलं नाही.';
        } elseif (!$purpose || !$startTime || !$endTime || $endTime <= $startTime) {
            $_SESSION['flash'] = '⚠️ कृपया सगळी माहिती बरोबर भरा — End Time, Start Time नंतरचं हवं.';
        } elseif ($startTime < date('Y-m-d H:i:00')) {
            $_SESSION['flash'] = '⚠️ मागच्या (भूतकाळातील) वेळेसाठी Booking करता येत नाही.';
        } else {
            // 🔁 पुनरावृत्ती — दररोज / दर आठवड्याला, जास्तीत जास्त 12 वेळा
            $repeat = in_array($_POST['repeat'] ?? '', ['daily', 'weekly'], true) ? $_POST['repeat'] : '';
            $times = $repeat ? max(1, min(12, (int)($_POST['repeat_count'] ?? 1))) : 1;
            $step = $repeat === 'daily' ? '+1 day' : '+1 week';
            // Conflict-Check व Insert एकाच Transaction मध्ये — Room-Row Lock (FOR UPDATE) मुळे एकाच वेळी दोघांचं Booking होत नाही
            $pdo->beginTransaction();
            try {
                $pdo->prepare("SELECT id FROM bookable_rooms WHERE id = ? FOR UPDATE")->execute([$roomId]);
                $conflictStmt = $pdo->prepare("
                    SELECT COUNT(*) c FROM room_bookings
                    WHERE room_id = ? AND status = 'confirmed' AND start_time < ? AND end_time > ?
                    FOR UPDATE
                ");
                $insStmt = $pdo->prepare("INSERT INTO room_bookings (room_id, booked_by, purpose, start_time, end_time) VALUES (?, ?, ?, ?, ?)");
                $made = 0; $clash = [];
                for ($k = 0; $k < $times; $k++) {
                    $s1 = $k ? date('Y-m-d H:i:s', strtotime($startTime . ' ' . str_replace('1', (string)$k, $step))) : $startTime;
                    $e1 = $k ? date('Y-m-d H:i:s', strtotime($endTime . ' ' . str_replace('1', (string)$k, $step))) : $endTime;
                    $conflictStmt->execute([$roomId, $e1, $s1]);
                    if ($conflictStmt->fetch()['c'] > 0) { $clash[] = date('d M', strtotime($s1)); continue; }
                    $insStmt->execute([$roomId, $user['id'], $purpose, $s1, $e1]);
                    $made++;
                }
                $pdo->commit();
                if (!$made) {
                    $_SESSION['flash'] = '⚠️ या वेळेत हे Room आधीच Book झालेलं आहे — दुसरी वेळ निवडा.';
                } else {
                    $_SESSION['flash'] = $made > 1 ? "Booking झालं — $made वेळा." : 'Booking झालं.';
                    if ($clash) $_SESSION['flash'] .= ' ⚠️ आधीच Book असल्यामुळे वगळलं: ' . implode(', ', $clash);
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $_SESSION['flash'] = '⚠️ Booking करता आलं नाही — पुन्हा प्रयत्न करा.';
            }
        }
    } elseif ($action === 'cancel_booking') {
        $bookingId = (int)($_POST['booking_id'] ?? 0);
        // फक्त याच संस्थेच्या Room चं Booking
        $checkStmt = $pdo->prepare("SELECT rb.booked_by FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id WHERE rb.id = ? AND br.organization_id = ? AND rb.status = 'confirmed'");
        $checkStmt->execute([$bookingId, $orgId]);
        $booking = $checkStmt->fetch();
        if ($booking && ((int)$booking['booked_by'] === (int)$user['id'] || $canManageRooms)) {
            $pdo->prepare("UPDATE room_bookings SET status = 'cancelled' WHERE id = ?")->execute([$bookingId]);
            $_SESSION['flash'] = 'Booking रद्द झालं.';
        } else {
            $_SESSION['flash'] = '⚠️ Booking सापडलं नाही किंवा रद्द करण्याची परवानगी नाही.';
        }
    } elseif ($action === 'delete_room' && $canManageRooms) {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $pdo->prepare("DELETE FROM bookable_rooms WHERE id = ? AND organization_id = ?")->execute([$roomId, $orgId]);
        $_SESSION['flash'] = 'Room काढलं गेलं.';
    }
    header('Location: index.php' . (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['back_date'] ?? '') ? '?date=' . $_POST['back_date'] : ''));
    exit;
}

// 📊 Excel — कालावधीतील सगळी Bookings
if (($_GET['export'] ?? '') === 'csv') {
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-t');
    $q = $pdo->prepare("SELECT rb.*, br.name room_name, c.name campus_name, u.name booker_name FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id
        JOIN campuses c ON c.id = br.campus_id JOIN users u ON u.id = rb.booked_by WHERE br.organization_id = ? AND DATE(rb.start_time) BETWEEN ? AND ? ORDER BY rb.start_time");
    $q->execute([$orgId, $from, $to]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="room-bookings-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['दिनांक', 'सुरू', 'संपेल', 'तास', 'Room', 'College/School', 'कारण', 'कोणी Book केलं', 'स्थिती']);
    foreach ($q->fetchAll() as $b) {
        fputcsv($out, [date('Y-m-d', strtotime($b['start_time'])), date('H:i', strtotime($b['start_time'])), date('H:i', strtotime($b['end_time'])),
            round((strtotime($b['end_time']) - strtotime($b['start_time'])) / 3600, 2), $b['room_name'], $b['campus_name'], $b['purpose'], $b['booker_name'], $b['status'] === 'confirmed' ? 'निश्चित' : 'रद्द']);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Room/Hall Booking';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$selectedDate = $_GET['date'] ?? date('Y-m-d');
if (!DateTime::createFromFormat('!Y-m-d', (string)$selectedDate)) { $selectedDate = date('Y-m-d'); }

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

// आठवड्याची पट्टी — निवडलेल्या दिवसाच्या आठवड्यातले प्रत्येक दिवसाचे Bookings
$weekStart = date('Y-m-d', strtotime($selectedDate . ' -' . ((int)date('N', strtotime($selectedDate)) - 1) . ' days'));
$wk = $pdo->prepare("SELECT DATE(rb.start_time) d, COUNT(*) n FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id
    WHERE br.organization_id = ? AND rb.status = 'confirmed' AND DATE(rb.start_time) BETWEEN ? AND DATE_ADD(?, INTERVAL 6 DAY) GROUP BY d");
$wk->execute([$orgId, $weekStart, $weekStart]);
$weekCounts = array_column($wk->fetchAll(), 'n', 'd');
// आत्ता कोणती खोली वापरात
$busyNow = [];
$bn = $pdo->prepare("SELECT rb.room_id, rb.purpose, rb.end_time FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id WHERE br.organization_id = ? AND rb.status = 'confirmed' AND NOW() BETWEEN rb.start_time AND rb.end_time");
$bn->execute([$orgId]);
foreach ($bn->fetchAll() as $x) $busyNow[(int)$x['room_id']] = $x;
// माझी आगामी Bookings
$my = $pdo->prepare("SELECT rb.*, br.name room_name FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id WHERE br.organization_id = ? AND rb.booked_by = ? AND rb.status = 'confirmed' AND rb.end_time >= NOW() ORDER BY rb.start_time LIMIT 8");
$my->execute([$orgId, $user['id']]);
$myBookings = $my->fetchAll();
// Timeline — 7 ते 21 (त्याबाहेरचं Booking असेल तर वाढवतो)
$tlStart = 7; $tlEnd = 21;
foreach ($bookings as $b) {
    $tlStart = min($tlStart, (int)date('G', strtotime($b['start_time'])));
    $tlEnd = max($tlEnd, (int)ceil((strtotime($b['end_time']) - strtotime($selectedDate)) / 3600));
}
$tlEnd = min(24, $tlEnd);
$byRoom = [];
foreach ($bookings as $b) $byRoom[(int)$b['room_id']][] = $b;
$mrDays = ['', 'सोम', 'मंगळ', 'बुध', 'गुरु', 'शुक्र', 'शनि', 'रवि'];

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

<?php require __DIR__ . '/../includes/insights/room-booking.php'; ?>

<div class="rb-bar">
  <a class="rb-nav" href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' -1 day')) ?>" title="मागचा दिवस">‹</a>
  <form method="GET" class="m-0"><input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($selectedDate) ?>" onchange="this.form.submit()"></form>
  <a class="rb-nav" href="?date=<?= date('Y-m-d', strtotime($selectedDate . ' +1 day')) ?>" title="पुढचा दिवस">›</a>
  <?php if ($selectedDate !== date('Y-m-d')): ?><a class="rb-today" href="?date=<?= date('Y-m-d') ?>">आज</a><?php endif; ?>
  <div class="rb-week">
    <?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($weekStart . " +$i day")); $n = (int)($weekCounts[$d] ?? 0); ?>
      <a href="?date=<?= $d ?>" class="<?= $d === $selectedDate ? 'on' : '' ?><?= $d === date('Y-m-d') ? ' today' : '' ?>"><small><?= $mrDays[$i + 1] ?></small><b><?= (int)date('j', strtotime($d)) ?></b><i><?= $n ? $n : '' ?></i></a>
    <?php endfor; ?>
  </div>
  <a class="btn btn-sm ms-auto" style="border:1px solid var(--line);border-radius:8px;" href="?export=csv&from=<?= date('Y-m-01', strtotime($selectedDate)) ?>&to=<?= date('Y-m-t', strtotime($selectedDate)) ?>">📊 या महिन्याचं Excel</a>
</div>

<?php if ($rooms): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">🗓️ <?= date('d M Y', strtotime($selectedDate)) ?> — खोलीनिहाय वेळापत्रक</div><div class="sub">मोकळ्या जागेवर Click करा → त्या खोलीचं त्या वेळेचं Booking</div></div>
  <div class="rb-tl-wrap"><div class="rb-tl" style="--h:<?= $tlEnd - $tlStart ?>;">
    <div class="rb-row rb-head"><div class="rb-name"></div><div class="rb-track"><?php for ($hh = $tlStart; $hh < $tlEnd; $hh++): ?><span><?= $hh > 12 ? $hh - 12 : $hh ?><?= $hh >= 12 ? 'pm' : 'am' ?></span><?php endfor; ?></div></div>
    <?php foreach ($rooms as $rm): ?>
      <div class="rb-row"><div class="rb-name" title="<?= h($rm['campus_name']) ?>"><?= h($rm['name']) ?><?= isset($busyNow[(int)$rm['id']]) ? ' <em class="rb-busy">● वापरात</em>' : '' ?></div>
        <div class="rb-track rb-click" data-room="<?= $rm['id'] ?>">
          <?php for ($hh = $tlStart; $hh < $tlEnd; $hh++): ?><u></u><?php endfor; ?>
          <?php foreach ($byRoom[(int)$rm['id']] ?? [] as $b):
            $s0 = (strtotime($b['start_time']) - strtotime($selectedDate)) / 3600 - $tlStart; $len = (strtotime($b['end_time']) - strtotime($b['start_time'])) / 3600; ?>
            <b class="<?= (int)$b['booked_by'] === (int)$user['id'] ? 'mine' : '' ?>" style="left:calc(<?= max(0, $s0) ?> / var(--h) * 100%);width:calc(<?= min($len, $tlEnd - $tlStart - max(0, $s0)) ?> / var(--h) * 100%);" title="<?= h(date('h:i A', strtotime($b['start_time'])) . '–' . date('h:i A', strtotime($b['end_time'])) . ' · ' . $b['purpose'] . ' · ' . $b['booker_name']) ?>"><?= h($b['purpose']) ?></b>
          <?php endforeach; ?>
          <?php if ($selectedDate === date('Y-m-d') && (int)date('G') >= $tlStart && (int)date('G') < $tlEnd): ?><s style="left:calc(<?= round((int)date('G') + (int)date('i') / 60 - $tlStart, 3) ?> / var(--h) * 100%);"></s><?php endif; ?>
        </div></div>
    <?php endforeach; ?>
  </div></div>
</div>
<?php endif; ?>

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
            <div style="font-size:11px;margin-top:2px;"><?= isset($busyNow[(int)$r['id']]) ? '<span style="color:var(--rose);font-weight:600;">● वापरात — ' . h(date('h:i A', strtotime($busyNow[(int)$r['id']]['end_time']))) . ' पर्यंत</span>' : '<span style="color:#15803D;font-weight:600;">● आत्ता मोकळं</span>' ?></div>
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
    <div class="panel mt-3">
      <div class="panel-head"><div class="title">🙋 माझी आगामी Bookings</div></div>
      <?php foreach ($myBookings as $mb): ?>
        <a href="?date=<?= date('Y-m-d', strtotime($mb['start_time'])) ?>" style="display:block;padding:10px 18px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;">
          <div style="font-size:13px;font-weight:600;"><?= h($mb['room_name']) ?> · <?= h($mb['purpose']) ?></div>
          <div class="font-mono" style="font-size:11px;color:var(--text-dim);"><?= date('d M, h:i A', strtotime($mb['start_time'])) ?> – <?= date('h:i A', strtotime($mb['end_time'])) ?></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$myBookings): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 18px;">तुमचं कुठलंही आगामी Booking नाही</p><?php endif; ?>
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
      <input type="hidden" name="action" value="book"><input type="hidden" name="back_date" value="<?= h($selectedDate) ?>">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Room Book करा</div>
        <label class="form-label-custom">Room</label>
        <select name="room_id" id="bkRoom" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['name']) ?> (<?= h($r['campus_name']) ?>)</option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">कारण</label>
        <input type="text" name="purpose" class="form-control-custom mb-2" required>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label-custom">सुरू</label><input type="datetime-local" name="start_time" id="bkStart" class="form-control-custom" required></div>
          <div class="col-md-6"><label class="form-label-custom">संपेल</label><input type="datetime-local" name="end_time" id="bkEnd" class="form-control-custom" required></div>
        </div>
        <div class="row g-2 mt-1">
          <div class="col-7"><label class="form-label-custom">🔁 पुनरावृत्ती</label><select name="repeat" id="bkRep" class="form-select-custom"><option value="">एकदाच</option><option value="daily">दररोज</option><option value="weekly">दर आठवड्याला</option></select></div>
          <div class="col-5" id="bkCntW" style="display:none;"><label class="form-label-custom">किती वेळा</label><input type="number" name="repeat_count" class="form-control-custom" min="2" max="12" value="4"></div>
        </div>
        <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">आधीच Book असलेल्या दिवसांचं Booking आपोआप वगळलं जातं.</div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Book करा</button>
      </div>
    </form>
  </div></div>
</div>

<style>
.rb-bar{display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:14px;}
.rb-nav,.rb-today{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:36px;border:1px solid var(--line);border-radius:9px;text-decoration:none;color:var(--text);font-weight:700;font-size:17px;padding:0 10px;background:var(--panel,#fff);}
.rb-today{font-size:12.5px;}
.rb-week{display:flex;gap:4px;overflow-x:auto;}
.rb-week a{display:flex;flex-direction:column;align-items:center;min-width:44px;padding:4px 6px;border:1px solid var(--line);border-radius:10px;text-decoration:none;color:var(--text);background:var(--panel,#fff);line-height:1.15;}
.rb-week a small{font-size:10px;color:var(--text-dim);} .rb-week a b{font-size:15px;} .rb-week a i{font-style:normal;font-size:10px;color:var(--rail);font-weight:700;min-height:12px;}
.rb-week a.today{border-color:var(--amber);} .rb-week a.on{background:var(--rail);border-color:var(--rail);color:#fff;} .rb-week a.on small,.rb-week a.on i{color:#fff;}
.rb-tl-wrap{overflow-x:auto;padding:8px 14px 14px;}
.rb-tl{min-width:720px;}
.rb-row{display:flex;align-items:stretch;border-bottom:1px solid var(--line);}
.rb-name{width:150px;flex:none;padding:8px 8px 8px 0;font-size:12.5px;font-weight:600;line-height:1.25;}
.rb-busy{font-style:normal;color:var(--rose);font-size:10.5px;display:block;}
.rb-track{position:relative;flex:1;display:flex;min-height:38px;}
.rb-head .rb-track span{flex:1;font-size:10px;color:var(--text-dim);padding:6px 0 4px;border-left:1px solid var(--line);padding-left:3px;}
.rb-track u{flex:1;border-left:1px dashed var(--line);text-decoration:none;}
.rb-click{cursor:copy;} .rb-click:hover u{background:color-mix(in srgb,var(--rail) 4%,transparent);}
.rb-track b{position:absolute;top:5px;bottom:5px;background:color-mix(in srgb,var(--amber) 85%,#fff);color:#3b2400;border-radius:7px;font-size:11px;font-weight:600;padding:3px 6px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;cursor:default;box-shadow:0 1px 3px rgba(0,0,0,.12);}
.rb-track b.mine{background:var(--rail);color:#fff;}
.rb-track s{position:absolute;top:0;bottom:0;width:2px;background:var(--rose);text-decoration:none;}
</style>
<script>
(function () {
  var date = <?= json_encode($selectedDate) ?>, t0 = <?= (int)$tlStart ?>, hrs = <?= (int)($tlEnd - $tlStart) ?>;
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var rep = document.getElementById('bkRep');
  if (rep) rep.onchange = function () { document.getElementById('bkCntW').style.display = rep.value ? '' : 'none'; };
  document.querySelectorAll('.rb-click').forEach(function (tr) {
    tr.addEventListener('click', function (e) {
      if (e.target.tagName === 'B') return;   // आधीचं Booking
      var r = tr.getBoundingClientRect(), h = t0 + Math.floor((e.clientX - r.left) / r.width * hrs);
      h = Math.max(0, Math.min(23, h));
      document.getElementById('bkRoom').value = tr.dataset.room;
      document.getElementById('bkStart').value = date + 'T' + pad(h) + ':00';
      document.getElementById('bkEnd').value = date + 'T' + pad(Math.min(23, h + 1)) + ':' + (h + 1 > 23 ? '59' : '00');
      if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(document.getElementById('bookModal')).show();
    });
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
