<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();

$period = $_GET['period'] ?? 'weekly';
$days = $period === 'monthly' ? 30 : 7;
$startDate = date('Y-m-d', strtotime("-" . ($days - 1) . " days"));
$endDate = date('Y-m-d');

$pageTitle = 'CCTV अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$dvrsStmt = $pdo->prepare("SELECT * FROM cctv_dvrs WHERE organization_id = ? AND is_active = 1 ORDER BY dvr_name");
$dvrsStmt->execute([$orgId]);
$dvrs = $dvrsStmt->fetchAll();

$rows = [];
foreach ($dvrs as $dvr) {
    $checkupsStmt = $pdo->prepare("SELECT * FROM cctv_checkups WHERE dvr_id = ? AND checkup_date BETWEEN ? AND ?");
    $checkupsStmt->execute([$dvr['id'], $startDate, $endDate]);
    $checkups = $checkupsStmt->fetchAll();

    $checkupCompliance = $days > 0 ? round((count($checkups) / $days) * 100) : 0;
    $backupsDone = count(array_filter($checkups, fn($c) => $c['backup_done']));
    $backupCompliance = count($checkups) > 0 ? round(($backupsDone / count($checkups)) * 100) : null;
    $avgStorage = count($checkups) > 0 ? round(array_sum(array_column($checkups, 'storage_used_percent')) / count($checkups)) : null;

    // Camera Uptime — या कालावधीतल्या सगळ्या Checkups मध्ये किती % वेळा "working" आलं
    $uptimeStmt = $pdo->prepare("
        SELECT cs.status, COUNT(*) c FROM cctv_camera_status cs
        JOIN cctv_checkups ck ON ck.id = cs.checkup_id
        WHERE ck.dvr_id = ? AND ck.checkup_date BETWEEN ? AND ?
        GROUP BY cs.status
    ");
    $uptimeStmt->execute([$dvr['id'], $startDate, $endDate]);
    $uptimeCounts = ['working' => 0, 'not_working' => 0, 'needs_attention' => 0];
    foreach ($uptimeStmt->fetchAll() as $r) { $uptimeCounts[$r['status']] = (int)$r['c']; }
    $totalStatusEntries = array_sum($uptimeCounts);
    $cameraUptime = $totalStatusEntries > 0 ? round(($uptimeCounts['working'] / $totalStatusEntries) * 100) : null;

    $rows[] = [
        'dvr' => $dvr, 'checkupCompliance' => $checkupCompliance, 'checkupCount' => count($checkups),
        'backupCompliance' => $backupCompliance, 'avgStorage' => $avgStorage, 'cameraUptime' => $cameraUptime,
    ];
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 CCTV अहवाल</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>CCTV विभाग</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= date('d M Y', strtotime($startDate)) ?> — <?= date('d M Y', strtotime($endDate)) ?></p>

<div class="tabrow-custom mb-4" style="display:flex;gap:8px;">
  <a href="?period=weekly" class="btn" style="border-radius:9px;font-size:13px;padding:8px 18px;<?= $period === 'weekly' ? 'background:var(--ink);color:#fff;' : 'border:1px solid var(--line);' ?>">साप्ताहिक (7 दिवस)</a>
  <a href="?period=monthly" class="btn" style="border-radius:9px;font-size:13px;padding:8px 18px;<?= $period === 'monthly' ? 'background:var(--ink);color:#fff;' : 'border:1px solid var(--line);' ?>">मासिक (30 दिवस)</a>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>DVR</th><th>Checkup Compliance</th><th>Backup Compliance</th><th>सरासरी Storage वापर</th><th>Camera Uptime</th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही DVR नाही</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:600;"><?= h($r['dvr']['dvr_name']) ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="background:var(--canvas);border-radius:10px;height:8px;width:80px;overflow:hidden;">
                <div style="height:100%;width:<?= $r['checkupCompliance'] ?>%;background:<?= $r['checkupCompliance'] >= 80 ? 'var(--rail)' : ($r['checkupCompliance'] >= 50 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
              </div>
              <strong style="font-size:12.5px;"><?= $r['checkupCompliance'] ?>%</strong>
              <span style="font-size:10.5px;color:var(--text-dim);">(<?= $r['checkupCount'] ?>/<?= $days ?> दिवस)</span>
            </div>
          </td>
          <td><?= $r['backupCompliance'] !== null ? $r['backupCompliance'] . '%' : '—' ?></td>
          <td><?= $r['avgStorage'] !== null ? $r['avgStorage'] . '%' : '—' ?></td>
          <td>
            <?php if ($r['cameraUptime'] !== null): ?>
              <span style="font-weight:700;color:<?= $r['cameraUptime'] >= 90 ? 'var(--rail)' : ($r['cameraUptime'] >= 70 ? 'var(--amber)' : 'var(--rose)') ?>;"><?= $r['cameraUptime'] ?>%</span>
            <?php else: ?>—<?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
