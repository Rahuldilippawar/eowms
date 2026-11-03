<?php
// 🎉 कार्यक्रम — Smart आढावा (events/index.php मधून)
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            SUM(status IN ('planned','ongoing') AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) week,
            SUM(status IN ('planned','ongoing') AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) month,
            SUM(status IN ('planned','ongoing') AND event_date = CURDATE()) today,
            SUM(status = 'completed' AND event_date >= DATE_FORMAT(CURDATE(), '%Y-01-01')) done_year,
            SUM(status IN ('planned','ongoing') AND event_date < CURDATE()) not_closed,
            SUM(status IN ('planned','ongoing') AND event_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 14 DAY) AND responsible_user_id IS NULL) no_owner,
            SUM(CASE WHEN status = 'completed' AND event_date >= DATE_FORMAT(CURDATE(), '%Y-01-01') THEN COALESCE(budget_estimated,0) END) est_year,
            SUM(CASE WHEN status = 'completed' AND event_date >= DATE_FORMAT(CURDATE(), '%Y-01-01') THEN COALESCE(budget_actual,0) END) act_year
        FROM events WHERE organization_id = ?");
    $st->execute([$orgId]);
    $ec = $st->fetch() ?: [];
    $est = (float)($ec['est_year'] ?? 0); $act = (float)($ec['act_year'] ?? 0);
    smartTiles([
        ['label' => 'आज', 'value' => (int)$ec['today'], 'tone' => (int)$ec['today'] ? 'warn' : ''],
        ['label' => 'पुढील ७ दिवस', 'value' => (int)$ec['week']],
        ['label' => 'पुढील ३० दिवस', 'value' => (int)$ec['month']],
        ['label' => 'या वर्षी पूर्ण', 'value' => (int)$ec['done_year'], 'tone' => 'ok'],
        ['label' => 'Budget वापर (या वर्षी)', 'value' => $est > 0 ? round($act * 100 / $est) . '%' : '—', 'tone' => $est > 0 && $act > $est ? 'bad' : '', 'sub' => '₹' . number_format($act) . ' / ₹' . number_format($est)],
    ]);
    $al = [];
    if ((int)$ec['today']) $al[] = ['warn', 'आज ' . (int)$ec['today'] . ' कार्यक्रम आहेत', '?status=all'];
    if ((int)$ec['no_owner']) $al[] = ['bad', (int)$ec['no_owner'] . ' आगामी कार्यक्रमांना जबाबदार व्यक्ती नेमलेली नाही', '?status=all'];
    if ((int)$ec['not_closed']) $al[] = ['info', (int)$ec['not_closed'] . ' होऊन गेलेले कार्यक्रम अजून "पूर्ण" म्हणून बंद केलेले नाहीत', '?status=planned'];
    if ($est > 0 && $act > $est) $al[] = ['warn', 'या वर्षीचा प्रत्यक्ष खर्च अंदाजापेक्षा ₹' . number_format($act - $est) . ' जास्त', ''];
    smartAlerts($al);
} catch (PDOException $e) {}
