<?php
$pageTitle = 'CCTV विभाग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/cctv-helpers.php';
requireCctvAccess($pdo, $user);

$orgId = activeOrgId();

$dbNotReady = false;
$totalDvrs = 0; $totalCameras = 0;
$statusCounts = ['working' => 0, 'not_working' => 0, 'needs_attention' => 0];
$dvrs = [];

try {
    $totalDvrsStmt = $pdo->prepare("SELECT COUNT(*) c FROM cctv_dvrs WHERE organization_id = ? AND is_active = 1");
    $totalDvrsStmt->execute([$orgId]);
    $totalDvrs = (int)$totalDvrsStmt->fetch()['c'];

    $totalCamerasStmt = $pdo->prepare("
        SELECT COUNT(*) c FROM cctv_cameras cc JOIN cctv_dvrs d ON d.id = cc.dvr_id
        WHERE cc.organization_id = ? AND cc.is_active = 1 AND d.is_active = 1
    ");
    $totalCamerasStmt->execute([$orgId]);
    $totalCameras = (int)$totalCamerasStmt->fetch()['c'];

    // प्रत्येक Camera ची शेवटची नोंदवलेली स्थिती (Latest Checkup वरून)
    $statusStmt = $pdo->prepare("
        SELECT cs.status, COUNT(*) c FROM cctv_camera_status cs
        JOIN cctv_cameras cam ON cam.id = cs.camera_id
        JOIN cctv_checkups ck ON ck.id = cs.checkup_id
        WHERE cam.organization_id = ? AND ck.id = (
            SELECT id FROM cctv_checkups WHERE dvr_id = ck.dvr_id ORDER BY checkup_date DESC LIMIT 1
        )
        GROUP BY cs.status
    ");
    $statusStmt->execute([$orgId]);
    foreach ($statusStmt->fetchAll() as $row) { $statusCounts[$row['status']] = (int)$row['c']; }

    $dvrsStmt = $pdo->prepare("
        SELECT d.*, (SELECT COUNT(*) FROM cctv_cameras WHERE dvr_id = d.id AND is_active = 1) AS camera_count,
          (SELECT checkup_date FROM cctv_checkups WHERE dvr_id = d.id ORDER BY checkup_date DESC LIMIT 1) AS last_checkup_date,
          (SELECT storage_used_percent FROM cctv_checkups WHERE dvr_id = d.id ORDER BY checkup_date DESC LIMIT 1) AS last_storage_used,
          (SELECT backup_done FROM cctv_checkups WHERE dvr_id = d.id ORDER BY checkup_date DESC LIMIT 1) AS last_backup_done
        FROM cctv_dvrs d WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY d.dvr_name
    ");
    $dvrsStmt->execute([$orgId]);
    $dvrs = $dvrsStmt->fetchAll();
} catch (PDOException $e) {
    // CCTV विभागाच्या Tables अजून Database मध्ये तयार झालेल्या नाहीत —
    // म्हणजे database/migration-cctv.sql अजून चालवलेली नाही.
    $dbNotReady = true;
}
?>

<?php if ($dbNotReady): ?>
  <div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
  <div class="page-title mb-3">📹 CCTV विभाग</div>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:18px 22px;">
    ⚠️ <strong>Database अजून तयार नाही.</strong> CCTV विभागासाठी लागणाऱ्या Tables (cctv_dvrs, cctv_cameras, इ.) अजून तयार झालेल्या नाहीत.<br><br>
    <strong>उपाय:</strong> phpMyAdmin मध्ये जाऊन <span class="font-mono" style="background:#fff;padding:2px 8px;border-radius:5px;">database/migration-cctv.sql</span> ही File चालवा, मग हे पान Refresh करा.
  </div>
  <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📹 CCTV विभाग</div>
  <div class="d-flex gap-2">
    <a href="dvrs.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;">DVR यादी</a>
    <a href="cameras.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;">Camera यादी</a>
    <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;">📊 अहवाल</a>
    <a href="checkup.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">✅ आजची Checkup करा</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण DVR</div><div class="val"><?= $totalDvrs ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Camera</div><div class="val"><?= $totalCameras ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ चालू (Working)</div><div class="val" style="color:var(--rail);"><?= $statusCounts['working'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 बंद (Not Working)</div><div class="val" style="color:var(--rose);"><?= $statusCounts['not_working'] ?></div></div></div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📀 DVR-निहाय स्थिती</div></div>
  <table class="rtable">
    <thead><tr><th>DVR</th><th>ठिकाण</th><th>Cameras</th><th>Storage वापर</th><th>शेवटचा Backup</th><th>शेवटची Checkup</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($dvrs)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही DVR जोडलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($dvrs as $d):
        $storageUsed = $d['last_storage_used'];
        $storageColor = $storageUsed !== null && $storageUsed >= 90 ? 'var(--rose)' : ($storageUsed !== null && $storageUsed >= 75 ? 'var(--amber)' : 'var(--rail)');
        $daysSinceCheckup = $d['last_checkup_date'] ? (int)((time() - strtotime($d['last_checkup_date'])) / 86400) : null;
      ?>
        <tr>
          <td style="font-weight:600;"><?= h($d['dvr_name']) ?></td>
          <td><?= h($d['location']) ?></td>
          <td><?= $d['camera_count'] ?><?= $d['channel_capacity'] ? ' / ' . $d['channel_capacity'] : '' ?></td>
          <td><?php if ($storageUsed !== null): ?><span style="color:<?= $storageColor ?>;font-weight:600;"><?= number_format($storageUsed, 0) ?>%</span><?php else: ?>—<?php endif; ?></td>
          <td><?php if ($d['last_backup_done'] !== null): ?><?= $d['last_backup_done'] ? '✅ झाला' : '❌ बाकी' ?><?php else: ?>—<?php endif; ?></td>
          <td><?php if ($d['last_checkup_date']): ?><?= date('d M Y', strtotime($d['last_checkup_date'])) ?> <?php if ($daysSinceCheckup > 2): ?><span style="color:var(--rose);font-size:11px;">(⚠️ <?= $daysSinceCheckup ?> दिवस झाले)</span><?php endif; ?><?php else: ?><span style="color:var(--rose);">कधीच नाही</span><?php endif; ?></td>
          <td><a href="checkup.php?dvr_id=<?= $d['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;">Checkup करा →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
