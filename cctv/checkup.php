<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dvrId = (int)($_POST['dvr_id'] ?? 0);
    $checkupDate = ($_POST['checkup_date'] ?? '') ?: date('Y-m-d');
    $storageUsed = ($_POST['storage_used_percent'] ?? '') !== '' ? (float)$_POST['storage_used_percent'] : null;
    $footageDays = ($_POST['footage_days_available'] ?? '') !== '' ? (int)$_POST['footage_days_available'] : null;
    $backupDone = isset($_POST['backup_done']) ? 1 : 0;
    $backupLocation = trim($_POST['backup_location'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    // आधीच याच DVR ची, याच दिवसाची Checkup असेल तर Update करा (Duplicate टाळण्यासाठी)
    $existing = cctvGetTodayCheckup($pdo, $dvrId, $checkupDate);
    if ($existing) {
        $pdo->prepare("UPDATE cctv_checkups SET storage_used_percent=?, footage_days_available=?, backup_done=?, backup_location=?, remarks=?, checked_by=? WHERE id=?")
            ->execute([$storageUsed, $footageDays, $backupDone, $backupLocation, $remarks, $user['id'], $existing['id']]);
        $checkupId = $existing['id'];
    } else {
        $pdo->prepare("INSERT INTO cctv_checkups (organization_id, dvr_id, checkup_date, storage_used_percent, footage_days_available, backup_done, backup_location, remarks, checked_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $dvrId, $checkupDate, $storageUsed, $footageDays, $backupDone, $backupLocation, $remarks, $user['id']]);
        $checkupId = $pdo->lastInsertId();
    }

    // प्रत्येक Camera ची स्थिती साठवा
    $cameraStatuses = $_POST['camera_status'] ?? [];
    $cameraRemarks = $_POST['camera_remarks'] ?? [];
    foreach ($cameraStatuses as $cameraId => $status) {
        $cameraId = (int)$cameraId;
        $camRemark = trim($cameraRemarks[$cameraId] ?? '');
        $pdo->prepare("
            INSERT INTO cctv_camera_status (checkup_id, camera_id, status, remarks) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), remarks = VALUES(remarks)
        ")->execute([$checkupId, $cameraId, $status, $camRemark ?: null]);
    }

    logActivity('CCTV Checkup नोंदवली', 'cctv_checkup', $checkupId, $checkupDate);
    $_SESSION['flash'] = 'Checkup यशस्वीरित्या नोंदवली गेली.';
    header('Location: index.php');
    exit;
}

$pageTitle = 'CCTV Checkup';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$dvrsStmt = $pdo->prepare("SELECT * FROM cctv_dvrs WHERE organization_id = ? AND is_active = 1 ORDER BY dvr_name");
$dvrsStmt->execute([$orgId]);
$dvrs = $dvrsStmt->fetchAll();

$selectedDvrId = (int)($_GET['dvr_id'] ?? ($dvrs[0]['id'] ?? 0));

$camerasStmt = $pdo->prepare("SELECT * FROM cctv_cameras WHERE dvr_id = ? AND is_active = 1 ORDER BY camera_name");
$camerasStmt->execute([$selectedDvrId]);
$cameras = $camerasStmt->fetchAll();

// आज आधीच Checkup झाली असेल तर ती माहिती Pre-fill करण्यासाठी आणा
$todayCheckup = $selectedDvrId ? cctvGetTodayCheckup($pdo, $selectedDvrId) : null;
$existingCameraStatus = [];
if ($todayCheckup) {
    $csStmt = $pdo->prepare("SELECT camera_id, status, remarks FROM cctv_camera_status WHERE checkup_id = ?");
    $csStmt->execute([$todayCheckup['id']]);
    foreach ($csStmt->fetchAll() as $cs) { $existingCameraStatus[$cs['camera_id']] = $cs; }
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">✅ आजची CCTV Checkup</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>CCTV विभाग</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= date('d M Y') ?><?php if ($todayCheckup): ?> — <span style="color:var(--rail);font-weight:600;">आज आधीच नोंदवलेली आहे, बदल करून पुन्हा Save करू शकता</span><?php endif; ?></p>

<?php if (empty($dvrs)): ?>
  <div class="panel" style="padding:24px;text-align:center;color:var(--text-dim);">आधी <a href="dvrs.php">DVR जोडा</a>.</div>
<?php else: ?>

<form method="GET" class="mb-3">
  <select name="dvr_id" class="form-select-custom" style="max-width:320px;" onchange="this.form.submit()">
    <?php foreach ($dvrs as $d): ?>
      <option value="<?= $d['id'] ?>" <?= $selectedDvrId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['dvr_name']) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<form method="POST">
  <input type="hidden" name="dvr_id" value="<?= $selectedDvrId ?>">
  <input type="hidden" name="checkup_date" value="<?= date('Y-m-d') ?>">

  <div class="panel mb-3">
    <div class="panel-head"><div class="title">💾 Storage व Backup तपशील</div></div>
    <div style="padding:18px 24px;">
      <div class="row g-3">
        <div class="col-md-3"><label class="form-label-custom">Storage वापरलं (%)</label><input type="number" step="0.1" max="100" name="storage_used_percent" class="form-control-custom" value="<?= h($todayCheckup['storage_used_percent'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label-custom">किती दिवसांचं Footage उपलब्ध</label><input type="number" name="footage_days_available" class="form-control-custom" value="<?= h($todayCheckup['footage_days_available'] ?? '') ?>"></div>
        <div class="col-md-3"><label class="form-label-custom">Backup ठिकाण</label><input type="text" name="backup_location" class="form-control-custom" placeholder="External HDD / Cloud" value="<?= h($todayCheckup['backup_location'] ?? '') ?>"></div>
        <div class="col-md-3 d-flex align-items-end">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
            <input type="checkbox" name="backup_done" value="1" <?= !empty($todayCheckup['backup_done']) ? 'checked' : '' ?> style="width:18px;height:18px;"> आजचा Backup झाला
          </label>
        </div>
      </div>
      <label class="form-label-custom mt-2">टिप्पणी (ऐच्छिक)</label>
      <textarea name="remarks" class="form-control-custom" rows="2"><?= h($todayCheckup['remarks'] ?? '') ?></textarea>
    </div>
  </div>

  <div class="panel mb-3">
    <div class="panel-head"><div class="title">📷 प्रत्येक Camera ची स्थिती</div></div>
    <div style="padding:10px 24px;">
      <?php if (empty($cameras)): ?>
        <p style="font-size:12.5px;color:var(--text-dim);padding:14px 0;">या DVR ला अजून कुठलाही Camera जोडलेला नाही — <a href="cameras.php">इथे जोडा</a>.</p>
      <?php endif; ?>
      <?php foreach ($cameras as $cam):
        $curStatus = $existingCameraStatus[$cam['id']]['status'] ?? 'working';
        $curRemark = $existingCameraStatus[$cam['id']]['remarks'] ?? '';
      ?>
        <div style="display:flex;align-items:center;gap:14px;padding:12px 0;border-bottom:1px solid var(--line);flex-wrap:wrap;">
          <div style="flex:1;min-width:160px;">
            <div style="font-size:13px;font-weight:600;"><?= h($cam['camera_name']) ?></div>
            <div style="font-size:11px;color:var(--text-dim);"><?= h($cam['location']) ?></div>
          </div>
          <select name="camera_status[<?= $cam['id'] ?>]" class="form-select-custom" style="max-width:170px;">
            <option value="working" <?= $curStatus === 'working' ? 'selected' : '' ?>>✅ चालू आहे</option>
            <option value="not_working" <?= $curStatus === 'not_working' ? 'selected' : '' ?>>🔴 बंद आहे</option>
            <option value="needs_attention" <?= $curStatus === 'needs_attention' ? 'selected' : '' ?>>🟡 लक्ष द्यायला हवं</option>
          </select>
          <input type="text" name="camera_remarks[<?= $cam['id'] ?>]" class="form-control-custom" placeholder="टिप्पणी (ऐच्छिक)" style="max-width:220px;" value="<?= h($curRemark) ?>">
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">Checkup Save करा</button>
</form>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
