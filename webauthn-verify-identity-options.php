<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
$user = currentUser();
require_once __DIR__ . '/includes/webauthn.php';

header('Content-Type: application/json');

$credStmt = $pdo->prepare("SELECT credential_id FROM webauthn_credentials WHERE user_id = ?");
$credStmt->execute([$user['id']]);
$credentials = $credStmt->fetchAll();

if (empty($credentials)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'तुम्ही अजून Fingerprint/Face ID सुरू केलेलं नाही.']);
    exit;
}

$challenge = webauthn_generate_challenge();
$_SESSION['webauthn_identity_challenge'] = base64_encode($challenge);

echo json_encode([
    'challenge' => base64url_encode($challenge),
    'rpId' => webauthn_rp_id(),
    'timeout' => 60000,
    'userVerification' => 'required',
    'allowCredentials' => array_map(fn($c) => ['type' => 'public-key', 'id' => $c['credential_id']], $credentials),
]);
