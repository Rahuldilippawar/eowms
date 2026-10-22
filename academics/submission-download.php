<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasRole('super_admin') || hasPermission('manage_academics');

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("
    SELECT sub.*, ca.classroom_id, vc.department_id FROM classroom_assignment_submissions sub
    JOIN classroom_assignments ca ON ca.id = sub.assignment_id JOIN virtual_classrooms vc ON vc.id = ca.classroom_id
    WHERE sub.id = ? AND vc.organization_id = ?
");
$stmt->execute([$id, $orgId]);
$submission = $stmt->fetch();
if (!$submission || !$submission['file_path']) { http_response_code(404); die('सापडलं नाही.'); }

$isHodOfDept = hasCategory('hod') && (int)($user['department_id'] ?? 0) === (int)$submission['department_id'];
$isAssignedTeacherStmt = $pdo->prepare("SELECT COUNT(*) c FROM classroom_staff WHERE classroom_id = ? AND user_id = ?");
$isAssignedTeacherStmt->execute([$submission['classroom_id'], $user['id']]);
$isAssignedTeacher = (int)$isAssignedTeacherStmt->fetch()['c'] > 0;

if (!$isMgmt && !$isHodOfDept && !$isAssignedTeacher) {
    http_response_code(403);
    die('परवानगी नाही.');
}

$filePath = __DIR__ . '/../' . $submission['file_path'];
if (!is_file($filePath)) { http_response_code(404); die('File Server वर सापडली नाही.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($submission['original_filename'] ?: $submission['file_path']) . '"');
header('Content-Length: ' . filesize($filePath));
csrfStopOutputRewrite();
readfile($filePath);
exit;
