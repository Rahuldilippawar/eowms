<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$period = $_GET['period'] ?? 'weekly';
$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_GET['department_id'] ?? '');
$orgId = activeOrgId();

switch ($period) {
    case 'monthly': $start = date('Y-m-01'); $end = date('Y-m-t'); break;
    case 'yearly': $start = date('Y-01-01'); $end = date('Y-12-31'); break;
    default: $start = date('Y-m-d', strtotime('monday this week')); $end = date('Y-m-d', strtotime('sunday this week'));
}

$sql = "SELECT wr.work_date, d.name_local AS dept,
          (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS emp,
          wr.title, wr.priority, wr.status, wr.requested_by
        FROM work_records wr
        JOIN departments d ON d.id = wr.department_id
        WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ?";
$params = [$orgId, $start, $end];
if ($filterDeptId) { $sql .= " AND wr.department_id = ?"; $params[] = $filterDeptId; }
$sql .= " ORDER BY wr.work_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="daily-work-report-' . $period . '-' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['दिनांक', 'विभाग', 'कर्मचारी', 'काम', 'विनंती करणारा', 'प्राधान्य', 'स्थिती']);
foreach ($rows as $r) {
    fputcsv($out, [
        date('d-m-Y', strtotime($r['work_date'])), $r['dept'], $r['emp'], $r['title'],
        $r['requested_by'] ?? '', $r['priority'], $r['status'],
    ]);
}
fclose($out);
exit;
