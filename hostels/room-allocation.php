<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
require_once __DIR__ . '/../includes/hostel-helpers.php';
requireHostelStaffAccess($pdo, $user);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireHostelPostIdsInOrg($pdo, $orgId);
    $action = $_POST['action'] ?? '';
    if ($action === 'add_room') {
        $hostelId = (int)($_POST['hostel_id'] ?? 0);
        $roomNumber = trim($_POST['room_number'] ?? '');
        $capacity = (int)($_POST['capacity'] ?? 1);
        if ($hostelId && $roomNumber) {
            try {
                $pdo->prepare("INSERT INTO hostel_rooms (hostel_id, room_number, capacity) VALUES (?, ?, ?)")->execute([$hostelId, $roomNumber, $capacity]);
                $_SESSION['flash'] = 'Room जोडली गेली.';
            } catch (PDOException $e) {
                $_SESSION['form_error'] = 'ही Room आधीच अस्तित्वात आहे.';
            }
        }
    } elseif ($action === 'allocate') {
        $roomId = (int)($_POST['room_id'] ?? 0);
        $studentId = (int)($_POST['student_id'] ?? 0);
        $bedLabel = trim($_POST['bed_label'] ?? '');

        $capStmt = $pdo->prepare("SELECT capacity, (SELECT COUNT(*) FROM hostel_room_allocations WHERE room_id = hostel_rooms.id AND status = 'active') AS occupied FROM hostel_rooms WHERE id = ?");
        $capStmt->execute([$roomId]);
        $room = $capStmt->fetch();

        if ($room && $room['occupied'] >= $room['capacity']) {
            $_SESSION['form_error'] = 'ही Room आधीच पूर्ण भरलेली आहे.';
        } elseif ($roomId && $studentId) {
            $pdo->prepare("UPDATE hostel_room_allocations SET status = 'vacated', vacated_date = CURDATE() WHERE student_id = ? AND status = 'active'")->execute([$studentId]);
            $pdo->prepare("INSERT INTO hostel_room_allocations (room_id, student_id, bed_label, allocated_date, created_by) VALUES (?, ?, ?, CURDATE(), ?)")
                ->execute([$roomId, $studentId, $bedLabel, $user['id']]);
            $_SESSION['flash'] = 'Room वाटप झालं.';
        }
    } elseif ($action === 'vacate') {
        $allocationId = (int)($_POST['allocation_id'] ?? 0);
        $pdo->prepare("UPDATE hostel_room_allocations SET status = 'vacated', vacated_date = CURDATE() WHERE id = ?")->execute([$allocationId]);
        $_SESSION['flash'] = 'Room रिकामी केली.';
    }
    header('Location: room-allocation.php');
    exit;
}

$pageTitle = 'Room/Bed वाटप';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$hostelsStmt = $pdo->prepare("SELECT * FROM hostels WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$hostelsStmt->execute([$orgId]);
$hostels = $hostelsStmt->fetchAll();

$roomsStmt = $pdo->prepare("
    SELECT r.*, h.name AS hostel_name,
      (SELECT COUNT(*) FROM hostel_room_allocations WHERE room_id = r.id AND status = 'active') AS occupied
    FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id
    WHERE h.organization_id = ? AND r.is_active = 1 ORDER BY h.name, r.room_number
");
$roomsStmt->execute([$orgId]);
$rooms = $roomsStmt->fetchAll();

$unallocatedStmt = $pdo->prepare("
    SELECT hs.id, hs.name, h.name AS hostel_name FROM hostel_students hs JOIN hostels h ON h.id = hs.hostel_id
    WHERE h.organization_id = ? AND hs.is_active = 1
      AND NOT EXISTS (SELECT 1 FROM hostel_room_allocations WHERE student_id = hs.id AND status = 'active')
    ORDER BY hs.name
");
$unallocatedStmt->execute([$orgId]);
$unallocatedStudents = $unallocatedStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🛏️ Room/Bed वाटप</div>
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

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">नवीन Room जोडा</div></div>
      <form method="POST" style="padding:18px 24px;">
        <input type="hidden" name="action" value="add_room">
        <div class="row g-2">
          <div class="col-5">
            <select name="hostel_id" class="form-select-custom" required>
              <option value="">-- Hostel --</option>
              <?php foreach ($hostels as $h): ?><option value="<?= $h['id'] ?>"><?= h($h['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-3"><input type="text" name="room_number" class="form-control-custom" placeholder="Room क्र." required></div>
          <div class="col-2"><input type="number" name="capacity" class="form-control-custom" placeholder="क्षमता" value="4" required></div>
          <div class="col-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">जोडा</button></div>
        </div>
      </form>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">विद्यार्थ्याला Room द्या</div></div>
      <form method="POST" style="padding:18px 24px;">
        <input type="hidden" name="action" value="allocate">
        <div class="row g-2">
          <div class="col-5">
            <select name="student_id" class="form-select-custom" required>
              <option value="">-- विद्यार्थी --</option>
              <?php foreach ($unallocatedStudents as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['hostel_name']) ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-4">
            <select name="room_id" class="form-select-custom" required>
              <option value="">-- Room --</option>
              <?php foreach ($rooms as $r): ?><option value="<?= $r['id'] ?>"><?= h($r['hostel_name']) ?> — <?= h($r['room_number']) ?> (<?= $r['occupied'] ?>/<?= $r['capacity'] ?>)</option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-1"><input type="text" name="bed_label" class="form-control-custom" placeholder="Bed"></div>
          <div class="col-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">द्या</button></div>
        </div>
      </form>
      <?php if (empty($unallocatedStudents)): ?><div style="padding:0 24px 14px;font-size:11.5px;color:var(--text-dim);">सगळ्या विद्यार्थ्यांना Room दिलेली आहे.</div><?php endif; ?>
    </div>
  </div>
</div>

<?php
  $roomsByHostel = [];
  foreach ($rooms as $r) { $roomsByHostel[$r['hostel_name']][] = $r; }
?>
<?php foreach ($roomsByHostel as $hostelName => $hostelRooms): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">🏠 <?= h($hostelName) ?></div></div>
    <div class="row g-3" style="padding:16px 20px;">
      <?php foreach ($hostelRooms as $r):
        $occStmt = $pdo->prepare("SELECT a.*, s.name AS student_name FROM hostel_room_allocations a JOIN hostel_students s ON s.id = a.student_id WHERE a.room_id = ? AND a.status = 'active'");
        $occStmt->execute([$r['id']]);
        $occupants = $occStmt->fetchAll();
        $isFull = $r['occupied'] >= $r['capacity'];
      ?>
        <div class="col-md-4 col-lg-3">
          <div style="border:1px solid var(--line);border-radius:10px;padding:12px 14px;<?= $isFull ? 'background:var(--rail-soft);' : '' ?>">
            <div style="font-weight:700;font-size:13px;">Room <?= h($r['room_number']) ?> <span style="font-size:10.5px;color:var(--text-dim);font-weight:400;">(<?= $r['occupied'] ?>/<?= $r['capacity'] ?>)</span></div>
            <?php foreach ($occupants as $o): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;font-size:11px;margin-top:5px;">
                <span><?= h($o['student_name']) ?><?= $o['bed_label'] ? ' (' . h($o['bed_label']) . ')' : '' ?></span>
                <form method="POST"><input type="hidden" name="action" value="vacate"><input type="hidden" name="allocation_id" value="<?= $o['id'] ?>">
                  <button type="submit" style="border:none;background:none;color:var(--rose);font-size:10px;" onclick="return confirm('रिकामी करायची?')">✕</button>
                </form>
              </div>
            <?php endforeach; ?>
            <?php if (empty($occupants)): ?><div style="font-size:10.5px;color:var(--text-dim);margin-top:5px;">रिकामी</div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
<?php endforeach; ?>
<?php if (empty($rooms)): ?><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कुठलीही Room जोडलेली नाही</div><?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
