<?php
$pageTitle = 'माझ्या निवास-विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT r.*, b.name AS building_name, ml.name AS allotted_room_name, ab.name AS allotted_building_name
    FROM residency_allotment_requests r
    LEFT JOIN buildings b ON b.id = r.preferred_building_id
    LEFT JOIN maintenance_locations ml ON ml.id = r.allotted_location_id
    LEFT JOIN buildings ab ON ab.id = ml.building_id
    WHERE r.organization_id = ? AND r.requested_by = ? ORDER BY r.created_at DESC
");
$stmt->execute([$orgId, $user['id']]);
$requests = $stmt->fetchAll();

$statusLabels = ['pending' => '⏳ प्रलंबित', 'approved' => '✅ मंजूर', 'rejected' => '❌ नामंजूर', 'waitlisted' => '📋 प्रतीक्षा-यादीत'];
$statusChips = ['pending' => 'pending', 'approved' => 'done', 'rejected' => 'overdue', 'waitlisted' => 'pending'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 माझ्या निवास-विनंत्या</div>
  <a href="request-allotment.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन विनंती
  </a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>पसंतीची Building</th><th>कारण</th><th>स्थिती</th><th>वाटप झालेली Room</th></tr></thead>
    <tbody>
      <?php if (empty($requests)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">तुम्ही अजून कुठलीही विनंती केलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($requests as $r): ?>
        <tr>
          <td><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td><?= h($r['building_name'] ?: '—') ?></td>
          <td style="max-width:220px;"><?= h($r['reason']) ?></td>
          <td><span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span></td>
          <td><?= $r['allotted_room_name'] ? h($r['allotted_building_name'] ? $r['allotted_building_name'] . ' — ' : '') . h($r['allotted_room_name']) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
