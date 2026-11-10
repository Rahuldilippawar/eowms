<?php
// 🎫 तक्रार निवारण — Smart आढावा (फक्त हाताळणाऱ्यांना; grievances/index.php मधून)
// कोणाचीही ओळख नाही — फक्त एकत्रित आकडे: निपटारा-वेळ, SLA पालन, प्रकार, वय, 6 महिन्यांचा कल.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) new30,
            SUM(status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) done30,
            AVG(CASE WHEN status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) THEN TIMESTAMPDIFF(HOUR, created_at, resolved_at) END) avg_h,
            SUM(status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND sla_deadline IS NOT NULL AND DATE(resolved_at) <= sla_deadline) in_sla,
            SUM(status IN ('resolved','closed') AND resolved_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) AND sla_deadline IS NOT NULL) with_sla,
            SUM(is_anonymous = 1 AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) anon90,
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) all90,
            SUM(status IN ('open','in_progress') AND assigned_to IS NULL) unassigned,
            SUM(status IN ('open','in_progress') AND created_at >= DATE_SUB(NOW(), INTERVAL 3 DAY)) age3,
            SUM(status IN ('open','in_progress') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY) AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) age7,
            SUM(status IN ('open','in_progress') AND created_at < DATE_SUB(NOW(), INTERVAL 7 DAY) AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) age30,
            SUM(status IN ('open','in_progress') AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)) age_old,
            SUM(reopened_count > 0) reopened
        FROM grievances WHERE organization_id = ?");
    $st->execute([$orgId]);
    $ga = $st->fetch() ?: [];
    $avgH = (float)($ga['avg_h'] ?? 0);
    $avgTxt = !$avgH ? '—' : ($avgH >= 24 ? round($avgH / 24, 1) . ' दिवस' : round($avgH) . ' तास');
    $slaPct = (int)($ga['with_sla'] ?? 0) ? (int)round($ga['in_sla'] * 100 / $ga['with_sla']) : null;
    smartTiles([
        ['label' => 'मागील ३० दिवस', 'value' => (int)$ga['new30'] . ' नवीन', 'sub' => (int)$ga['done30'] . ' सोडवल्या'],
        ['label' => 'सरासरी निपटारा-वेळ', 'value' => $avgTxt, 'sub' => 'मागील ९० दिवस'],
        ['label' => 'मुदतीत सोडवल्या', 'value' => $slaPct === null ? '—' : $slaPct . '%', 'tone' => $slaPct === null ? '' : ($slaPct >= 90 ? 'ok' : ($slaPct >= 70 ? 'warn' : 'bad'))],
        ['label' => 'गुप्त तक्रारी', 'value' => (int)$ga['all90'] ? (int)round($ga['anon90'] * 100 / $ga['all90']) . '%' : '—', 'sub' => 'मागील ९० दिवस'],
    ]);
    $al = [];
    if ((int)$ga['unassigned']) $al[] = ['warn', (int)$ga['unassigned'] . ' उघड्या तक्रारी कोणालाही नेमलेल्या नाहीत', '#'];
    if ((int)$ga['age_old']) $al[] = ['bad', (int)$ga['age_old'] . ' तक्रारी ३० दिवसांपेक्षा जास्त उघड्या आहेत', '#'];
    if ((int)$ga['reopened']) $al[] = ['info', (int)$ga['reopened'] . ' तक्रारी पुन्हा उघडल्या गेल्या — उत्तर समाधानकारक नव्हतं', '#'];
    smartAlerts($al);

    $cs = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(category), ''), 'इतर') c, COUNT(*) n, SUM(status IN ('open','in_progress')) o FROM grievances WHERE organization_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 180 DAY) GROUP BY c ORDER BY n DESC LIMIT 8");
    $cs->execute([$orgId]);
    $cats = $cs->fetchAll();
    $tr = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%Y-%m') ym, COUNT(*) n FROM grievances WHERE organization_id = ? AND created_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01') GROUP BY ym");
    $tr->execute([$orgId]);
    $newBy = array_column($tr->fetchAll(), 'n', 'ym');
    $tr = $pdo->prepare("SELECT DATE_FORMAT(resolved_at, '%Y-%m') ym, COUNT(*) n FROM grievances WHERE organization_id = ? AND resolved_at >= DATE_FORMAT(CURDATE() - INTERVAL 5 MONTH, '%Y-%m-01') GROUP BY ym");
    $tr->execute([$orgId]);
    $doneBy = array_column($tr->fetchAll(), 'n', 'ym');
    $mr = ['', 'जाने', 'फेब्रु', 'मार्च', 'एप्रि', 'मे', 'जून', 'जुलै', 'ऑग', 'सप्टें', 'ऑक्टो', 'नोव्हें', 'डिसें'];
    $gL = $gN = $gD = [];
    for ($i = 5; $i >= 0; $i--) { $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $gL[] = $mr[(int)substr($ym, 5)]; $gN[] = (int)($newBy[$ym] ?? 0); $gD[] = (int)($doneBy[$ym] ?? 0); }
    $ageRows = [
        ['name' => '३ दिवसांच्या आत', 'value' => (int)$ga['age3'], 'color' => 'var(--rail)'],
        ['name' => '३–७ दिवस', 'value' => (int)$ga['age7'], 'color' => 'var(--amber)'],
        ['name' => '७–३० दिवस', 'value' => (int)$ga['age30'], 'color' => '#E07A3F'],
        ['name' => '३० + दिवस', 'value' => (int)$ga['age_old'], 'color' => 'var(--rose)'],
    ];
    echo '<div class="row g-3 mb-3"><div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><div class="title">📈 नवीन व सोडवलेल्या — 6 महिने</div></div><div style="padding:10px 16px;height:220px;"><canvas id="gvTrend"></canvas></div></div></div><div class="col-lg-4">';
    smartBars('🗂️ प्रकारानुसार', 'मागील ६ महिने · (उघड्या)', array_map(fn($c) => ['name' => $c['c'], 'value' => (int)$c['n'], 'label' => $c['n'] . ((int)$c['o'] ? ' (' . $c['o'] . ')' : '')], $cats), '#8A5CD6', 'तक्रारी नाहीत');
    echo '</div><div class="col-lg-3">';
    smartBars('⏳ उघड्या तक्रारींचं वय', '', array_sum(array_column($ageRows, 'value')) ? $ageRows : [], 'var(--rail)', '✅ एकही तक्रार उघडी नाही');
    echo '</div></div>';
    echo '<script>document.addEventListener("DOMContentLoaded",function(){if(!window.Chart)return;new Chart(document.getElementById("gvTrend"),{type:"bar",data:{labels:' . json_encode($gL, JSON_UNESCAPED_UNICODE) . ',datasets:[{label:"नवीन",data:' . json_encode($gN) . ',backgroundColor:"#E07A3F",borderRadius:6},{label:"सोडवल्या",data:' . json_encode($gD) . ',backgroundColor:"#0F766E",borderRadius:6}]},options:{maintainAspectRatio:false,plugins:{legend:{position:"bottom"}},scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});});</script>';
} catch (PDOException $e) {}
