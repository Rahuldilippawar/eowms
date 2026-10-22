<?php
// Android App साठी FCM Device Token नोंदणी / रद्द करणे.
// App, WebView चे Session Cookie वापरून हे बोलावतो (म्हणजे User login असणे आवश्यक).
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['success' => false, 'error' => 'login आवश्यक']); exit; }

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $input['action'] ?? 'register';
$token = trim((string)($input['token'] ?? ''));
if ($token === '' || strlen($token) > 255) { echo json_encode(['success' => false, 'error' => 'अवैध token']); exit; }

try {
    if ($action === 'unregister') {
        $pdo->prepare("DELETE FROM device_tokens WHERE token = ? AND user_id = ?")->execute([$token, $user['id']]);
    } else {
        $ver = substr((string)($input['app_version'] ?? ''), 0, 20);
        // एकच Device दुसऱ्या User ने login केल्यास token त्या User कडे वर्ग होतो.
        $pdo->prepare("
            INSERT INTO device_tokens (user_id, token, platform, app_version) VALUES (?, ?, 'android', ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), app_version = VALUES(app_version), last_seen = CURRENT_TIMESTAMP
        ")->execute([$user['id'], $token, $ver]);
    }
    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database अजून तयार नाही']);
}
exit;
