<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);
$orgId = activeOrgId();

$pageTitle = 'Smart Board अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sbStmt = $pdo->prepare("
    SELECT a.*, l.lab_name,
      (SELECT COUNT(*) FROM it_repair_tickets WHERE asset_id = a.id) AS ticket_count,
      (SELECT COUNT(*) FROM it_repair_tickets WHERE asset_id = a.id AND status != 'resolved') AS pending_ticket_count
    FROM it_assets a JOIN it_labs l ON l.id = a.lab_id
    WHERE a.organization_id = ? AND a.asset_type = 'smartboard'
    ORDER BY l.lab_name, a.asset_tag
");
$sbStmt->execute([$orgId]);
$smartBoards = $sbStmt->fetchAll();

$statusLabels = ['working' => '✅ चालू', 'faulty' => '🔴 बिघडलेलं', 'under_repair' => '🟡 दुरुस्तीत', 'scrapped' => '⚫ बाद केलेलं'];
$statusChips = ['working' => 'done', 'faulty' => 'overdue', 'under_repair' => 'pending', 'scrapped' => 'overdue'];

$total = count($smartBoards);
$working = count(array_filter($smartBoards, fn($s) => $s['status'] === 'working'));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📺 Smart Board अहवाल</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>IT विभाग</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"></p>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Smart Boards</div><div class="val"><?= $total ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ चालू</div><div class="val" style="color:var(--rail);"><?= $working ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 बिघडलेले</div><div class="val" style="color:var(--rose);"><?= $total - $working ?></div></div></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Tag</th><th>Lab</th><th>Brand/Model</th><th>Warranty</th><th>एकूण तक्रारी</th><th>स्थिती</th><th>⚡ Power</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($smartBoards)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही Smart Board नोंदवलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($smartBoards as $sb): ?>
        <tr>
          <td class="font-mono" style="font-weight:600;"><?= h($sb['asset_tag']) ?></td>
          <td><?= h($sb['lab_name']) ?></td>
          <td><?= h($sb['brand']) ?> <?= h($sb['model']) ?></td>
          <td><?= $sb['warranty_expiry'] ? date('d M Y', strtotime($sb['warranty_expiry'])) : '—' ?></td>
          <td><?= $sb['ticket_count'] ?> <?php if ($sb['pending_ticket_count'] > 0): ?><span style="color:var(--rose);font-size:11px;">(<?= $sb['pending_ticket_count'] ?> प्रलंबित)</span><?php endif; ?></td>
          <td><span class="status-chip <?= $statusChips[$sb['status']] ?>"><?= $statusLabels[$sb['status']] ?></span></td>
          <td><span class="status-chip <?= $sb['power_status'] === 'on' ? 'done' : 'overdue' ?>"><?= $sb['power_status'] === 'on' ? '🟢 ON' : '🔴 OFF' ?></span></td>
          <td><a href="asset-view.php?id=<?= $sb['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;">बघा →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
