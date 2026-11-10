<?php
// 🧘 ध्यान व सत्संग — Smart आढावा (meditation/index.php मधून): उपस्थितीचा कल, आगामी सत्संग,
// प्रलंबित सेवा (Duties), सलग गैरहजर कर्मचारी.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM satsang_events WHERE organization_id = ? AND event_date >= CURDATE()) upcoming,
            (SELECT COUNT(*) FROM satsang_events WHERE organization_id = ? AND event_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND event_date < CURDATE()) month_done,
            (SELECT COUNT(*) FROM satsang_duties d JOIN satsang_events e ON e.id = d.satsang_event_id WHERE e.organization_id = ? AND d.status = 'pending' AND e.event_date >= CURDATE() - INTERVAL 7 DAY) duties");
    $st->execute([$orgId, $orgId, $orgId]);
    $s = $st->fetch() ?: [];
    // प्रत्येक सत्संगाची उपस्थिती % — मागचे 12 सत्संग
    $at = $pdo->prepare("SELECT e.id, e.event_date, e.theme, SUM(a.status = 'present') p, COUNT(a.id) n FROM satsang_events e LEFT JOIN satsang_attendance a ON a.satsang_event_id = e.id
        WHERE e.organization_id = ? AND e.event_date <= CURDATE() GROUP BY e.id ORDER BY e.event_date DESC LIMIT 12");
    $at->execute([$orgId]);
    $rows = array_reverse(array_filter($at->fetchAll(), fn($r) => (int)$r['n'] > 0));
    $avg = $rows ? (int)round(array_sum(array_map(fn($r) => $r['p'] * 100 / $r['n'], $rows)) / count($rows)) : null;
    smartTiles([
        ['label' => 'आगामी सत्संग', 'value' => (int)$s['upcoming']],
        ['label' => 'या महिन्यात झाले', 'value' => (int)$s['month_done']],
        ['label' => 'सरासरी उपस्थिती', 'value' => $avg === null ? '—' : $avg . '%', 'sub' => 'मागचे ' . count($rows) . ' सत्संग', 'tone' => $avg === null ? '' : ($avg >= 75 ? 'ok' : ($avg >= 50 ? 'warn' : 'bad'))],
        ['label' => 'प्रलंबित सेवा', 'value' => (int)$s['duties'], 'tone' => (int)$s['duties'] ? 'warn' : ''],
    ]);
    // सलग 3 सत्संगांना गैरहजर
    $ab = $pdo->prepare("SELECT u.name, COUNT(*) n FROM satsang_attendance a JOIN users u ON u.id = a.user_id
        WHERE a.status = 'absent' AND a.satsang_event_id IN (SELECT id FROM (SELECT id FROM satsang_events WHERE organization_id = ? AND event_date <= CURDATE() ORDER BY event_date DESC LIMIT 3) t)
        GROUP BY a.user_id, u.name HAVING n >= 3 ORDER BY u.name LIMIT 10");
    $ab->execute([$orgId]);
    $absent = $ab->fetchAll(PDO::FETCH_COLUMN);
    smartAlerts($absent ? [['warn', '🙏 मागच्या 3 सत्संगांना सलग गैरहजर: ' . implode(', ', array_slice($absent, 0, 6)) . (count($absent) > 6 ? ' …' : ''), '']] : []);
    if (count($rows) >= 2) {
        echo '<div class="panel mb-3"><div class="panel-head"><div class="title">📈 सत्संग उपस्थिती — मागचे ' . count($rows) . '</div></div><div style="padding:10px 16px;height:200px;"><canvas id="ssTrend"></canvas></div></div>';
        echo '<script>document.addEventListener("DOMContentLoaded",function(){if(!window.Chart)return;new Chart(document.getElementById("ssTrend"),{type:"line",data:{labels:' . json_encode(array_map(fn($r) => date('d/m', strtotime($r['event_date'])), $rows)) . ',datasets:[{label:"उपस्थिती %",data:' . json_encode(array_map(fn($r) => (int)round($r['p'] * 100 / $r['n']), $rows)) . ',borderColor:"#8A5CD6",backgroundColor:"rgba(138,92,214,.12)",fill:true,tension:.3}]},options:{maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,max:100,ticks:{callback:function(v){return v+"%";}}}}}});});</script>';
    }
} catch (PDOException $e) {}
