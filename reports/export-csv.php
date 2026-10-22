<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$scope = $_GET['scope'] ?? 'personal';
$period = $_GET['period'] ?? 'weekly';
$requestedDeptId = (int)($_GET['department_id'] ?? 0);

$isManagement = hasPermission('view_org_wide_dashboards');
$canViewOrgReport = hasPermission('view_org_wide_reports');
$isHod = hasCategory('hod');

if ($scope === 'organization' && !$canViewOrgReport) { die(t('s441')); }
if ($scope === 'department' && !$isManagement && !$isHod) { die(t('s441')); }

switch ($period) {
    case 'daily': $start = date('Y-m-d'); $end = date('Y-m-d'); break;
    case 'monthly': $start = date('Y-m-01'); $end = date('Y-m-t'); break;
    case 'six_monthly': $start = date('Y-m-d', strtotime('-6 months')); $end = date('Y-m-d'); break;
    case 'yearly': $start = date('Y-01-01'); $end = date('Y-12-31'); break;
    case 'all': $start = '2000-01-01'; $end = date('Y-m-d'); break;
    default: $start = date('Y-m-d', strtotime('monday this week')); $end = date('Y-m-d', strtotime('sunday this week'));
}

$orgId = activeOrgId();

if ($scope === 'personal') {
    $stmt = $pdo->prepare("
        SELECT wr.work_date, d.name_local AS dept, wr.title, wr.priority, wr.status
        FROM work_records wr LEFT JOIN departments d ON d.id = wr.department_id
        WHERE EXISTS (SELECT 1 FROM work_record_team wt WHERE wt.work_record_id = wr.id AND wt.user_id = ?) AND wr.work_date BETWEEN ? AND ? ORDER BY wr.work_date DESC
    ");
    $stmt->execute([$user['id'], $start, $end]);
    $rows = $stmt->fetchAll();
    $headers = ['दिनांक', 'विभाग', 'काम', 'प्राधान्य', 'स्थिती'];
} elseif ($scope === 'department') {
    $deptId = $isHod && !$isManagement ? $user['department_id'] : $requestedDeptId;
    $stmt = $pdo->prepare("
        SELECT wr.work_date, (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS emp, wr.title, wr.priority, wr.status
        FROM work_records wr
        WHERE wr.department_id = ? AND wr.work_date BETWEEN ? AND ? ORDER BY wr.work_date DESC
    ");
    $stmt->execute([$deptId, $start, $end]);
    $rows = $stmt->fetchAll();
    $headers = ['दिनांक', 'कर्मचारी', 'काम', 'प्राधान्य', 'स्थिती'];
} else {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $stmt->execute([$orgId]);
    $depts = $stmt->fetchAll();
    $rows = [];
    foreach ($depts as $d) {
        $t = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id=? AND work_date BETWEEN ? AND ?");
        $t->execute([$d['id'], $start, $end]);
        $total = $t->fetch()['c'];
        $don = $pdo->prepare("SELECT COUNT(*) c FROM work_records WHERE department_id=? AND work_date BETWEEN ? AND ? AND status='completed'");
        $don->execute([$d['id'], $start, $end]);
        $done = $don->fetch()['c'];
        $rows[] = [$d['name_local'] ?: $d['name'], $total, $done, $total > 0 ? round(($done / $total) * 100) . '%' : '0%'];
    }
    $headers = ['विभाग', 'एकूण कामे', 'पूर्ण', 'पूर्णता दर'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="report-' . $scope . '-' . $period . '-' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM so Marathi renders correctly in Excel

$out = fopen('php://output', 'w');
fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, is_array($row) && !isset($row['work_date']) ? $row : array_values($row));
}
fclose($out);
exit;
