<?php
// 📊 कार्यक्रम — Excel (?year=YYYY, default हे वर्ष)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$orgId = activeOrgId();
$year = preg_match('/^\d{4}$/', $_GET['year'] ?? '') ? (int)$_GET['year'] : (int)date('Y');
$q = $pdo->prepare("SELECT e.*, c.name AS campus_name, u.name AS responsible_name FROM events e LEFT JOIN campuses c ON c.id = e.campus_id LEFT JOIN users u ON u.id = e.responsible_user_id
    WHERE e.organization_id = ? AND YEAR(e.event_date) = ? ORDER BY e.event_date, e.start_time");
$q->execute([$orgId, $year]);
$st = ['planned' => 'नियोजित', 'ongoing' => 'सुरू', 'completed' => 'पूर्ण', 'cancelled' => 'रद्द'];
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="events-' . $year . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['दिनांक', 'वेळ', 'कार्यक्रम', 'ठिकाण', 'College/School', 'जबाबदार', 'स्थिती', 'अंदाजे Budget (₹)', 'प्रत्यक्ष खर्च (₹)', 'फरक (₹)', 'वर्णन']);
foreach ($q->fetchAll() as $e) {
    $est = $e['budget_estimated'] !== null ? (float)$e['budget_estimated'] : null; $act = $e['budget_actual'] !== null ? (float)$e['budget_actual'] : null;
    fputcsv($out, [$e['event_date'], trim(($e['start_time'] ? substr($e['start_time'], 0, 5) : '') . ($e['end_time'] ? '–' . substr($e['end_time'], 0, 5) : '')), $e['name'], $e['venue'], $e['campus_name'],
        $e['responsible_name'], $st[$e['status']] ?? $e['status'], $est, $act, $est !== null && $act !== null ? $act - $est : '', $e['description']]);
}
fclose($out);
