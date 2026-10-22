<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$targetId = (int)($_POST['user_id'] ?? 0);
$canEdit = $targetId === (int)$user['id'] || hasRole('super_admin');
if (!$canEdit) { http_response_code(403); die('परवानगी नाही.'); }

$name = trim($_POST['emergency_contact_name'] ?? '');
$relation = trim($_POST['emergency_contact_relation'] ?? '');
$mobile = trim($_POST['emergency_contact_mobile'] ?? '');

$pdo->prepare("UPDATE users SET emergency_contact_name = ?, emergency_contact_relation = ?, emergency_contact_mobile = ? WHERE id = ? AND organization_id = ?")
    ->execute([$name ?: null, $relation ?: null, $mobile ?: null, $targetId, $orgId]);

logActivity('Emergency Contact Update केलं', 'user', $targetId);
$_SESSION['flash'] = 'Emergency Contact जतन झालं.';
header('Location: profile.php?id=' . $targetId);
exit;
