<?php
// 🐄 गौशाळा — Smart आढावा (gaushala/index.php मधून): लसीकरण/उपचार देय, वासरू अपेक्षित, कमी चारा,
// संपणारी Sponsorship, 30 दिवसांचं दूध व सर्वाधिक दूध देणाऱ्या गायी.
require_once __DIR__ . '/../smart-insights.php';
try {
    $al = [];
    $v = $pdo->prepare("SELECT SUM(h.next_due_date < CURDATE()) od, SUM(h.next_due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) soon
        FROM gaushala_health_records h JOIN gaushala_cattle c ON c.id = h.cattle_id
        WHERE c.organization_id = ? AND c.status = 'active' AND h.next_due_date IS NOT NULL
          AND h.id = (SELECT h2.id FROM gaushala_health_records h2 WHERE h2.cattle_id = h.cattle_id AND h2.record_type = h.record_type ORDER BY h2.record_date DESC, h2.id DESC LIMIT 1)");
    $v->execute([$orgId]);
    $vr = $v->fetch() ?: [];
    if ((int)$vr['od']) $al[] = ['bad', '💉 ' . (int)$vr['od'] . ' लसीकरण / उपचारांची मुदत उलटली', 'cattle.php'];
    if ((int)$vr['soon']) $al[] = ['warn', '💉 ' . (int)$vr['soon'] . ' लसीकरण / उपचार पुढच्या 7 दिवसांत', 'cattle.php'];
    $b = $pdo->prepare("SELECT COUNT(*) FROM gaushala_breeding_records b JOIN gaushala_cattle c ON c.id = b.cattle_id WHERE c.organization_id = ? AND b.status = 'pregnant' AND b.expected_calving_date BETWEEN CURDATE() - INTERVAL 7 DAY AND CURDATE() + INTERVAL 15 DAY");
    $b->execute([$orgId]);
    if ($n = (int)$b->fetchColumn()) $al[] = ['info', '🍼 ' . $n . ' गायींचं वासरू पुढच्या 15 दिवसांत अपेक्षित — तयारी ठेवा', 'breeding.php'];
    $f = $pdo->prepare("SELECT name, current_stock, unit FROM gaushala_fodder_types WHERE organization_id = ? AND current_stock <= reorder_level ORDER BY current_stock LIMIT 4");
    $f->execute([$orgId]);
    $low = $f->fetchAll();
    if ($low) $al[] = ['bad', '🌾 चारा कमी: ' . implode(', ', array_map(fn($x) => $x['name'] . ' (' . rtrim(rtrim(number_format((float)$x['current_stock'], 2), '0'), '.') . ' ' . $x['unit'] . ')', $low)), 'fodder.php'];
    $sp = $pdo->prepare("SELECT COUNT(*) FROM gaushala_sponsorships WHERE organization_id = ? AND status = 'active' AND end_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 30 DAY");
    $sp->execute([$orgId]);
    if ($n = (int)$sp->fetchColumn()) $al[] = ['info', '💝 ' . $n . ' Sponsorship 30 दिवसांत संपणार — दात्यांशी संपर्क', 'sponsorships.php'];
    smartAlerts($al);

    // 🥛 मागचे 30 दिवस — रोजचं दूध
    $m = $pdo->prepare("SELECT m.record_date d, SUM(m.quantity_liters) q FROM gaushala_milk_records m JOIN gaushala_cattle c ON c.id = m.cattle_id
        WHERE c.organization_id = ? AND m.record_date >= CURDATE() - INTERVAL 29 DAY GROUP BY d");
    $m->execute([$orgId]);
    $byDay = array_column($m->fetchAll(), 'q', 'd');
    if ($byDay) {
        $lbl = []; $val = [];
        for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $lbl[] = date('d/m', strtotime($d)); $val[] = round((float)($byDay[$d] ?? 0), 1); }
        $last7 = array_sum(array_slice($val, -7)) / 7; $prev7 = array_sum(array_slice($val, -14, 7)) / 7;
        $tp = $pdo->prepare("SELECT COALESCE(NULLIF(c.name, ''), c.tag_number, CONCAT('#', c.id)) n, SUM(m.quantity_liters) q FROM gaushala_milk_records m JOIN gaushala_cattle c ON c.id = m.cattle_id
            WHERE c.organization_id = ? AND m.record_date >= CURDATE() - INTERVAL 6 DAY GROUP BY c.id, n ORDER BY q DESC LIMIT 8");
        $tp->execute([$orgId]);
        $top = $tp->fetchAll();
        echo '<div class="row g-3 mb-3"><div class="col-lg-8"><div class="panel h-100"><div class="panel-head"><div class="title">🥛 रोजचं दूध — मागचे 30 दिवस</div><div class="sub">या आठवड्याची सरासरी ' . round($last7, 1) . ' L/दिवस'
            . ($prev7 > 0 ? ' · मागच्या आठवड्यापेक्षा ' . ($last7 >= $prev7 ? '▲ ' : '▼ ') . abs((int)round(($last7 - $prev7) * 100 / $prev7)) . '%' : '') . '</div></div><div style="padding:10px 16px;height:220px;"><canvas id="gsMilk"></canvas></div></div></div><div class="col-lg-4">';
        smartBars('🏆 सर्वाधिक दूध — 7 दिवस', '', array_map(fn($t) => ['name' => $t['n'], 'value' => (float)$t['q'], 'label' => round($t['q'], 1) . ' L'], $top), 'var(--rail)', 'नोंदी नाहीत');
        echo '</div></div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){if(!window.Chart)return;new Chart(document.getElementById("gsMilk"),{type:"bar",data:{labels:' . json_encode($lbl) . ',datasets:[{label:"लिटर",data:' . json_encode($val) . ',backgroundColor:"#0F766E",borderRadius:4}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});});</script>';
    }
} catch (PDOException $e) {}
