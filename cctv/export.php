<?php
// 📊 CCTV रोजच्या तपासण्या — Excel (कालावधी: ?from=&to=, default हा महिना)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$q = $pdo->prepare("SELECT c.*, d.dvr_name, d.location, u.name AS checked_by_name,
        (SELECT COUNT(*) FROM cctv_camera_status s WHERE s.checkup_id = c.id AND s.status = 'working') w,
        (SELECT COUNT(*) FROM cctv_camera_status s WHERE s.checkup_id = c.id AND s.status = 'not_working') nw,
        (SELECT COUNT(*) FROM cctv_camera_status s WHERE s.checkup_id = c.id AND s.status = 'needs_attention') na
    FROM cctv_checkups c JOIN cctv_dvrs d ON d.id = c.dvr_id LEFT JOIN users u ON u.id = c.checked_by
    WHERE c.organization_id = ? AND c.checkup_date BETWEEN ? AND ? ORDER BY c.checkup_date DESC, d.dvr_name");
$q->execute([$orgId, $from, $to]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="cctv-checkups-' . $from . '-to-' . $to . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['दिनांक', 'DVR', 'ठिकाण', 'Storage %', 'Footage (दिवस)', 'Backup', 'Backup ठिकाण', 'चालू Camera', 'बंद Camera', 'लक्ष हवं', 'तपासणारे', 'नोंद']);
foreach ($q->fetchAll() as $c) {
    fputcsv($out, [$c['checkup_date'], $c['dvr_name'], $c['location'], $c['storage_used_percent'], $c['footage_days_available'], $c['backup_done'] ? 'हो' : 'नाही', $c['backup_location'], $c['w'], $c['nw'], $c['na'], $c['checked_by_name'], $c['remarks']]);
}
fclose($out);
