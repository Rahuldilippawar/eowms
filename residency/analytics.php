<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();

$pageTitle = 'Occupancy Analytics';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$totalRoomsStmt = $pdo->prepare("SELECT COUNT(*) c FROM maintenance_locations WHERE organization_id = ? AND is_active = 1");
$totalRoomsStmt->execute([$orgId]);
$totalRooms = (int)$totalRoomsStmt->fetch()['c'];

$occupiedRoomsStmt = $pdo->prepare("SELECT COUNT(DISTINCT location_id) c FROM residency_occupants WHERE organization_id = ? AND status = 'active'");
$occupiedRoomsStmt->execute([$orgId]);
$occupiedRooms = (int)$occupiedRoomsStmt->fetch()['c'];
$vacantRooms = max(0, $totalRooms - $occupiedRooms);
$occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100) : 0;

$totalOccupantsStmt = $pdo->prepare("SELECT COUNT(*) c FROM residency_occupants WHERE organization_id = ? AND status = 'active'");
$totalOccupantsStmt->execute([$orgId]);
$totalOccupants = (int)$totalOccupantsStmt->fetch()['c'];

// सरासरी वास्तव्य-काळ (महिन्यांत) — Vacate झालेल्या नोंदींवरून
$avgTenureStmt = $pdo->prepare("SELECT AVG(DATEDIFF(vacated_date, moved_in_date)) AS avg_days FROM residency_occupants WHERE organization_id = ? AND status = 'vacated' AND moved_in_date IS NOT NULL AND vacated_date IS NOT NULL");
$avgTenureStmt->execute([$orgId]);
$avgDays = (float)($avgTenureStmt->fetch()['avg_days'] ?? 0);
$avgMonths = $avgDays > 0 ? round($avgDays / 30) : null;

// प्रतीक्षा-यादी संख्या
$waitlistStmt = $pdo->prepare("SELECT COUNT(*) c FROM residency_allotment_requests WHERE organization_id = ? AND status IN ('pending','waitlisted')");
$waitlistStmt->execute([$orgId]);
$waitlistCount = (int)$waitlistStmt->fetch()['c'];

// Building-निहाय विभाजन
$buildingStmt = $pdo->prepare("
    SELECT b.name AS building_name,
      COUNT(DISTINCT ml.id) AS total_rooms,
      COUNT(DISTINCT ro.location_id) AS occupied_rooms
    FROM buildings b
    LEFT JOIN maintenance_locations ml ON ml.building_id = b.id AND ml.is_active = 1
    LEFT JOIN residency_occupants ro ON ro.location_id = ml.id AND ro.status = 'active'
    WHERE b.organization_id = ?
    GROUP BY b.id, b.name
");
$buildingStmt->execute([$orgId]);
$buildingBreakdown = $buildingStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-3">📊 Occupancy Analytics</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">एकूण Rooms</div><div class="val"><?= $totalRooms ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">भरलेल्या</div><div class="val" style="color:var(--rail);"><?= $occupiedRooms ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">रिकाम्या</div><div class="val" style="color:var(--amber);"><?= $vacantRooms ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">Occupancy दर</div><div class="val"><?= $occupancyRate ?>%</div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">सरासरी वास्तव्य</div><div class="val"><?= $avgMonths !== null ? $avgMonths . ' म.' : '—' ?></div></div></div>
  <div class="col-6 col-lg-2"><div class="kpi-mini"><div class="lbl">📋 प्रतीक्षा-यादी</div><div class="val" style="color:var(--rose);"><?= $waitlistCount ?></div></div></div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">Occupancy दर</div></div>
      <div style="padding:16px 20px;"><canvas id="occupancyChart" height="180"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">Building-निहाय विभाजन</div></div>
      <table class="rtable">
        <thead><tr><th>Building</th><th>एकूण Rooms</th><th>भरलेल्या</th><th>Occupancy %</th></tr></thead>
        <tbody>
          <?php if (empty($buildingBreakdown)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:20px;">अजून कोणतीही Building नाही</td></tr><?php endif; ?>
          <?php foreach ($buildingBreakdown as $b):
            $pct = $b['total_rooms'] > 0 ? round(($b['occupied_rooms'] / $b['total_rooms']) * 100) : 0;
          ?>
            <tr>
              <td style="font-weight:600;"><?= h($b['building_name']) ?></td>
              <td class="font-mono"><?= $b['total_rooms'] ?></td>
              <td class="font-mono" style="color:var(--rail);"><?= $b['occupied_rooms'] ?></td>
              <td class="font-mono"><?= $pct ?>%</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function () {
  new Chart(document.getElementById('occupancyChart'), {
    type: 'doughnut',
    data: {
      labels: ['भरलेल्या', 'रिकाम्या'],
      datasets: [{ data: [<?= $occupiedRooms ?>, <?= $vacantRooms ?>], backgroundColor: ['#1B8A83', '#E4E6EC'], borderWidth: 0 }]
    },
    options: { plugins: { legend: { position: 'bottom' } }, cutout: '65%' }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
