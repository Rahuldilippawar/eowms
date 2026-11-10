<?php
// 📹 CCTV — "लक्ष द्या" सूचना: आज तपासणी न झालेले DVR, भरत आलेली Storage,
// कमी दिवसांचे Footage व ७ दिवसांत Backup न झालेले DVR.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT d.id, d.dvr_name,
            (SELECT c.checkup_date FROM cctv_checkups c WHERE c.dvr_id = d.id ORDER BY c.checkup_date DESC LIMIT 1) last_check,
            (SELECT c.storage_used_percent FROM cctv_checkups c WHERE c.dvr_id = d.id ORDER BY c.checkup_date DESC LIMIT 1) storage,
            (SELECT c.footage_days_available FROM cctv_checkups c WHERE c.dvr_id = d.id ORDER BY c.checkup_date DESC LIMIT 1) footage,
            (SELECT MAX(c.checkup_date) FROM cctv_checkups c WHERE c.dvr_id = d.id AND c.backup_done = 1) last_backup
        FROM cctv_dvrs d WHERE d.organization_id = ? AND d.is_active = 1");
    $st->execute([$orgId]);
    $al = []; $notToday = [];
    foreach ($st->fetchAll() as $d) {
        if ($d['last_check'] !== date('Y-m-d')) $notToday[] = $d['dvr_name'];
        if ($d['storage'] !== null && (float)$d['storage'] >= 90) $al[] = ['bad', '💾 ' . $d['dvr_name'] . ' — Storage ' . (float)$d['storage'] . '% भरली', ''];
        if ($d['footage'] !== null && (int)$d['footage'] < 7) $al[] = ['warn', '🎞️ ' . $d['dvr_name'] . ' — फक्त ' . (int)$d['footage'] . ' दिवसांचं Footage', ''];
        if (!$d['last_backup'] || strtotime($d['last_backup']) < strtotime('-7 days')) $al[] = ['warn', '🗄️ ' . $d['dvr_name'] . ' — ७ दिवसांत Backup नाही', ''];
    }
    if ($notToday && (int)date('G') >= 11) array_unshift($al, ['info', '📋 ' . count($notToday) . ' DVR ची आजची तपासणी बाकी: ' . implode(', ', array_slice($notToday, 0, 4)) . (count($notToday) > 4 ? '…' : ''), '']);
    smartAlerts(array_slice($al, 0, 10));

    // 📈 मागचे 14 दिवस — किती DVR ची तपासणी झाली; 💾 प्रत्येक DVR ची सध्याची Storage
    $st->execute([$orgId]);
    $dvrList = $st->fetchAll();
    $totalD = count($dvrList);
    if ($totalD) {
        $cq = $pdo->prepare("SELECT checkup_date d, COUNT(DISTINCT dvr_id) n FROM cctv_checkups WHERE organization_id = ? AND checkup_date >= DATE_SUB(CURDATE(), INTERVAL 13 DAY) GROUP BY d");
        $cq->execute([$orgId]);
        $byDay = array_column($cq->fetchAll(), 'n', 'd');
        $cl = $cv = []; $doneDays = 0;
        for ($i = 13; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i day")); $cl[] = date('d/m', strtotime($d)); $n = (int)($byDay[$d] ?? 0); $cv[] = $n; if ($n >= $totalD) $doneDays++; }
        echo '<div class="row g-3 mb-3"><div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><div class="title">📈 रोजची तपासणी — मागचे 14 दिवस</div><div class="sub">' . $doneDays . ' / 14 दिवस सगळ्या ' . $totalD . ' DVR ची तपासणी पूर्ण</div></div><div style="padding:10px 16px;height:210px;"><canvas id="cctvTrend"></canvas></div></div></div><div class="col-lg-5">';
        $rows = [];
        foreach ($dvrList as $d) if ($d['storage'] !== null) $rows[] = ['name' => $d['dvr_name'], 'value' => (float)$d['storage'], 'label' => (float)$d['storage'] . '%', 'color' => (float)$d['storage'] >= 90 ? 'var(--rose)' : ((float)$d['storage'] >= 75 ? 'var(--amber)' : 'var(--rail)')];
        usort($rows, fn($a, $b) => $b['value'] <=> $a['value']);
        smartBars('💾 Storage — शेवटच्या तपासणीनुसार', '90%+ लाल', array_slice($rows, 0, 8), 'var(--rail)', 'अजून तपासणी नाही');
        echo '</div></div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){if(!window.Chart)return;new Chart(document.getElementById("cctvTrend"),{type:"bar",data:{labels:' . json_encode($cl) . ',datasets:[{label:"तपासलेले DVR",data:' . json_encode($cv) . ',backgroundColor:' . json_encode(array_map(fn($n) => $n >= $totalD ? '#0F766E' : ($n ? '#F59E0B' : '#E5E7EB'), $cv)) . ',borderRadius:5}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:' . $totalD . ',ticks:{precision:0}}}}});});</script>';
    }
} catch (PDOException $e) {}
