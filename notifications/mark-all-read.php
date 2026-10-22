<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

try {
    $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")->execute([$user['id']]);
} catch (PDOException $e) {}

header('Location: ' . ($_SERVER['HTTP_REFERER'] ?? '../index.php'));
exit;
