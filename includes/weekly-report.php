<?php
/**
 * 📊 Management साठी साप्ताहिक अहवाल — दर सोमवारी सकाळी PDF सह Email.
 * गेल्या आठवड्याचं (सोमवार–रविवार) चित्र: विभागांचं आरोग्य, कामं, मुदतबाह्य Tasks,
 * SLA बाहेरच्या विनंत्या, नूतनीकरण, खर्च (Budget). Cron: cron/weekly-management-report.php
 * Preview / Settings: reports/weekly.php
 */
require_once __DIR__ . '/app-settings.php';
require_once __DIR__ . '/dept-health.php';
require_once __DIR__ . '/mailer.php';

/** अहवालाचा कालावधी — $end पर्यंतचे 7 दिवस (default: कालपर्यंत; सोमवारी = मागचा सोमवार–रविवार) */
function weeklyReportPeriod($end = null) {
    $endTs = $end ? strtotime($end) : strtotime('yesterday');
    $e = date('Y-m-d', $endTs);
    $s = date('Y-m-d', strtotime('-6 days', $endTs));
    return ['start' => $s, 'end' => $e, 'prevStart' => date('Y-m-d', strtotime('-7 days', strtotime($s))), 'prevEnd' => date('Y-m-d', strtotime('-1 day', strtotime($s)))];
}

function weeklyReportMarathiDate($date, $withYear = true) {
    $m = ['', 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
    $ts = strtotime($date);
    return date('j', $ts) . ' ' . $m[(int)date('n', $ts)] . ($withYear ? ' ' . date('Y', $ts) : '');
}

function weeklyReportMoney($n) {
    $n = (float)$n;
    if ($n >= 10000000) { return '₹' . rtrim(rtrim(number_format($n / 10000000, 2), '0'), '.') . ' कोटी'; }
    if ($n >= 100000) { return '₹' . rtrim(rtrim(number_format($n / 100000, 2), '0'), '.') . ' लाख'; }
    return '₹' . number_format($n, 0);
}

/** अहवालाचा सगळा Data */
function weeklyReportData(PDO $pdo, $orgId, array $period) {
    $q = function ($sql, $params, $mode = 'all') use ($pdo) {
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $mode === 'one' ? $st->fetch() : ($mode === 'col' ? $st->fetchColumn() : $st->fetchAll());
        } catch (PDOException $e) { return $mode === 'all' ? [] : null; }
    };
    [$s, $e, $ps, $pe] = [$period['start'], $period['end'], $period['prevStart'], $period['prevEnd']];
    $org = $q("SELECT name FROM organizations WHERE id = ?", [$orgId], 'one');

    $depts = $q("SELECT d.*, u.name AS hod_name, c.name AS campus_name, c.college_type
        FROM departments d LEFT JOIN users u ON u.id = d.hod_id LEFT JOIN campuses c ON c.id = d.campus_id
        WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY d.name_local", [$orgId]);
    $health = deptHealthRows($pdo, $orgId, $depts);
    $sum = deptHealthSummary($health);

    // या आठवड्याची कामं (विभागवार) आणि मागचा आठवडा
    $workBy = [];
    foreach ($q("SELECT department_id, COUNT(*) total, SUM(status = 'completed') done FROM work_records WHERE organization_id = ? AND work_date BETWEEN ? AND ? GROUP BY department_id", [$orgId, $s, $e]) as $r) {
        $workBy[(int)$r['department_id']] = $r;
    }
    $work = $q("SELECT COUNT(*) total, COALESCE(SUM(status = 'completed'), 0) done, COUNT(DISTINCT employee_id) people FROM work_records WHERE organization_id = ? AND work_date BETWEEN ? AND ?", [$orgId, $s, $e], 'one') ?: ['total' => 0, 'done' => 0, 'people' => 0];
    $workPrev = (int)$q("SELECT COUNT(*) FROM work_records WHERE organization_id = ? AND work_date BETWEEN ? AND ?", [$orgId, $ps, $pe], 'col');
    $daily = [];
    foreach ($q("SELECT work_date, COUNT(*) c FROM work_records WHERE organization_id = ? AND work_date BETWEEN ? AND ? GROUP BY work_date", [$orgId, $s, $e]) as $r) { $daily[$r['work_date']] = (int)$r['c']; }
    $days = [];
    for ($t = strtotime($s); $t <= strtotime($e); $t += 86400) { $days[date('Y-m-d', $t)] = $daily[date('Y-m-d', $t)] ?? 0; }

    // Tasks
    $tasks = $q("SELECT COALESCE(SUM(status IN ('pending','in_progress')), 0) open_cnt,
            COALESCE(SUM(status IN ('pending','in_progress') AND target_date < CURDATE()), 0) overdue,
            COALESCE(SUM(status = 'completed' AND DATE(completed_at) BETWEEN ? AND ?), 0) done_week,
            COALESCE(SUM(DATE(created_at) BETWEEN ? AND ?), 0) new_week
        FROM tasks WHERE organization_id = ?", [$s, $e, $s, $e, $orgId], 'one') ?: [];
    $overdueTasks = $q("SELECT t.title, t.target_date, t.priority, DATEDIFF(CURDATE(), t.target_date) late, u.name assignee, COALESCE(NULLIF(d.name_local, ''), d.name) dept
        FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN departments d ON d.id = t.department_id
        WHERE t.organization_id = ? AND t.status IN ('pending','in_progress') AND t.target_date < CURDATE()
        ORDER BY t.target_date ASC, FIELD(t.priority, 'urgent','high','normal','low') LIMIT 10", [$orgId]);

    // कामाच्या विनंत्या (Work Requests)
    $req = $q("SELECT COALESCE(SUM(status IN ('pending','in_progress')), 0) open_cnt,
            COALESCE(SUM(status IN ('pending','in_progress') AND due_at < NOW()), 0) overdue,
            COALESCE(SUM(DATE(created_at) BETWEEN ? AND ?), 0) new_week,
            COALESCE(SUM(DATE(completed_at) BETWEEN ? AND ?), 0) done_week
        FROM work_requests WHERE organization_id = ?", [$s, $e, $s, $e, $orgId], 'one') ?: [];

    // नूतनीकरण
    $renewals = $q("SELECT r.item_name, r.expiry_date, DATEDIFF(r.expiry_date, CURDATE()) days_left, COALESCE(NULLIF(d.name_local, ''), d.name) dept
        FROM renewal_items r LEFT JOIN departments d ON d.id = r.department_id
        WHERE r.organization_id = ? AND r.status <> 'inactive' AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
        ORDER BY r.expiry_date ASC LIMIT 12", [$orgId]);

    // खर्च — Budget व्यवहार (या आठवड्यात) + चालू आर्थिक वर्ष
    $fy = (int)date('n') >= 4 ? (int)date('Y') : (int)date('Y') - 1;
    $spendWeek = (float)$q("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE organization_id = ? AND COALESCE(spend_date, DATE(created_at)) BETWEEN ? AND ?", [$orgId, $s, $e], 'col');
    $spendPrev = (float)$q("SELECT COALESCE(SUM(amount), 0) FROM budget_transactions WHERE organization_id = ? AND COALESCE(spend_date, DATE(created_at)) BETWEEN ? AND ?", [$orgId, $ps, $pe], 'col');
    $spendCat = $q("SELECT category, SUM(amount) amt, COUNT(*) n FROM budget_transactions WHERE organization_id = ? AND COALESCE(spend_date, DATE(created_at)) BETWEEN ? AND ? GROUP BY category ORDER BY amt DESC", [$orgId, $s, $e]);
    $budget = $q("SELECT d.id, COALESCE(NULLIF(d.name_local, ''), d.name) dept, COALESCE(b.allocated_amount, 0) allocated,
            (SELECT COALESCE(SUM(amount), 0) FROM budget_transactions t WHERE t.department_id = d.id AND t.financial_year = ?) spent,
            (SELECT COALESCE(SUM(amount), 0) FROM budget_transactions t WHERE t.department_id = d.id AND COALESCE(t.spend_date, DATE(t.created_at)) BETWEEN ? AND ?) week
        FROM departments d LEFT JOIN department_budgets b ON b.department_id = d.id AND b.financial_year = ?
        WHERE d.organization_id = ? AND d.is_active = 1
        HAVING allocated > 0 OR spent > 0 ORDER BY week DESC, spent DESC", [$fy, $s, $e, $fy, $orgId]);
    $fyAlloc = array_sum(array_column($budget, 'allocated'));
    $fySpent = array_sum(array_column($budget, 'spent'));

    // विभाग-तक्ता: आरोग्य + या आठवड्याची कामं
    foreach ($health as &$h) {
        $w = $workBy[$h['id']] ?? null;
        $h['weekWork'] = (int)($w['total'] ?? 0);
        $h['weekDone'] = (int)($w['done'] ?? 0);
        $h['weekRate'] = $h['weekWork'] ? (int)round($h['weekDone'] * 100 / $h['weekWork']) : null;
    }
    unset($h);
    usort($health, fn($a, $b) => $a['score'] <=> $b['score']); // सर्वात कमकुवत आधी

    // लक्ष द्या — आपोआप निष्कर्ष
    $alerts = [];
    $idle = array_filter($health, fn($h) => $h['weekWork'] === 0);
    foreach (array_slice(array_filter($health, fn($h) => $h['level'] === 'bad'), 0, 3) as $h) {
        $alerts[] = ['bad', $h['name'] . ' — आरोग्य ' . $h['score'] . '/100' . ($h['issues'] ? ' (' . implode(', ', $h['issues']) . ')' : '')];
    }
    if ((int)($tasks['overdue'] ?? 0) > 0) { $alerts[] = ['bad', $tasks['overdue'] . ' Tasks मुदत उलटून अपूर्ण' . ($overdueTasks ? ' — सर्वात जुना ' . $overdueTasks[0]['late'] . ' दिवस उशीर' : '')]; }
    if ((int)($req['overdue'] ?? 0) > 0) { $alerts[] = ['warn', $req['overdue'] . ' कामाच्या विनंत्या SLA वेळ उलटून प्रलंबित']; }
    $expired = array_filter($renewals, fn($r) => (int)$r['days_left'] < 0);
    if ($expired) { $alerts[] = ['bad', count($expired) . ' परवाने/नूतनीकरण मुदत संपलेली — ' . implode(', ', array_slice(array_column($expired, 'item_name'), 0, 3))]; }
    if ($idle && count($idle) < count($health)) { $alerts[] = ['warn', count($idle) . ' विभागांत आठवडाभर एकही कामाची नोंद नाही — ' . implode(', ', array_slice(array_column($idle, 'name'), 0, 4))]; }
    foreach ($budget as $b) {
        if ($b['allocated'] > 0 && $b['spent'] > $b['allocated']) { $alerts[] = ['bad', $b['dept'] . ' — Budget ओलांडलं (' . weeklyReportMoney($b['spent']) . ' / ' . weeklyReportMoney($b['allocated']) . ')']; }
        elseif ($b['allocated'] > 0 && $b['spent'] >= 0.9 * $b['allocated']) { $alerts[] = ['warn', $b['dept'] . ' — Budget चा ' . round($b['spent'] * 100 / $b['allocated']) . '% वापरला']; }
    }
    $good = [];
    if ($sum['best'] && $sum['best']['score'] >= 75) { $good[] = $sum['best']['name'] . ' — सर्वोत्तम आरोग्य ' . $sum['best']['score'] . '/100'; }
    if ($workPrev > 0 && $work['total'] > $workPrev) { $good[] = 'कामांच्या नोंदी मागच्या आठवड्यापेक्षा ' . round(($work['total'] - $workPrev) * 100 / $workPrev) . '% जास्त'; }
    if ((int)($tasks['done_week'] ?? 0) > 0) { $good[] = $tasks['done_week'] . ' Tasks या आठवड्यात पूर्ण'; }

    // E-Campus — आठवड्याचा शेवटचा दिवस ज्या महिन्यात तो महिना
    require_once __DIR__ . '/ecampus-helpers.php';
    $ecPeriod = substr($e, 0, 7);
    $ecHtml = ecampusPdfSection($pdo, $orgId, $ecPeriod, 'brief', $s . ' 00:00:00');
    $ecampus = null;
    if ($ecHtml !== '') {
        $ecS = ecampusSummary(ecampusRows($pdo, $orgId, $ecPeriod), $ecPeriod);
        $ecampus = ['html' => $ecHtml, 'overall' => $ecS['overall'], 'done' => $ecS['done'], 'work' => $ecS['work'], 'period' => $ecPeriod];
        foreach (ecampusAlerts($pdo, $orgId, $ecPeriod) as $a) { $alerts[] = $a; }
    }

    return [
        'org' => $org['name'] ?? 'E-Admin', 'orgId' => (int)$orgId, 'period' => $period, 'fy' => $fy, 'ecampus' => $ecampus,
        'summary' => $sum, 'health' => $health, 'work' => $work, 'workPrev' => $workPrev, 'days' => $days,
        'tasks' => $tasks, 'overdueTasks' => $overdueTasks, 'req' => $req, 'renewals' => $renewals,
        'spendWeek' => $spendWeek, 'spendPrev' => $spendPrev, 'spendCat' => $spendCat, 'budget' => $budget, 'fyAlloc' => $fyAlloc, 'fySpent' => $fySpent,
        'alerts' => $alerts, 'good' => $good, 'generated' => date('Y-m-d H:i'),
    ];
}

/** बदलाचा बाण — मागच्या आठवड्याच्या तुलनेत */
function weeklyReportDelta($now, $prev, $upIsGood = true) {
    if ($prev <= 0) { return ''; }
    $pct = (int)round(($now - $prev) * 100 / $prev);
    if ($pct === 0) { return '<span style="color:#64708A;">= मागच्यासारखं</span>'; }
    $good = ($pct > 0) === $upIsGood;
    return '<span style="color:' . ($good ? '#137A4B' : '#B42318') . ';">' . ($pct > 0 ? '▲ ' : '▼ ') . abs($pct) . '%</span>';
}

/** PDF साठी HTML (mPDF — साधं CSS: Tables, रंग, Borders) */
function weeklyReportPdfHtml(array $d) {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $sum = $d['summary'];
    $lvlColor = ['good' => '#137A4B', 'warn' => '#B45309', 'bad' => '#B42318'];
    $lvlBg = ['good' => '#E7F6EF', 'warn' => '#FEF3C7', 'bad' => '#FDECEC'];
    $lvlTxt = ['good' => 'चांगलं', 'warn' => 'लक्ष द्या', 'bad' => 'गंभीर'];
    $period = weeklyReportMarathiDate($d['period']['start'], false) . ' – ' . weeklyReportMarathiDate($d['period']['end']);
    $rate = $d['work']['total'] ? (int)round($d['work']['done'] * 100 / $d['work']['total']) : 0;

    $kpi = function ($label, $value, $sub, $color = '#191C3A') {
        return '<td class="kpi"><div class="kl">' . $label . '</div><div class="kv" style="color:' . $color . ';">' . $value . '</div><div class="ks">' . $sub . '</div></td>';
    };

    $html = '<style>
      body{font-family:freesans;color:#1A1D29;font-size:10pt;}
      .hdr{background:#191C3A;color:#fff;padding:14px 18px;border-bottom:4px solid #D9530F;}
      .hdr .o{font-size:17pt;font-weight:bold;} .hdr .t{font-size:11pt;color:#FDBA8C;margin-top:2px;} .hdr .p{font-size:9pt;color:#C9CCE0;margin-top:4px;}
      h2{font-size:12.5pt;color:#191C3A;margin:16px 0 6px;padding-bottom:3px;border-bottom:1.5px solid #EAE5DF;} .h2s{font-size:8.5pt;color:#8A90AC;font-weight:normal;}
      table.kp{width:100%;border-collapse:separate;border-spacing:6px;margin:0 -6px;}
      td.kpi{background:#F7F5F2;border:1px solid #EAE5DF;padding:8px 10px;width:25%;vertical-align:top;}
      .kl{font-size:8.5pt;color:#64708A;} .kv{font-size:17pt;font-weight:bold;margin:2px 0;} .ks{font-size:8pt;color:#64708A;}
      table.g{width:100%;border-collapse:collapse;font-size:9pt;}
      table.g th{background:#F3F1EE;color:#475069;font-weight:bold;text-align:left;padding:5px 6px;border-bottom:1px solid #DDD6CE;font-size:8.5pt;}
      table.g td{padding:5px 6px;border-bottom:1px solid #EEE9E3;vertical-align:middle;}
      .num{text-align:right;} .ctr{text-align:center;}
      .pill{padding:1px 6px;font-size:8pt;font-weight:bold;}
      .al{padding:6px 10px;margin:4px 0;font-size:9.5pt;}
      .muted{color:#8A90AC;font-size:8.5pt;}
      .bar{height:7px;background:#EEE9E3;} .bar div{height:7px;}
      .num-r{text-align:right;} table.split{width:100%;border-collapse:collapse;} table.split td{vertical-align:top;padding:0;}
    </style>';

    $html .= '<div class="hdr"><div class="o">' . $h($d['org']) . '</div><div class="t">साप्ताहिक व्यवस्थापन अहवाल</div><div class="p">कालावधी: ' . $h($period) . ' &nbsp;·&nbsp; तयार: ' . $h(date('d-m-Y, h:i A', strtotime($d['generated']))) . '</div></div>';

    // KPIs
    $html .= '<table class="kp"><tr>'
        . $kpi('एकूण आरोग्य गुण', $sum['count'] ? $sum['score'] . '<span style="font-size:10pt;color:#8A90AC;">/100</span>' : '—', $sum['count'] . ' विभाग · ' . $sum['good'] . ' चांगले · ' . $sum['bad'] . ' गंभीर', $lvlColor[$sum['level']])
        . $kpi('या आठवड्याची कामं', number_format($d['work']['total']), $rate . '% पूर्ण · ' . (int)$d['work']['people'] . ' कर्मचारी · ' . weeklyReportDelta($d['work']['total'], $d['workPrev']))
        . $kpi('मुदतबाह्य Tasks', (int)($d['tasks']['overdue'] ?? 0), (int)($d['tasks']['open_cnt'] ?? 0) . ' प्रलंबित · ' . (int)($d['tasks']['done_week'] ?? 0) . ' या आठवड्यात पूर्ण', ($d['tasks']['overdue'] ?? 0) ? '#B42318' : '#137A4B')
        . $kpi('या आठवड्याचा खर्च', weeklyReportMoney($d['spendWeek']), 'FY: ' . weeklyReportMoney($d['fySpent']) . ($d['fyAlloc'] ? ' / ' . weeklyReportMoney($d['fyAlloc']) : '') . ' · ' . weeklyReportDelta($d['spendWeek'], $d['spendPrev'], false))
        . '</tr></table>';

    // लक्ष द्या
    if ($d['alerts'] || $d['good']) {
        $html .= '<h2>लक्ष द्या</h2>';
        foreach (array_slice($d['alerts'], 0, 8) as [$lv, $msg]) {
            $html .= '<div class="al" style="background:' . $lvlBg[$lv] . ';border-left:3px solid ' . $lvlColor[$lv] . ';">' . '<span style="color:' . $lvlColor[$lv] . ';">' . ($lv === 'bad' ? '●' : '○') . '</span> ' . $h($msg) . '</div>';
        }
        foreach ($d['good'] as $msg) { $html .= '<div class="al" style="background:#E7F6EF;border-left:3px solid #137A4B;"><span style="color:#137A4B;">●</span> ' . $h($msg) . '</div>'; }
    }

    // रोजची कामं — आडवे Bars (mPDF मध्ये Table-आधारित Bars विश्वसनीय)
    $max = max(1, max($d['days'] ?: [0]));
    $dn = ['Mon' => 'सोमवार', 'Tue' => 'मंगळवार', 'Wed' => 'बुधवार', 'Thu' => 'गुरुवार', 'Fri' => 'शुक्रवार', 'Sat' => 'शनिवार', 'Sun' => 'रविवार'];
    $html .= '<h2>दिवसनिहाय कामाच्या नोंदी</h2><table class="g">';
    foreach ($d['days'] as $date => $c) {
        $html .= '<tr><td style="width:22%;">' . $dn[date('D', strtotime($date))] . ' <span class="muted">' . date('d/m', strtotime($date)) . '</span></td>'
            . '<td style="width:68%;">' . weeklyReportBar($c / $max * 100, '#D9530F') . '</td><td class="num" style="width:10%;"><b>' . $c . '</b></td></tr>';
    }
    $html .= '</table>';

    // विभाग-तक्ता
    $html .= '<h2>विभागनिहाय आरोग्य</h2>';
    if (!$d['health']) { $html .= '<div class="muted">विभाग नाहीत.</div>'; }
    else {
        $html .= '<table class="g"><thead><tr><th style="width:27%;">विभाग</th><th class="ctr" style="width:17%;">आरोग्य</th><th class="num" style="width:11%;">कामं (आठवडा)</th><th class="num" style="width:10%;">पूर्णता</th><th class="num" style="width:12%;">मुदतबाह्य Tasks</th><th class="num" style="width:12%;">SLA बाहेर विनंत्या</th><th class="num" style="width:11%;">संपलेलं नूतनीकरण</th></tr></thead><tbody>';
        global $pdo;
        require_once __DIR__ . '/dept-groups.php';
        $html .= deptGroupRowsHtml($pdo, (int)$d['orgId'], $d['health'], 7, function ($r) use ($h, $lvlBg, $lvlColor, $lvlTxt) {   // Campus नुसार गटवार
            return '<tr><td><b>' . $h($r['name']) . '</b>' . ($r['hod'] ? '<br><span class="muted">' . $h($r['hod']) . '</span>' : '') . '</td>'
                . '<td class="ctr"><span class="pill" style="background:' . $lvlBg[$r['level']] . ';color:' . $lvlColor[$r['level']] . ';">' . $r['score'] . ' · ' . $lvlTxt[$r['level']] . '</span></td>'
                . '<td class="num">' . $r['weekWork'] . '</td>'
                . '<td class="num">' . ($r['weekRate'] === null ? '—' : $r['weekRate'] . '%') . '</td>'
                . '<td class="num" style="' . ($r['overdueTasks'] ? 'color:#B42318;font-weight:bold;' : '') . '">' . $r['overdueTasks'] . '</td>'
                . '<td class="num" style="' . ($r['overdueReq'] ? 'color:#B45309;font-weight:bold;' : '') . '">' . $r['overdueReq'] . '</td>'
                . '<td class="num" style="' . ($r['expired'] ? 'color:#B42318;font-weight:bold;' : '') . '">' . $r['expired'] . '</td></tr>';
        });
        $html .= '</tbody></table><div class="muted" style="margin-top:4px;">आरोग्य गुण = गेल्या 30 दिवसांची पूर्णता, मुदतबाह्य Tasks, SLA बाहेरच्या विनंत्या आणि संपलेली नूतनीकरणं यावरून (100 = उत्तम).</div>';
    }

    // मुदतबाह्य Tasks
    $html .= '<h2>मुदतबाह्य Tasks' . ((int)($d['tasks']['overdue'] ?? 0) > count($d['overdueTasks']) ? ' (सर्वात जुने ' . count($d['overdueTasks']) . ')' : '') . '</h2>';
    if (!$d['overdueTasks']) { $html .= '<div class="al" style="background:#E7F6EF;">एकही Task मुदतबाह्य नाही.</div>'; }
    else {
        $html .= '<table class="g"><thead><tr><th style="width:42%;">Task</th><th style="width:18%;">विभाग</th><th style="width:20%;">कोणाकडे</th><th class="num" style="width:9%;">मुदत</th><th class="num" style="width:11%;">उशीर</th></tr></thead><tbody>';
        foreach ($d['overdueTasks'] as $t) {
            $html .= '<tr><td>' . $h(mb_strimwidth($t['title'], 0, 70, '…')) . ($t['priority'] === 'urgent' || $t['priority'] === 'high' ? ' <span class="pill" style="background:#FDECEC;color:#B42318;">' . ($t['priority'] === 'urgent' ? 'तातडीचं' : 'उच्च') . '</span>' : '') . '</td><td>' . $h($t['dept'] ?? '—') . '</td><td>' . $h($t['assignee'] ?? '—') . '</td><td class="num">' . date('d/m', strtotime($t['target_date'])) . '</td><td class="num" style="color:#B42318;font-weight:bold;">' . (int)$t['late'] . ' दिवस</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    // विनंत्या + नूतनीकरण
    $r = $d['req'];
    $html .= '<h2>कामाच्या विनंत्या व नूतनीकरण</h2><table class="kp"><tr>'
        . $kpi('नवीन विनंत्या', (int)($r['new_week'] ?? 0), 'या आठवड्यात आल्या')
        . $kpi('पूर्ण विनंत्या', (int)($r['done_week'] ?? 0), 'या आठवड्यात', '#137A4B')
        . $kpi('प्रलंबित विनंत्या', (int)($r['open_cnt'] ?? 0), (int)($r['overdue'] ?? 0) . ' SLA वेळ उलटून', ($r['overdue'] ?? 0) ? '#B45309' : '#191C3A')
        . $kpi('नूतनीकरण (30 दिवस)', count($d['renewals']), count(array_filter($d['renewals'], fn($x) => $x['days_left'] < 0)) . ' मुदत संपलेली', '#191C3A')
        . '</tr></table>';
    if ($d['renewals']) {
        $html .= '<table class="g" style="margin-top:6px;"><thead><tr><th style="width:46%;">परवाना / नूतनीकरण</th><th style="width:20%;">विभाग</th><th class="num" style="width:14%;">मुदत</th><th class="num" style="width:20%;">स्थिती</th></tr></thead><tbody>';
        foreach ($d['renewals'] as $x) {
            $late = (int)$x['days_left'] < 0;
            $html .= '<tr><td>' . $h($x['item_name']) . '</td><td>' . $h($x['dept'] ?? '—') . '</td><td class="num">' . date('d/m/Y', strtotime($x['expiry_date'])) . '</td><td class="num" style="font-weight:bold;color:' . ($late ? '#B42318' : '#B45309') . ';">' . ($late ? abs($x['days_left']) . ' दिवस उलटले' : $x['days_left'] . ' दिवस बाकी') . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    // E-Campus
    if (!empty($d['ecampus'])) {
        $html .= '<h2>E-Campus प्रगती <span style="font-size:8.5pt;color:#8A90AC;font-weight:normal;">' . $h(ecampusPeriodLabel($d['ecampus']['period'])) . '</span></h2>' . $d['ecampus']['html'];
    }

    // खर्च
    $html .= '<h2>खर्च (Budget)</h2>';
    if (!$d['budget'] && !$d['spendCat']) { $html .= '<div class="muted">या आर्थिक वर्षात Budget / खर्चाची नोंद नाही.</div>'; }
    else {
        if ($d['spendCat']) {
            $html .= '<table class="g" style="margin-bottom:8px;"><thead><tr><th style="width:60%;">प्रकार (या आठवड्यात)</th><th class="num" style="width:15%;">व्यवहार</th><th class="num" style="width:25%;">रक्कम</th></tr></thead><tbody>';
            foreach ($d['spendCat'] as $c) { $html .= '<tr><td>' . $h($c['category']) . '</td><td class="num">' . (int)$c['n'] . '</td><td class="num"><b>' . weeklyReportMoney($c['amt']) . '</b></td></tr>'; }
            $html .= '</tbody></table>';
        }
        $html .= '<table class="g"><thead><tr><th style="width:24%;">विभाग</th><th class="num" style="width:16%;">या आठवड्यात</th><th class="num">वर्षभर खर्च</th><th class="num">Budget</th><th style="width:28%;">वापर (FY ' . $d['fy'] . '-' . substr($d['fy'] + 1, 2) . ')</th></tr></thead><tbody>';
        foreach ($d['budget'] as $b) {
            $pct = $b['allocated'] > 0 ? (int)round($b['spent'] * 100 / $b['allocated']) : null;
            $col = $pct === null ? '#8A90AC' : ($pct > 100 ? '#B42318' : ($pct >= 90 ? '#B45309' : '#137A4B'));
            $html .= '<tr><td>' . $h($b['dept']) . '</td><td class="num">' . ($b['week'] > 0 ? weeklyReportMoney($b['week']) : '—') . '</td><td class="num">' . weeklyReportMoney($b['spent']) . '</td><td class="num">' . ($b['allocated'] > 0 ? weeklyReportMoney($b['allocated']) : '—') . '</td>'
                . '<td>' . ($pct === null ? '<span class="muted">Budget नाही</span>' : weeklyReportBar(min(100, $pct), $col, 34) . ' <span style="font-size:8pt;color:' . $col . ';font-weight:bold;">' . $pct . '%</span>') . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    // 👤 व्यक्तीनिहाय जबाबदारी — कोणाकडे काय अडकलं आहे
    global $pdo;
    if ($pdo instanceof PDO) {
        require_once __DIR__ . '/report-smart.php';
        $html .= mgmtAccountabilityHtml(mgmtAccountability($pdo, (int)$d['orgId'], $d['period']['end'], 12));
    }

    $html .= '<div class="muted" style="margin-top:18px;text-align:center;">!! आत्मा मालिक !! — E-Admin ने आपोआप तयार केलेला अहवाल · ' . $h($d['org']) . '</div>';
    return $html;
}

/** एका रंगाचा 1×1 PNG (data: URI) — GD शिवाय; PDF मधले Bars यानेच काढतो */
function weeklyReportPixel($hex) {
    static $cache = [];
    if (isset($cache[$hex])) { return $cache[$hex]; }
    $rgb = pack('C3', hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2)));
    $chunk = fn($type, $data) => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('IDAT', gzcompress("\x00" . $rgb)) . $chunk('IEND', '');
    return $cache[$hex] = 'data:image/png;base64,' . base64_encode($png);
}

/** आडवा Bar — $pct (0–100) × $fullMm इतका लांब */
function weeklyReportBar($pct, $color, $fullMm = 110) {
    $pct = max(0, min(100, (float)$pct));
    $w = max(0.6, round($fullMm * $pct / 100, 1));
    return '<img src="' . weeklyReportPixel($color) . '" style="width:' . $w . 'mm;height:2.6mm;">';
}

/**
 * HTML → PDF (bytes) — साप्ताहिक, दैनिक व E-Campus अहवालांसाठी एकच mPDF सेटअप
 * @param array $opts ['link' => Online अहवालाचा relative पत्ता (QR), 'orgId' => Logo साठी, 'subtitle' => पान 2+ वरच्या पट्टीत]
 */
function mgmtReportPdf($html, $title, $orgName, $format = 'A4', array $opts = []) {
    global $pdo;
    require_once __DIR__ . '/../lib/vendor/autoload.php';
    require_once __DIR__ . '/report-smart.php';
    $tmp = __DIR__ . '/../storage/tmp/mpdf';
    if (!is_dir($tmp)) { @mkdir($tmp, 0755, true); }
    $fontDir = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => $format, 'tempDir' => $tmp,
        'margin_left' => 12, 'margin_right' => 12, 'margin_top' => 10, 'margin_bottom' => 14, 'margin_header' => 4,
        'fontDir' => $fontDir,
        'fontdata' => [
            'freesans' => ['R' => 'FreeSans.ttf', 'B' => 'FreeSansBold.ttf', 'useOTL' => 0xFF],
            'dejavusans' => ['R' => 'DejaVuSans.ttf', 'B' => 'DejaVuSans-Bold.ttf', 'useOTL' => 0xFF],
        ],
        'default_font' => 'freesans',
        'backupSubsFont' => ['dejavusans'],
        'useSubstitutions' => true,
        'h2bookmarks' => ['H2' => 0],   // PDF च्या बाजूच्या यादीत (Bookmarks) प्रत्येक विभाग
    ]);
    $mpdf->SetTitle($title);
    $mpdf->SetAuthor('E-Admin — ' . $orgName);
    $mpdf->SetSubject($title);
    $mpdf->SetKeywords('E-Admin, अहवाल, ' . $orgName);
    $mpdf->SetCreator('E-Admin');

    // पान 2 पासून वरची पट्टी — कोणत्याही पानावर कळतं हा कोणता अहवाल
    $sub = htmlspecialchars($opts['subtitle'] ?? $title, ENT_QUOTES, 'UTF-8');
    $running = '<htmlpageheader name="runhdr"><table width="100%" style="border-bottom:0.6pt solid #EAE5DF;font-size:7.5pt;color:#8A90AC;"><tr>'
        . '<td><b style="color:#191C3A;">' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') . '</b></td><td style="text-align:right;">' . $sub . '</td></tr></table></htmlpageheader>'
        . '<sethtmlpageheader name="runhdr" value="on" show-this-page="0" />';
    $mpdf->SetHTMLFooter('<table width="100%" style="font-size:7.5pt;color:#8A90AC;border-top:0.6pt solid #EAE5DF;"><tr>'
        . '<td width="40%"><i>' . htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8') . '</i></td>'
        . '<td width="30%" style="text-align:center;">गोपनीय — फक्त अंतर्गत वापरासाठी</td>'
        . '<td width="30%" style="text-align:right;">पान {PAGENO} / {nbpg}</td></tr></table>');

    $html = mgmtReportDecorate($html, [
        'link' => !empty($opts['link']) ? mgmtReportLink($opts['link']) : '',
        'logo' => (!empty($opts['orgId']) && $pdo instanceof PDO) ? mgmtReportLogo($pdo, $opts['orgId']) : '',
        'size' => $opts['badgeMm'] ?? 19,   // Header ची उंची कमी असेल (E-Campus Landscape) तर लहान
    ]);
    $mpdf->WriteHTML($running . $html);
    return $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
}

/** साप्ताहिक PDF (bytes) */
function weeklyReportPdf(array $d) {
    return mgmtReportPdf(weeklyReportPdfHtml($d), 'साप्ताहिक अहवाल — ' . $d['org'], $d['org'], 'A4', [
        'link' => 'reports/weekly.php', 'orgId' => $d['orgId'] ?? null,
        'subtitle' => 'साप्ताहिक व्यवस्थापन अहवाल · ' . weeklyReportMarathiDate($d['period']['start'], false) . ' – ' . weeklyReportMarathiDate($d['period']['end']),
    ]);
}

function weeklyReportFileName(array $d) {
    return 'weekly-report-' . (trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $d['org']), '-') ?: 'org') . '-' . $d['period']['end'] . '.pdf';
}

/** Email चा मजकूर — मुख्य आकडे + लक्ष द्या; पूर्ण अहवाल PDF मध्ये */
function weeklyReportEmailHtml(array $d) {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $sum = $d['summary'];
    $col = ['good' => '#137A4B', 'warn' => '#B45309', 'bad' => '#B42318'];
    $period = weeklyReportMarathiDate($d['period']['start'], false) . ' – ' . weeklyReportMarathiDate($d['period']['end']);
    $cell = fn($v, $l, $c = '#191C3A') => "<td style='width:25%;background:#F7F5F2;border-radius:8px;padding:10px;text-align:center;'><div style='font-size:20px;font-weight:bold;color:$c;'>$v</div><div style='font-size:11px;color:#64708A;'>$l</div></td>";
    $alerts = '';
    foreach (array_slice($d['alerts'], 0, 5) as [$lv, $msg]) { $alerts .= "<li style='margin:4px 0;color:" . $col[$lv] . ";'><span style='color:#1A1D29;'>" . $h($msg) . "</span></li>"; }
    foreach (array_slice($d['good'], 0, 2) as $msg) { $alerts .= "<li style='margin:4px 0;color:#137A4B;'><span style='color:#1A1D29;'>" . $h($msg) . "</span></li>"; }
    $url = siteBaseUrl();
    return "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:20px;background:#F3F4F7;'>
      <div style='background:#191C3A;padding:18px 22px;border-radius:10px 10px 0 0;border-bottom:4px solid #D9530F;'>
        <div style='color:#fff;font-size:18px;font-weight:bold;'>" . $h($d['org']) . "</div>
        <div style='color:#FDBA8C;font-size:13px;'>📊 साप्ताहिक व्यवस्थापन अहवाल · " . $h($period) . "</div></div>
      <div style='background:#fff;padding:20px 22px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;margin-top:0;'>नमस्कार,<br>गेल्या आठवड्याचा सारांश खाली आहे. सविस्तर अहवाल (विभागनिहाय आरोग्य, मुदतबाह्य Tasks, नूतनीकरण, खर्च) सोबतच्या <b>PDF</b> मध्ये आहे.</p>
        <table style='width:100%;border-collapse:separate;border-spacing:6px;margin:0 -6px;'><tr>"
        . $cell($sum['count'] ? $sum['score'] . '/100' : '—', 'आरोग्य गुण', $col[$sum['level']])
        . $cell(number_format($d['work']['total']), 'कामांच्या नोंदी')
        . $cell((int)($d['tasks']['overdue'] ?? 0), 'मुदतबाह्य Tasks', ($d['tasks']['overdue'] ?? 0) ? '#B42318' : '#137A4B')
        . $cell(weeklyReportMoney($d['spendWeek']), 'खर्च')
        . "</tr></table>"
        . (!empty($d['ecampus']) ? "<div style='margin-top:12px;font-size:13px;background:#F7F5F2;border-radius:8px;padding:8px 12px;'>🎓 <b>E-Campus:</b> " . (int)$d['ecampus']['overall'] . "% पूर्ण — " . (int)$d['ecampus']['done'] . '/' . (int)$d['ecampus']['work'] . " कामं पूर्ण — सविस्तर E-Campus अहवाल सोबतच्या दुसऱ्या PDF मध्ये</div>" : '')
        . ($alerts ? "<div style='font-weight:bold;font-size:14px;margin:16px 0 4px;color:#191C3A;'>लक्ष द्या</div><ul style='padding-left:18px;margin:0;font-size:13px;line-height:1.6;'>$alerts</ul>" : '')
        . ($url ? "<a href='{$url}departments/all.php' style='display:inline-block;margin-top:18px;background:#D9530F;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:bold;'>सर्व विभाग Dashboard उघडा</a>" : '')
        . "<p style='font-size:11.5px;color:#8A90AC;margin-top:20px;'>!! आत्मा मालिक !! — हा अहवाल दर सोमवारी आपोआप पाठवला जातो. पत्ते बदलण्यासाठी: E-Admin → अहवाल → साप्ताहिक अहवाल.</p>
      </div></div>";
}

/** कोणाला पाठवायचा — Management पदावरील (Email असलेले) + Super Admin ने दिलेले जादा पत्ते */
function weeklyReportRecipients(PDO $pdo, $orgId) {
    $out = [];
    if (orgSetting($pdo, $orgId, 'weekly_report_management', '1') === '1') {
        try {
            $st = $pdo->prepare("SELECT u.email FROM users u JOIN role_definitions rd ON rd.role_key = u.role
                WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'management' AND u.email IS NOT NULL AND u.email <> ''");
            $st->execute([$orgId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $e) { if (filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[strtolower($e)] = strtolower($e); } }
        } catch (PDOException $e) {}
    }
    foreach (parseEmailList(orgSetting($pdo, $orgId, 'weekly_report_emails', '')) as $e) { $out[$e] = $e; }
    return array_values($out);
}

function weeklyReportEnabled(PDO $pdo, $orgId) { return orgSetting($pdo, $orgId, 'weekly_report_enabled', '1') === '1'; }

/** अहवाल तयार करून पाठवतो. $to दिलं तर फक्त त्यांना (Test). परत ['ok','msg','sent'] */
function weeklyReportSend(PDO $pdo, $orgId, ?array $to = null) {
    $recips = $to ?? weeklyReportRecipients($pdo, $orgId);
    if (!$recips) { return ['ok' => false, 'msg' => 'एकही Email पत्ता नाही (Management पदावरच्यांचे Email / जादा पत्ते तपासा)', 'sent' => 0]; }
    try {
        $d = weeklyReportData($pdo, $orgId, weeklyReportPeriod());
        $pdf = weeklyReportPdf($d);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'अहवाल/PDF तयार करताना त्रुटी: ' . mb_substr($e->getMessage(), 0, 200), 'sent' => 0];
    }
    $period = weeklyReportMarathiDate($d['period']['start'], false) . ' – ' . weeklyReportMarathiDate($d['period']['end']);
    $files = [['name' => weeklyReportFileName($d), 'data' => $pdf, 'type' => 'application/pdf']];
    require_once __DIR__ . '/ecampus-helpers.php';
    if ($ec = ecampusReportAttachment($pdo, $orgId, $d['org'])) { $files[] = $ec; } // 🎓 पूर्ण E-Campus अहवाल — वेगळी PDF
    $sent = eowmsSendMail($recips, '📊 साप्ताहिक अहवाल — ' . $d['org'] . ' (' . $period . ')', weeklyReportEmailHtml($d), $files, $d['org'] . ' — E-Admin');
    $res = ['ok' => $sent > 0, 'msg' => $sent > 0 ? "अहवाल $sent पत्त्यांवर पाठवला" : 'Server वरून Email जाऊ शकला नाही (mail() अयशस्वी)', 'sent' => $sent];
    if ($to === null) {
        try {
            orgSettingSet($pdo, $orgId, 'weekly_report_last_at', date('Y-m-d H:i:s'));
            orgSettingSet($pdo, $orgId, 'weekly_report_last_msg', ($res['ok'] ? '✓ ' : '✗ ') . $res['msg']);
        } catch (PDOException $e) {}
    }
    return $res;
}
