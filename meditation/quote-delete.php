<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s636'));
}
$user = currentUser();

$id = (int)($_POST['id'] ?? 0);
$orgId = activeOrgId();

if ($id) {
    $stmt = $pdo->prepare("SELECT quote_text FROM meditation_quotes WHERE id = ? AND organization_id = ?");
    $stmt->execute([$id, $orgId]);
    $q = $stmt->fetch();
    if ($q) {
        $pdo->prepare("DELETE FROM meditation_quotes WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        logActivity('सुविचार डिलीट केला', 'meditation_quote', $id, $q['quote_text']);
        $_SESSION['flash'] = t('s638');
    }
}

header('Location: quotes.php');
exit;
