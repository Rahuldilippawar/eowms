<?php
/**
 * 🎓 E-Campus नोंदी व अहवाल — सामायिक Helpers
 *  - Super Admin कर्मचाऱ्यांना E-Campus चं काम नेमतो (Wing → Section → कर्मचारी → काम)
 *  - फक्त नेमलेला कर्मचारी आपल्या कामाची महिन्याची प्रगती (% + नोंद) भरतो
 *  - Dashboard / मासिक अहवाल / दैनिक-साप्ताहिक अहवाल — सगळे इथूनच Data घेतात
 * नवीन महिना लागला की सगळी कामं 0% पासून — त्या महिन्याचं काम पाहून % भरायचे (मागचे 12 महिनेही भरता येतात).
 * अपवाद: "एकदाच" काम आधीच्या महिन्यात 100% पूर्ण झालं असेल तर ते "पूर्ण"च दिसतं (परत करायची गरज नाही).
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

/** किती मागचे महिने भरता / बदलता येतात */
const ECAMPUS_EDIT_MONTHS = 12;

/** हा महिना भरता येतो का — चालू किंवा मागच्या 12 महिन्यांपैकी */
function ecampusEditablePeriod($ym) {
    if (!is_string($ym) || !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym)) { return false; }
    $oldest = date('Y-m', strtotime(date('Y-m-01') . ' -' . (ECAMPUS_EDIT_MONTHS - 1) . ' month'));
    return $ym <= date('Y-m') && $ym >= $oldest;
}

/** महिना-निवडीसाठी यादी — चालू महिना आधी */
function ecampusEditableMonths() {
    $m = [];
    for ($i = 0; $i < ECAMPUS_EDIT_MONTHS; $i++) { $m[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); }
    return $m;
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
    $sql = "SELECT t.*, u.name AS staff_name, u.designation AS user_designation, u.is_active AS user_active,
                   p.percent, p.notes, p.updated_at, p.updated_by, ub.name AS updated_by_name
            FROM ecampus_tasks t JOIN users u ON u.id = t.user_id
            LEFT JOIN ecampus_progress p ON p.task_id = t.id AND p.period = ?
            LEFT JOIN users ub ON ub.id = p.updated_by
            WHERE t.organization_id = ? AND t.is_active = 1";   // नंतर नेमलेलं काम मागच्या महिन्यांतही दिसतं — मागचे महिने भरता यावेत म्हणून
    $params = [$period, (int)$orgId];
    if ($userId) { $sql .= " AND t.user_id = ?"; $params[] = (int)$userId; }
    $sql .= " ORDER BY t.sort_order, t.wing, t.section, u.name, t.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();

    // नवीन महिना = 0% पासून. फक्त "एकदाच" काम आधी 100% पूर्ण झालं असेल तर ते "पूर्ण"च दिसतं
    $carry = $pdo->prepare("SELECT percent, notes, updated_at FROM ecampus_progress WHERE task_id = ? AND period < ? AND percent >= 100 ORDER BY period DESC LIMIT 1");
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

/** Wing चा ठरलेला क्रम — VJMAT → Trust → AMRIT → AMIS → AMEMS → बाकी (नावानुसार) */
function ecampusWingKey($wing) {
    static $order = ['VJMAT', 'TRUST', 'AMRIT', 'AMIS', 'AMEMS'];
    $u = strtoupper(trim((string)$wing));
    foreach ($order as $i => $w) { if (preg_match('/^' . $w . '(?![A-Z])/', $u)) { return [$i, $u]; } }
    return [count($order), $u];
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
        if ($k === 'wings') { uksort($s[$k], fn($a, $b) => ecampusWingKey($a) <=> ecampusWingKey($b)); }
        else { uasort($s[$k], fn($a, $b) => $a['avg'] <=> $b['avg']); }
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
            $html .= '<table class="g" style="margin-top:6px;"><thead><tr><th style="width:24%;">कर्मचारी</th><th style="width:24%;">Wing</th><th class="num-r" style="width:10%;">%</th><th style="width:42%;">नोंद</th></tr></thead><tbody>';
            foreach (array_slice($S['lagging'], 0, 8) as $r) { $html .= '<tr><td>' . $h($r['staff_name']) . '</td><td>' . $h($r['wing']) . '</td><td class="num-r" style="font-weight:bold;color:' . $bc($r['percent']) . ';">' . $r['percent'] . '%</td><td>' . $h(mb_strimwidth((string)$r['notes'], 0, 70, '…')) . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
        return $html;
    }
    // full — 🔮 महिनाअखेरचा अंदाज + 👥 कर्मचारीनिहाय + पूर्ण तक्ता · detail (अनेक महिन्यांच्या अहवालात) — फक्त पूर्ण तक्ता
    $fc = $mode === 'detail' ? null : ecampusForecast(ecampusDailyTrend($pdo, $orgId, $period, $rows), $period, $S['overall']);
    if ($fc) {
        $html .= '<div class="al" style="margin-top:6px;padding:5px 9px;background:' . ($fc['onTrack'] ? '#E7F6EF' : '#FEF3C7') . ';border-left:3px solid ' . ($fc['onTrack'] ? '#137A4B' : '#B45309') . ';font-size:8.6pt;">'
            . '<b>महिनाअखेरचा अंदाज: ' . $fc['projected'] . '%</b> — सध्याचा वेग रोज ~' . $fc['perDay'] . '% · ' . $fc['daysLeft'] . ' दिवस बाकी'
            . ($fc['onTrack'] ? ' · वेळेत पूर्ण होण्याच्या मार्गावर' : ' · 100% साठी रोज ~' . $fc['need'] . '% प्रगती हवी') . '</div>';
    }
    // मासिक अहवाल कामनिहाय — कर्मचारीनिहाय भाग नाही
    if ($mode !== 'detail') { $html .= '<h2>कामनिहाय तपशील</h2>'; }
    // फक्त Wing नुसार गट (विभागनिहाय नाही; Sort क्रम कायम)
    $ord = array_keys($rows);
    usort($ord, fn($x, $y) => [ecampusWingKey($rows[$x]['wing']), $x] <=> [ecampusWingKey($rows[$y]['wing']), $y]);
    $html .= '<table class="g"><thead><tr><th style="width:4%;">Sr.</th><th style="width:14%;">Staff Name</th><th style="width:30%;">Assigned Task</th><th style="width:30%;">Progress Notes</th><th class="num-r" style="width:8%;">%</th><th style="width:10%;">Status</th></tr></thead><tbody>';
    $grp = null; $sr = 0;
    foreach ($ord as $k) {
        $r = $rows[$k];
        $g = $r['wing'];
        if ($g !== $grp) {
            $grp = $g;
            $html .= '<tr><td colspan="6" style="background:#191C3A;color:#fff;font-weight:bold;padding:4px 6px;">' . $h($r['wing']) . '</td></tr>';
        }
        $html .= '<tr><td>' . (++$sr) . '</td><td><b>' . $h($r['staff_name']) . '</b><div class="muted">' . $h($r['designation_show']) . '</div></td><td>' . nl2br($h($r['task_description'])) . '</td><td>'
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

/** 📄 मासिक E-Campus अहवाल — पूर्ण PDF (Landscape). Dashboard, Download आणि दैनिक/साप्ताहिक Email — सगळे हेच वापरतात */
function ecampusReportPdf(PDO $pdo, $orgId, $period, $orgName) {
    require_once __DIR__ . '/weekly-report.php';
    require_once __DIR__ . '/daily-report.php';
    $css = '<style>body{font-family:freesans;color:#1A1D29;font-size:8.6pt;}
      .hdr{background:#191C3A;color:#fff;padding:12px 16px;border-bottom:4px solid #D9530F;} .hdr .o{font-size:14pt;font-weight:bold;} .hdr .t{font-size:10.5pt;color:#FDBA8C;}
      table.kp{width:100%;border-collapse:separate;border-spacing:5px;margin:0 -5px;} td.kpi{background:#F7F5F2;border:1px solid #EAE5DF;padding:6px 8px;vertical-align:top;}
      .kl{font-size:7.6pt;color:#64708A;} .kv{font-size:14pt;font-weight:bold;} .ks{font-size:7.2pt;color:#64708A;}
      table.g{width:100%;border-collapse:collapse;font-size:8pt;} table.g th{background:#F3F1EE;color:#475069;text-align:left;padding:4px 5px;border-bottom:1px solid #DDD6CE;font-size:7.6pt;}
      table.g td{padding:4px 5px;border-bottom:1px solid #EEE9E3;vertical-align:top;} .num-r{text-align:right;} .muted{color:#8A90AC;font-size:7.6pt;}
      table.split{width:100%;border-collapse:collapse;} table.split td{vertical-align:top;padding:0;}
      h2{font-size:10.5pt;color:#191C3A;margin:10px 0 5px;padding-bottom:2px;border-bottom:1.2px solid #EAE5DF;} .h2s{font-size:7.6pt;color:#8A90AC;font-weight:normal;}</style>';
    $html = $css . '<div class="hdr"><div class="o">' . htmlspecialchars($orgName) . '</div><div class="t">MONTHLY E-CAMPUS REPORT — ' . htmlspecialchars(ecampusPeriodLabel($period)) . ' (' . date('m/Y', strtotime($period . '-01')) . ')</div><div class="p" style="font-size:7.6pt;color:#C9CCE0;">!! आत्मा मालिक !! · तयार: ' . date('d-m-Y, h:i A') . '</div></div>'
        . ecampusPdfSection($pdo, $orgId, $period, 'full');
    return mgmtReportPdf($html, 'E-Campus Report — ' . $period, $orgName, 'A4-L', [
        'link' => 'ecampus/index.php?month=' . $period, 'orgId' => $orgId, 'badgeMm' => 13, 'subtitle' => 'मासिक E-Campus अहवाल · ' . ecampusPeriodLabel($period),
    ]);
}

function ecampusReportFileName($period) { return 'e-campus-report-' . $period . '-' . date('Ymd-His') . '.pdf'; }

/** दैनिक/साप्ताहिक Email सोबत जोडण्यासाठी E-Campus PDF (कामं नसतील किंवा त्रुटी आली तर null — मुख्य अहवाल थांबत नाही) */
function ecampusReportAttachment(PDO $pdo, $orgId, $orgName) {
    try {
        if (!ecampusReady($pdo)) { return null; }
        $period = date('Y-m');
        if (!ecampusRows($pdo, $orgId, $period)) { return null; }
        return ['name' => ecampusReportFileName($period), 'data' => ecampusReportPdf($pdo, $orgId, $period, $orgName), 'type' => 'application/pdf'];
    } catch (Throwable $e) {
        error_log('E-Campus PDF attachment: ' . $e->getMessage());
        return null;
    }
}


/* ======================= 🧠 स्मार्ट विश्लेषण (Dashboard) ======================= */

/**
 * महिनाअखेरचा अंदाज — गेल्या 7 दिवसांच्या वेगावरून (नोंदी कमी असतील तर महिन्याच्या सुरुवातीपासूनच्या सरासरी वेगावरून).
 * परत: ['projected'=>int, 'perDay'=>float, 'need'=>float (100% साठी रोज किती हवं), 'daysLeft'=>int, 'onTrack'=>bool] किंवा null (मागचा महिना / Data नाही)
 */
function ecampusForecast(array $trend, $period, $overall) {
    if ($period !== date('Y-m') || !$trend || $overall === null) { return null; }
    $vals = array_values($trend);
    $n = count($vals);
    $daysIn = (int)date('t');
    $daysLeft = $daysIn - (int)date('j');
    if ($n >= 8) { $perDay = ($vals[$n - 1] - $vals[$n - 8]) / 7; }
    else { $perDay = $n > 1 ? ($vals[$n - 1] - $vals[0]) / max(1, $n - 1) : 0; }
    if ($perDay <= 0 && $n > 1) { $perDay = max(0, $vals[$n - 1] / max(1, $n)); }   // 7 दिवसांत हालचाल नाही — महिन्याच्या सरासरी वेगाने
    $projected = (int)min(100, round($overall + $perDay * $daysLeft));
    $need = $daysLeft > 0 ? max(0, (100 - $overall) / $daysLeft) : 0;
    return ['projected' => $projected, 'perDay' => round($perDay, 1), 'need' => round($need, 1), 'daysLeft' => $daysLeft, 'onTrack' => $projected >= 95 || $overall >= 100];
}

/** कर्मचारीनिहाय — सरासरी %, पूर्ण/एकूण, शेवटची नोंद, जुनी (7+ दिवस) कामं. कमी % आधी */
function ecampusStaffBoard(array $rows, $period) {
    $isCurrent = $period === date('Y-m');
    $out = [];
    foreach ($rows as $r) {
        if ($r['access_type'] !== 'work') { continue; }
        $u = (int)$r['user_id'];
        $out[$u] ??= ['user_id' => $u, 'name' => $r['staff_name'], 'designation' => $r['designation_show'], 'sum' => 0, 'n' => 0, 'done' => 0, 'last' => null, 'stale' => 0, 'pending' => 0];
        $o = &$out[$u];
        $o['sum'] += $r['percent']; $o['n']++;
        if ($r['percent'] >= 100) { $o['done']++; }
        if ($r['percent'] <= 0) { $o['pending']++; }
        $upd = $r['carried'] ? null : $r['updated_at'];
        if ($upd && (!$o['last'] || $upd > $o['last'])) { $o['last'] = $upd; }
        if ($isCurrent && $r['percent'] < 100 && (!$upd || strtotime($upd) < strtotime('-7 days'))) { $o['stale']++; }
        unset($o);
    }
    foreach ($out as &$o) { $o['avg'] = (int)round($o['sum'] / max(1, $o['n'])); }
    unset($o);
    usort($out, fn($a, $b) => [$a['avg'], $a['name']] <=> [$b['avg'], $b['name']]);
    return $out;
}

/** गेल्या $days दिवसांत सर्वात जास्त पुढे गेलेली कामं: [['row'=>…, 'from'=>int, 'to'=>int, 'delta'=>int]] */
function ecampusMovers(PDO $pdo, $period, array $rows, $days = 7, $limit = 5) {
    $work = array_values(array_filter($rows, fn($r) => $r['access_type'] === 'work'));
    if (!$work || $period !== date('Y-m')) { return []; }
    $cut = date('Y-m-d H:i:s', strtotime("-$days days"));
    $ids = array_column($work, 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    // कटऑफ आधीची शेवटची नोंद (या महिन्यातली) — नसेल तर "एकदाच" कामासाठी मागच्या महिन्याची, नाहीतर 0
    $st = $pdo->prepare("SELECT u.task_id, u.percent FROM ecampus_updates u JOIN (SELECT task_id, MAX(id) mid FROM ecampus_updates WHERE period = ? AND created_at < ? AND task_id IN ($ph) GROUP BY task_id) x ON x.mid = u.id");
    $st->execute(array_merge([$period, $cut], $ids));
    $before = array_map('intval', $st->fetchAll(PDO::FETCH_KEY_PAIR));
    $chg = $pdo->prepare("SELECT DISTINCT task_id FROM ecampus_updates WHERE period = ? AND created_at >= ? AND task_id IN ($ph)");
    $chg->execute(array_merge([$period, $cut], $ids));
    $changed = array_flip(array_map('intval', $chg->fetchAll(PDO::FETCH_COLUMN)));
    $prev = $pdo->prepare("SELECT percent FROM ecampus_progress WHERE task_id = ? AND period < ? ORDER BY period DESC LIMIT 1");
    $out = [];
    foreach ($work as $r) {
        if (!isset($changed[(int)$r['id']])) { continue; }
        $from = $before[(int)$r['id']] ?? null;
        if ($from === null) { $from = 0; if ($r['task_type'] === 'one_time') { $prev->execute([$r['id'], $period]); $from = (int)($prev->fetchColumn() ?: 0); } }
        $delta = $r['percent'] - $from;
        if ($delta > 0) { $out[] = ['row' => $r, 'from' => $from, 'to' => $r['percent'], 'delta' => $delta]; }
    }
    usort($out, fn($a, $b) => $b['delta'] <=> $a['delta']);
    return array_slice($out, 0, $limit);
}

/** मागचे $n महिने — एकूण % (जुना → नवा): ['YYYY-MM' => %|null] */
function ecampusMonthlyHistory(PDO $pdo, $orgId, $n = 6) {
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        $p = date('Y-m', strtotime(date('Y-m-01') . " -$i month"));
        $rows = ecampusRows($pdo, $orgId, $p);
        $out[$p] = $rows ? ecampusSummary($rows, $p)['overall'] : null;
    }
    return $out;
}

/** नोंद अद्ययावत न केलेल्या / सुरू न केलेल्या कर्मचाऱ्यांना आठवण (Notification). परत: किती जणांना पाठवली */
function ecampusSendReminders(PDO $pdo, $orgId, ?array $userIds = null) {
    $period = date('Y-m');
    $board = ecampusStaffBoard(ecampusRows($pdo, $orgId, $period), $period);
    $sent = 0;
    foreach ($board as $b) {
        if ($userIds !== null && !in_array($b['user_id'], $userIds, true)) { continue; }
        $todo = $b['n'] - $b['done'];
        if ($todo <= 0 || ($userIds === null && !$b['stale'] && !$b['pending'])) { continue; }
        notifyUser($b['user_id'], $orgId, '🎓 E-Campus — प्रगती अद्ययावत करा',
            $todo . ' काम' . ($todo > 1 ? 'ं' : '') . ' बाकी (सरासरी ' . $b['avg'] . '%)' . ($b['stale'] ? ' · ' . $b['stale'] . ' कामांची नोंद 7 दिवसांत अद्ययावत नाही' : '') . ' — आजच % आणि नोंद भरा.',
            'ecampus/index.php');
        $sent++;
    }
    return $sent;
}

/* ======================= 📚 अनेक महिन्यांचा / वार्षिक अहवाल ======================= */

/** एका अहवालात जास्तीत जास्त किती महिने (PDF तक्ता Landscape मध्ये बसावा म्हणून) */
const ECAMPUS_RANGE_MAX = 12;

/** तयार कालावधी — [key => [लेबल, from, to]] (भविष्यातला शेवट चालू महिन्यापर्यंतच) */
function ecampusRangePresets() {
    $now = date('Y-m');
    $y = (int)date('Y'); $m = (int)date('n');
    $fyStart = $m >= 4 ? $y : $y - 1;   // आर्थिक वर्ष एप्रिल–मार्च
    $clip = fn($p) => min($p, $now);
    return [
        'fy' => ['चालू आर्थिक वर्ष (' . $fyStart . '-' . substr($fyStart + 1, 2) . ')', sprintf('%d-04', $fyStart), $clip(sprintf('%d-03', $fyStart + 1))],
        'lastfy' => ['मागचं आर्थिक वर्ष (' . ($fyStart - 1) . '-' . substr($fyStart, 2) . ')', sprintf('%d-04', $fyStart - 1), sprintf('%d-03', $fyStart)],
        'year' => ['हे वर्ष (जाने–डिसें ' . $y . ')', $y . '-01', $clip($y . '-12')],
        'last6' => ['मागचे 6 महिने', date('Y-m', strtotime(date('Y-m-01') . ' -5 month')), $now],
        'last12' => ['मागचे 12 महिने', date('Y-m', strtotime(date('Y-m-01') . ' -11 month')), $now],
    ];
}

/** from–to मधले महिने (क्रमाने); चुकीचं असेल तर [] — जास्तीत जास्त ECAMPUS_RANGE_MAX */
function ecampusRangeMonths($from, $to) {
    $ok = fn($p) => is_string($p) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p) && $p >= '2020-01' && $p <= date('Y-m');
    if (!$ok($from) || !$ok($to)) { return []; }
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    $out = [];
    for ($p = $from; $p <= $to && count($out) < ECAMPUS_RANGE_MAX; $p = date('Y-m', strtotime($p . '-01 +1 month'))) { $out[] = $p; }
    return $out;
}

/** लहान महिना-लेबल (तक्त्याच्या Column साठी) — उदा. "ऑक्टो 26" */
function ecampusShortMonth($ym) {
    $m = ['', 'जाने', 'फेब्रु', 'मार्च', 'एप्रि', 'मे', 'जून', 'जुलै', 'ऑग', 'सप्टें', 'ऑक्टो', 'नोव्हें', 'डिसें'];
    return $m[(int)substr($ym, 5, 2)] . ' ' . substr($ym, 2, 2);
}

/**
 * कालावधीचा Data — [months, perMonth[ym=>rows], summary[ym=>S], staff[uid=>[name,desig,m[ym]=>avg]], tasks[tid=>[row, m[ym]=>pct]]]
 */
function ecampusRangeData(PDO $pdo, $orgId, array $months) {
    $per = []; $sum = []; $staff = []; $tasks = [];
    foreach ($months as $p) {
        $rows = ecampusRows($pdo, $orgId, $p);
        $per[$p] = $rows;
        $sum[$p] = $rows ? ecampusSummary($rows, $p) : null;
        // त्या महिन्यात एकही नोंद भरलेली नसेल तर 0% नव्हे — "नोंद नाही" (सरासरी/सर्वोत्तम/कमी मध्ये धरत नाही)
        if ($sum[$p]) { $sum[$p]['filled'] = $sum[$p]['work'] - $sum[$p]['notUpdated']; if ($sum[$p]['filled'] <= 0) { $sum[$p]['overall'] = null; } }
        $byU = [];
        foreach ($rows as $r) {
            if ($r['access_type'] !== 'work') { continue; }
            $u = (int)$r['user_id'];
            $staff[$u] ??= ['name' => $r['staff_name'], 'desig' => $r['designation_show'], 'm' => []];
            if ($r['updated_at'] || $r['carried']) { $byU[$u][] = $r['percent']; }   // भरलेल्या नोंदींचीच सरासरी
            $tasks[(int)$r['id']] ??= ['row' => $r, 'm' => []];
            // भरलेलं नसलेलं (नोंदच नाही) वेगळं दाखवण्यासाठी — null
            $tasks[(int)$r['id']]['m'][$p] = ($r['updated_at'] || $r['carried']) ? (int)$r['percent'] : null;
        }
        foreach ($byU as $u => $list) { $staff[$u]['m'][$p] = (int)round(array_sum($list) / count($list)); }
    }
    uasort($staff, fn($a, $b) => strcmp($a['name'], $b['name']));
    uasort($tasks, fn($a, $b) => [ecampusWingKey($a['row']['wing']), $a['row']['staff_name']] <=> [ecampusWingKey($b['row']['wing']), $b['row']['staff_name']]);
    return ['months' => $months, 'per' => $per, 'sum' => $sum, 'staff' => $staff, 'tasks' => $tasks];
}

/** 📄 अनेक महिन्यांचा / वार्षिक E-Campus PDF (Landscape) */
function ecampusRangeReportPdf(PDO $pdo, $orgId, array $months, $orgName, $withDetail = false) {
    require_once __DIR__ . '/weekly-report.php';
    require_once __DIR__ . '/daily-report.php';
    $D = ecampusRangeData($pdo, $orgId, $months);
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $bc = fn($p) => $p >= 100 ? '#16A34A' : ($p >= 75 ? '#65A30D' : ($p >= 50 ? '#F59E0B' : '#DC2626'));
    $bg = fn($p) => $p === null ? '#F3F1EE' : ($p >= 100 ? '#DCFCE7' : ($p >= 75 ? '#ECFCCB' : ($p >= 50 ? '#FEF3C7' : '#FEE2E2')));
    $cell = fn($p) => '<td class="mc" style="background:' . $bg($p) . ';color:' . ($p === null ? '#A8AEC2' : $bc($p)) . ';">' . ($p === null ? '—' : $p . '%') . '</td>';
    $first = $months[0]; $last = end($months);
    $label = $first === $last ? ecampusPeriodLabel($first) : ecampusPeriodLabel($first) . ' ते ' . ecampusPeriodLabel($last);
    $overalls = array_filter(array_map(fn($s) => $s['overall'] ?? null, $D['sum']), fn($v) => $v !== null);
    $avg = $overalls ? (int)round(array_sum($overalls) / count($overalls)) : null;
    $best = $overalls ? array_search(max($overalls), $overalls, true) : null;
    $worst = $overalls ? array_search(min($overalls), $overalls, true) : null;
    $mh = implode('', array_map(fn($p) => '<th class="mc">' . $h(ecampusShortMonth($p)) . '</th>', $months));
    $kpi = fn($l, $v, $s = '', $col = '#191C3A') => '<td class="kpi"><div class="kl">' . $l . '</div><div class="kv" style="color:' . $col . ';">' . $v . '</div><div class="ks">' . $s . '</div></td>';

    $css = '<style>body{font-family:freesans;color:#1A1D29;font-size:8.4pt;}
      .hdr{background:#191C3A;color:#fff;padding:12px 16px;border-bottom:4px solid #D9530F;} .hdr .o{font-size:14pt;font-weight:bold;} .hdr .t{font-size:10.5pt;color:#FDBA8C;}
      table.kp{width:100%;border-collapse:separate;border-spacing:5px;margin:0 -5px;} td.kpi{background:#F7F5F2;border:1px solid #EAE5DF;padding:6px 8px;vertical-align:top;}
      .kl{font-size:7.6pt;color:#64708A;} .kv{font-size:14pt;font-weight:bold;} .ks{font-size:7.2pt;color:#64708A;}
      table.g{width:100%;border-collapse:collapse;font-size:7.8pt;} table.g th{background:#F3F1EE;color:#475069;text-align:left;padding:4px 4px;border-bottom:1px solid #DDD6CE;font-size:7.4pt;}
      table.g td{padding:3px 4px;border-bottom:1px solid #EEE9E3;vertical-align:top;} .num-r{text-align:right;} .muted{color:#8A90AC;font-size:7.4pt;}
      th.mc,td.mc{text-align:center;width:6.2%;font-weight:bold;} td.mc{border-left:1px solid #fff;}
      h2{font-size:10.5pt;color:#191C3A;margin:12px 0 5px;padding-bottom:2px;border-bottom:1.2px solid #EAE5DF;} .h2s{font-size:7.6pt;color:#8A90AC;font-weight:normal;}
      table.split{width:100%;border-collapse:collapse;} table.split td{vertical-align:top;padding:0;}</style>';
    $html = $css . '<div class="hdr"><div class="o">' . $h($orgName) . '</div><div class="t">E-CAMPUS REPORT — ' . $h($label) . ' (' . count($months) . ' महिने)</div>'
        . '<div class="p" style="font-size:7.6pt;color:#C9CCE0;">!! आत्मा मालिक !! · तयार: ' . date('d-m-Y, h:i A') . '</div></div>';

    $html .= '<table class="kp"><tr>'
        . $kpi('कालावधीची सरासरी प्रगती', $avg === null ? '—' : $avg . '%', count($overalls) . ' महिन्यांची नोंद', $avg === null ? '#8A90AC' : $bc($avg))
        . $kpi('सर्वोत्तम महिना', $best ? $h(ecampusPeriodLabel($best)) : '—', $best ? $overalls[$best] . '%' : '', '#137A4B')
        . $kpi('सर्वात कमी', $worst ? $h(ecampusPeriodLabel($worst)) : '—', $worst ? $overalls[$worst] . '%' : '', '#B42318')
        . $kpi('कामं / कर्मचारी', count($D['tasks']) . ' / ' . count($D['staff']), 'या कालावधीत') . '</tr></table>';

    // 1) महिनावार एकूण
    $html .= '<h2>महिनावार एकूण प्रगती</h2><table class="g"><thead><tr><th style="width:18%;">महिना</th><th style="width:44%;"></th><th class="num-r">एकूण %</th><th class="num-r">पूर्ण</th><th class="num-r">सुरू</th><th class="num-r">सुरू नाही</th><th class="num-r">नोंद नाही</th></tr></thead><tbody>';
    foreach ($months as $p) {
        $s = $D['sum'][$p];
        if (!$s) { $html .= '<tr><td>' . $h(ecampusPeriodLabel($p)) . '</td><td colspan="6" class="muted">कामं नेमलेली नव्हती</td></tr>'; continue; }
        if ($s['overall'] === null) { $html .= '<tr><td>' . $h(ecampusPeriodLabel($p)) . '</td><td colspan="6" class="muted">— या महिन्याची एकही नोंद भरलेली नाही</td></tr>'; continue; }
        $o = (int)$s['overall'];
        $html .= '<tr><td><b>' . $h(ecampusPeriodLabel($p)) . '</b></td><td>' . weeklyReportBar($o, $bc($o), 95) . '</td><td class="num-r" style="font-weight:bold;color:' . $bc($o) . ';">' . $o . '%</td>'
            . '<td class="num-r">' . (int)$s['done'] . '/' . (int)$s['work'] . '</td><td class="num-r">' . (int)$s['progress'] . '</td><td class="num-r">' . (int)$s['pending'] . '</td><td class="num-r">' . (int)$s['notUpdated'] . '</td></tr>';
    }
    $html .= '</tbody></table>';

    // 2) कामनिहाय — फक्त Wing नुसार गट, प्रत्येक कामाचा महिनावार % व सरासरी
    if ($D['tasks']) {
        $html .= '<h2>कामनिहाय प्रगती — महिनावार % <span class="h2s">हिरवा = 100% · पिवळा = 50–74% · लाल = 50% पेक्षा कमी · — = त्या महिन्याची नोंद भरलेली नाही</span></h2>'
            . '<table class="g"><thead><tr><th style="width:3%;">#</th><th style="width:13%;">कर्मचारी</th><th style="width:' . max(16, 40 - count($months) * 2) . '%;">काम</th>' . $mh . '<th class="mc">सरासरी</th></tr></thead><tbody>';
        $grp = null; $sr = 0; $ncol = count($months) + 4;
        foreach ($D['tasks'] as $t) {
            $r = $t['row'];
            $g = $r['wing'];
            if ($g !== $grp) {
                $grp = $g;
                $html .= '<tr><td colspan="' . $ncol . '" style="background:#191C3A;color:#fff;font-weight:bold;padding:4px 6px;">' . $h($r['wing']) . '</td></tr>';
            }
            $vals = array_values(array_filter($t['m'], fn($v) => $v !== null));
            $a = $vals ? (int)round(array_sum($vals) / count($vals)) : null;
            $html .= '<tr><td>' . (++$sr) . '</td><td><b>' . $h($r['staff_name']) . '</b><div class="muted">' . $h($r['designation_show']) . '</div></td><td>' . $h(mb_strimwidth((string)$r['task_description'], 0, 160, '…')) . '</td>';
            foreach ($months as $p) { $html .= $cell(array_key_exists($p, $t['m']) ? $t['m'][$p] : null); }
            $html .= $cell($a) . '</tr>';
        }
        $html .= '</tbody></table>';
    }

    // 4) प्रत्येक महिन्याचा पूर्ण तपशील (ऐच्छिक)
    if ($withDetail) {
        foreach ($months as $p) {
            if (!$D['per'][$p]) { continue; }
            $html .= '<pagebreak /><h2>' . $h(ecampusPeriodLabel($p)) . ' — पूर्ण तपशील</h2>' . ecampusPdfSection($pdo, $orgId, $p, 'detail');
        }
    }
    $html .= '<div class="muted" style="margin-top:8px;text-align:center;">— = नोंद नाही / कामं नव्हती · नवीन महिन्यात प्रत्येक काम 0% पासून मोजलं जातं</div>';
    return mgmtReportPdf($html, 'E-Campus Report — ' . $label, $orgName, 'A4-L', [
        'link' => 'ecampus/report.php?from=' . $first . '&to=' . $last, 'orgId' => $orgId, 'badgeMm' => 13,
        'subtitle' => 'E-Campus अहवाल · ' . $label,
    ]);
}

function ecampusRangeFileName(array $months) {
    return 'e-campus-report-' . $months[0] . '-to-' . end($months) . '-' . date('Ymd-His') . '.pdf';
}
