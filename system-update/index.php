<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$pageTitle = 'System Update';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$backupHistoryDir = __DIR__ . '/../backups/update-safety';
$safetyBackups = [];
if (is_dir($backupHistoryDir)) {
    $files = glob($backupHistoryDir . '/*.zip');
    rsort($files);
    foreach (array_slice($files, 0, 5) as $f) {
        $safetyBackups[] = ['name' => basename($f), 'size' => round(filesize($f) / 1024 / 1024, 2), 'date' => date('d M Y, h:i A', filemtime($f))];
    }
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔄 System Update</div>
  <div class="d-flex gap-2">
    <a href="db-update.php" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;"><i class="fa-solid fa-database me-1"></i>🛠️ Database Update</a>
    <a href="push-setup.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-bell me-1"></i>📲 Push Notification Setup</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">App चे Updates आता थेट GitHub वरून आपोआप Live होतात — इथून ZIP Upload करण्याची गरज नाही (ती सुविधा सुरक्षिततेसाठी काढली आहे).</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-md-4">
    <a href="db-update.php" class="panel su-card"><i class="fa-solid fa-database"></i><b>🛠️ Database Update</b><span>नवीन Version नंतर लागणारे Database बदल (Migrations) तपासा व लागू करा</span></a>
  </div>
  <div class="col-md-4">
    <a href="maintenance.php" class="panel su-card"><i class="fa-solid fa-screwdriver-wrench"></i><b>🛠️ Site Maintenance Mode</b><span>काम चालू असताना Super Admin सोडून इतरांचा Login तात्पुरता थांबवा</span></a>
  </div>
  <div class="col-md-4">
    <a href="push-setup.php" class="panel su-card"><i class="fa-solid fa-bell"></i><b>📲 Push Notification Setup</b><span>Browser / Mobile वर सूचना पाठवण्याची व्यवस्था</span></a>
  </div>
</div>
<style>
  .su-card { display: flex; flex-direction: column; gap: 6px; padding: 18px 20px; text-decoration: none; color: inherit; height: 100%; transition: border-color .15s, transform .15s; }
  .su-card:hover { border-color: var(--rail); transform: translateY(-2px); }
  .su-card i { font-size: 20px; color: var(--rail); }
  .su-card b { font-size: 14px; } .su-card span { font-size: 12px; color: var(--text-dim); }
</style>

<?php if (!empty($safetyBackups)): ?>
  <div class="panel mt-4">
    <div class="panel-head"><div class="title">🛡️ जुने Safety Backups</div><div class="sub">आधीच्या ZIP Updates वेळी घेतलेले — गरज असल्यास Download करता येतात</div></div>
    <table class="rtable">
      <thead><tr><th>File</th><th>आकार</th><th>दिनांक</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($safetyBackups as $sb): ?>
          <tr>
            <td class="font-mono" style="font-size:11.5px;"><?= h($sb['name']) ?></td>
            <td><?= $sb['size'] ?> MB</td>
            <td><?= h($sb['date']) ?></td>
            <td><a href="download-backup.php?file=<?= urlencode($sb['name']) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11.5px;padding:5px 12px;"><i class="fa-solid fa-download me-1"></i>Download</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
