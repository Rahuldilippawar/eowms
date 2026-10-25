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

// सगळ्या Cron Jobs ची शेवटची वेळ — Cron बंद पडला तर इथे लगेच दिसतं
require_once __DIR__ . '/../includes/cron-helpers.php';
$cronRuns = [];
try {
    foreach ($pdo->query("SELECT * FROM cron_job_runs")->fetchAll() as $r) { $cronRuns[$r['job_key']] = $r; }
} catch (PDOException $e) { $cronRuns = null; /* migration not run yet */ }
$cronHost = preg_replace('/[^a-zA-Z0-9_-]/', '', $_SERVER['HTTP_HOST'] ?? 'yourusername');
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
    <div style="margin-top:12px;">
      <strong>शिफारस:</strong> वेगवेगळ्या Cron Lines ऐवजी — फक्त ही <strong>एकच ओळ</strong> (दर 15 मिनिटांनी) टाका. ती Backup, नूतनीकरण Reminders, उशिरा टास्क, वाहन Service, गोशाळा लसीकरण इ. सगळे Jobs त्यांच्या ठरलेल्या वेळी क्रमाने चालवते:
    </div>
    <div class="font-mono" style="background:var(--canvas);padding:10px 14px;border-radius:8px;margin-top:8px;font-size:12px;">
      */5 * * * * php /home/<?= h($cronHost) ?>/public_html/cron/run-all.php
    </div>
  </div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">⏱️ Cron Jobs — शेवटचं कधी चाललं</div><div class="sub">run-all.php मधून (किंवा नवीन Jobs स्वतंत्र चालवल्यास) नोंदवलं जातं</div></div>
  <?php if ($cronRuns === null): ?>
    <div style="padding:16px 20px;font-size:12.5px;color:var(--text-dim);">Database Update आवश्यक आहे (cron_job_runs Table नाही).</div>
  <?php else:
    $runAll = $cronRuns['run-all'] ?? null;
    $runAllStale = !$runAll || (time() - strtotime($runAll['last_finished_at'] ?? $runAll['last_started_at'])) > 3600;
  ?>
    <div style="padding:12px 20px 0;font-size:12.5px;">
      <?php if ($runAllStale): ?>
        <span style="color:var(--rose);font-weight:600;">⚠️ run-all.php गेल्या 1 तासात चाललेलं नाही<?= $runAll ? ' (शेवटचं: ' . date('d M Y, h:i A', strtotime($runAll['last_started_at'])) . ')' : '' ?> — Cron Job तपासा.</span>
      <?php else: ?>
        <span style="color:var(--rail);font-weight:600;">✅ run-all.php शेवटचं: <?= date('d M Y, h:i A', strtotime($runAll['last_started_at'])) ?></span>
      <?php endif; ?>
    </div>
    <table class="rtable">
      <thead><tr><th>Job</th><th>वेळापत्रक</th><th>शेवटचं चाललं</th><th>कालावधी</th><th>स्थिती</th><th>शेवटचा Output</th></tr></thead>
      <tbody>
        <?php foreach (cronRunAllJobs() as $jobKey => $sched):
          $run = $cronRuns[$jobKey] ?? null;
          $maxAge = isset($sched['every']) ? $sched['every'] * 60 * 4 : 26 * 3600;
          $stale = !$run || (time() - strtotime($run['last_started_at'])) > $maxAge;
          $chip = !$run ? 'pending' : ($run['last_status'] === 'failed' || $stale ? 'overdue' : ($run['last_status'] === 'running' ? 'pending' : 'done'));
          $chipText = !$run ? 'कधीच नाही' : ($run['last_status'] === 'failed' ? 'अयशस्वी' : ($stale ? 'जुनं' : ($run['last_status'] === 'running' ? 'चालू' : 'यशस्वी')));
        ?>
          <tr>
            <td class="font-mono" style="font-size:11.5px;"><?= h($jobKey) ?></td>
            <td style="font-size:11.5px;"><?= isset($sched['every']) ? 'दर ' . (int)$sched['every'] . ' मिनिटांनी' : 'रोज ' . (int)$sched['daily'] . ':00 नंतर' ?></td>
            <td style="font-size:12px;"><?= $run && $run['last_started_at'] ? date('d M Y, h:i A', strtotime($run['last_started_at'])) : '—' ?></td>
            <td style="font-size:12px;"><?= $run && $run['last_duration_sec'] !== null ? (int)$run['last_duration_sec'] . ' s' : '—' ?></td>
            <td><span class="status-chip <?= $chip ?>"><?= h($chipText) ?></span></td>
            <td style="font-size:11px;color:var(--text-dim);max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= h($run['last_output'] ?? '') ?>"><?= h(mb_substr($run['last_output'] ?? '', -120)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
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
