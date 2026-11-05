<?php
/**
 * 🎓 E-Campus नोंदी व अहवाल — सामायिक Helpers
 *  - Super Admin कर्मचाऱ्यांना E-Campus चं काम नेमतो (Wing → Section → कर्मचारी → काम)
 *  - फक्त नेमलेला कर्मचारी आपल्या कामाची महिन्याची प्रगती (% + नोंद) भरतो
 *  - Dashboard / मासिक अहवाल / दैनिक-साप्ताहिक अहवाल — सगळे इथूनच Data घेतात
 * "दरमहा" काम दर महिन्याला 0% पासून; "एकदाच" काम मागच्या महिन्याची प्रगती पुढे नेतं.
 * "फक्त पाहण्याचा Access" ओळी पूर्ण धरल्या जातात, पण एकूण % मध्ये मोजल्या जात नाहीत.
 */

function ecampusReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { $pdo->query("SELECT 1 FROM ecampus_progress LIMIT 1"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** 'YYYY-MM' — चुकीचं/भविष्यातलं असेल तर चालू महिना */
function ecampusPeriod($ym = null) {
    if (is_string($ym) && preg_match('/^(\d{4})-(\d{2})$/', $ym, $m) && (int)$m[2] >= 1 && (int)$m[2] <= 12 && $ym <= date('Y-m') && $ym >= '2020-01') { return $ym; }
    return date('Y-m');
}

function ecampusPeriodLabel($ym) {
    $m = ['', 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'];
    return $m[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 0, 4);
}

function ecampusCanManage() { return hasRole('super_admin'); }
/**
 * Centralized E-Campus Dashboard कोणाला दिसतो — Super Admin "कामांचं वाटप" पानावर ठरवतो (org setting ecampus_visibility):
 *  all = संस्थेतले सगळे कर्मचारी (फक्त पाहणं; प्रत्येकजण फक्त स्वतःचीच ओळ भरतो) · assigned = ज्यांना E-Campus काम नेमलंय ते · management = फक्त Management
 */
const ECAMPUS_VISIBILITY = ['all', 'assigned', 'management'];
function ecampusVisibility() {
    global $pdo;
    require_once __DIR__ . '/app-settings.php';
    if (!($pdo instanceof PDO) || !orgSettingsReady($pdo)) { return 'all'; }
    $v = (string)orgSetting($pdo, activeOrgId(), 'ecampus_visibility', 'all');
    return in_array($v, ECAMPUS_VISIBILITY, true) ? $v : 'all';
}
function ecampusCanViewAll() {
    global $pdo;
    if (isManagementOrAbove() || hasRole('super_admin')) { return true; }
    $v = ecampusVisibility();
    if ($v === 'all') { return true; }
    if ($v === 'assigned') { return ($pdo instanceof PDO) && ecampusMyTaskCount($pdo, currentUser()['id'] ?? 0) > 0; }
    return false;
}

function ecampusMyTaskCount(PDO $pdo, $userId) {
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM ecampus_tasks WHERE user_id = ? AND is_active = 1");
        $st->execute([(int)$userId]);
        return (int)$st->fetchColumn();
    } catch (PDOException $e) { return 0; }
}

/** स्थिती — [key, मराठी लेबल, रंग] */
function ecampusStatus($percent, $access = 'work') {
    if ($access === 'view_only') { return ['access', 'Access', '#2563EB']; }
    if ((int)$percent >= 100) { return ['done', 'पूर्ण', '#16A34A']; }
    if ((int)$percent > 0) { return ['progress', 'सुरू', '#F59E0B']; }
    return ['pending', 'सुरू नाही', '#DC2626'];
}

/**
 * त्या महिन्याच्या सगळ्या ओळी (PDF मधल्या तक्त्याप्रमाणे).
 * $userId दिला तर फक्त त्या कर्मचाऱ्याच्या.
 */
function ecampusRows(PDO $pdo, $orgId, $period, $userId = null) {
    $end = date('Y-m-t 23:59:59', strtotime($period . '-01'));
    $sql = "SELECT t.*, u.name AS staff_name, u.designation AS user_designation, u.is_active AS user_active,
                   p.percent, p.notes, p.updated_at, p.updated_by, ub.name AS updated_by_name
            FROM ecampus_tasks t JOIN users u ON u.id = t.user_id
            LEFT JOIN ecampus_progress p ON p.task_id = t.id AND p.period = ?
            LEFT JOIN users ub ON ub.id = p.updated_by
            WHERE t.organization_id = ? AND t.is_active = 1 AND t.created_at <= ?";
    $params = [$period, (int)$orgId, $end];
    if ($userId) { $sql .= " AND t.user_id = ?"; $params[] = (int)$userId; }
    $sql .= " ORDER BY t.sort_order, t.wing, t.section, u.name, t.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // "एकदाच" काम — या महिन्यात नोंद नसेल तर मागची शेवटची प्रगती पुढे
    $carry = $pdo->prepare("SELECT percent, notes, updated_at FROM ecampus_progress WHERE task_id = ? AND period < ? ORDER BY period DESC LIMIT 1");
    foreach ($rows as &$r) {
        $r['carried'] = false;
        if ($r['percent'] === null && $r['task_type'] === 'one_time') {
            $carry->execute([$r['id'], $period]);
            if ($c = $carry->fetch()) { $r['percent'] = $c['percent']; $r['notes'] = $c['notes']; $r['updated_at'] = $c['updated_at']; $r['carried'] = true; }
        }
        if ($r['access_type'] === 'view_only') { $r['percent'] = 100; }
        $r['percent'] = (int)($r['percent'] ?? 0);
        $r['designation_show'] = $r['designation'] ?: $r['user_designation'];
        [$r['status'], $r['status_label'], $r['status_color']] = ecampusStatus($r['percent'], $r['access_type']);
    }
    unset($r);
    return $rows;
}

/** एकत्रित हिशोब — एकूण %, स्थितीनुसार संख्या, Wing/Section नुसार, मागे पडलेली कामं */
function ecampusSummary(array $rows, $period) {
    $work = array_values(array_filter($rows, fn($r) => $r['access_type'] === 'work'));
    $n = count($work);
    $s = ['tasks' => count($rows), 'work' => $n, 'viewOnly' => count($rows) - $n, 'overall' => $n ? (int)round(array_sum(array_column($work, 'percent')) / $n) : null,
        'done' => 0, 'progress' => 0, 'pending' => 0, 'staff' => count(array_unique(array_column($rows, 'user_id'))),
        'wings' => [], 'sections' => [], 'stale' => [], 'lagging' => [], 'notUpdated' => 0];
    $isCurrent = $period === date('Y-m');
    foreach ($work as $r) {
        $s[$r['status']]++;
        foreach (['wings' => 'wing', 'sections' => 'section'] as $k => $col) {
            $key = $r[$col];
            $s[$k][$key]['sum'] = ($s[$k][$key]['sum'] ?? 0) + $r['percent'];
            $s[$k][$key]['n'] = ($s[$k][$key]['n'] ?? 0) + 1;
            $s[$k][$key]['done'] = ($s[$k][$key]['done'] ?? 0) + ($r['percent'] >= 100 ? 1 : 0);
            if ($k === 'sections') { $s[$k][$key]['wing'] = $r['wing']; }
        }
        if (!$r['updated_at'] || $r['carried']) { $s['notUpdated']++; }
        if ($isCurrent && $r['percent'] < 100 && (!$r['updated_at'] || strtotime($r['updated_at']) < strtotime('-7 days'))) { $s['stale'][] = $r; }
        if ($r['percent'] < 100) { $s['lagging'][] = $r; }
    }
    foreach (['wings', 'sections'] as $k) {
        foreach ($s[$k] as &$g) { $g['avg'] = (int)round($g['sum'] / max(1, $g['n'])); }
        unset($g);
        uasort($s[$k], fn($a, $b) => $a['avg'] <=> $b['avg']);
    }
    usort($s['lagging'], fn($a, $b) => $a['percent'] <=> $b['percent']);
    $s['level'] = $s['overall'] === null ? 'good' : ($s['overall'] >= 85 ? 'good' : ($s['overall'] >= 60 ? 'warn' : 'bad'));
    // महिना किती गेला विरुद्ध काम किती झालं — "वेळापत्रकाच्या मागे/पुढे"
    $daysIn = (int)date('t', strtotime($period . '-01'));
    $dayNow = $isCurrent ? (int)date('j') : $daysIn;
    $s['expected'] = (int)round($dayNow * 100 / $daysIn);
    return $s;
}

/** महिन्यातला दिवसागणिक एकूण % (Line/Bar Chart साठी) — ecampus_updates इतिहासावरून */
function ecampusDailyTrend(PDO $pdo, $orgId, $period, array $rows) {
    $work = array_values(array_filter($rows, fn($r) => $r['access_type'] === 'work'));
    if (!$work) { return []; }
    $ids = array_column($work, 'id');
    $val = [];
    $prev = $pdo->prepare("SELECT percent FROM ecampus_progress WHERE task_id = ? AND period < ? ORDER BY period DESC LIMIT 1");
    foreach ($work as $r) {
        $val[$r['id']] = 0;
        if ($r['task_type'] === 'one_time') { $prev->execute([$r['id'], $period]); $val[$r['id']] = (int)($prev->fetchColumn() ?: 0); }
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT task_id, percent, DATE(created_at) d FROM ecampus_updates WHERE period = ? AND task_id IN ($ph) ORDER BY created_at, id");
    $st->execute(array_merge([$period], $ids));
    $byDay = [];
    foreach ($st->fetchAll() as $u) { $byDay[$u['d']][] = $u; }
    $first = $period . '-01';
    $last = $period === date('Y-m') ? date('Y-m-d') : date('Y-m-t', strtotime($first));
    $out = [];
    for ($t = strtotime($first); $t <= strtotime($last); $t += 86400) {
        $d = date('Y-m-d', $t);
        foreach ($byDay[$d] ?? [] as $u) { $val[$u['task_id']] = (int)$u['percent']; }
        $out[$d] = (int)round(array_sum($val) / count($val));
    }
    return $out;
}

/** प्रगती जतन — त्या महिन्याची नोंद + इतिहास */
function ecampusSave(PDO $pdo, $taskId, $period, $percent, $notes, $userId) {
    $percent = max(0, min(100, (int)$percent));
    $notes = mb_substr(trim((string)$notes), 0, 2000);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO ecampus_progress (task_id, period, percent, notes, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE percent = VALUES(percent), notes = VALUES(notes), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)")
        ->execute([(int)$taskId, $period, $percent, $notes !== '' ? $notes : null, (int)$userId, $now]);
    $pdo->prepare("INSERT INTO ecampus_updates (task_id, period, percent, notes, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([(int)$taskId, $period, $percent, $notes !== '' ? $notes : null, (int)$userId, $now]);
}

/** मानवी-वाचनीय "किती वेळापूर्वी" */
function ecampusAgo($dt) {
    if (!$dt) { return 'कधीच नाही'; }
    $d = (int)floor((time() - strtotime($dt)) / 86400);
    if ($d <= 0) { return 'आज'; }
    if ($d === 1) { return 'काल'; }
    return $d . ' दिवसांपूर्वी';
}

/**
 * PDF साठी E-Campus चा भाग (mPDF HTML) — मासिक अहवाल, दैनिक व साप्ताहिक अहवाल तिघेही वापरतात.
 * $mode: 'full' = सगळ्या ओळींचा तक्ता (मासिक), 'brief' = सारांश + Wing + मागे पडलेली कामं (दैनिक/साप्ताहिक)
 */
function ecampusPdfSection(PDO $pdo, $orgId, $period, $mode = 'brief', $since = null) {
    if (!ecampusReady($pdo)) { return ''; }
    $rows = ecampusRows($pdo, $orgId, $period);
    if (!$rows) { return ''; }
    require_once __DIR__ . '/daily-report.php';
    $S = ecampusSummary($rows, $period);
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $C = ['good' => '#137A4B', 'warn' => '#B45309', 'bad' => '#B42318'];
    $bc = fn($p) => $p >= 100 ? '#16A34A' : ($p >= 75 ? '#65A30D' : ($p >= 50 ? '#F59E0B' : '#DC2626'));
    $kpi = fn($l, $v, $s = '', $col = '#191C3A') => '<td class="kpi"><div class="kl">' . $l . '</div><div class="kv" style="color:' . $col . ';">' . $v . '</div><div class="ks">' . $s . '</div></td>';
    $updatedSince = 0;
    if ($since) { foreach ($rows as $r) { if ($r['updated_at'] && !$r['carried'] && $r['updated_at'] >= $since) { $updatedSince++; } } }

    $html = '<table class="kp"><tr>'
        . $kpi('E-Campus एकूण प्रगती', ($S['overall'] ?? 0) . '%', ecampusPeriodLabel($period) . ($period === date('Y-m') ? ' · महिना ' . $S['expected'] . '% गेला' : ''), $C[$S['level']])
        . $kpi('पूर्ण कामं', (int)$S['done'] . '/' . (int)$S['work'], '', '#137A4B')
        . $kpi('सुरू / सुरू नाही', (int)$S['progress'] . ' / ' . (int)$S['pending'], '', $S['pending'] ? '#B42318' : '#B45309')
        . ($since !== null ? $kpi('नोंदी अद्ययावत', $updatedSince, $mode === 'brief' ? 'या कालावधीत' : '') : $kpi('कर्मचारी', (int)$S['staff']))
        . '</tr></table>';
    $html .= '<table class="split"><tr><td style="width:30%;">' . drImg(drDonut([[(int)$S['done'], '#16A34A'], [(int)$S['progress'], '#F59E0B'], [(int)$S['pending'], '#DC2626']], ($S['overall'] ?? 0) . '%'), 30)
        . drLegend([['पूर्ण', (int)$S['done'], '#16A34A'], ['सुरू', (int)$S['progress'], '#F59E0B'], ['सुरू नाही', (int)$S['pending'], '#DC2626']]) . '</td><td>';
    $html .= '<table class="g"><thead><tr><th style="width:38%;">Wing</th><th style="width:44%;"></th><th class="num-r" style="width:18%;">%</th></tr></thead><tbody>';
    foreach ($S['wings'] as $w => $g) { $html .= '<tr><td>' . $h($w) . ' <span class="muted">(' . (int)$g['done'] . '/' . (int)$g['n'] . ')</span></td><td>' . weeklyReportBar($g['avg'], $bc($g['avg']), 60) . '</td><td class="num-r"><b>' . (int)$g['avg'] . '%</b></td></tr>'; }
    $html .= '</tbody></table></td></tr></table>';

    if ($mode === 'brief') {
        if ($S['lagging']) {
            $html .= '<table class="g" style="margin-top:6px;"><thead><tr><th style="width:24%;">कर्मचारी</th><th style="width:24%;">Section</th><th class="num-r" style="width:10%;">%</th><th style="width:42%;">नोंद</th></tr></thead><tbody>';
            foreach (array_slice($S['lagging'], 0, 8) as $r) { $html .= '<tr><td>' . $h($r['staff_name']) . '</td><td>' . $h($r['section']) . '</td><td class="num-r" style="font-weight:bold;color:' . $bc($r['percent']) . ';">' . $r['percent'] . '%</td><td>' . $h(mb_strimwidth((string)$r['notes'], 0, 70, '…')) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        return $html;
    }
    // full — PDF मधल्या मासिक अहवालासारखा पूर्ण तक्ता
    $html .= '<table class="g" style="margin-top:8px;"><thead><tr><th style="width:4%;">Sr.</th><th style="width:11%;">Wing / Category</th><th style="width:12%;">Section / Department</th><th style="width:12%;">Staff Name</th><th style="width:10%;">Designation</th><th style="width:22%;">Assigned Task</th><th style="width:17%;">Progress Notes</th><th class="num-r" style="width:6%;">%</th><th style="width:6%;">Status</th></tr></thead><tbody>';
    foreach ($rows as $i => $r) {
        $html .= '<tr><td>' . ($i + 1) . '</td><td>' . $h($r['wing']) . '</td><td>' . $h($r['section']) . '</td><td>' . $h($r['staff_name']) . '</td><td>' . $h($r['designation_show']) . '</td><td>' . nl2br($h($r['task_description'])) . '</td><td>'
            . $h((string)$r['notes']) . '</td><td class="num-r" style="font-weight:bold;color:' . $bc($r['percent']) . ';">' . $r['percent'] . '%</td><td style="font-weight:bold;color:' . $r['status_color'] . ';">' . $h($r['status_label']) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

/** दैनिक/साप्ताहिक अहवालातल्या "ठळक मुद्द्यां" साठी E-Campus निष्कर्ष */
function ecampusAlerts(PDO $pdo, $orgId, $period) {
    if (!ecampusReady($pdo)) { return []; }
    $rows = ecampusRows($pdo, $orgId, $period);
    if (!$rows) { return []; }
    $S = ecampusSummary($rows, $period);
    $out = [];
    if ($S['overall'] !== null && $period === date('Y-m') && $S['overall'] + 10 < $S['expected']) { $out[] = ['warn', 'E-Campus प्रगती ' . $S['overall'] . '% — वेळापत्रकाच्या मागे (महिना ' . $S['expected'] . '% गेला)']; }
    if ($S['pending']) { $out[] = ['bad', 'E-Campus: ' . $S['pending'] . ' कामं अजून सुरूच झाली नाहीत']; }
    if (count($S['stale'])) { $out[] = ['warn', 'E-Campus: ' . count($S['stale']) . ' कामांची नोंद 7+ दिवस अद्ययावत नाही']; }
    return $out;
}
