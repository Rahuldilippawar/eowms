<?php
// 📊 IT Assets — Excel (Lab, प्रकार, Serial, Warranty, License, स्थिती, दुरुस्ती-इतिहास)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);
$orgId = activeOrgId();
$q = $pdo->prepare("SELECT a.*, l.lab_name,
        (SELECT COUNT(*) FROM it_repair_tickets t WHERE t.asset_id = a.id) tickets,
        (SELECT MAX(t.created_at) FROM it_repair_tickets t WHERE t.asset_id = a.id) last_ticket
    FROM it_assets a LEFT JOIN it_labs l ON l.id = a.lab_id WHERE a.organization_id = ? ORDER BY l.lab_name, a.workstation_no, a.asset_tag");
$q->execute([$orgId]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="it-assets-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['Asset Tag', 'Lab', 'Workstation', 'प्रकार', 'Brand', 'Model', 'Serial', 'खरेदी', 'किंमत (₹)', 'Warranty पर्यंत', 'OS / License', 'License पर्यंत', 'स्थिती', 'दुरुस्ती-Tickets', 'शेवटचं Ticket', 'नोंद']);
foreach ($q->fetchAll() as $a) {
    fputcsv($out, [$a['asset_tag'], $a['lab_name'], $a['workstation_no'], $a['asset_type'], $a['brand'], $a['model'], $a['serial_no'], $a['purchase_date'], $a['purchase_cost'],
        $a['warranty_expiry'], $a['os_license'], $a['license_expiry'], $a['status'], $a['tickets'], $a['last_ticket'], $a['remarks']]);
}
fclose($out);
