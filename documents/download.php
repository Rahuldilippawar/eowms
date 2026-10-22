<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM documents WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$doc = $stmt->fetch();
if (!$doc) { http_response_code(404); die('दस्तऐवज सापडला नाही.'); }

// Access तपासतो — सगळ्यांसाठी असेल, किंवा स्वतःच्याच विभागासाठी असेल,
// किंवा Org-wide Access असेल तरच Download करता येईल.
$hasAccess = empty($doc['department_id']) || (int)$doc['department_id'] === (int)($user['department_id'] ?? 0) || hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
if (!$hasAccess) {
    http_response_code(403);
    die('तुम्हाला हा दस्तऐवज Download करण्याची परवानगी नाही.');
}

$filePath = __DIR__ . '/../' . $doc['file_path'];
if (!is_file($filePath)) { http_response_code(404); die('File Server वर सापडली नाही.'); }

logActivity('दस्तऐवज Download केला', 'document', $id, $doc['title']);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . basename($doc['original_filename'] ?: $doc['file_path']) . '"');
header('Content-Length: ' . filesize($filePath));
readfile($filePath);
exit;
