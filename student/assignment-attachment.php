<?php
require_once __DIR__ . '/../includes/student-auth.php';
requireStudentLogin('');
$student = currentStudent();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM classroom_assignments WHERE id = ?");
$stmt->execute([$id]);
$assignment = $stmt->fetch();

if (!$assignment || !studentHasClassroomAccess($pdo, $student['id'], $assignment['classroom_id']) || !$assignment['attachment_path']) {
    http_response_code(403);
    die('तुम्हाला ही File बघण्याची परवानगी नाही.');
}

$filePath = __DIR__ . '/../' . $assignment['attachment_path'];
if (!is_file($filePath)) { http_response_code(404); die('File Server वर सापडली नाही.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($assignment['attachment_path']) . '"');
header('Content-Length: ' . filesize($filePath));
csrfStopOutputRewrite();
readfile($filePath);
exit;
