<?php
$pageTitle = 'Automated Backup';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM scheduled_backups WHERE organization_id = ? ORDER BY created_at DESC LIMIT 60");
$stmt->execute([$orgId]);
$backups = $stmt->fetchAll();

$successCount = count(array_filter($backups, fn($b) => $b['status'] === 'success'));
$lastSuccess = null;
foreach ($backups as $b) { if ($b['status'] === 'success') { $lastSuccess = $b; break; } }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">⏰ Automated Scheduled Backup</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">रोज आपोआप होणारे Backups — शेवटचे 14 जतन राहतात, त्याहून जुने आपोआप काढले जातात</div>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Manual Backup कडे परत</a>
</div>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-4"><div class="kpi-mini"><div class="lbl">एकूण यशस्वी Backups</div><div class="val" style="color:var(--rail);"><?= $successCount ?></div></div></div>
  <div class="col-6 col-lg-4"><div class="kpi-mini"><div class="lbl">शेवटचं यशस्वी Backup</div><div class="val" style="font-size:15px;"><?= $lastSuccess ? date('d M Y, h:i A', strtotime($lastSuccess['created_at'])) : '—' ?></div></div></div>
</div>

<?php if (!$lastSuccess): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
    ⚠️ अजून कुठलंही Automated Backup झालेलं नाही — Cron Job सेट केलेलं आहे का तपासा (खाली सूचना दिलेल्या आहेत).
  </div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📖 Cron Job कसा सेट करायचा</div></div>
  <div style="padding:16px 20px;font-size:12.5px;color:var(--text-dim);">
    Hostinger <strong>hPanel → Advanced → Cron Jobs</strong> मध्ये जाऊन खालील टाका (रोज रात्री 2 वाजता चालेल):
    <div class="font-mono" style="background:var(--canvas);padding:10px 14px;border-radius:8px;margin-top:8px;font-size:12px;">
      0 2 * * * php /home/<?= h(preg_replace('/[^a-zA-Z0-9_-]/', '', $_SERVER['HTTP_HOST'] ?? 'yourusername')) ?>/public_html/cron/scheduled-backup.php
    </div>
  </div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>File</th><th>आकार</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($backups)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतंही Backup नाही</td></tr><?php endif; ?>
      <?php foreach ($backups as $b): ?>
        <tr>
          <td><?= date('d M Y, h:i A', strtotime($b['created_at'])) ?></td>
          <td class="font-mono" style="font-size:11.5px;"><?= h($b['filename'] ?: '—') ?></td>
          <td><?= $b['file_size_kb'] ? number_format($b['file_size_kb']) . ' KB' : '—' ?></td>
          <td><span class="status-chip <?= $b['status'] === 'success' ? 'done' : 'overdue' ?>"><?= $b['status'] === 'success' ? 'यशस्वी' : 'अयशस्वी' ?></span></td>
          <td><?php if ($b['status'] === 'success'): ?><a href="download-scheduled.php?id=<?= $b['id'] ?>" style="font-size:11.5px;color:var(--rail);">डाउनलोड करा</a><?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
