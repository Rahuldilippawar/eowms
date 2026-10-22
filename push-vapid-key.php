<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
header('Content-Type: application/json; charset=utf-8');

$orgId = activeOrgId();
$stmt = $pdo->prepare("SELECT vapid_public_key FROM organizations WHERE id = ?");
$stmt->execute([$orgId]);
$key = $stmt->fetch()['vapid_public_key'] ?? null;

echo json_encode(['publicKey' => $key]);
exit;
