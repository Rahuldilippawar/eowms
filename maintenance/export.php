<?php
// 📊 देखभाल नोंदी — Excel (?from=&to=, default हे आर्थिक वर्ष)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$dc = $pdo->prepare("SELECT has_issue_register FROM departments WHERE id = ?");
$dc->execute([$user['department_id'] ?? 0]);
if (!hasPermission('manage_maintenance_settings') && empty($dc->fetch()['has_issue_register'])) { http_response_code(403); die('परवानगी नाही.'); }
$fyStart = (int)date('n') >= 4 ? date('Y-04-01') : (date('Y') - 1) . '-04-01';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $fyStart;
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$q = $pdo->prepare("SELECT ml.*, loc.name loc_name, loc.location_code, c.name campus_name, COALESCE(d.name_local, d.name) dept_name, u.name performer
    FROM maintenance_logs ml JOIN maintenance_locations loc ON loc.id = ml.location_id LEFT JOIN campuses c ON c.id = loc.campus_id
    LEFT JOIN departments d ON d.id = ml.department_id LEFT JOIN users u ON u.id = ml.performed_by
    WHERE ml.organization_id = ? AND ml.work_date BETWEEN ? AND ? ORDER BY ml.work_date DESC, ml.id DESC");
$q->execute([$orgId, $from, $to]);
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="maintenance-' . $from . '-to-' . $to . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['दिनांक', 'जागा', 'जागा-कोड', 'College/School', 'वस्तू', 'काम', 'विभाग', 'खर्च (₹)', 'केलं', 'नोंद']);
foreach ($q->fetchAll() as $r) fputcsv($out, [$r['work_date'], $r['loc_name'], $r['location_code'], $r['campus_name'], $r['item_description'], $r['work_type'], $r['dept_name'], $r['cost'], $r['performer'], $r['remarks']]);
fclose($out);
