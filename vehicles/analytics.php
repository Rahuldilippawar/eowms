<?php
// 📊 Fleet विश्लेषण — सगळ्या वाहनांचा कालावधीनिहाय हिशोब:
// km, इंधन, देखभाल, खर्च/km, मायलेज, वापराचे दिवस; महिनावार कल; चालकनिहाय;
// इंधन-विसंगती (मायलेज अचानक कमी / Meter मागे / दर जास्त); सर्वाधिक खर्चाची देखभाल.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

// कोण पाहू शकतं — यादीतल्या "सगळी वाहनं" नियमासारखंच
$dc = $pdo->prepare("SELECT code FROM departments WHERE id = ? AND organization_id = ?");
$dc->execute([$user['department_id'] ?? 0, $orgId]);
if (!(canManageVehicles() || ($dc->fetch()['code'] ?? null) === 'TRP' || isManagementOrAbove())) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">हे पान फक्त वाहन व्यवस्थापन / वाहतूक विभाग / Management साठी. <a href="index.php">वाहनं</a></div>');
}

// कालावधी
$periods = ['m' => 'हा महिना', 'lm' => 'मागचा महिना', '3' => 'मागचे 3 महिने', '6' => 'मागचे 6 महिने', '12' => 'मागचे 12 महिने', 'fy' => 'चालू आर्थिक वर्ष'];
$p = isset($periods[$_GET['p'] ?? '']) ? (string)$_GET['p'] : '3';
$to = date('Y-m-d');
switch ($p) {
    case 'm': $from = date('Y-m-01'); break;
    case 'lm': $from = date('Y-m-01', strtotime('first day of last month')); $to = date('Y-m-t', strtotime('first day of last month')); break;
    case '6': $from = date('Y-m-01', strtotime('-5 months')); break;
    case '12': $from = date('Y-m-01', strtotime('-11 months')); break;
    case 'fy': $from = (date('n') >= 4 ? date('Y') : date('Y') - 1) . '-04-01'; break;
    default: $from = date('Y-m-01', strtotime('-2 months'));
}
$days = max(1, (int)round((strtotime($to) - strtotime($from)) / 86400) + 1);

$st = $pdo->prepare("SELECT v.*, u.name AS driver_name FROM vehicles v LEFT JOIN users u ON u.id = v.driver_id WHERE v.organization_id = ? ORDER BY v.vehicle_number");
$st->execute([$orgId]);
$vehicles = $st->fetchAll();
$ids = array_map(fn($v) => (int)$v['id'], $vehicles);
$stats = vehicleFleetStats($pdo, $ids, $from, $to);
$ph = $ids ? implode(',', array_fill(0, count($ids), '?')) : '0';

$T = ['km' => 0, 'trips' => 0, 'liters' => 0, 'fuel' => 0, 'maint' => 0];
foreach ($stats as $s) { foreach ($T as $k => $_) { $T[$k] += $s[$k]; } }
$T['cost'] = $T['fuel'] + $T['maint'];
$T['kmpl'] = $T['liters'] > 0 && $T['km'] > 0 ? round($T['km'] / $T['liters'], 1) : null;
$T['cost_km'] = $T['km'] > 0 ? $T['cost'] / $T['km'] : null;
$activeIds = array_map(fn($v) => (int)$v['id'], array_filter($vehicles, fn($v) => $v['is_active']));
$usedIds = array_filter($activeIds, fn($id) => $stats[$id]['trips'] > 0);
$utilPct = $activeIds ? round(array_sum(array_map(fn($id) => $stats[$id]['days_used'], $activeIds)) * 100 / (count($activeIds) * $days), 1) : 0;

// Excel (CSV) — वाहननिहाय तक्ता
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fleet-' . $from . '-to-' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['वाहन', 'प्रकार', 'चालक', 'ट्रिप', 'वापराचे दिवस', 'km', 'लिटर', 'km/l', 'इंधन ₹', 'देखभाल ₹', 'एकूण ₹', '₹/km', 'शेवटची ट्रिप']);
    foreach ($vehicles as $v) { $s = $stats[(int)$v['id']];
        fputcsv($o, [$v['vehicle_number'], $v['vehicle_type'], $v['driver_name'], $s['trips'], $s['days_used'], $s['km'], $s['liters'], $s['kmpl'], $s['fuel'], $s['maint'], $s['cost'], $s['cost_km'], $s['last_trip']]); }
    exit;
}

// महिनावार कल
$months = []; $cur = strtotime(date('Y-m-01', strtotime($from)));
while ($cur <= strtotime($to)) { $months[date('Y-m', $cur)] = ['km' => 0, 'fuel' => 0, 'maint' => 0]; $cur = strtotime('+1 month', $cur); }
if ($ids) {
    $q = function ($sql) use ($pdo, $ids, $from, $to) { $s = $pdo->prepare($sql); $s->execute(array_merge($ids, [$from, $to])); return $s->fetchAll(); };
    foreach ($q("SELECT DATE_FORMAT(trip_date,'%Y-%m') m, SUM(distance_km) v FROM vehicle_trips WHERE vehicle_id IN ($ph) AND trip_date BETWEEN ? AND ? GROUP BY m") as $r) { if (isset($months[$r['m']])) $months[$r['m']]['km'] = (float)$r['v']; }
    foreach ($q("SELECT DATE_FORMAT(fill_date,'%Y-%m') m, SUM(COALESCE(cost,0)) v FROM vehicle_fuel_log WHERE vehicle_id IN ($ph) AND fill_date BETWEEN ? AND ? GROUP BY m") as $r) { if (isset($months[$r['m']])) $months[$r['m']]['fuel'] = (float)$r['v']; }
    foreach ($q("SELECT DATE_FORMAT(maintenance_date,'%Y-%m') m, SUM(COALESCE(cost,0)) v FROM vehicle_maintenance WHERE vehicle_id IN ($ph) AND maintenance_date BETWEEN ? AND ? GROUP BY m") as $r) { if (isset($months[$r['m']])) $months[$r['m']]['maint'] = (float)$r['v']; }
}

// चालकनिहाय (Trip मधला चालक; नसेल तर वाहनाचा नेमलेला चालक)
$drivers = [];
if ($ids) {
    $s = $pdo->prepare("SELECT COALESCE(u.name, u2.name, '—') name, COUNT(*) trips, SUM(t.distance_km) km, COUNT(DISTINCT t.vehicle_id) veh, COUNT(DISTINCT t.trip_date) d
        FROM vehicle_trips t JOIN vehicles v ON v.id = t.vehicle_id LEFT JOIN users u ON u.id = t.driver_id LEFT JOIN users u2 ON u2.id = v.driver_id
        WHERE t.vehicle_id IN ($ph) AND t.trip_date BETWEEN ? AND ? GROUP BY name ORDER BY km DESC LIMIT 15");
    $s->execute(array_merge($ids, [$from, $to]));
    $drivers = $s->fetchAll();
}

// देखभाल — प्रकारनिहाय खर्च
$maintTypes = [];
if ($ids) {
    $s = $pdo->prepare("SELECT type, COUNT(*) n, SUM(COALESCE(cost,0)) c FROM vehicle_maintenance WHERE vehicle_id IN ($ph) AND maintenance_date BETWEEN ? AND ? GROUP BY type ORDER BY c DESC LIMIT 10");
    $s->execute(array_merge($ids, [$from, $to]));
    $maintTypes = $s->fetchAll();
}

// इंधन-विसंगती — कालावधीतील भरण्यांपैकी
$anoms = [];
foreach ($vehicles as $v) {
    $fa = vehicleFuelAnalysis($pdo, $v['id'], 80);
    foreach ($fa['fills'] as $fid => $e) {
        if (!$e['flags'] || $e['date'] < $from || $e['date'] > $to) continue;
        foreach ($e['flags'] as $fl) { $anoms[] = ['v' => $v, 'date' => $e['date'], 'text' => vehicleFuelFlagText($fl, $e, $fa['median_kmpl']), 'flag' => $fl]; }
    }
}
usort($anoms, fn($a, $b) => strcmp($b['date'], $a['date']));

// स्मार्ट निरीक्षणं
$al = [];
$withCost = array_filter($vehicles, fn($v) => $stats[(int)$v['id']]['cost_km'] !== null && $stats[(int)$v['id']]['km'] >= 100);
if (count($withCost) >= 3 && $T['cost_km']) {
    foreach ($withCost as $v) { $s = $stats[(int)$v['id']];
        if ($s['cost_km'] > $T['cost_km'] * 1.5) $al[] = ['warn', '💸 ' . $v['vehicle_number'] . ' — ₹' . number_format($s['cost_km'], 2) . '/km (सरासरी ₹' . number_format($T['cost_km'], 2) . ') · देखभाल ₹' . number_format($s['maint']), 'dashboard.php?id=' . $v['id']]; }
}
$idle = array_filter($activeIds, fn($id) => $stats[$id]['trips'] === 0);
if ($idle && $p !== 'm') { $names = array_map(fn($id) => array_values(array_filter($vehicles, fn($v) => (int)$v['id'] === $id))[0]['vehicle_number'], $idle);
    $al[] = ['info', '💤 ' . count($idle) . ' सक्रिय वाहनं या कालावधीत एकदाही वापरली नाहीत: ' . implode(', ', array_slice($names, 0, 6)) . (count($names) > 6 ? '…' : '') . ' — गरज आहे का?', '']; }
if ($anoms) $al[] = ['bad', '⛽ ' . count($anoms) . ' इंधन-नोंदींत विसंगती — खाली यादी पाहा', '#anoms'];
$noFuel = array_filter($activeIds, fn($id) => $stats[$id]['km'] > 300 && $stats[$id]['liters'] == 0);
if ($noFuel) $al[] = ['warn', '📝 ' . count($noFuel) . ' वाहनांनी ३००+ km प्रवास केला पण इंधन-नोंद नाही — नोंदी अपूर्ण?', ''];

$pageTitle = 'Fleet विश्लेषण';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$mrMonths = [1 => 'जाने', 'फेब्रु', 'मार्च', 'एप्रि', 'मे', 'जून', 'जुलै', 'ऑग', 'सप्टें', 'ऑक्टो', 'नोव्हें', 'डिसें'];
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
  <div>
    <div class="page-title mb-0">📊 Fleet विश्लेषण</div>
    <div style="font-size:12.5px;color:var(--text-dim);"><?= date('d M Y', strtotime($from)) ?> – <?= date('d M Y', strtotime($to)) ?> · <?= $days ?> दिवस</div>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <select class="form-control-custom" style="width:auto;" onchange="location='?p='+this.value">
      <?php foreach ($periods as $k => $lbl): ?><option value="<?= $k ?>" <?= (string)$k === (string)$p ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
    </select>
    <a href="?p=<?= h($p) ?>&export=1" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-file-excel me-1"></i>Excel</a>
    <button type="button" onclick="window.print()" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-print me-1"></i>Print</button>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व वाहनं</a>
  </div>
</div>

<?php
smartTiles([
    ['label' => 'एकूण प्रवास', 'value' => number_format($T['km']) . ' km', 'sub' => $T['trips'] . ' ट्रिप · ' . count($usedIds) . '/' . count($activeIds) . ' वाहनं वापरली'],
    ['label' => 'एकूण खर्च', 'value' => '₹' . number_format($T['cost']), 'sub' => 'इंधन ₹' . number_format($T['fuel']) . ' · देखभाल ₹' . number_format($T['maint'])],
    ['label' => 'खर्च / km', 'value' => $T['cost_km'] !== null ? '₹' . number_format($T['cost_km'], 2) : '—', 'sub' => 'इंधन + देखभाल'],
    ['label' => 'सरासरी मायलेज', 'value' => $T['kmpl'] ? $T['kmpl'] . ' km/l' : '—', 'sub' => number_format($T['liters']) . ' लिटर'],
    ['label' => 'वाहन-वापर', 'value' => $utilPct . '%', 'sub' => 'सक्रिय वाहनं × दिवस यापैकी वापरलेले दिवस', 'tone' => $utilPct < 30 ? 'warn' : 'ok'],
]);
smartAlerts($al);
?>

<?php if (count($months) > 1): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">📈 महिनावार कल</div><div class="sub">प्रवास (km) आणि खर्च (₹)</div></div>
  <div style="padding:14px 18px;"><canvas id="fleetTrend" height="85"></canvas></div>
</div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">🚐 वाहननिहाय हिशोब</div><div class="sub">खर्च / km जास्त असलेली आधी · ओळीवर Click करून वाहन उघडा</div></div>
  <div style="overflow-x:auto;">
  <table class="rtable">
    <thead><tr><th>वाहन</th><th>चालक</th><th class="text-end">ट्रिप</th><th class="text-end">दिवस</th><th class="text-end">km</th><th class="text-end">km/l</th><th class="text-end">इंधन ₹</th><th class="text-end">देखभाल ₹</th><th class="text-end">एकूण ₹</th><th class="text-end">₹/km</th><th>शेवटची ट्रिप</th></tr></thead>
    <tbody>
    <?php
    $rows = $vehicles;
    usort($rows, fn($a, $b) => ($stats[(int)$b['id']]['cost_km'] ?? -1) <=> ($stats[(int)$a['id']]['cost_km'] ?? -1) ?: $stats[(int)$b['id']]['km'] <=> $stats[(int)$a['id']]['km']);
    $maxCk = max(0.01, ...array_map(fn($s) => (float)($s['cost_km'] ?? 0), $stats ?: [['cost_km' => 0]]));
    if (!$rows): ?><tr><td colspan="11" style="text-align:center;color:var(--text-dim);padding:20px;">वाहनं नाहीत</td></tr><?php endif;
    foreach ($rows as $v): $s = $stats[(int)$v['id']]; $hi = $T['cost_km'] && $s['cost_km'] !== null && $s['km'] >= 100 && $s['cost_km'] > $T['cost_km'] * 1.5; ?>
      <tr style="cursor:pointer;<?= !$v['is_active'] ? 'opacity:.55;' : '' ?>" onclick="location='dashboard.php?id=<?= (int)$v['id'] ?>'">
        <td><b class="font-mono"><?= h($v['vehicle_number']) ?></b><div style="font-size:11px;color:var(--text-dim);"><?= h($v['vehicle_type'] ?? '') ?></div></td>
        <td style="font-size:12.5px;"><?= h($v['driver_name'] ?? '—') ?></td>
        <td class="text-end font-mono"><?= $s['trips'] ?></td>
        <td class="text-end font-mono"><?= $s['days_used'] ?></td>
        <td class="text-end font-mono"><?= number_format($s['km']) ?></td>
        <td class="text-end font-mono"><?= $s['kmpl'] ?? '—' ?></td>
        <td class="text-end font-mono"><?= number_format($s['fuel']) ?></td>
        <td class="text-end font-mono"><?= number_format($s['maint']) ?><?= $s['maint_n'] ? '<small style="color:var(--text-dim);"> (' . $s['maint_n'] . ')</small>' : '' ?></td>
        <td class="text-end font-mono"><b><?= number_format($s['cost']) ?></b></td>
        <td class="text-end font-mono" style="min-width:110px;">
          <?php if ($s['cost_km'] !== null): ?>
            <span style="<?= $hi ? 'color:var(--rose);font-weight:700;' : '' ?>"><?= number_format($s['cost_km'], 2) ?></span>
            <div style="height:4px;background:var(--canvas);border-radius:3px;margin-top:3px;"><i style="display:block;height:100%;border-radius:3px;width:<?= round($s['cost_km'] * 100 / $maxCk) ?>%;background:<?= $hi ? 'var(--rose)' : 'var(--rail)' ?>;"></i></div>
          <?php else: ?>—<?php endif; ?>
        </td>
        <td style="font-size:12px;"><?= $s['last_trip'] ? date('d M', strtotime($s['last_trip'])) : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($rows): ?>
    <tfoot><tr style="font-weight:700;background:var(--canvas);"><td colspan="2">एकूण</td><td class="text-end font-mono"><?= $T['trips'] ?></td><td></td><td class="text-end font-mono"><?= number_format($T['km']) ?></td><td class="text-end font-mono"><?= $T['kmpl'] ?? '—' ?></td><td class="text-end font-mono"><?= number_format($T['fuel']) ?></td><td class="text-end font-mono"><?= number_format($T['maint']) ?></td><td class="text-end font-mono"><?= number_format($T['cost']) ?></td><td class="text-end font-mono"><?= $T['cost_km'] !== null ? number_format($T['cost_km'], 2) : '—' ?></td><td></td></tr></tfoot>
    <?php endif; ?>
  </table>
  </div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6">
    <div class="panel h-100">
      <div class="panel-head"><div class="title">👤 चालकनिहाय</div><div class="sub">ट्रिप नोंदीतला चालक</div></div>
      <table class="rtable">
        <thead><tr><th>चालक</th><th class="text-end">ट्रिप</th><th class="text-end">दिवस</th><th class="text-end">km</th><th class="text-end">वाहनं</th></tr></thead>
        <tbody>
          <?php if (!$drivers): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:18px;">या कालावधीत ट्रिप नाहीत</td></tr><?php endif; ?>
          <?php foreach ($drivers as $d): ?><tr><td><?= h($d['name']) ?></td><td class="text-end font-mono"><?= (int)$d['trips'] ?></td><td class="text-end font-mono"><?= (int)$d['d'] ?></td><td class="text-end font-mono"><?= number_format((float)$d['km']) ?></td><td class="text-end font-mono"><?= (int)$d['veh'] ?></td></tr><?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="col-lg-6">
    <?php smartBars('🔧 देखभाल — प्रकारनिहाय खर्च', 'कुठे जास्त पैसा जातो', array_map(fn($m) => ['name' => $m['type'], 'value' => (float)$m['c'], 'label' => '₹' . number_format((float)$m['c']) . ' · ' . (int)$m['n'] . ' वेळा'], $maintTypes), 'var(--amber)', 'या कालावधीत देखभाल नोंद नाही'); ?>
  </div>
</div>

<div class="panel mb-3" id="anoms">
  <div class="panel-head"><div class="title">⛽ इंधन-विसंगती</div><div class="sub">मायलेज नेहमीपेक्षा ३०%+ कमी · Meter मागे · दर १५%+ जास्त — गळती, चोरी किंवा चुकीची नोंद असू शकते</div></div>
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>वाहन</th><th>काय दिसलं</th></tr></thead>
    <tbody>
      <?php if (!$anoms): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:18px;">✓ या कालावधीत एकही विसंगती नाही</td></tr><?php endif; ?>
      <?php foreach (array_slice($anoms, 0, 50) as $a): ?>
        <tr style="cursor:pointer;" onclick="location='dashboard.php?id=<?= (int)$a['v']['id'] ?>'"><td class="font-mono"><?= date('d M Y', strtotime($a['date'])) ?></td><td class="font-mono"><b><?= h($a['v']['vehicle_number']) ?></b></td>
          <td><span class="status-chip <?= $a['flag'] === 'rate' ? 'pending' : 'overdue' ?>" style="font-size:11px;"><?= h($a['text']) ?></span></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (count($months) > 1): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  if (!window.Chart) return;
  new Chart(document.getElementById('fleetTrend'), {
    data: {
      labels: <?= json_encode(array_map(fn($m) => $mrMonths[(int)substr($m, 5)] . ' ' . substr($m, 2, 2), array_keys($months)), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [
        { type: 'bar', label: 'इंधन ₹', data: <?= json_encode(array_column($months, 'fuel')) ?>, backgroundColor: '#D9530F', stack: 'c', yAxisID: 'y', borderRadius: 4 },
        { type: 'bar', label: 'देखभाल ₹', data: <?= json_encode(array_column($months, 'maint')) ?>, backgroundColor: '#E8A33D', stack: 'c', yAxisID: 'y', borderRadius: 4 },
        { type: 'line', label: 'प्रवास (km)', data: <?= json_encode(array_column($months, 'km')) ?>, borderColor: '#1B8A83', backgroundColor: '#1B8A83', tension: .3, yAxisID: 'y1' }
      ]
    },
    options: { responsive: true, interaction: { mode: 'index', intersect: false }, plugins: { legend: { position: 'bottom' } },
      scales: { y: { stacked: true, beginAtZero: true, title: { display: true, text: '₹' } }, y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: 'km' } }, x: { stacked: true } } }
  });
});
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
