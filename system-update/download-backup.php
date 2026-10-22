<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

$filename = $_GET['file'] ?? '';

// सुरक्षा — फक्त "pre-update-" ने सुरू होणारं, अपेक्षित नमुन्याचंच नाव
// स्वीकारतो (../ सारखे Path-Traversal प्रयत्न पूर्णपणे नाकारले जातात).
if (!preg_match('/^pre-update-\d{4}-\d{2}-\d{2}_\d{6}\.zip$/', $filename)) {
    http_response_code(400);
    die('अवैध File नाव.');
}

$backupDir = realpath(__DIR__ . '/../backups/update-safety');
$filePath = $backupDir . '/' . $filename;

// realpath() ने पुन्हा एकदा खात्री — File खरंच याच Folder च्या आत आहे ना
if (!$backupDir || strpos(realpath($filePath) ?: '', $backupDir) !== 0 || !is_file($filePath)) {
    http_response_code(404);
    die('File सापडली नाही.');
}

logActivity('Safety Backup Download केला', 'system_update', null, $filename);

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . basename($filePath) . '"');
header('Content-Length: ' . filesize($filePath));
header('Cache-Control: no-store');
csrfStopOutputRewrite();
readfile($filePath);
exit;
