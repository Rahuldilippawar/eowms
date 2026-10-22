<?php
require_once __DIR__ . '/../includes/student-auth.php';
requireStudentLogin('');
$student = currentStudent();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM classroom_materials WHERE id = ?");
$stmt->execute([$id]);
$material = $stmt->fetch();

if (!$material || !studentHasClassroomAccess($pdo, $student['id'], $material['classroom_id'])) {
    http_response_code(403);
    die('तुम्हाला हे Material बघण्याची परवानगी नाही.');
}

$filePath = __DIR__ . '/../' . $material['file_path'];
if (!is_file($filePath)) { http_response_code(404); die('File Server वर सापडली नाही.'); }

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($material['original_filename'] ?: $material['file_path']) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
