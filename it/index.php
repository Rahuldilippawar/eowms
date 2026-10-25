<?php
$pageTitle = 'IT विभाग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/it-helpers.php';
requireItAccess($pdo, $user);

$orgId = activeOrgId();

$dbNotReady = false;
$totalLabs = 0; $totalAssets = 0; $pendingTickets = 0; $totalSmartBoards = 0;
$assetStats = ['working' => 0, 'faulty' => 0, 'under_repair' => 0, 'scrapped' => 0];
$sbStats = ['working' => 0, 'faulty' => 0, 'under_repair' => 0, 'scrapped' => 0];
$labs = [];

try {
    $totalLabsStmt = $pdo->prepare("SELECT COUNT(*) c FROM it_labs WHERE organization_id = ? AND is_active = 1");
    $totalLabsStmt->execute([$orgId]);
    $totalLabs = (int)$totalLabsStmt->fetch()['c'];

    $assetStatsStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM it_assets WHERE organization_id = ? GROUP BY status");
    $assetStatsStmt->execute([$orgId]);
    foreach ($assetStatsStmt->fetchAll() as $r) { $assetStats[$r['status']] = (int)$r['c']; }
    $totalAssets = array_sum($assetStats);

    $pendingTicketsStmt = $pdo->prepare("SELECT COUNT(*) c FROM it_repair_tickets WHERE organization_id = ? AND status != 'resolved'");
    $pendingTicketsStmt->execute([$orgId]);
    $pendingTickets = (int)$pendingTicketsStmt->fetch()['c'];
    try {
        $pendingGeneralStmt = $pdo->prepare("SELECT COUNT(*) c FROM work_requests WHERE organization_id = ? AND category = 'it' AND status IN ('pending','in_progress')");
        $pendingGeneralStmt->execute([$orgId]);
        $pendingTickets += (int)$pendingGeneralStmt->fetch()['c'];
    } catch (PDOException $e) { /* table not migrated yet */ }

    $smartBoardStmt = $pdo->prepare("SELECT status, COUNT(*) c FROM it_assets WHERE organization_id = ? AND asset_type = 'smartboard' GROUP BY status");
    $smartBoardStmt->execute([$orgId]);
    foreach ($smartBoardStmt->fetchAll() as $r) { $sbStats[$r['status']] = (int)$r['c']; }
    $totalSmartBoards = array_sum($sbStats);

    $labsStmt = $pdo->prepare("
        SELECT l.*, u.name AS assistant_name,
          (SELECT COUNT(*) FROM it_assets WHERE lab_id = l.id) AS asset_count,
          (SELECT COUNT(*) FROM it_assets WHERE lab_id = l.id AND status = 'working') AS working_count,
          (SELECT COUNT(*) FROM it_assets WHERE lab_id = l.id AND status IN ('faulty','under_repair')) AS issue_count
        FROM it_labs l LEFT JOIN users u ON u.id = l.assistant_id
        WHERE l.organization_id = ? AND l.is_active = 1 ORDER BY l.lab_name
    ");
    $labsStmt->execute([$orgId]);
    $labs = $labsStmt->fetchAll();
} catch (PDOException $e) {
    // IT विभागाच्या Tables अजून Database मध्ये तयार झालेल्या नाहीत —
    // म्हणजे database/migration-it-department.sql अजून चालवलेली नाही.
    $dbNotReady = true;
}
?>

<?php if ($dbNotReady): ?>
  <div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
  <div class="page-title mb-3">🖥️ IT विभाग</div>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;padding:18px 22px;">
    ⚠️ <strong>Database अजून तयार नाही.</strong> IT विभागासाठी लागणाऱ्या Tables (it_labs, it_assets, इ.) अजून तयार झालेल्या नाहीत.<br><br>
    <strong>उपाय:</strong> phpMyAdmin मध्ये जाऊन <span class="font-mono" style="background:#fff;padding:2px 8px;border-radius:5px;">database/migration-it-department.sql</span> ही File चालवा, मग हे पान Refresh करा.
  </div>
  <?php require_once __DIR__ . '/../includes/footer.php'; exit; ?>
<?php endif; ?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🖥️ IT विभाग</div>
  <div class="d-flex gap-2">
    <a href="labs.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;">Labs</a>
    <a href="general-requests-manage.php" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:13px;padding:9px 16px;font-weight:600;position:relative;">
      🖥️ IT Requests व्यवस्थापन <?php if ($pendingTickets > 0): ?><span style="background:var(--rose);color:#fff;border-radius:20px;font-size:10.5px;padding:1px 7px;margin-left:4px;"><?= $pendingTickets ?></span><?php endif; ?>
    </a>
    <a href="smartboard-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;">📺 Smart Board अहवाल</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Labs</div><div class="val"><?= $totalLabs ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Assets</div><div class="val"><?= $totalAssets ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ चालू</div><div class="val" style="color:var(--rail);"><?= $assetStats['working'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 बिघडलेले</div><div class="val" style="color:var(--rose);"><?= $assetStats['faulty'] + $assetStats['under_repair'] ?></div></div></div>
</div>

<div class="panel mb-3" style="border-color:var(--rail);">
  <div class="panel-head"><div class="title">📺 Smart Board — सारांश</div></div>
  <div style="padding:16px 20px;display:flex;gap:24px;flex-wrap:wrap;">
    <div><div style="font-size:10.5px;color:var(--text-dim);text-transform:uppercase;">एकूण</div><div style="font-size:20px;font-weight:700;"><?= $totalSmartBoards ?></div></div>
    <div><div style="font-size:10.5px;color:var(--text-dim);text-transform:uppercase;">✅ चालू</div><div style="font-size:20px;font-weight:700;color:var(--rail);"><?= $sbStats['working'] ?></div></div>
    <div><div style="font-size:10.5px;color:var(--text-dim);text-transform:uppercase;">🔴 बिघडलेले</div><div style="font-size:20px;font-weight:700;color:var(--rose);"><?= $sbStats['faulty'] + $sbStats['under_repair'] ?></div></div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">🏫 Lab-निहाय स्थिती</div></div>
  <table class="rtable">
    <thead><tr><th>Lab</th><th>Assistant</th><th>एकूण Assets</th><th>चालू</th><th>समस्या</th><th>आरोग्य</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($labs)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतीही Lab जोडलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($labs as $l):
        $health = $l['asset_count'] > 0 ? round(($l['working_count'] / $l['asset_count']) * 100) : null;
      ?>
        <tr>
          <td style="font-weight:600;"><?= h($l['lab_name']) ?></td>
          <td><?= h($l['assistant_name'] ?? '— नेमलेला नाही —') ?></td>
          <td><?= $l['asset_count'] ?></td>
          <td style="color:var(--rail);font-weight:600;"><?= $l['working_count'] ?></td>
          <td style="color:<?= $l['issue_count'] > 0 ? 'var(--rose)' : 'var(--text-dim)' ?>;font-weight:600;"><?= $l['issue_count'] ?></td>
          <td>
            <?php if ($health !== null): ?>
              <div style="display:flex;align-items:center;gap:8px;">
                <div style="background:var(--canvas);border-radius:10px;height:8px;width:70px;overflow:hidden;">
                  <div style="height:100%;width:<?= $health ?>%;background:<?= $health >= 80 ? 'var(--rail)' : ($health >= 50 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
                </div>
                <span style="font-size:11.5px;font-weight:600;"><?= $health ?>%</span>
              </div>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td><a href="assets.php?lab_id=<?= $l['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;">बघा →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
