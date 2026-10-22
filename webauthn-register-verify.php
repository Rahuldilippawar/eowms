<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webauthn.php';
requireLogin('');
$user = currentUser();

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($_SESSION['webauthn_register_challenge'])) {
        throw new Exception('अवैध विनंती — पुन्हा प्रयत्न करा.');
    }

    $expectedChallenge = base64_decode($_SESSION['webauthn_register_challenge']);
    unset($_SESSION['webauthn_register_challenge']); // एकदाच वापरण्यायोग्य

    $clientDataJSON = base64url_decode($input['clientDataJSON']);
    $attestationObject = base64url_decode($input['attestationObject']);
    $credentialId = base64url_decode($input['id']);

    if (!webauthn_verify_client_data($clientDataJSON, $expectedChallenge, 'webauthn.create', webauthn_current_origin())) {
        throw new Exception('पडताळणी अयशस्वी — Challenge किंवा Origin जुळत नाही.');
    }

    $attestation = cbor_decode($attestationObject);
    $authData = webauthn_parse_auth_data($attestation['authData']);

    if (!$authData['coseKey']) {
        throw new Exception('Authenticator कडून Public Key मिळाली नाही.');
    }

    $pem = webauthn_cose_key_to_pem($authData['coseKey']);
    $credIdB64 = base64url_encode($authData['credentialId']);
    $deviceLabel = trim($input['deviceLabel'] ?? '') ?: 'फोन/लॅपटॉप';

    // एकच credential दोनदा साठवली जाऊ नये
    $check = $pdo->prepare("SELECT id FROM webauthn_credentials WHERE credential_id = ?");
    $check->execute([$credIdB64]);
    if ($check->fetch()) {
        throw new Exception('हे डिव्हाइस आधीच नोंदवलेलं आहे.');
    }

    $pdo->prepare("INSERT INTO webauthn_credentials (user_id, credential_id, public_key_pem, sign_count, device_label) VALUES (?, ?, ?, ?, ?)")
        ->execute([$user['id'], $credIdB64, $pem, $authData['signCount'], $deviceLabel]);

    logActivity('Fingerprint/Face ID Login नोंदवलं', 'webauthn_credential', $pdo->lastInsertId(), $deviceLabel);
    echo json_encode(['success' => true, 'message' => 'Fingerprint/Face ID Login यशस्वीरित्या सुरू केलं!']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
