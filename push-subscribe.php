<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['endpoint']) || empty($input['keys']['p256dh']) || empty($input['keys']['auth'])) {
    echo json_encode(['success' => false, 'error' => 'अपुरी माहिती']);
    exit;
}

// Browser Push Endpoint नेहमी https:// असतो — Server नंतर याच URL ला Request
// पाठवतो, त्यामुळे http://localhost सारखे अंतर्गत पत्ते (SSRF) नाकारतो.
if (!is_string($input['endpoint']) || stripos($input['endpoint'], 'https://') !== 0 || strlen($input['endpoint']) > 512) {
    echo json_encode(['success' => false, 'error' => 'अवैध endpoint']);
    exit;
}

try {
    $pdo->prepare("
        INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)
    ")->execute([$user['id'], $input['endpoint'], $input['keys']['p256dh'], $input['keys']['auth']]);
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database अजून तयार नाही']);
}
exit;
