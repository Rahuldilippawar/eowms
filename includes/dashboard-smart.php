<?php
// 🧠 Smart Command Center — Work Requests, Tasks, नूतनीकरण, विभाग-आरोग्य
// आणि "आज लक्ष द्या" सूचना. index.php मधून include होतं; तिथले $pdo, $user,
// $orgId, $isMgmtView, $myDeptId, $departments वापरतो.
// प्रत्येक Module ची स्वतःची Visibility (कोण काय बघू शकतो) इथेही तशीच पाळली आहे.

require_once __DIR__ . '/ticket-helpers.php';

/** तारखेचं मानवी वर्णन — आज / उद्या / ३ दिवसांनी / २ दिवस उशीर */
function dashRelDay($date) {
    $diff = (int)floor((strtotime($date) - strtotime(date('Y-m-d'))) / 86400);
    if ($diff === 0) return ['आज', 'amber'];
    if ($diff === 1) return ['उद्या', 'amber'];
    if ($diff === -1) return ['काल संपली', 'rose'];
    if ($diff < 0) return [abs($diff) . ' दिवस उशीर', 'rose'];
    return [$diff . ' दिवसांनी', $diff <= 7 ? 'amber' : 'rail'];
}
function dashInitials($name) {
    $parts = preg_split('/\s+/u', trim((string)$name));
    $s = mb_substr($parts[0] ?? '', 0, 1);
    if (count($parts) > 1) $s .= mb_substr(end($parts), 0, 1);
    return mb_strtoupper($s ?: '?');
}

$smart = ['requests' => null, 'tasks' => null, 'renewals' => null, 'health' => [], 'alerts' => []];
$today = date('Y-m-d');
$weekEnd = date('Y-m-d', strtotime('+7 days'));

// ===================== 1. कामाच्या विनंत्या =====================
// requests/index.php प्रमाणेच Scope
$reqCanQueue = hasRole('super_admin') || isDeptScoped() || hasPermission('manage_requests_org_wide');
$reqVisibleDepts = $reqCanQueue ? getVisibleDepartmentIds('manage_requests_org_wide') : [];
$reqScopeSql = 'r.organization_id = ?';
$reqScopeParams = [$orgId];
if ($reqCanQueue && $reqVisibleDepts === null) {
    // सगळे विभाग
} elseif ($reqCanQueue && $reqVisibleDepts) {
    $ph = implode(',', array_fill(0, count($reqVisibleDepts), '?'));
    $reqScopeSql .= " AND (r.department_id IN ($ph) OR r.assigned_to_user_id = ?)";
    $reqScopeParams = array_merge($reqScopeParams, $reqVisibleDepts, [$user['id']]);
} else {
    $reqScopeSql .= ' AND r.assigned_to_user_id = ?';
    $reqScopeParams[] = $user['id'];
}
try {
    $st = $pdo->prepare("SELECT
            SUM(r.status IN ('pending','in_progress')) open_cnt,
            SUM(r.status = 'pending') pending_cnt,
            SUM(r.status = 'in_progress') progress_cnt,
            SUM(r.status IN ('pending','in_progress') AND r.due_at < NOW()) overdue_cnt,
            SUM(r.status IN ('pending','in_progress') AND r.priority IN ('urgent','high')) urgent_cnt,
            SUM(r.status IN ('completed','closed') AND DATE(r.completed_at) = CURDATE()) done_today,
            SUM(DATE(r.created_at) = CURDATE()) new_today,
            AVG(CASE WHEN r.rating IS NOT NULL AND r.created_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN r.rating END) avg_rating
        FROM work_requests r WHERE $reqScopeSql");
    $st->execute($reqScopeParams);
    $rc = array_map(fn($v) => $v === null ? 0 : $v, $st->fetch());

    $st = $pdo->prepare("SELECT r.id, r.request_code, r.title, r.category, r.priority, r.status, r.due_at, r.created_at,
            r.requester_name, r.assigned_to_user_id, d.name_local AS dept_name, au.name AS assignee_name
        FROM work_requests r LEFT JOIN departments d ON d.id = r.department_id LEFT JOIN users au ON au.id = r.assigned_to_user_id
        WHERE $reqScopeSql AND r.status IN ('pending','in_progress')
        ORDER BY (r.due_at IS NOT NULL AND r.due_at < NOW()) DESC, FIELD(r.priority,'urgent','high','normal','low'), r.due_at IS NULL, r.due_at, r.created_at
        LIMIT 60");
    $st->execute($reqScopeParams);
    $openReqs = $st->fetchAll();
    $smart['requests'] = [
        'counts' => $rc,
        'tabs' => [
            'open' => array_slice($openReqs, 0, 6),
            'overdue' => array_slice(array_values(array_filter($openReqs, fn($r) => $r['due_at'] && strtotime($r['due_at']) < time())), 0, 6),
            'urgent' => array_slice(array_values(array_filter($openReqs, fn($r) => in_array($r['priority'], ['urgent', 'high'], true))), 0, 6),
            'mine' => array_slice(array_values(array_filter($openReqs, fn($r) => (int)$r['assigned_to_user_id'] === (int)$user['id'])), 0, 6),
        ],
    ];
} catch (PDOException $e) { /* Module/Columns तयार नाहीत — Panel दिसणार नाही */ }

// ===================== 2. Tasks =====================
$taskOrgWide = hasPermission('view_task_tracker');
$taskScopeSql = 't.organization_id = ?' . ($taskOrgWide ? '' : ' AND (t.assigned_to = ? OR t.assigned_by = ?)');
$taskScopeParams = $taskOrgWide ? [$orgId] : [$orgId, $user['id'], $user['id']];
try {
    $st = $pdo->prepare("SELECT
            SUM(t.status IN ('pending','in_progress')) open_cnt,
            SUM(t.status IN ('pending','in_progress') AND t.target_date < CURDATE()) overdue_cnt,
            SUM(t.status IN ('pending','in_progress') AND t.target_date = CURDATE()) today_cnt,
            SUM(t.status IN ('pending','in_progress') AND t.target_date BETWEEN CURDATE() AND ?) week_cnt,
            SUM(t.status = 'in_progress') progress_cnt,
            SUM(t.status = 'completed' AND t.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) done_month,
            SUM(t.status = 'completed' AND t.completed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') AND DATE(t.completed_at) <= t.target_date) ontime_month,
            SUM(t.status = 'completed' AND t.verified_at IS NULL AND t.assigned_by = ?) to_verify,
            SUM(t.status IN ('pending','in_progress') AND t.assigned_to = ?) mine_open
        FROM tasks t WHERE $taskScopeSql");
    $st->execute(array_merge([$weekEnd, $user['id'], $user['id']], $taskScopeParams));
    $tc = array_map(fn($v) => $v === null ? 0 : (int)$v, $st->fetch());

    $st = $pdo->prepare("SELECT t.id, t.title, t.priority, t.status, t.target_date, t.assigned_to, t.assigned_by,
            d.name_local AS dept_name, u.name AS assignee_name
        FROM tasks t JOIN departments d ON d.id = t.department_id JOIN users u ON u.id = t.assigned_to
        WHERE $taskScopeSql AND t.status IN ('pending','in_progress')
          AND (t.target_date <= ? OR t.assigned_to = ? OR t.assigned_by = ?)
        ORDER BY t.target_date, FIELD(t.priority,'urgent','high','normal','low') LIMIT 80");
    $st->execute(array_merge($taskScopeParams, [$weekEnd, $user['id'], $user['id']]));
    $openTasks = $st->fetchAll();
    $smart['tasks'] = [
        'counts' => $tc,
        'orgWide' => $taskOrgWide,
        'tabs' => [
            'overdue' => array_slice(array_values(array_filter($openTasks, fn($t) => $t['target_date'] < $today)), 0, 6),
            'week' => array_slice(array_values(array_filter($openTasks, fn($t) => $t['target_date'] >= $today && $t['target_date'] <= $weekEnd)), 0, 6),
            'mine' => array_slice(array_values(array_filter($openTasks, fn($t) => (int)$t['assigned_to'] === (int)$user['id'])), 0, 6),
            'given' => array_slice(array_values(array_filter($openTasks, fn($t) => (int)$t['assigned_by'] === (int)$user['id'])), 0, 6),
        ],
    ];
} catch (PDOException $e) {}

// ===================== 3. नूतनीकरण =====================
$renScopeSql = 'r.organization_id = ? AND r.status <> \'inactive\'' . ($isMgmtView ? '' : ' AND r.department_id = ?');
$renScopeParams = $isMgmtView ? [$orgId] : [$orgId, $myDeptId];
try {
    $st = $pdo->prepare("SELECT
            SUM(r.expiry_date < CURDATE()) expired,
            SUM(r.expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)) d7,
            SUM(r.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 8 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) d30,
            SUM(r.expiry_date BETWEEN DATE_ADD(CURDATE(), INTERVAL 31 DAY) AND DATE_ADD(CURDATE(), INTERVAL 90 DAY)) d90,
            COUNT(*) total,
            SUM(CASE WHEN r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN COALESCE(r.renewal_cost, 0) ELSE 0 END) cost30
        FROM renewal_items r WHERE $renScopeSql");
    $st->execute($renScopeParams);
    $rn = array_map(fn($v) => $v === null ? 0 : $v, $st->fetch());
    $st = $pdo->prepare("SELECT r.id, r.item_name, r.item_type, r.expiry_date, r.renewal_cost, d.name_local AS dept_name,
            DATEDIFF(r.expiry_date, CURDATE()) AS days_left
        FROM renewal_items r JOIN departments d ON d.id = r.department_id
        WHERE $renScopeSql AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY)
        ORDER BY r.expiry_date LIMIT 7");
    $st->execute($renScopeParams);
    $smart['renewals'] = ['counts' => $rn, 'items' => $st->fetchAll()];
} catch (PDOException $e) {}

// ===================== 4. विभाग-आरोग्य (Health) =====================
require_once __DIR__ . '/dept-health.php';
$smart['health'] = deptHealthRows($pdo, $orgId, $departments);
$smart['healthSum'] = deptHealthSummary($smart['health']);

// ===================== 5. "आज लक्ष द्या" — Smart सूचना =====================
$addAlert = function ($cnt, $sev, $icon, $text, $href) use (&$smart) {
    if ($cnt > 0) $smart['alerts'][] = ['cnt' => (int)$cnt, 'sev' => $sev, 'icon' => $icon, 'text' => $text, 'href' => $href];
};
if ($smart['requests']) {
    $addAlert($smart['requests']['counts']['overdue_cnt'], 'bad', 'fa-solid fa-stopwatch', 'विनंत्यांची SLA मुदत ओलांडली', 'requests/index.php?status=overdue');
    $addAlert($smart['requests']['counts']['urgent_cnt'], 'warn', 'fa-solid fa-bolt', 'तातडीच्या / जास्त प्राधान्याच्या विनंत्या उघड्या', 'requests/index.php');
}
if ($smart['tasks']) {
    $addAlert($smart['tasks']['counts']['overdue_cnt'], 'bad', 'fa-solid fa-hourglass-end', 'Tasks ची मुदत संपली', $smart['tasks']['orgWide'] ? 'tasks/index.php?status=pending' : 'tasks/my-tasks.php');
    $addAlert($smart['tasks']['counts']['today_cnt'], 'warn', 'fa-solid fa-calendar-day', 'Tasks ची मुदत आज आहे', $smart['tasks']['orgWide'] ? 'tasks/index.php' : 'tasks/my-tasks.php');
    $addAlert($smart['tasks']['counts']['to_verify'], 'info', 'fa-solid fa-clipboard-check', 'पूर्ण झालेले Tasks तुमच्या पडताळणीसाठी', 'tasks/index.php');
}
if ($smart['renewals']) {
    $addAlert($smart['renewals']['counts']['expired'], 'bad', 'fa-solid fa-file-circle-xmark', 'नूतनीकरणांची मुदत संपली', 'renewals/index.php?filter=expired');
    $addAlert($smart['renewals']['counts']['d7'], 'warn', 'fa-solid fa-shield-halved', 'नूतनीकरणं ७ दिवसांत संपणार', 'renewals/index.php?filter=due_soon');
}
if ($isMgmtView && count($smart['health']) > 1 && (int)date('G') >= 14) {
    $idle = count(array_filter($smart['health'], fn($h) => $h['today'] === 0));
    $addAlert($idle, 'info', 'fa-solid fa-building-circle-exclamation', 'विभागांनी आज अजून काम नोंदवलं नाही', 'daily-work/report.php');
}
usort($smart['alerts'], fn($a, $b) => ['bad' => 0, 'warn' => 1, 'info' => 2][$a['sev']] <=> ['bad' => 0, 'warn' => 1, 'info' => 2][$b['sev']]);

// Quick-Assign साठी — विभाग व कर्मचारी (assign.php प्रमाणेच Scope)
$quickAssign = null;
if (hasPermission('assign_tasks')) {
    $qaScoped = !hasPermission('assign_tasks_org_wide');
    $st = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1" . ($qaScoped ? ' AND id = ?' : '') . ' ORDER BY name_local');
    $st->execute($qaScoped ? [$orgId, $myDeptId] : [$orgId]);
    $qaDepts = $st->fetchAll();
    $st = $pdo->prepare('SELECT id, name, department_id FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name');
    $st->execute([$orgId]);
    $qaLoad = [];
    try {
        foreach ($pdo->query("SELECT assigned_to, COUNT(*) c, SUM(target_date < CURDATE()) od FROM tasks WHERE organization_id = " . (int)$orgId . " AND status IN ('pending','in_progress') GROUP BY assigned_to")->fetchAll() as $l) {
            $qaLoad[(int)$l['assigned_to']] = [(int)$l['c'], (int)$l['od']];
        }
    } catch (PDOException $e) {}
    $qaStaff = [];
    foreach ($st->fetchAll() as $s) {
        $ld = $qaLoad[(int)$s['id']] ?? [0, 0];
        $qaStaff[(int)$s['department_id']][] = ['id' => (int)$s['id'], 'name' => $s['name'], 'open' => $ld[0], 'overdue' => $ld[1]];
    }
    // कमी भार असलेले आधी
    foreach ($qaStaff as &$lst) { usort($lst, fn($a, $b) => [$a['overdue'], $a['open'], $a['name']] <=> [$b['overdue'], $b['open'], $b['name']]); }
    unset($lst);
    if ($qaDepts) $quickAssign = ['depts' => $qaDepts, 'staff' => $qaStaff];
}

$prioDot = ['urgent' => 'var(--rose)', 'high' => 'var(--amber)', 'normal' => '#3B6FC9', 'low' => 'var(--text-dim)'];
$prioLabel = ['urgent' => 'तातडीचं', 'high' => 'जास्त', 'normal' => 'सर्वसाधारण', 'low' => 'कमी'];
