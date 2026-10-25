<?php
// कामाचा अहवाल — Excel (CSV): व्यक्तिगत / विभाग / संस्था, वापरलेल्या साहित्यासह
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/report-common.php';
$user = currentUser();

// Scope-परवानगी + विभाग याच संस्थेचा आहे का — हे सगळं इथे तपासलं जातं
$ctx = dwReportContext($pdo, $user);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="daily-work-report-' . $ctx['scope'] . '-' . $ctx['period'] . '-' . date('Y-m-d') . '.csv"');
echo "\xEF\xBB\xBF"; // UTF-8 BOM — Excel मध्ये मराठी बरोबर दिसण्यासाठी

$out = fopen('php://output', 'w');
if ($ctx['scope'] === 'organization' && !$ctx['department_id'] && ($_GET['mode'] ?? '') === 'summary') {
    fputcsv($out, ['विभाग', 'College/School', 'HOD', 'एकूण कामे', 'पूर्ण', 'पूर्णता दर']);
    foreach (dwReportOrgSummary($pdo, $ctx) as $r) {
        fputcsv($out, [$r['name'], $r['campus_name'] ?: 'Trust-स्तरीय', $r['hod_name'], $r['total'], $r['done'], $r['pct'] . '%']);
    }
} else {
    fputcsv($out, ['दिनांक', 'विभाग', 'कर्मचारी', 'काम', 'तपशील', 'विनंती करणारा', 'प्राधान्य', 'खर्च (अंदाजित)', 'खर्च (प्रत्यक्ष)', 'वापरलेलं साहित्य', 'स्थिती']);
    foreach (dwReportRecords($pdo, $ctx, $user) as $r) {
        fputcsv($out, [
            date('d-m-Y', strtotime($r['work_date'])), $r['dept_name'] ?: $r['dept_name_en'], $r['emp_name'], $r['title'], $r['description'] ?? '',
            $r['requested_by'] ?? '', $r['priority'], $r['estimated_cost'] ?? '', $r['actual_cost'] ?? '', $r['materials_used'] ?? '', $r['status'],
        ]);
    }
}
fclose($out);
exit;
