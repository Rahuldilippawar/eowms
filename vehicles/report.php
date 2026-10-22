<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$vehicleId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

$stmt = $pdo->prepare("SELECT v.*, u.name AS driver_name FROM vehicles v LEFT JOIN users u ON u.id = v.driver_id WHERE v.id = ? AND v.organization_id = ?");
$stmt->execute([$vehicleId, $orgId]);
$vehicle = $stmt->fetch();
if (!$vehicle) { die('वाहन सापडले नाही.'); }

$canLog = hasPermission('manage_vehicles') || $userDeptCode === 'TRP' || (int)$vehicle['driver_id'] === (int)$user['id'];
if (!$canLog && !hasRole('super_admin') && !isManagementOrAbove()) {
    http_response_code(403);
    die('तुम्हाला हे वाहन पाहण्याची परवानगी नाही.');
}

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$stmt = $pdo->prepare("SELECT * FROM vehicle_trips WHERE vehicle_id = ? ORDER BY trip_date DESC LIMIT 100");
$stmt->execute([$vehicleId]);
$trips = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM vehicle_fuel_log WHERE vehicle_id = ? ORDER BY fill_date DESC LIMIT 100");
$stmt->execute([$vehicleId]);
$fuelLogs = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM vehicle_maintenance WHERE vehicle_id = ? ORDER BY maintenance_date DESC LIMIT 100");
$stmt->execute([$vehicleId]);
$maintenanceLogs = $stmt->fetchAll();

$totalKm = array_sum(array_column($trips, 'distance_km'));
$totalLiters = array_sum(array_column($fuelLogs, 'liters'));
$totalFuelCost = array_sum(array_column($fuelLogs, 'cost'));
$totalMaintenanceCost = array_sum(array_column($maintenanceLogs, 'cost'));
$avgEfficiency = $totalLiters > 0 ? round($totalKm / $totalLiters, 2) : null;
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>वाहन अहवाल — <?= h($vehicle['vehicle_number']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .doc{max-width:850px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:20px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-top:6px;color:var(--rail);}
  .summary-row{display:flex;gap:14px;margin:22px 0;justify-content:center;flex-wrap:wrap;}
  .summary-box{border:1px solid var(--line);border-radius:10px;padding:12px 18px;text-align:center;min-width:110px;}
  .summary-box .lbl{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;}
  .summary-box .val{font-family:'Space Grotesk';font-weight:700;font-size:18px;}
  h3{font-family:'Space Grotesk';font-size:14px;color:var(--ink);margin:26px 0 8px;}
  table{width:100%;border-collapse:collapse;margin-top:6px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10.5px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:12px;}
  @media print{ .toolbar{display:none;} #eowmsLangSelect, #google_translate_element{display:none !important;} body{background:#fff;} .doc{margin:0;} }
</style>
</head>
<body>
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text-dim);background:#fff;cursor:pointer;">
  <option value="">🌐 भाषा (Language)</option>
  <option value="en">English</option>
  <option value="hi">हिंदी (Hindi)</option>
  <option value="gu">ગુજરાતી (Gujarati)</option>
  <option value="mr">मराठी (मूळ)</option>
</select>
<div id="google_translate_element" style="display:none;"></div>
<style>body > .skiptranslate{display:none !important;} body{top:0 !important;}</style>
<script>
  function googleTranslateElementInit() {
    new google.translate.TranslateElement({ pageLanguage: 'mr', autoDisplay: false }, 'google_translate_element');
  }
  function eowmsChangeLanguage(lang) {
    var tries = 0;
    var trigger = function () {
      var combo = document.querySelector('.goog-te-combo');
      if (combo) {
        combo.value = lang;
        combo.dispatchEvent(new Event('change'));
      } else if (tries < 20) {
        tries++;
        setTimeout(trigger, 250);
      }
    };
    trigger();
  }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>


<div class="toolbar">
  <a href="dashboard.php?id=<?= $vehicleId ?>" style="color:#fff;text-decoration:none;font-size:13px;">← मागे</a>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>

<div class="doc">
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">वाहन अहवाल — <?= h($vehicle['vehicle_number']) ?></div>
  <div style="text-align:center;font-size:12px;color:var(--text-dim);margin-top:4px;">चालक: <?= h($vehicle['driver_name'] ?? '—') ?> · <?= h($vehicle['vehicle_type'] ?? '') ?></div>

  <?php
    $rtoFields = [
        ['मॉडेल', $vehicle['model'] ?? null], ['नोंदणी दिनांक', !empty($vehicle['date_of_registration']) ? date('d-m-Y', strtotime($vehicle['date_of_registration'])) : null],
        ['चेसिस क्र.', $vehicle['chassis_no'] ?? null], ['इंजिन क्र.', $vehicle['engine_no'] ?? null],
        ['वाहन वर्ग', $vehicle['vehicle_class'] ?? null], ['प्रवासी क्षमता', $vehicle['passenger_capacity'] ?? null],
        ['इंधन प्रकार', $vehicle['fuel_type'] ?? null],
    ];
  ?>
  <table style="width:100%;border-collapse:collapse;margin-top:18px;font-size:11.5px;">
    <tbody>
      <?php foreach (array_chunk($rtoFields, 2) as $pair): ?>
        <tr>
          <?php foreach ($pair as $rf): if (!$rf[1]) { echo '<td colspan="2"></td>'; continue; } ?>
            <td style="padding:6px 10px;color:var(--text-dim);border-bottom:1px solid #EEF0F4;width:20%;"><?= h($rf[0]) ?></td>
            <td style="padding:6px 10px;font-weight:600;border-bottom:1px solid #EEF0F4;width:30%;"><?= h($rf[1]) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="summary-row">
    <div class="summary-box"><div class="lbl">एकूण अंतर</div><div class="val"><?= number_format($totalKm,0) ?> km</div></div>
    <div class="summary-box"><div class="lbl">एकूण इंधन</div><div class="val"><?= number_format($totalLiters,1) ?> L</div></div>
    <div class="summary-box"><div class="lbl">सरासरी</div><div class="val" style="color:var(--rail);"><?= $avgEfficiency ?? '—' ?> km/l</div></div>
    <div class="summary-box"><div class="lbl">इंधन खर्च</div><div class="val">₹<?= number_format($totalFuelCost,0) ?></div></div>
    <div class="summary-box"><div class="lbl">देखभाल खर्च</div><div class="val">₹<?= number_format($totalMaintenanceCost,0) ?></div></div>
  </div>

  <h3>ट्रिप तपशील</h3>
  <table>
    <thead><tr><th>दिनांक</th><th>कुठून → कुठे</th><th>सुरुवात</th><th>शेवट</th><th>अंतर (km)</th></tr></thead>
    <tbody>
      <?php foreach ($trips as $t): ?>
        <tr><td><?= date('d M Y', strtotime($t['trip_date'])) ?></td><td><?= h($t['from_location']) ?> → <?= h($t['to_location']) ?></td><td><?= $t['start_odometer'] ?></td><td><?= $t['end_odometer'] ?></td><td><?= $t['distance_km'] ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($trips)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);">कोणतीही नोंद नाही</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>इंधन इतिहास</h3>
  <table>
    <thead><tr><th>दिनांक</th><th>मीटर</th><th>लिटर</th><th>खर्च (₹)</th><th>पंप</th></tr></thead>
    <tbody>
      <?php foreach ($fuelLogs as $f): ?>
        <tr><td><?= date('d M Y', strtotime($f['fill_date'])) ?></td><td><?= $f['odometer_reading'] ?></td><td><?= $f['liters'] ?></td><td><?= $f['cost'] ? number_format($f['cost'],0) : '—' ?></td><td><?= h($f['fuel_station'] ?? '—') ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($fuelLogs)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);">कोणतीही नोंद नाही</td></tr><?php endif; ?>
    </tbody>
  </table>

  <h3>देखभाल इतिहास</h3>
  <table>
    <thead><tr><th>दिनांक</th><th>प्रकार</th><th>खर्च (₹)</th><th>पुढील तारीख</th></tr></thead>
    <tbody>
      <?php foreach ($maintenanceLogs as $m): ?>
        <tr><td><?= date('d M Y', strtotime($m['maintenance_date'])) ?></td><td><?= h($m['type']) ?></td><td><?= $m['cost'] ? number_format($m['cost'],0) : '—' ?></td><td><?= $m['next_due_date'] ? date('d M Y', strtotime($m['next_due_date'])) : '—' ?></td></tr>
      <?php endforeach; ?>
      <?php if (empty($maintenanceLogs)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);">कोणतीही नोंद नाही</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:24px;font-size:11px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>

</body>
</html>
