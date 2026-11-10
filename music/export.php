<?php
// 📊 संगीत विभाग — Excel: ?type=students (विद्यार्थी, प्रवेश, उपस्थिती) | instruments | exams
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$dc = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$dc->execute([$user['department_id'] ?? 0]);
if (($dc->fetch()['code'] ?? '') !== 'MUS' && !hasPermission('manage_music') && !isManagementOrAbove()) { http_response_code(403); die('परवानगी नाही.'); }
$orgId = activeOrgId();
$type = in_array($_GET['type'] ?? '', ['students', 'instruments', 'exams'], true) ? $_GET['type'] : 'students';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="music-' . $type . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
if ($type === 'instruments') {
    $q = $pdo->prepare("SELECT i.*, u.name holder FROM music_instruments i LEFT JOIN users u ON u.id = i.current_holder_id WHERE i.organization_id = ? ORDER BY i.is_active DESC, i.instrument_name");
    $q->execute([$orgId]);
    fputcsv($out, ['वाद्य', 'प्रकार', 'Brand / Model', 'खरेदी', 'किंमत (₹)', 'स्थिती', 'शेवटचं Tuning', 'सध्या कोणाकडे', 'सक्रिय', 'नोंद']);
    foreach ($q->fetchAll() as $r) fputcsv($out, [$r['instrument_name'], $r['instrument_type'], $r['brand_model'], $r['purchase_date'], $r['purchase_cost'], $r['condition_status'], $r['last_tuned_date'], $r['holder'] ?: $r['current_holder_name'], $r['is_active'] ? 'हो' : 'नाही', $r['remarks']]);
} elseif ($type === 'exams') {
    $q = $pdo->prepare("SELECT e.*, s.name student FROM music_exams e JOIN music_students s ON s.id = e.student_id WHERE s.organization_id = ? ORDER BY e.exam_date DESC");
    $q->execute([$orgId]);
    fputcsv($out, ['दिनांक', 'विद्यार्थी', 'Board', 'श्रेणी', 'विषय', 'निकाल', 'गुण']);
    foreach ($q->fetchAll() as $r) fputcsv($out, [$r['exam_date'], $r['student'], $r['exam_board'], $r['grade_level'], $r['subject'], $r['result'], $r['marks']]);
} else {
    $q = $pdo->prepare("SELECT s.*, GROUP_CONCAT(DISTINCT CONCAT(e.subject, IF(e.status <> 'active', CONCAT(' (', e.status, ')'), '')) SEPARATOR ', ') subjects,
            (SELECT SUM(ma.status = 'present') FROM music_attendance ma JOIN music_enrollments e2 ON e2.id = ma.enrollment_id WHERE e2.student_id = s.id AND ma.attendance_date >= CURDATE() - INTERVAL 29 DAY) p30,
            (SELECT COUNT(*) FROM music_attendance ma JOIN music_enrollments e2 ON e2.id = ma.enrollment_id WHERE e2.student_id = s.id AND ma.attendance_date >= CURDATE() - INTERVAL 29 DAY) n30
        FROM music_students s LEFT JOIN music_enrollments e ON e.student_id = s.id WHERE s.organization_id = ? GROUP BY s.id ORDER BY s.is_active DESC, s.name");
    $q->execute([$orgId]);
    fputcsv($out, ['विद्यार्थी', 'संपर्क', 'पालक', 'प्रवेश', 'विषय', 'उपस्थिती 30 दिवस (%)', 'सक्रिय']);
    foreach ($q->fetchAll() as $r) fputcsv($out, [$r['name'], $r['contact'], $r['guardian_name'], $r['admission_date'], $r['subjects'], (int)$r['n30'] ? (int)round($r['p30'] * 100 / $r['n30']) : '', $r['is_active'] ? 'हो' : 'नाही']);
}
fclose($out);
