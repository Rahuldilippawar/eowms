<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$logsStmt = $pdo->prepare("
    SELECT ci.name AS item_name, ci.category, u1.name AS borrower_name, u2.name AS issued_by_name,
      cl.purpose, cl.checkout_at, cl.expected_return_at, cl.actual_return_at, u3.name AS returned_to_name
    FROM checkout_logs cl
    JOIN checkout_items ci ON ci.id = cl.item_id
    JOIN users u1 ON u1.id = cl.borrowed_by
    JOIN users u2 ON u2.id = cl.issued_by
    LEFT JOIN users u3 ON u3.id = cl.returned_to
    WHERE ci.organization_id = ? ORDER BY cl.checkout_at DESC
");
$logsStmt->execute([$orgId]);
$logs = $logsStmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="checkout-log-' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM — Excel मध्ये मराठी बरोबर दिसण्यासाठी
fputcsv($out, ['वस्तू', 'प्रकार', 'कोणाला दिली', 'कोणी दिली', 'कारण', 'दिल्याची वेळ', 'परत करायची मुदत', 'प्रत्यक्ष परत आलं', 'कोणी घेतलं']);
foreach ($logs as $l) {
    fputcsv($out, [
        $l['item_name'], $l['category'], $l['borrower_name'], $l['issued_by_name'], $l['purpose'],
        $l['checkout_at'], $l['expected_return_at'], $l['actual_return_at'] ?: 'अजून परत आलं नाही', $l['returned_to_name'],
    ]);
}
fclose($out);
exit;
