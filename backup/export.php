<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/backup-helpers.php';

$orgId = activeOrgId();
$backup = buildOrgBackup($pdo, $orgId);

$orgSlug = preg_replace('/[^a-zA-Z0-9]+/', '-', $backup['organization']['name'] ?? 'organization');
$filename = 'eowms-backup-' . trim($orgSlug, '-') . '-' . date('Y-m-d') . '.json';

header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
