<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_dead_stock_master');
$deptId = (int)($_GET['department_id'] ?? 0);
if (!$isMgmt) { $deptId = (int)($user['department_id'] ?? 0); }

$sql = "
    SELECT ds.item_code, ds.item_name, ds.category, d.name_local AS dept_name, ds.quantity, ds.unit,
      ds.purchase_date, ds.purchase_cost, ds.`condition`, ds.location, ds.remarks
    FROM dead_stock_items ds JOIN departments d ON d.id = ds.department_id
    WHERE ds.organization_id = ?
";
$params = [$orgId];
if ($deptId) { $sql .= " AND ds.department_id = ?"; $params[] = $deptId; }
$sql .= " ORDER BY d.name_local, ds.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dead-stock-register.csv"');
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');
fputcsv($out, ['Code', 'वस्तू', 'प्रकार', 'विभाग', 'संख्या', 'एकक', 'खरेदी दिनांक', 'खरेदी किंमत', 'स्थिती', 'ठिकाण', 'टिपा']);
foreach ($items as $it) {
    fputcsv($out, [
        $it['item_code'], $it['item_name'], $it['category'] ?? '', $it['dept_name'],
        $it['quantity'], $it['unit'] ?? '', $it['purchase_date'] ?? '', $it['purchase_cost'] ?? '',
        $it['condition'], $it['location'] ?? '', $it['remarks'] ?? '',
    ]);
}
fclose($out);
exit;
