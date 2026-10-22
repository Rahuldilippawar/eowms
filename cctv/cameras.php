<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dvrId = (int)($_POST['dvr_id'] ?? 0);
    $cameraName = trim($_POST['camera_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $cameraType = $_POST['camera_type'] ?? 'dome';

    if ($dvrId && $cameraName) {
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
    <thead><tr><th>Camera</th><th>DVR</th><th>ठिकाण</th><th>प्रकार</th></tr></thead>
    <tbody>
      <?php if (empty($cameras)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही Camera नाही</td></tr><?php endif; ?>
      <?php foreach ($cameras as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= h($c['camera_name']) ?></td>
          <td><?= h($c['dvr_name']) ?></td>
          <td><?= h($c['location']) ?></td>
          <td><?= h($typeLabels[$c['camera_type']] ?? $c['camera_type']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
