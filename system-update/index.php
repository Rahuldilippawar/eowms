<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/update-helpers.php';
$user = currentUser();

$stagingDir = __DIR__ . '/../update-staging';
$hasStaged = is_dir($stagingDir) && count(array_diff(scandir($stagingDir), ['.', '..', '.htaccess'])) > 0;

$stagedFiles = [];
if ($hasStaged) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($stagingDir, RecursiveDirectoryIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile()) continue;
        $rel = str_replace('\\', '/', substr($file->getRealPath(), strlen($stagingDir) + 1));
        if ($rel === '.htaccess') continue;
        $stagedFiles[] = $rel;
    }
    sort($stagedFiles);
}

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
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Developer कडून मिळालेली नवीन ZIP Upload करून, App स्वतःच Update करा — FTP/File Manager ची गरज नाही.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if (!class_exists('ZipArchive')): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:12.5px;padding:16px 20px;">
    ❌ <strong>Server वर PHP चा "zip" Extension Enabled नाही</strong> — त्यामुळे हे Feature सध्या वापरता येणार नाही.<br><br>
    <strong>जर Localhost/XAMPP वर असाल:</strong>
    <ol style="margin:6px 0 0 18px;padding:0;">
      <li><span class="font-mono">C:\xampp\php\php.ini</span> ही File Notepad ने उघडा</li>
      <li>त्यात <span class="font-mono">;extension=zip</span> असं शोधा — सुरुवातीचा <span class="font-mono">;</span> (Semicolon) काढून टाका</li>
      <li>Save करा, मग XAMPP Control Panel मधून Apache **Stop → Start** करा</li>
      <li>हे पान Refresh करा</li>
    </ol>
    <strong>जर Live Hosting (Hostinger) वर असाल:</strong> hPanel → PHP Configuration मध्ये जाऊन "zip" Extension चालू करा, किंवा Hosting Support ला विचारा.
  </div>
<?php endif; ?>

<div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;">
  🛡️ <strong>सुरक्षा:</strong> हे Folders कधीही बदलले जात नाहीत — <span class="font-mono">assets/uploads/, assets/audio/, backups/, config/</span>.
  Apply करण्याआधी सध्याच्या सगळ्या Files चा आपोआप Backup घेतला जातो.
</div>

<?php if (!$hasStaged): ?>
  <div class="panel mt-3">
    <div class="panel-head"><div class="title">1️⃣ नवीन Update ZIP Upload करा</div></div>
    <form method="POST" action="upload.php" enctype="multipart/form-data" style="padding:22px 24px;">
      <input type="file" name="update_zip" accept=".zip" required class="form-control-custom mb-3">
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 22px;font-weight:600;">
        <i class="fa-solid fa-upload me-1"></i>Upload करून तपासा
      </button>
    </form>
  </div>
<?php else: ?>
  <div class="panel mt-3" style="border-color:var(--amber);">
    <div class="panel-head"><div class="title">2️⃣ काय-काय बदलणार आहे — तपासा</div></div>
    <div style="padding:16px 24px;">
      <p style="font-size:12.5px;color:var(--text-dim);">एकूण <strong><?= count($stagedFiles) ?></strong> Files Update होतील:</p>
      <div style="max-height:280px;overflow-y:auto;background:var(--canvas);border-radius:10px;padding:12px 16px;font-family:'JetBrains Mono',monospace;font-size:11.5px;">
        <?php foreach ($stagedFiles as $f): ?>
          <div><?= h($f) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="panel mt-3" style="border-color:var(--rose);">
    <div class="panel-head"><div class="title">3️⃣ Apply करा (अंतिम पायरी)</div></div>
    <form method="POST" action="apply.php" style="padding:22px 24px;">
      <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">Apply करण्याआधी सध्याच्या सगळ्या Files चा Backup आपोआप घेतला जाईल. खात्री करण्यासाठी खाली <strong>CONFIRM</strong> टाईप करा.</p>
      <input type="text" name="confirm_text" class="form-control-custom mb-3" placeholder="CONFIRM असं टाईप करा" required style="max-width:280px;">
      <div class="d-flex gap-2">
        <button type="submit" class="btn" style="background:var(--rose);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 22px;font-weight:600;">
          <i class="fa-solid fa-triangle-exclamation me-1"></i>Update Apply करा
        </button>
        <button type="submit" formaction="cancel.php" formnovalidate class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 22px;">रद्द करा</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php if (!empty($safetyBackups)): ?>
  <div class="panel mt-4">
    <div class="panel-head"><div class="title">🛡️ अलीकडचे Safety Backups (Update करण्याआधीचे)</div></div>
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
