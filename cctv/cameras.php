<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $dvrId = (int)($_POST['dvr_id'] ?? 0);
    $cameraName = trim($_POST['camera_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $cameraType = $_POST['camera_type'] ?? 'dome';
    if (!in_array($cameraType, ['dome', 'bullet', 'ptz', 'other'], true)) { $cameraType = 'other'; }

    // DVR याच संस्थेचा व सक्रिय असावा (Toggle साठी लागत नाही)
    $dvrOk = false;
    if ($dvrId) {
        $dvrCheck = $pdo->prepare("SELECT id FROM cctv_dvrs WHERE id = ? AND organization_id = ? AND is_active = 1");
        $dvrCheck->execute([$dvrId, $orgId]);
        $dvrOk = (bool)$dvrCheck->fetch();
    }

    if ($action === 'update' || $action === 'toggle_active') {
        $cameraId = (int)($_POST['camera_id'] ?? 0);
        $camStmt = $pdo->prepare("SELECT * FROM cctv_cameras WHERE id = ? AND organization_id = ?");
        $camStmt->execute([$cameraId, $orgId]);
        $camera = $camStmt->fetch();
        if (!$camera) {
            $_SESSION['flash'] = 'Camera सापडला नाही.';
        } elseif ($action === 'toggle_active') {
            $newActive = $camera['is_active'] ? 0 : 1;
            $pdo->prepare("UPDATE cctv_cameras SET is_active = ? WHERE id = ? AND organization_id = ?")->execute([$newActive, $cameraId, $orgId]);
            logActivity($newActive ? 'CCTV Camera पुन्हा सुरू केला' : 'CCTV Camera बंद केला', 'cctv_camera', $cameraId, $camera['camera_name']);
            $_SESSION['flash'] = "'{$camera['camera_name']}' " . ($newActive ? 'पुन्हा सक्रिय केला.' : 'निष्क्रिय केला.');
        } elseif (!$dvrOk || $cameraName === '') {
            $_SESSION['flash'] = 'DVR सापडला नाही किंवा नाव रिकामं आहे — बदल झाला नाही.';
        } else {
            $pdo->prepare("UPDATE cctv_cameras SET dvr_id = ?, camera_name = ?, location = ?, camera_type = ? WHERE id = ? AND organization_id = ?")
                ->execute([$dvrId, $cameraName, $location, $cameraType, $cameraId, $orgId]);
            logActivity('CCTV Camera बदलला', 'cctv_camera', $cameraId, $cameraName);
            $_SESSION['flash'] = "'$cameraName' अद्ययावत झाला.";
        }
        header('Location: cameras.php');
        exit;
    }

    if (!$dvrOk) {
        $_SESSION['flash'] = 'निवडलेला DVR सापडला नाही — Camera जोडला नाही.';
    } elseif ($cameraName) {
        $pdo->prepare("INSERT INTO cctv_cameras (organization_id, dvr_id, camera_name, location, camera_type) VALUES (?, ?, ?, ?, ?)")
            ->execute([$orgId, $dvrId, $cameraName, $location, $cameraType]);
        logActivity('CCTV Camera जोडला', 'cctv_camera', $pdo->lastInsertId(), $cameraName);
        $_SESSION['flash'] = "'$cameraName' Camera जोडला गेला.";
    }
    header('Location: cameras.php');
    exit;
}

$pageTitle = 'Camera यादी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$dvrsStmt = $pdo->prepare("SELECT * FROM cctv_dvrs WHERE organization_id = ? AND is_active = 1 ORDER BY dvr_name");
$dvrsStmt->execute([$orgId]);
$dvrs = $dvrsStmt->fetchAll();

$camerasStmt = $pdo->prepare("
    SELECT cc.*, d.dvr_name FROM cctv_cameras cc JOIN cctv_dvrs d ON d.id = cc.dvr_id
    WHERE cc.organization_id = ? ORDER BY d.dvr_name, cc.camera_name
");
$camerasStmt->execute([$orgId]);
$cameras = $camerasStmt->fetchAll();

$typeLabels = ['dome' => 'Dome', 'bullet' => 'Bullet', 'ptz' => 'PTZ', 'other' => 'इतर'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📷 Camera यादी</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>CCTV विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (empty($dvrs)): ?>
  <div class="panel" style="padding:24px;text-align:center;color:var(--text-dim);">आधी <a href="dvrs.php">DVR जोडा</a>, मग Camera जोडता येईल.</div>
<?php else: ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Camera जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">कोणत्या DVR ला जोडलेला</label>
        <select name="dvr_id" class="form-select-custom" required>
          <?php foreach ($dvrs as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['dvr_name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label-custom">Camera चं नाव</label><input type="text" name="camera_name" class="form-control-custom" placeholder="उदा. Cam-01" required></div>
      <div class="col-md-3"><label class="form-label-custom">ठिकाण</label><input type="text" name="location" class="form-control-custom" placeholder="मुख्य प्रवेशद्वार"></div>
      <div class="col-md-3">
        <label class="form-label-custom">प्रकार</label>
        <select name="camera_type" class="form-select-custom">
          <option value="dome">Dome</option><option value="bullet">Bullet</option><option value="ptz">PTZ</option><option value="other">इतर</option>
        </select>
      </div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Camera जोडा</button>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Camera</th><th>DVR</th><th>ठिकाण</th><th>प्रकार</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($cameras)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही Camera नाही</td></tr><?php endif; ?>
      <?php foreach ($cameras as $c): ?>
        <tr style="<?= $c['is_active'] ? '' : 'opacity:.55;' ?>">
          <td style="font-weight:600;"><?= h($c['camera_name']) ?><?= $c['is_active'] ? '' : ' <span class="status-chip overdue">निष्क्रिय</span>' ?></td>
          <td><?= h($c['dvr_name']) ?></td>
          <td><?= h($c['location']) ?></td>
          <td><?= h($typeLabels[$c['camera_type']] ?? $c['camera_type']) ?></td>
          <td style="white-space:nowrap;">
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 10px;" onclick="document.getElementById('camEdit<?= $c['id'] ?>').classList.toggle('d-none')">Edit</button>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $c['is_active'] ? 'हा Camera निष्क्रिय करायचा?' : 'हा Camera पुन्हा सक्रिय करायचा?' ?>')">
              <input type="hidden" name="action" value="toggle_active"><input type="hidden" name="camera_id" value="<?= $c['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 10px;"><?= $c['is_active'] ? 'निष्क्रिय करा' : 'सक्रिय करा' ?></button>
            </form>
          </td>
        </tr>
        <tr id="camEdit<?= $c['id'] ?>" class="d-none">
          <td colspan="5">
            <form method="POST" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="update"><input type="hidden" name="camera_id" value="<?= $c['id'] ?>">
              <div class="col-md-3"><select name="dvr_id" class="form-select-custom"><?php foreach ($dvrs as $d): ?><option value="<?= $d['id'] ?>" <?= (int)$d['id'] === (int)$c['dvr_id'] ? 'selected' : '' ?>><?= h($d['dvr_name']) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-3"><input type="text" name="camera_name" class="form-control-custom" value="<?= h($c['camera_name']) ?>" required></div>
              <div class="col-md-3"><input type="text" name="location" class="form-control-custom" value="<?= h($c['location']) ?>" placeholder="ठिकाण"></div>
              <div class="col-md-2"><select name="camera_type" class="form-select-custom"><?php foreach ($typeLabels as $tv => $tl): ?><option value="<?= $tv ?>" <?= $c['camera_type'] === $tv ? 'selected' : '' ?>><?= h($tl) ?></option><?php endforeach; ?></select></div>
              <div class="col-md-1"><button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;">जतन</button></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
