<?php
// 🙋 पाहुणे — Smart आढावा (guests/index.php मधून): मागील ३० दिवसांचा कल,
// सरासरी भेटीचा वेळ, गर्दीची वेळ, सर्वाधिक भेटी घेणारे, Check-out विसरलेले व No-show.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT DATE(check_in_time) d, COUNT(*) c FROM guests
        WHERE organization_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY DATE(check_in_time)");
    $st->execute([$orgId]);
    $gDaily = []; foreach ($st->fetchAll() as $r) { $gDaily[$r['d']] = (int)$r['c']; }
    $gSeries = []; for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $gSeries[] = ['d' => date('d M', strtotime($d)), 'c' => $gDaily[$d] ?? 0]; }
    $total30 = array_sum(array_column($gSeries, 'c'));

    $st = $pdo->prepare("SELECT
            ROUND(AVG(CASE WHEN check_out_time IS NOT NULL THEN TIMESTAMPDIFF(MINUTE, check_in_time, check_out_time) END)) avg_min,
            SUM(check_out_time IS NULL AND check_in_time < CURDATE()) forgot,
            SUM(check_out_time IS NULL AND check_in_time >= CURDATE() AND check_in_time < DATE_SUB(NOW(), INTERVAL 4 HOUR)) long_stay
        FROM guests WHERE organization_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)");
    $st->execute([$orgId]);
    $gs = $st->fetch() ?: [];
    $st = $pdo->prepare("SELECT HOUR(check_in_time) h, COUNT(*) c FROM guests WHERE organization_id = ? AND check_in_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY h ORDER BY c DESC LIMIT 1");
    $st->execute([$orgId]);
    $peak = $st->fetch();
    $st = $pdo->prepare("SELECT u.name, COUNT(*) c FROM guests g JOIN users u ON u.id = g.meeting_with
        WHERE g.organization_id = ? AND g.check_in_time >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY g.meeting_with, u.name ORDER BY c DESC LIMIT 6");
    $st->execute([$orgId]);
    $hosts = $st->fetchAll();
    $st = $pdo->prepare("SELECT SUM(status = 'no_show') ns, SUM(status IN ('arrived','no_show')) done FROM guest_pre_registrations
        WHERE organization_id = ? AND expected_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND expected_date < CURDATE()");
    $st->execute([$orgId]);
    $pr = $st->fetch() ?: [];
    $avgMin = (int)($gs['avg_min'] ?? 0);

    smartTiles([
        ['label' => 'मागील ३० दिवस', 'value' => $total30, 'sub' => 'रोज सरासरी ' . round($total30 / 30, 1)],
        ['label' => 'सरासरी भेट', 'value' => $avgMin ? ($avgMin >= 60 ? intdiv($avgMin, 60) . ' तास ' . ($avgMin % 60) . ' मि' : $avgMin . ' मिनिटं') : '—'],
        ['label' => 'गर्दीची वेळ', 'value' => $peak ? sprintf('%02d:00–%02d:00', $peak['h'], $peak['h'] + 1) : '—'],
        ['label' => 'Pre-Reg No-show', 'value' => !empty($pr['done']) ? round($pr['ns'] * 100 / $pr['done']) . '%' : '—', 'sub' => (int)($pr['ns'] ?? 0) . ' / ' . (int)($pr['done'] ?? 0)],
    ]);
    $al = [];
    if ((int)($gs['forgot'] ?? 0)) $al[] = ['bad', (int)$gs['forgot'] . ' पाहुण्यांचा मागील दिवसांचा Check-out झालेला नाही — सुरक्षा-नोंद अपूर्ण', '?date=' . date('Y-m-d', strtotime('-1 day'))];
    if ((int)($gs['long_stay'] ?? 0)) $al[] = ['warn', (int)$gs['long_stay'] . ' पाहुणे ४ तासांपेक्षा जास्त वेळ आत आहेत', '?date=' . date('Y-m-d')];
    smartAlerts($al);
    echo '<div class="row g-3 mb-3"><div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><div class="title">📈 मागील ३० दिवस — रोजचे पाहुणे</div></div><div style="padding:12px 18px;height:200px;position:relative;"><canvas id="gTrendChart"></canvas></div></div></div><div class="col-lg-5">';
    smartBars('🤝 सर्वाधिक भेटी कोणाला', 'मागील ३० दिवस', array_map(fn($r) => ['name' => $r['name'], 'value' => (int)$r['c']], $hosts), 'var(--amber)', 'नोंदी नाहीत');
    echo '</div></div>';
    ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var s = <?= json_encode($gSeries) ?>, dim = getComputedStyle(document.documentElement).getPropertyValue('--text-dim').trim() || '#6B7080';
  new Chart(document.getElementById('gTrendChart'), { type: 'bar',
    data: { labels: s.map(function (x) { return x.d; }), datasets: [{ label: 'पाहुणे', data: s.map(function (x) { return x.c; }), backgroundColor: '#8A5CD6', borderRadius: 4, maxBarThickness: 18 }] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } },
      scales: { x: { grid: { display: false }, ticks: { color: dim, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 10 } }, y: { beginAtZero: true, ticks: { color: dim, precision: 0 } } } } });
});
</script>
<?php
} catch (PDOException $e) {}
