<?php
// विभाग Finance — Excel (CSV) Export: सारांश (Budget vs Actual + Paid/Due) किंवा खर्च-Ledger
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$canAccess = hasPermission('manage_dept_finance') || hasPermission('manage_budget') || hasCategory('hod') || isManagementOrAbove();
$accessibleDeptId = deptFinanceAccessibleDeptId($user);
if (!$canAccess || $accessibleDeptId === 0) { http_response_code(403); die('परवानगी नाही.'); }

$fy = (int)($_GET['fy'] ?? getCurrentFinancialYear());
if ($fy < 2000 || $fy > 2100) { $fy = getCurrentFinancialYear(); }
$filterDeptId = $accessibleDeptId ?? (int)($_GET['department_id'] ?? 0);
$type = ($_GET['type'] ?? 'summary') === 'ledger' ? 'ledger' : 'summary';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="dept-finance-' . $type . '-FY' . $fy . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel मध्ये मराठी बरोबर दिसण्यासाठी

if ($type === 'summary') {
    $rows = deptFinanceSummaryRows($pdo, $orgId, $fy, $filterDeptId ?: null);
    $sourceLabels = array_values(array_unique(array_values(budgetSourceLabels())));
    fputcsv($out, array_merge(['विभाग', 'College/School', 'FY', 'दिलेलं Budget', 'खर्च', 'शिल्लक', 'वापर %'], $sourceLabels, ['Purchase/नूतनीकरण/देखभाल — एकूण देणे', 'Paid', 'Due']));
    foreach ($rows as $r) {
        $src = [];
        foreach ($sourceLabels as $lbl) { $src[] = $r['by_source'][$lbl] ?? 0; }
        fputcsv($out, array_merge([$r['name'], $r['campus_name'] ?: 'Trust-स्तरीय', "$fy-" . substr($fy + 1, 2), $r['allocated'], $r['spent'], $r['remaining'], $r['percent_used']], $src, [$r['owed'], $r['paid'], $r['due']]));
    }
} else {
    $sql = "
        SELECT bt.spend_date, bt.created_at, COALESCE(NULLIF(d.name_local,''), d.name) dept, bt.category, bt.source_module, bt.description, bt.amount, u.name AS created_by_name
        FROM budget_transactions bt JOIN departments d ON d.id = bt.department_id LEFT JOIN users u ON u.id = bt.created_by
        WHERE bt.organization_id = ? AND bt.financial_year = ?";
    $params = [$orgId, $fy];
    if ($filterDeptId) { $sql .= " AND bt.department_id = ?"; $params[] = $filterDeptId; }
    $sql .= " ORDER BY bt.spend_date, bt.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $labels = budgetSourceLabels();
    fputcsv($out, ['दिनांक', 'विभाग', 'श्रेणी', 'Source', 'तपशील', 'रक्कम', 'नोंदवणारा']);
    foreach ($st->fetchAll() as $t) {
        fputcsv($out, [$t['spend_date'] ?: substr($t['created_at'], 0, 10), $t['dept'], $t['category'], $labels[$t['source_module']] ?? $t['source_module'], $t['description'], $t['amount'], $t['created_by_name']]);
    }
}
fclose($out);
exit;
