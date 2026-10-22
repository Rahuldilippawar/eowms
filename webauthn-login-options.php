<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webauthn.php';

header('Content-Type: application/json');

$email = trim($_POST['email'] ?? $_GET['email'] ?? '');
if (!$email) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'कृपया आधी Email टाका.']);
    exit;
}

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND is_active = 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'हा Email सापडला नाही किंवा Fingerprint Login सुरू केलेलं नाही.']);
    exit;
}

$credStmt = $pdo->prepare("SELECT credential_id FROM webauthn_credentials WHERE user_id = ?");
$credStmt->execute([$user['id']]);
$credentials = $credStmt->fetchAll();

if (empty($credentials)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'या खात्यासाठी अजून Fingerprint/Face ID Login सुरू केलेलं नाही.']);
    exit;
}

$challenge = webauthn_generate_challenge();
$_SESSION['webauthn_login_challenge'] = base64_encode($challenge);
$_SESSION['webauthn_login_email'] = $email;

echo json_encode([
    'challenge' => base64url_encode($challenge),
    'rpId' => webauthn_rp_id(),
    'timeout' => 60000,
    'userVerification' => 'required',
    'allowCredentials' => array_map(fn($c) => ['type' => 'public-key', 'id' => $c['credential_id']], $credentials),
]);
