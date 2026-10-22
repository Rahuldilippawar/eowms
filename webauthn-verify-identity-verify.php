<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
$user = currentUser();
require_once __DIR__ . '/includes/webauthn.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($_SESSION['webauthn_identity_challenge'])) {
        throw new Exception('अवैध विनंती — पुन्हा प्रयत्न करा.');
    }

    $expectedChallenge = base64_decode($_SESSION['webauthn_identity_challenge']);
    unset($_SESSION['webauthn_identity_challenge']); // एकदाच वापरण्यायोग्य

    $credentialId = base64url_encode(base64url_decode($input['id']));
    $clientDataJSON = base64url_decode($input['clientDataJSON']);
    $authenticatorData = base64url_decode($input['authenticatorData']);
    $signature = base64url_decode($input['signature']);

    if (!webauthn_verify_client_data($clientDataJSON, $expectedChallenge, 'webauthn.get', webauthn_current_origin())) {
        throw new Exception('पडताळणी अयशस्वी — Challenge किंवा Origin जुळत नाही.');
    }

    // ही credential याच (सध्या Login असलेल्या) व्यक्तीची आहे का
    $credStmt = $pdo->prepare("SELECT * FROM webauthn_credentials WHERE credential_id = ? AND user_id = ?");
    $credStmt->execute([$credentialId, $user['id']]);
    $cred = $credStmt->fetch();
    if (!$cred) { throw new Exception('ही Fingerprint नोंद तुमच्या खात्याची नाही.'); }

    $parsedAuthData = webauthn_parse_auth_data($authenticatorData);
    $expectedRpIdHash = hash('sha256', webauthn_rp_id(), true);
    if (!hash_equals($expectedRpIdHash, $parsedAuthData['rpIdHash'])) {
        throw new Exception('चुकीची Website — Relying Party जुळत नाही.');
    }
    if (!($parsedAuthData['flags'] & 0x01) || !($parsedAuthData['flags'] & 0x04)) {
        throw new Exception('Fingerprint/Face ID पडताळणी पूर्ण झाली नाही.');
    }

    $signedData = $authenticatorData . hash('sha256', $clientDataJSON, true);
    $publicKey = openssl_pkey_get_public($cred['public_key_pem']);
    if (!$publicKey) { throw new Exception('साठवलेली Public Key वाचता आली नाही.'); }

    $verifyResult = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verifyResult !== 1) {
        throw new Exception('स्वाक्षरी जुळत नाही — पडताळणी अयशस्वी.');
    }

    $newSignCount = $parsedAuthData['signCount'];
    if (!($cred['sign_count'] == 0 && $newSignCount == 0) && $newSignCount <= $cred['sign_count']) {
        throw new Exception('सुरक्षा तपासणी अयशस्वी (Counter) — कृपया Fingerprint पुन्हा नोंदवा.');
    }
    $pdo->prepare("UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?")
        ->execute([$newSignCount, $cred['id']]);

    // पडताळणी यशस्वी — एक अल्पायुषी (5 मिनिटं) टोकन Session मध्ये ठेवतो,
    // जो Sign & Approve endpoint वापरून पडताळेल.
    $_SESSION['identity_verified_at'] = time();
    $_SESSION['identity_verified_method'] = 'fingerprint';

    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
