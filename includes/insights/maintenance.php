<?php
// 🔧 देखभाल — Smart आढावा (maintenance/index.php मधून): या महिन्यातली कामं व खर्च, वारंवार बिघडणाऱ्या वस्तू,
// विभागनिहाय खर्च, कामाच्या प्रकारानुसार 6 महिन्यांचा कल.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            SUM(work_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) m_n,
            COALESCE(SUM(CASE WHEN work_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN cost END), 0) m_cost,
            COALESCE(SUM(CASE WHEN work_date >= DATE_FORMAT(CURDATE(), '%Y-04-01') - INTERVAL (MONTH(CURDATE()) < 4) YEAR THEN cost END), 0) fy_cost,
            SUM(work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)) n30,
            SUM(work_date >= DATE_SUB(CURDATE(), INTERVAL 59 DAY) AND work_date < DATE_SUB(CURDATE(), INTERVAL 29 DAY)) nprev
        FROM maintenance_logs WHERE organization_id = ?");
    $st->execute([$orgId]);
    $m = $st->fetch() ?: [];
    $n30 = (int)$m['n30']; $np = (int)$m['nprev'];
    $trend = $np ? (int)round(($n30 - $np) * 100 / $np) : null;
    smartTiles([
        ['label' => 'या महिन्यात कामं', 'value' => (int)$m['m_n'], 'sub' => '₹' . number_format((float)$m['m_cost'])],
        ['label' => 'या आर्थिक वर्षात खर्च', 'value' => '₹' . number_format((float)$m['fy_cost'])],
        ['label' => 'मागील 30 दिवस', 'value' => $n30 . ' कामं', 'sub' => $trend === null ? '' : ($trend > 0 ? '▲ ' . $trend . '% मागच्यापेक्षा जास्त' : ($trend < 0 ? '▼ ' . abs($trend) . '% कमी' : 'तितकीच')), 'tone' => $trend !== null && $trend > 25 ? 'warn' : ''],
        ['label' => 'वारंवार बिघडणाऱ्या जागा', 'value' => (int)($flaggedCount ?? 0), 'tone' => !empty($flaggedCount) ? 'bad' : 'ok', 'sub' => '30 दिवसांत 2+ दुरुस्ती'],
    ]);
    // वारंवार दुरुस्त / बदललेल्या वस्तू (90 दिवस) — मूळ कारण शोधण्यासाठी
    $rp = $pdo->prepare("SELECT ml.item_description it, loc.name loc, COUNT(*) n, COALESCE(SUM(ml.cost), 0) c FROM maintenance_logs ml JOIN maintenance_locations loc ON loc.id = ml.location_id
        WHERE ml.organization_id = ? AND ml.work_date >= DATE_SUB(CURDATE(), INTERVAL 89 DAY) AND ml.work_type IN ('दुरुस्त केले','बदलले')
        GROUP BY ml.location_id, ml.item_description HAVING n >= 2 ORDER BY n DESC, c DESC LIMIT 8");
    $rp->execute([$orgId]);
    $repeat = $rp->fetchAll();
    $dp = $pdo->prepare("SELECT COALESCE(d.name_local, d.name) dn, COUNT(*) n, COALESCE(SUM(ml.cost), 0) c FROM maintenance_logs ml JOIN departments d ON d.id = ml.department_id
        WHERE ml.organization_id = ? AND ml.work_date >= DATE_SUB(CURDATE(), INTERVAL 89 DAY) GROUP BY d.id, dn ORDER BY c DESC, n DESC LIMIT 8");
    $dp->execute([$orgId]);
    $byDept = $dp->fetchAll();
    $tq = $pdo->prepare("SELECT DATE_FORMAT(work_date, '%Y-%m') ym, work_type wt, COUNT(*) n FROM maintenance_logs WHERE organization_id = ? AND work_date >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01') GROUP BY ym, wt");
    $tq->execute([$orgId]);
    $tr = [];
    foreach ($tq->fetchAll() as $r) $tr[$r['wt']][$r['ym']] = (int)$r['n'];
    $mr = ['', 'जाने', 'फेब्रु', 'मार्च', 'एप्रि', 'मे', 'जून', 'जुलै', 'ऑग', 'सप्टें', 'ऑक्टो', 'नोव्हें', 'डिसें'];
    $lbl = []; $yms = [];
    for ($i = 5; $i >= 0; $i--) { $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $yms[] = $ym; $lbl[] = $mr[(int)substr($ym, 5)]; }
    $colors = ['बसवले' => '#0F766E', 'दुरुस्त केले' => '#F59E0B', 'बदलले' => '#E11D48', 'तपासणी' => '#3B6FC9'];
    $ds = [];
    foreach ($colors as $wt => $col) $ds[] = ['label' => $wt, 'data' => array_map(fn($ym) => $tr[$wt][$ym] ?? 0, $yms), 'backgroundColor' => $col, 'borderRadius' => 4];

    $al = [];
    foreach (array_slice($repeat, 0, 3) as $r) $al[] = ['warn', '🔁 ' . $r['loc'] . ' — "' . $r['it'] . '" 90 दिवसांत ' . $r['n'] . ' वेळा दुरुस्त/बदलली' . ($r['c'] > 0 ? ' (₹' . number_format($r['c']) . ')' : '') . ' — कायमस्वरूपी उपाय?', ''];
    smartAlerts($al);

    echo '<div class="row g-3 mb-3"><div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><div class="title">📈 कामाचा प्रकार — 6 महिने</div></div><div style="padding:10px 16px;height:220px;"><canvas id="mtTrend"></canvas></div></div></div><div class="col-lg-4">';
    smartBars('🏢 विभागनिहाय खर्च', 'मागील 90 दिवस', array_map(fn($d) => ['name' => $d['dn'], 'value' => (float)$d['c'] ?: (int)$d['n'], 'label' => ((float)$d['c'] ? '₹' . number_format($d['c']) . ' · ' : '') . $d['n'] . ' कामं'], $byDept), 'var(--amber)', 'नोंदी नाहीत');
    echo '</div><div class="col-lg-3">';
    smartBars('🔁 वारंवार बिघडणारं', '90 दिवसांत 2+ वेळा', array_map(fn($r) => ['name' => $r['it'] . ' · ' . $r['loc'], 'value' => (int)$r['n'], 'label' => $r['n'] . '×'], $repeat), 'var(--rose)', '✅ एकही नाही');
    echo '</div></div>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){if(!window.Chart)return;new Chart(document.getElementById("mtTrend"),{type:"bar",data:{labels:' . json_encode($lbl, JSON_UNESCAPED_UNICODE) . ',datasets:' . json_encode($ds, JSON_UNESCAPED_UNICODE) . '},options:{maintainAspectRatio:false,plugins:{legend:{position:"bottom",labels:{boxWidth:12}}},scales:{x:{stacked:true},y:{stacked:true,beginAtZero:true,ticks:{precision:0}}}}});});</script>';
} catch (PDOException $e) {}
