<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$id = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM scheduled_backups WHERE id = ? AND organization_id = ? AND status = 'success'");
$stmt->execute([$id, $orgId]);
$backup = $stmt->fetch();
if (!$backup) { die('Backup सापडलं नाही.'); }

$filePath = __DIR__ . '/../backups/auto/' . $backup['filename'];
if (!is_file($filePath)) { die('Backup file सापडली नाही — कदाचित Retention मुळे आधीच काढली गेली असेल.'); }

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . basename($backup['filename']) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
