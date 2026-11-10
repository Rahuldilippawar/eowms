<?php
// 📊 तक्रारी — Excel (फक्त हाताळणाऱ्यांना). गुप्त तक्रारीत तक्रारदाराचं नाव कधीच नाही.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'grievances', '../');
$orgId = activeOrgId();
if (!(hasPermission('manage_grievances') || isManagementOrAbove())) { http_response_code(403); die('परवानगी नाही.'); }
$q = $pdo->prepare("SELECT g.id, g.created_at, g.category, g.description, g.is_anonymous, g.status, g.sla_deadline, g.resolved_at, g.resolution,
        CASE WHEN g.is_anonymous = 1 THEN NULL ELSE u.name END AS submitter_name, a.name AS assigned_name
    FROM grievances g LEFT JOIN users u ON u.id = g.submitted_by LEFT JOIN users a ON a.id = g.assigned_to
    WHERE g.organization_id = ? ORDER BY g.created_at DESC");
$q->execute([$orgId]);
$labels = ['open' => 'नवीन', 'in_progress' => 'सुरू आहे', 'resolved' => 'सोडवलं', 'closed' => 'बंद'];
logActivity('तक्रारींचं Excel काढलं', 'grievances', null, '');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="grievances-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, ['क्रमांक', 'दिनांक', 'प्रकार', 'तक्रार', 'तक्रारदार', 'स्थिती', 'नेमलं', 'मुदत', 'सोडवली', 'लागलेले दिवस', 'मुदतीत?', 'उत्तर']);
foreach ($q->fetchAll() as $g) {
    $days = $g['resolved_at'] ? (int)floor((strtotime($g['resolved_at']) - strtotime($g['created_at'])) / 86400) : '';
    $inSla = $g['resolved_at'] && $g['sla_deadline'] ? (date('Y-m-d', strtotime($g['resolved_at'])) <= $g['sla_deadline'] ? 'हो' : 'नाही') : '';
    fputcsv($out, ['#' . $g['id'], date('Y-m-d', strtotime($g['created_at'])), $g['category'], $g['description'], $g['is_anonymous'] ? 'गुप्त' : $g['submitter_name'],
        $labels[$g['status']] ?? $g['status'], $g['assigned_name'], $g['sla_deadline'], $g['resolved_at'] ? date('Y-m-d', strtotime($g['resolved_at'])) : '', $days, $inSla, $g['resolution']]);
}
fclose($out);
