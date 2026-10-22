<?php
$pageTitle = 'वाहन डॅशबोर्ड';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$vehicleId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

// Access: management with manage_vehicles permission, or the Transport (TRP) dept staff/HOD, or the assigned driver themselves
$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

$stmt = $pdo->prepare("SELECT v.*, u.name AS driver_name, u.mobile AS driver_mobile, c.name AS campus_name FROM vehicles v LEFT JOIN users u ON u.id = v.driver_id LEFT JOIN campuses c ON c.id = v.campus_id WHERE v.id = ? AND v.organization_id = ?");
$stmt->execute([$vehicleId, $orgId]);
$vehicle = $stmt->fetch();
if (!$vehicle) { die('वाहन सापडले नाही.'); }

$canLog = hasPermission('manage_vehicles') || $userDeptCode === 'TRP' || (int)$vehicle['driver_id'] === (int)$user['id'];
if (!$canLog && !hasRole('super_admin') && !isManagementOrAbove()) {
    http_response_code(403);
    die('तुम्हाला हे वाहन पाहण्याची परवानगी नाही.');
}

// Recent trips
$stmt = $pdo->prepare("SELECT vt.*, u.name AS driver_name FROM vehicle_trips vt LEFT JOIN users u ON u.id = vt.driver_id WHERE vt.vehicle_id = ? ORDER BY vt.trip_date DESC, vt.id DESC LIMIT 15");
$stmt->execute([$vehicleId]);
$trips = $stmt->fetchAll();

// Recent fuel fills
$stmt = $pdo->prepare("SELECT * FROM vehicle_fuel_log WHERE vehicle_id = ? ORDER BY fill_date DESC, id DESC LIMIT 10");
$stmt->execute([$vehicleId]);
$fuelLogs = $stmt->fetchAll();

// Recent maintenance
$stmt = $pdo->prepare("SELECT * FROM vehicle_maintenance WHERE vehicle_id = ? ORDER BY maintenance_date DESC LIMIT 10");
$stmt->execute([$vehicleId]);
$maintenanceLogs = $stmt->fetchAll();

// Overall stats
$stmt = $pdo->prepare("SELECT COALESCE(SUM(distance_km),0) c FROM vehicle_trips WHERE vehicle_id = ?");
$stmt->execute([$vehicleId]);
$totalKm = (float)$stmt->fetch()['c'];

$stmt = $pdo->prepare("SELECT COALESCE(SUM(liters),0) c FROM vehicle_fuel_log WHERE vehicle_id = ?");
$stmt->execute([$vehicleId]);
$totalLiters = (float)$stmt->fetch()['c'];

$avgFuelEfficiency = $totalLiters > 0 ? round($totalKm / $totalLiters, 2) : null;

$stmt = $pdo->prepare("SELECT COALESCE(SUM(cost),0) c FROM vehicle_maintenance WHERE vehicle_id = ?");
$stmt->execute([$vehicleId]);
$totalMaintenanceCost = (float)$stmt->fetch()['c'];

// Month-wise fuel efficiency trend (last 6 months) — for the chart
$trendLabels = [];
$trendKm = [];
$trendLiters = [];
for ($i = 5; $i >= 0; $i--) {
    $monthStart = date('Y-m-01', strtotime("-$i months"));
    $monthEnd = date('Y-m-t', strtotime("-$i months"));
    $trendLabels[] = date('M Y', strtotime($monthStart));
    $k = $pdo->prepare("SELECT COALESCE(SUM(distance_km),0) c FROM vehicle_trips WHERE vehicle_id = ? AND trip_date BETWEEN ? AND ?");
    $k->execute([$vehicleId, $monthStart, $monthEnd]);
    $trendKm[] = (float)$k->fetch()['c'];
    $l = $pdo->prepare("SELECT COALESCE(SUM(liters),0) c FROM vehicle_fuel_log WHERE vehicle_id = ? AND fill_date BETWEEN ? AND ?");
    $l->execute([$vehicleId, $monthStart, $monthEnd]);
    $trendLiters[] = (float)$l->fetch()['c'];
}
?>

<div class="page-eyebrow"><?= h($vehicle['campus_name'] ?? '') ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">🚐 <?= h($vehicle['vehicle_number']) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">चालक: <strong><?= h($vehicle['driver_name'] ?? 'नेमलेला नाही') ?></strong> <?= $vehicle['driver_mobile'] ? '· ' . h($vehicle['driver_mobile']) : '' ?></div>
  </div>
  <div class="d-flex gap-2">
    <a href="report.php?id=<?= $vehicleId ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF अहवाल</a>
    <a href="export-csv.php?id=<?= $vehicleId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व वाहने</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php
  $rtoFields = [
      ['मॉडेल', $vehicle['model'] ?? null],
      ['नोंदणी दिनांक', !empty($vehicle['date_of_registration']) ? date('d M Y', strtotime($vehicle['date_of_registration'])) : null],
      ['चेसिस क्रमांक', $vehicle['chassis_no'] ?? null],
      ['इंजिन क्रमांक', $vehicle['engine_no'] ?? null],
      ['वाहन वर्ग', $vehicle['vehicle_class'] ?? null],
      ['प्रवासी क्षमता', !empty($vehicle['passenger_capacity']) ? $vehicle['passenger_capacity'] . ' जण' : null],
      ['इंधन प्रकार', $vehicle['fuel_type'] ?? null],
  ];
  $hasAnyRto = false;
  foreach ($rtoFields as $rf) { if (!empty($rf[1])) { $hasAnyRto = true; break; } }
?>
<?php if ($hasAnyRto): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">📋 RTO / नोंदणी तपशील</div></div>
  <div style="padding:16px 20px;display:flex;flex-wrap:wrap;gap:20px 32px;">
    <?php foreach ($rtoFields as $rf): if (empty($rf[1])) continue; ?>
      <div>
        <div style="font-size:10.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;"><?= h($rf[0]) ?></div>
        <div style="font-size:13.5px;font-weight:600;color:var(--ink);margin-top:2px;"><?= h($rf[1]) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">सध्याचे मीटर</div><div class="val"><?= number_format($vehicle['current_odometer'], 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण अंतर (km)</div><div class="val"><?= number_format($totalKm, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">सरासरी इंधन (km/l)</div><div class="val" style="color:var(--rail);"><?= $avgFuelEfficiency ?? '—' ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">देखभाल खर्च (₹)</div><div class="val" style="color:var(--amber);"><?= number_format($totalMaintenanceCost, 0) ?></div></div></div>
</div>

<?php
  $renewalFieldsMap = [
      'permit_validity' => 'परमिट', 'puc_validity' => 'PUC', 'insurance_validity' => 'विमा',
      'fitness_validity' => 'फिटनेस', 'road_tax_validity' => 'रोड टॅक्स',
      'green_tax_validity' => 'ग्रीन टॅक्स', 'mv_tax_validity' => 'MV टॅक्स',
  ];
  $hasAnyRenewal = false;
  foreach ($renewalFieldsMap as $col => $label) { if (!empty($vehicle[$col])) { $hasAnyRenewal = true; break; } }
?>
<?php if ($hasAnyRenewal): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">🔔 नूतनीकरण व अनुपालन स्थिती</div><div class="sub">हीच माहिती "नूतनीकरण व अनुपालन" पानावरही आपोआप दिसते</div></div>
  <div style="padding:16px 20px;display:flex;flex-wrap:wrap;gap:12px;">
    <?php foreach ($renewalFieldsMap as $col => $label):
      if (empty($vehicle[$col])) continue;
      $daysLeft = (int)((strtotime($vehicle[$col]) - strtotime(date('Y-m-d'))) / 86400);
      if ($daysLeft < 0) { $chipClass = 'overdue'; $chipText = 'मुदत संपली'; }
      elseif ($daysLeft <= 30) { $chipClass = 'pending'; $chipText = $daysLeft . ' दिवस बाकी'; }
      else { $chipClass = 'done'; $chipText = $daysLeft . ' दिवस बाकी'; }
    ?>
      <div style="border:1px solid var(--line);border-radius:10px;padding:10px 14px;min-width:150px;">
        <div style="font-size:11px;color:var(--text-dim);font-weight:600;"><?= h($label) ?></div>
        <div style="font-size:12.5px;font-weight:600;margin-top:2px;"><?= date('d M Y', strtotime($vehicle[$col])) ?></div>
        <span class="status-chip <?= $chipClass ?>" style="font-size:10px;margin-top:4px;display:inline-block;"><?= h($chipText) ?></span>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php else: ?>
<div class="alert alert-warning" style="border-radius:10px;font-size:12.5px;">⚠️ या वाहनाच्या नूतनीकरण-तारखा (परमिट/PUC/विमा/इ.) अजून भरलेल्या नाहीत — <a href="edit.php?id=<?= $vehicleId ?>">इथे भरा</a>, म्हणजे इशारे आपोआप मिळतील.</div>
<?php endif; ?>

<?php if (array_sum($trendKm) > 0 || array_sum($trendLiters) > 0): ?>
<div class="row g-3 mb-3">
  <div class="col-12">
    <div class="panel">
      <div class="panel-head"><div class="title">मागील ६ महिन्यांचा कल</div><div class="sub">अंतर (km) आणि इंधन (लिटर)</div></div>
      <div style="padding:18px 20px;"><canvas id="vehicleTrendChart" height="80"></canvas></div>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($canLog): ?>
<div class="row g-3 mb-3">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">नवीन ट्रिप नोंदवा</div></div>
      <form method="POST" action="trip-save.php" style="padding:16px 20px;">
        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="trip_date" class="form-control-custom mb-2" value="<?= date('Y-m-d') ?>" required>
        <label class="form-label-custom">कुठून</label>
        <input type="text" name="from_location" class="form-control-custom mb-2" required>
        <label class="form-label-custom">कुठे</label>
        <input type="text" name="to_location" class="form-control-custom mb-2" required>
        <div class="row g-2">
          <div class="col-6"><label class="form-label-custom">सुरुवात मीटर</label><input type="number" step="0.1" name="start_odometer" class="form-control-custom" value="<?= $vehicle['current_odometer'] ?>" required></div>
          <div class="col-6"><label class="form-label-custom">शेवट मीटर</label><input type="number" step="0.1" name="end_odometer" class="form-control-custom" required></div>
        </div>
        <label class="form-label-custom mt-2">उद्देश (ऐच्छिक)</label>
        <input type="text" name="purpose" class="form-control-custom mb-2">
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;margin-top:6px;">ट्रिप जतन करा</button>
      </form>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">इंधन भरले</div></div>
      <form method="POST" action="fuel-save.php" style="padding:16px 20px;">
        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="fill_date" class="form-control-custom mb-2" value="<?= date('Y-m-d') ?>" required>
        <label class="form-label-custom">मीटर रीडिंग (भरतेवेळी)</label>
        <input type="number" step="0.1" name="odometer_reading" class="form-control-custom mb-2" value="<?= $vehicle['current_odometer'] ?>" required>
        <label class="form-label-custom">इंधन (लिटर)</label>
        <input type="number" step="0.01" name="liters" class="form-control-custom mb-2" required>
        <label class="form-label-custom">खर्च (₹, ऐच्छिक)</label>
        <input type="number" step="0.01" name="cost" class="form-control-custom mb-2">
        <label class="form-label-custom">पंप (ऐच्छिक)</label>
        <input type="text" name="fuel_station" class="form-control-custom mb-2">
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;margin-top:6px;">इंधन नोंद जतन करा</button>
      </form>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">देखभाल / दुरुस्ती नोंद</div></div>
      <form method="POST" action="maintenance-save.php" style="padding:16px 20px;">
        <input type="hidden" name="vehicle_id" value="<?= $vehicleId ?>">
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="maintenance_date" class="form-control-custom mb-2" value="<?= date('Y-m-d') ?>" required>
        <label class="form-label-custom">प्रकार</label>
        <input type="text" name="type" class="form-control-custom mb-2" placeholder="उदा. सर्व्हिसिंग, टायर बदलणे" required>
        <label class="form-label-custom">तपशील</label>
        <textarea name="description" class="form-control-custom mb-2" rows="2"></textarea>
        <div class="row g-2">
          <div class="col-6"><label class="form-label-custom">खर्च (₹)</label><input type="number" step="0.01" name="cost" class="form-control-custom"></div>
          <div class="col-6"><label class="form-label-custom">पुढील तारीख</label><input type="date" name="next_due_date" class="form-control-custom"></div>
        </div>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;margin-top:10px;">नोंद जतन करा</button>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">अलीकडील ट्रिप्स</div></div>
      <table class="rtable">
        <thead><tr><th>दिनांक</th><th>कुठून → कुठे</th><th>अंतर</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($trips)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:18px;">कोणतीही नोंद नाही</td></tr><?php endif; ?>
          <?php foreach ($trips as $t): ?>
            <tr><td class="font-mono"><?= date('d M', strtotime($t['trip_date'])) ?></td><td><?= h($t['from_location']) ?> → <?= h($t['to_location']) ?></td><td class="font-mono"><?= number_format($t['distance_km'],1) ?> km</td>
              <td><a href="request-correction.php?type=trip&entry_id=<?= $t['id'] ?>&vehicle_id=<?= $vehicleId ?>" style="font-size:11px;color:var(--rail);" title="चूक झाली? सुधारणा विनंती करा"><i class="fa-solid fa-flag"></i></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">इंधन इतिहास</div></div>
      <table class="rtable">
        <thead><tr><th>दिनांक</th><th>लिटर</th><th>₹</th><th></th></tr></thead>
        <tbody>
          <?php if (empty($fuelLogs)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:18px;">कोणतीही नोंद नाही</td></tr><?php endif; ?>
          <?php foreach ($fuelLogs as $f): ?>
            <tr><td class="font-mono"><?= date('d M', strtotime($f['fill_date'])) ?></td><td class="font-mono"><?= $f['liters'] ?></td><td class="font-mono"><?= $f['cost'] ? number_format($f['cost'],0) : '—' ?></td>
              <td><a href="request-correction.php?type=fuel&entry_id=<?= $f['id'] ?>&vehicle_id=<?= $vehicleId ?>" style="font-size:11px;color:var(--rail);" title="चूक झाली? सुधारणा विनंती करा"><i class="fa-solid fa-flag"></i></a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">देखभाल इतिहास</div></div>
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>प्रकार</th><th>तपशील</th><th>खर्च</th><th>पुढील तारीख</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($maintenanceLogs)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:18px;">कोणतीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($maintenanceLogs as $m): ?>
        <tr>
          <td class="font-mono"><?= date('d M Y', strtotime($m['maintenance_date'])) ?></td>
          <td style="font-weight:500;"><?= h($m['type']) ?></td>
          <td><?= h($m['description'] ?? '—') ?></td>
          <td class="font-mono"><?= $m['cost'] ? '₹' . number_format($m['cost'],0) : '—' ?></td>
          <td class="font-mono"><?= $m['next_due_date'] ? date('d M Y', strtotime($m['next_due_date'])) : '—' ?></td>
          <td><a href="request-correction.php?type=maintenance&entry_id=<?= $m['id'] ?>&vehicle_id=<?= $vehicleId ?>" style="font-size:11px;color:var(--rail);" title="चूक झाली? सुधारणा विनंती करा"><i class="fa-solid fa-flag"></i></a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (array_sum($trendKm) > 0 || array_sum($trendLiters) > 0): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
new Chart(document.getElementById('vehicleTrendChart'), {
  data: {
    labels: <?= json_encode($trendLabels) ?>,
    datasets: [
      { type: 'bar', label: 'अंतर (km)', data: <?= json_encode($trendKm) ?>, backgroundColor: '#1B8A83', borderRadius: 5, yAxisID: 'y' },
      { type: 'line', label: 'इंधन (लिटर)', data: <?= json_encode($trendLiters) ?>, borderColor: '#C97F1D', backgroundColor: 'rgba(201,127,29,.1)', tension: .35, yAxisID: 'y1' }
    ]
  },
  options: {
    plugins: { legend: { position: 'bottom', labels: { font: { family: "'Noto Sans Devanagari'", size: 11 }, boxWidth: 10 } } },
    scales: {
      x: { grid: { display: false } },
      y: { beginAtZero: true, position: 'left', grid: { color: '#EEF0F4' }, ticks: { font: { size: 10 } } },
      y1: { beginAtZero: true, position: 'right', grid: { display: false }, ticks: { font: { size: 10 } } }
    }
  }
});
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
