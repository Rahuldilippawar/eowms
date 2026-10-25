<?php
// Issue / Return Register — CSV Export (Module चीच परवानगी आवश्यक)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_asset_allocations') || hasPermission('manage_checkout') || isManagementOrAbove();
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

$logsStmt = $pdo->prepare("
    SELECT at.name AS type_name, at.is_loan, aa.asset_label, c.name AS campus_name, u1.name AS borrower_name, u2.name AS issued_by_name,
      aa.purpose, COALESCE(aa.allocated_at, aa.allocated_date) AS issued_at, aa.due_at, COALESCE(aa.returned_at, aa.unallocated_date) AS returned_at,
      u3.name AS returned_to_name, aa.unallocated_reason
    FROM asset_allocations aa
    JOIN asset_allocation_types at ON at.id = aa.type_id
    JOIN users u1 ON u1.id = aa.user_id
    LEFT JOIN users u2 ON u2.id = aa.allocated_by
    LEFT JOIN users u3 ON u3.id = aa.returned_to
    LEFT JOIN campuses c ON c.id = aa.campus_id
    WHERE aa.organization_id = ? ORDER BY issued_at DESC
");
$logsStmt->execute([$orgId]);
$logs = $logsStmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="issue-return-register-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel मध्ये मराठी बरोबर दिसण्यासाठी
fputcsv($out, ['Type', 'प्रकार', 'वस्तू', 'College/School', 'कोणाला दिली', 'कोणी दिली', 'कारण', 'दिल्याची वेळ', 'परत करायची मुदत', 'प्रत्यक्ष परत आलं', 'कोणी घेतलं', 'शेरा']);
foreach ($logs as $l) {
    fputcsv($out, [
        $l['type_name'], $l['is_loan'] ? 'उसनं' : 'कायमचं', $l['asset_label'], $l['campus_name'], $l['borrower_name'], $l['issued_by_name'], $l['purpose'],
        $l['issued_at'], $l['due_at'], $l['returned_at'] ?: 'अजून परत आलं नाही', $l['returned_to_name'], $l['unallocated_reason'],
    ]);
}
fclose($out);
exit;
