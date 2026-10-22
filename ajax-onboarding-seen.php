<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
try {
    $pdo->prepare("UPDATE users SET onboarding_seen = 1 WHERE id = ?")->execute([$user['id']]);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false]);
}
exit;
