<?php
// 📊 Dashboard Analytics — निवडलेल्या कालावधीसाठी (from–to) Charts चा Data (JSON).
// Dashboard (index.php) प्रमाणेच Scope: Management ला संपूर्ण संस्था,
// HOD/Staff ला फक्त त्यांचा स्वतःचा विभाग.
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$orgId = activeOrgId();
$isMgmtView = isManagementOrAbove();
$myDeptId = (int)($user['department_id'] ?? 0);
// Session लगेच सोडतो — Charts लोड होत असताना त्याच User ची इतर पानं अडकू नयेत
session_write_close();

function dashParseDate($v) {
    $d = DateTime::createFromFormat('!Y-m-d', (string)$v);
    return ($d && $d->format('Y-m-d') === $v) ? $d : null;
}

$from = dashParseDate($_GET['from'] ?? '') ?: new DateTime('-6 days');
$to = dashParseDate($_GET['to'] ?? '') ?: new DateTime('today');
$from->setTime(0, 0); $to->setTime(0, 0);
if ($from > $to) { [$from, $to] = [$to, $from]; }
// Server वर ताण येऊ नये म्हणून कमाल २ वर्षांचा कालावधी
$maxFrom = (clone $to)->modify('-730 days');
if ($from < $maxFrom) { $from = $maxFrom; }
$fromStr = $from->format('Y-m-d');
$toStr = $to->format('Y-m-d');
$days = (int)$from->diff($to)->days + 1;

// ३१ दिवसांपर्यंत रोजचं, ६ महिन्यांपर्यंत आठवड्याचं, त्यापुढे महिन्याचं विभाजन
$granularity = $days <= 31 ? 'day' : ($days <= 186 ? 'week' : 'month');

$deptSql = $isMgmtView ? '' : ' AND department_id = ?';
$deptParams = $isMgmtView ? [] : [$myDeptId];

$out = [
    'from' => $fromStr, 'to' => $toStr, 'days' => $days, 'granularity' => $granularity,
    'isManagement' => $isMgmtView,
];

// ---- कामाचा कल + स्थिती (work_records) ----
$bucketKey = function (DateTime $d) use ($granularity) {
    if ($granularity === 'day') return $d->format('Y-m-d');
    if ($granularity === 'week') return (clone $d)->modify('monday this week')->format('Y-m-d');
    return $d->format('Y-m');
};
$buckets = [];
$cursor = clone $from;
while ($cursor <= $to) {
    $k = $bucketKey($cursor);
    if (!isset($buckets[$k])) {
        if ($granularity === 'day') { $label = $cursor->format('d M'); }
        elseif ($granularity === 'week') { $label = (clone $cursor)->modify('monday this week')->format('d M'); }
        else { $label = $cursor->format('M Y'); }
        $buckets[$k] = ['label' => $label, 'total' => 0, 'completed' => 0];
    }
    $cursor->modify('+1 day');
}

$status = ['pending' => 0, 'in_progress' => 0, 'completed' => 0];
$stmt = $pdo->prepare("SELECT work_date, status, COUNT(*) c FROM work_records
    WHERE organization_id = ? AND work_date BETWEEN ? AND ?$deptSql GROUP BY work_date, status");
$stmt->execute(array_merge([$orgId, $fromStr, $toStr], $deptParams));
foreach ($stmt->fetchAll() as $r) {
    $k = $bucketKey(new DateTime($r['work_date']));
    $c = (int)$r['c'];
    if (isset($buckets[$k])) {
        $buckets[$k]['total'] += $c;
        if ($r['status'] === 'completed') { $buckets[$k]['completed'] += $c; }
    }
    if (isset($status[$r['status']])) { $status[$r['status']] += $c; }
}
$out['trend'] = [
    'labels' => array_column(array_values($buckets), 'label'),
    'total' => array_column(array_values($buckets), 'total'),
    'completed' => array_column(array_values($buckets), 'completed'),
];
$out['workStatus'] = $status;
$totalWork = array_sum($status);
$out['kpi'] = [
    'total' => $totalWork,
    'completed' => $status['completed'],
    'open' => $status['pending'] + $status['in_progress'],
    'rate' => $totalWork > 0 ? round($status['completed'] * 100 / $totalWork) : 0,
];

// ---- विभागनिहाय (फक्त Management — इतरांना दुसऱ्या विभागांची नावं दिसू नयेत) ----
$out['departments'] = [];
if ($isMgmtView) {
    $stmt = $pdo->prepare("SELECT d.name_local AS name, COUNT(wr.id) AS total, SUM(wr.status = 'completed') AS completed
        FROM work_records wr JOIN departments d ON d.id = wr.department_id
        WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ?
        GROUP BY d.id, d.name_local ORDER BY total DESC");
    $stmt->execute([$orgId, $fromStr, $toStr]);
    foreach ($stmt->fetchAll() as $r) {
        $out['departments'][] = ['name' => $r['name'], 'total' => (int)$r['total'], 'completed' => (int)$r['completed'],
            'open' => (int)$r['total'] - (int)$r['completed']];
    }
}

// ---- कामाच्या विनंत्या (work_requests) व Tasks — Table नसेल तर रिकामं ----
$out['requestStatus'] = null;
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM work_requests
        WHERE organization_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)$deptSql GROUP BY status");
    $stmt->execute(array_merge([$orgId, $fromStr, $toStr], $deptParams));
    $out['requestStatus'] = [];
    foreach ($stmt->fetchAll() as $r) { $out['requestStatus'][$r['status']] = (int)$r['c']; }
} catch (PDOException $e) {}

$out['taskStatus'] = null;
try {
    $stmt = $pdo->prepare("SELECT status, COUNT(*) c FROM tasks
        WHERE organization_id = ? AND target_date BETWEEN ? AND ?$deptSql GROUP BY status");
    $stmt->execute(array_merge([$orgId, $fromStr, $toStr], $deptParams));
    $out['taskStatus'] = [];
    foreach ($stmt->fetchAll() as $r) { $out['taskStatus'][$r['status']] = (int)$r['c']; }
} catch (PDOException $e) {}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
