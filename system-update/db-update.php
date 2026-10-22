<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/db-migrate-helpers.php';
$user = currentUser();

$migrationFile = __DIR__ . '/../database/RUN-ALL-MIGRATIONS.sql';
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run') {
    if (!is_file($migrationFile)) {
        $_SESSION['form_error'] = 'database/RUN-ALL-MIGRATIONS.sql ही File Server वर सापडली नाही — ती Upload करा आणि पुन्हा प्रयत्न करा.';
        header('Location: db-update.php');
        exit;
    }
    @set_time_limit(300);
    $result = dbmRunMigrationFile($pdo, $migrationFile);
    logActivity('Database Update चालवलं', 'system_update', null, "ran={$result['ran']}, skipped={$result['skipped']}, errors=" . count($result['errors']));
}

$pageTitle = 'Database Update';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🛠️ Database Update</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>System Update</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">नवीन Features साठी लागणाऱ्या Tables/Columns Database मध्ये जोडतो — phpMyAdmin ची गरज नाही.</p>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if ($result !== null): ?>
  <?php if (empty($result['errors'])): ?>
    <div class="alert alert-success" style="border-radius:10px;font-size:13px;">
      ✅ <strong>Database Update पूर्ण झालं.</strong>
      <?= (int)$result['ran'] ?> बदल लागू केले, <?= (int)$result['skipped'] ?> आधीच झालेले होते (वगळले).
      <?php if (!empty($result['created'])): ?><br>नवीन Tables: <span class="font-mono"><?= h(implode(', ', $result['created'])) ?></span><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
      ⚠️ Update झालं, पण <?= count($result['errors']) ?> बदल लागू होऊ शकले नाहीत (<?= (int)$result['ran'] ?> लागू, <?= (int)$result['skipped'] ?> आधीच होते).
      खालची यादी Developer ला पाठवा.
    </div>
    <div class="panel mb-3" style="padding:16px 20px;">
      <?php foreach ($result['errors'] as [$section, $sql, $msg]): ?>
        <div style="font-size:12px;padding:8px 0;border-bottom:1px solid var(--line);">
          <div style="font-weight:600;"><?= h($section) ?></div>
          <div class="font-mono" style="color:var(--rose);"><?= h($msg) ?></div>
          <div class="font-mono" style="color:var(--text-dim);font-size:11px;"><?= h($sql) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="panel" style="padding:22px 24px;">
  <div style="font-size:13px;line-height:1.9;margin-bottom:14px;">
    एखादं पान <strong>"Database Update आवश्यक आहे"</strong> असं दाखवत असेल, तर हे बटण दाबा.
    <ul style="margin:8px 0 0 18px;padding:0;">
      <li>आधीच असलेल्या Tables/Columns ना हात लावत नाही — फक्त जे नाही ते जोडतो.</li>
      <li>कितीही वेळा चालवलं तरी सुरक्षित — दुसऱ्यांदा चालवल्यास काहीही दुप्पट होत नाही.</li>
      <li>तुमचा Data (कर्मचारी, नोंदी, पत्रं) बदलत किंवा पुसत नाही.</li>
    </ul>
  </div>
  <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">💡 खात्री म्हणून आधी Hostinger hPanel → Databases → Backups मधून Database चा Backup घ्या.</div>
  <form method="POST" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='चालू आहे… पान बंद करू नका';">
    <input type="hidden" name="action" value="run">
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px 22px;font-weight:600;">🛠️ Database Update चालवा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
