<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_guests') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'checkin') {
        $name = trim($_POST['name'] ?? '');
        $mobile = trim($_POST['mobile'] ?? '') ?: null;
        $guestOrg = trim($_POST['guest_organization'] ?? '') ?: null;
        $purpose = trim($_POST['purpose'] ?? '') ?: null;
        $meetingWith = ($_POST['meeting_with'] ?? '') !== '' ? (int)$_POST['meeting_with'] : null;
        $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
        $preRegId = ($_POST['pre_registration_id'] ?? '') !== '' ? (int)$_POST['pre_registration_id'] : null;

        // Webcam ने काढलेला Photo — Canvas चा Base64 Data म्हणून येतो
        $photoPath = null;
        $photoData = $_POST['photo_data'] ?? '';
        if ($photoData && strpos($photoData, 'data:image/') === 0) {
            $parts = explode(',', $photoData, 2);
            if (count($parts) === 2) {
                $binary = base64_decode($parts[1]);
                if ($binary !== false) {
                    $uploadDir = __DIR__ . '/../assets/uploads/guest-photos';
                    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                    $safeName = 'guest-' . uniqid() . '.jpg';
                    file_put_contents($uploadDir . '/' . $safeName, $binary);
                    $photoPath = 'assets/uploads/guest-photos/' . $safeName;
                }
            }
        }

        if ($name) {
            $passCode = 'G' . date('md') . '-' . rand(100, 999);
            $pdo->prepare("
                INSERT INTO guests (organization_id, campus_id, name, mobile, guest_organization, purpose, meeting_with, check_in_time, pass_code, photo_path, pre_registration_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)
            ")->execute([$orgId, $campusId, $name, $mobile, $guestOrg, $purpose, $meetingWith, $passCode, $photoPath, $preRegId, $user['id']]);
            $newGuestId = $pdo->lastInsertId();
            if ($preRegId) {
                $pdo->prepare("UPDATE guest_pre_registrations SET status = 'arrived' WHERE id = ?")->execute([$preRegId]);
            }
            if ($meetingWith) {
                notifyUser($meetingWith, $orgId, '🎫 तुम्हाला भेटायला पाहुणे आले आहेत', "$name" . ($guestOrg ? " ($guestOrg)" : ''), 'guests/index.php');
            }
            logActivity('पाहुणे Check-in केले', 'guests', $newGuestId, $name);
            $_SESSION['flash'] = "Check-in झालं — Pass Code: $passCode";
        }
    } elseif ($action === 'checkout') {
        $guestId = (int)($_POST['guest_id'] ?? 0);
        $pdo->prepare("UPDATE guests SET check_out_time = NOW() WHERE id = ? AND organization_id = ?")->execute([$guestId, $orgId]);
        $_SESSION['flash'] = 'Check-out झालं.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'पाहुणे नोंदणी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$filterDate = $_GET['date'] ?? date('Y-m-d');

$guestsStmt = $pdo->prepare("
    SELECT g.*, u.name AS meeting_with_name, c.name AS campus_name
    FROM guests g LEFT JOIN users u ON u.id = g.meeting_with LEFT JOIN campuses c ON c.id = g.campus_id
    WHERE g.organization_id = ? AND DATE(g.check_in_time) = ?
    ORDER BY g.check_in_time DESC
");
$guestsStmt->execute([$orgId, $filterDate]);
$guests = $guestsStmt->fetchAll();

$currentlyInCount = count(array_filter($guests, fn($g) => !$g['check_out_time']));

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campusesForGuest = $campusesStmt->fetchAll();

$preRegTodayStmt = $pdo->prepare("
    SELECT gpr.*, u.name AS meeting_with_name FROM guest_pre_registrations gpr LEFT JOIN users u ON u.id = gpr.meeting_with
    WHERE gpr.organization_id = ? AND gpr.expected_date = CURDATE() AND gpr.status = 'pending'
    ORDER BY gpr.created_at
");
$preRegTodayStmt->execute([$orgId]);
$preRegToday = $preRegTodayStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaffForGuest = $staffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🎫 पाहुणे नोंदणी (Guest Log)</div>
  <?php if ($canManage): ?>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#checkinModal">+ नवीन Check-in</button>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">आजचे एकूण पाहुणे</div><div class="val"><?= count($guests) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 अजून Building मध्ये</div><div class="val" style="color:var(--rose);"><?= $currentlyInCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">📱 आज Expected (Pre-Reg)</div><div class="val" style="color:var(--amber);"><?= count($preRegToday) ?></div></div></div>
</div>

<?php if (!empty($preRegToday)): ?>
<div class="panel mb-4">
  <div class="panel-head"><div class="title">📱 आज येण्याची अपेक्षा असलेले पाहुणे (Pre-Registered)</div></div>
  <?php foreach ($preRegToday as $pr): ?>
    <div style="padding:10px 20px;border-bottom:1px solid var(--line);display:flex;justify-content:space-between;align-items:center;">
      <div>
        <div style="font-size:13px;font-weight:600;"><?= h($pr['name']) ?> <span style="font-weight:400;color:var(--text-dim);">— <?= h($pr['mobile']) ?></span></div>
        <div style="font-size:11px;color:var(--text-dim);"><?= h($pr['purpose'] ?: '') ?><?= $pr['meeting_with_name'] ? ' · ' . h($pr['meeting_with_name']) . ' यांना भेटणार' : '' ?></div>
      </div>
      <span class="status-chip pending">⏳ अजून आले नाहीत</span>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<form method="GET" class="mb-4">
  <input type="date" name="date" class="form-control-custom" style="width:auto;" value="<?= h($filterDate) ?>" onchange="this.form.submit()">
</form>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>संस्था</th><th>कारण</th><th>कोणाला भेटले</th><th>Check-in</th><th>Check-out</th><th>Pass Code</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($guests)): ?><tr><td colspan="8"><div class="empty-state"><div class="icon"><i class="fa-solid fa-id-card-clip"></i></div><div class="title">या दिवशी कुठलेही पाहुणे आले नाहीत</div><div class="sub">नवीन पाहुणे आले की, वरच्या "+ नवीन Check-in" ने नोंद करा</div></div></td></tr><?php endif; ?>
      <?php foreach ($guests as $g): ?>
        <tr style="<?= !$g['check_out_time'] ? 'background:var(--rail-soft, #DCEFEC);' : '' ?>">
          <td style="font-weight:600;">
            <div class="d-flex align-items-center gap-2">
              <?php if ($g['photo_path']): ?><img src="../<?= h($g['photo_path']) ?>" style="width:32px;height:32px;border-radius:50%;object-fit:cover;"><?php endif; ?>
              <div><?= h($g['name']) ?><div style="font-size:10.5px;color:var(--text-dim);font-weight:400;"><?= h($g['mobile'] ?: '') ?></div></div>
            </div>
          </td>
          <td><?= h($g['guest_organization'] ?: '—') ?></td>
          <td><?= h($g['purpose'] ?: '—') ?></td>
          <td><?= h($g['meeting_with_name'] ?: '—') ?></td>
          <td class="font-mono"><?= date('h:i A', strtotime($g['check_in_time'])) ?></td>
          <td class="font-mono"><?= $g['check_out_time'] ? date('h:i A', strtotime($g['check_out_time'])) : '—' ?></td>
          <td class="font-mono"><?= h($g['pass_code']) ?></td>
          <?php if ($canManage): ?>
            <td>
              <?php if (!$g['check_out_time']): ?>
                <form method="POST"><input type="hidden" name="action" value="checkout"><input type="hidden" name="guest_id" value="<?= $g['id'] ?>">
                  <button type="submit" class="btn btn-sm" style="background:var(--amber);color:#fff;border-radius:6px;font-size:10.5px;padding:4px 10px;">Check-out</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="checkinModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" id="checkinForm">
      <input type="hidden" name="action" value="checkin">
      <input type="hidden" name="photo_data" id="photoDataInput">
      <input type="hidden" name="pre_registration_id" id="preRegIdInput">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन पाहुणे — Check-in</div>
        <div class="row g-3">
          <div class="col-md-7">
            <label class="form-label-custom">Mobile <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(आधी टाका — जुनी माहिती असेल तर आपोआप भरेल)</span></label>
            <input type="text" name="mobile" id="guestMobile" class="form-control-custom mb-1" oninput="lookupGuestMobile(this.value)">
            <div id="frequentVisitorNote" style="font-size:11px;color:var(--rail);font-weight:600;margin-bottom:10px;display:none;"></div>

            <label class="form-label-custom">पाहुण्याचं नाव</label>
            <input type="text" name="name" id="guestName" class="form-control-custom mb-2" required>
            <label class="form-label-custom">संस्था/कंपनी</label>
            <input type="text" name="guest_organization" id="guestOrg" class="form-control-custom mb-2">
            <label class="form-label-custom">भेटीचं कारण</label>
            <input type="text" name="purpose" id="guestPurpose" class="form-control-custom mb-2">
            <label class="form-label-custom">कोणाला भेटायला आले</label>
            <select name="meeting_with" id="guestMeetingWith" class="form-select-custom mb-2">
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaffForGuest as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?><?= $s['designation'] ? ' — ' . h($s['designation']) : '' ?></option><?php endforeach; ?>
            </select>
            <label class="form-label-custom">College/School</label>
            <select name="campus_id" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($campusesForGuest as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-5">
            <label class="form-label-custom">📸 Photo (Security साठी)</label>
            <div style="background:#F3F4F7;border-radius:10px;overflow:hidden;position:relative;aspect-ratio:1;">
              <video id="guestCamVideo" autoplay playsinline style="width:100%;height:100%;object-fit:cover;display:block;"></video>
              <canvas id="guestCamCanvas" style="display:none;width:100%;height:100%;object-fit:cover;"></canvas>
            </div>
            <div class="d-flex gap-2 mt-2">
              <button type="button" class="btn btn-sm w-100" style="border:1px solid var(--line);border-radius:7px;font-size:11.5px;" onclick="startGuestCam()">🎥 Camera सुरू करा</button>
              <button type="button" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11.5px;" onclick="captureGuestPhoto()">📸 Capture</button>
            </div>
            <div style="font-size:10px;color:var(--text-dim);margin-top:4px;">Camera Permission द्यावी लागेल — Photo ऐच्छिक आहे.</div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Check-in करा</button>
      </div>
    </form>
  </div></div>
</div>

<script>
let guestCamStream = null;

function startGuestCam() {
  navigator.mediaDevices.getUserMedia({ video: true })
    .then(function (stream) {
      guestCamStream = stream;
      document.getElementById('guestCamVideo').srcObject = stream;
    })
    .catch(function () { alert('Camera सुरू करता आला नाही — Permission द्या किंवा Photo शिवाय पुढे जा.'); });
}

function captureGuestPhoto() {
  const video = document.getElementById('guestCamVideo');
  const canvas = document.getElementById('guestCamCanvas');
  if (!guestCamStream) { alert('आधी Camera सुरू करा.'); return; }
  canvas.width = video.videoWidth;
  canvas.height = video.videoHeight;
  canvas.getContext('2d').drawImage(video, 0, 0);
  document.getElementById('photoDataInput').value = canvas.toDataURL('image/jpeg', 0.8);
  canvas.style.display = 'block';
  video.style.display = 'none';
  if (guestCamStream) { guestCamStream.getTracks().forEach(function (t) { t.stop(); }); }
}

let lookupTimer = null;
function lookupGuestMobile(mobile) {
  clearTimeout(lookupTimer);
  if (mobile.length < 10) { document.getElementById('frequentVisitorNote').style.display = 'none'; return; }
  lookupTimer = setTimeout(function () {
    fetch('lookup-mobile.php?mobile=' + encodeURIComponent(mobile))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        const note = document.getElementById('frequentVisitorNote');
        if (data.found) {
          note.style.display = 'block';
          note.textContent = data.is_pre_registered
            ? '📱 आज यांची Pre-Registration आधीच झालेली आहे — माहिती आपोआप भरली'
            : '⭐ हे आधी ' + data.visit_count + ' वेळा आले आहेत — माहिती आपोआप भरली';
          document.getElementById('guestName').value = data.name || '';
          document.getElementById('guestOrg').value = data.guest_organization || '';
          document.getElementById('guestPurpose').value = data.purpose || '';
          if (data.meeting_with) { document.getElementById('guestMeetingWith').value = data.meeting_with; }
          document.getElementById('preRegIdInput').value = data.is_pre_registered ? data.pre_registration_id : '';
        } else {
          note.style.display = 'none';
          document.getElementById('preRegIdInput').value = '';
        }
      });
  }, 500);
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
