<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_letters_org_wide');
$type = $_GET['type'] ?? 'outward';
if (!in_array($type, ['outward', 'inward'], true)) { $type = 'outward'; }
$fyLabel = $_GET['fy'] ?? getFinancialYearLabel();
[$fyStart, $fyEnd] = financialYearLabelToRange($fyLabel);
$fyStartYear = (int)date('Y', strtotime($fyStart));
$deptId = (int)($_GET['department_id'] ?? ($user['department_id'] ?? 0));

if (!$isMgmt && (int)($user['department_id'] ?? 0) !== $deptId) {
    http_response_code(403);
    die('परवानगी नाही.');
}

$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
$deptStmt->execute([$deptId, $orgId]);
$dept = $deptStmt->fetch();
if (!$dept) { die('विभाग सापडला नाही.'); }

if ($type === 'outward') {
    $stmt = $pdo->prepare("
        SELECT l.outward_no, l.file_number, l.subject, l.dispatch_mode, l.dispatch_tracking_no, l.dispatch_date, u.name AS creator_name, l.created_at
        FROM letters l LEFT JOIN users u ON u.id = l.created_by
        WHERE l.organization_id = ? AND l.department_id = ? AND l.outward_no IS NOT NULL AND l.created_at BETWEEN ? AND ?
        ORDER BY l.outward_no
    ");
    $stmt->execute([$orgId, $deptId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
} else {
    $stmt = $pdo->prepare("
        SELECT lin.inward_no, lin.created_at AS inward_date, l.file_number, l.subject, u.name AS creator_name
        FROM letter_inward_numbers lin JOIN letters l ON l.id = lin.letter_id LEFT JOIN users u ON u.id = l.created_by
        WHERE lin.department_id = ? AND lin.year = ? ORDER BY lin.inward_no
    ");
    $stmt->execute([$deptId, $fyStartYear]);
}
$rows = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $type . '-register-' . $fyLabel . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

if ($type === 'outward') {
    fputcsv($out, ['जावक क्र.', 'फाईल क्र.', 'विषय', 'कसं पाठवलं', 'Tracking No.', 'Dispatch दिनांक', 'तयार केलं', 'दिनांक']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['outward_no'], $r['file_number'], $r['subject'], $r['dispatch_mode'], $r['dispatch_tracking_no'], $r['dispatch_date'], $r['creator_name'], $r['created_at']]);
    }
} else {
    fputcsv($out, ['आवक क्र.', 'फाईल क्र.', 'विषय', 'नोंदणी केली', 'दिनांक']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['inward_no'], $r['file_number'], $r['subject'], $r['creator_name'], $r['inward_date']]);
    }
}
fclose($out);
exit;
