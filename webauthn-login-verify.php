<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webauthn.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input || empty($_SESSION['webauthn_login_challenge']) || empty($_SESSION['webauthn_login_email'])) {
        throw new Exception('अवैध विनंती — पुन्हा प्रयत्न करा.');
    }

    $expectedChallenge = base64_decode($_SESSION['webauthn_login_challenge']);
    $email = $_SESSION['webauthn_login_email'];
    unset($_SESSION['webauthn_login_challenge'], $_SESSION['webauthn_login_email']); // एकदाच वापरण्यायोग्य

    $credentialId = base64url_encode(base64url_decode($input['id'])); // normalize
    $clientDataJSON = base64url_decode($input['clientDataJSON']);
    $authenticatorData = base64url_decode($input['authenticatorData']);
    $signature = base64url_decode($input['signature']);

    if (!webauthn_verify_client_data($clientDataJSON, $expectedChallenge, 'webauthn.get', webauthn_current_origin())) {
        throw new Exception('पडताळणी अयशस्वी — Challenge किंवा Origin जुळत नाही.');
    }

    // ही credential आपल्या नोंदीत आहे का, आणि ती त्याच email च्या खात्याची आहे का
    $credStmt = $pdo->prepare("
        SELECT wc.id AS webauthn_cred_id, wc.sign_count, wc.public_key_pem, wc.device_label, u.*
        FROM webauthn_credentials wc
        JOIN users u ON u.id = wc.user_id
        WHERE wc.credential_id = ? AND u.email = ? AND u.is_active = 1
    ");
    $credStmt->execute([$credentialId, $email]);
    $cred = $credStmt->fetch();
    if (!$cred) { throw new Exception('ही Fingerprint नोंद सापडली नाही.'); }

    // rpIdHash तपासा — बरोबर वेबसाईटसाठीच ही स्वाक्षरी आहे याची खात्री
    $parsedAuthData = webauthn_parse_auth_data($authenticatorData);
    $expectedRpIdHash = hash('sha256', webauthn_rp_id(), true);
    if (!hash_equals($expectedRpIdHash, $parsedAuthData['rpIdHash'])) {
        throw new Exception('चुकीची Website — Relying Party जुळत नाही.');
    }

    // User Present (bit 0) व User Verified (bit 2) दोन्ही असायलाच हवे —
    // म्हणजे प्रत्यक्ष फिंगरप्रिंट/Face ID ने पडताळणी झाली आहे, नुसतं बटण दाबून नाही
    if (!($parsedAuthData['flags'] & 0x01) || !($parsedAuthData['flags'] & 0x04)) {
        throw new Exception('Fingerprint/Face ID पडताळणी पूर्ण झाली नाही.');
    }

    // स्वाक्षरी पडताळा: signature = Sign(authenticatorData || SHA256(clientDataJSON))
    $signedData = $authenticatorData . hash('sha256', $clientDataJSON, true);
    $publicKey = openssl_pkey_get_public($cred['public_key_pem']);
    if (!$publicKey) { throw new Exception('साठवलेली Public Key वाचता आली नाही.'); }

    $verifyResult = openssl_verify($signedData, $signature, $publicKey, OPENSSL_ALGO_SHA256);
    if ($verifyResult !== 1) {
        throw new Exception('स्वाक्षरी जुळत नाही — पडताळणी अयशस्वी.');
    }

    // Replay-attack संरक्षण — काही Authenticators counter नेहमी 0 ठेवतात,
    // त्यामुळे दोन्ही 0 असतील तर ही तपासणी सौम्यपणे वगळली जाते.
    $newSignCount = $parsedAuthData['signCount'];
    if (!($cred['sign_count'] == 0 && $newSignCount == 0) && $newSignCount <= $cred['sign_count']) {
        throw new Exception('सुरक्षा तपासणी अयशस्वी (Counter) — कृपया Fingerprint पुन्हा नोंदवा.');
    }

    $pdo->prepare("UPDATE webauthn_credentials SET sign_count = ?, last_used_at = NOW() WHERE id = ?")
        ->execute([$newSignCount, $cred['webauthn_cred_id']]);

    // ---- लॉगिन यशस्वी — login.php प्रमाणेच session तयार करा ----
    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE id = ?")->execute([$cred['id']]);
    session_regenerate_id(true);

    $userRow = $cred;
    unset($userRow['password'], $userRow['webauthn_cred_id'], $userRow['sign_count'], $userRow['public_key_pem'], $userRow['device_label']);

    $mustChange = !empty($userRow['must_change_password']);
    $_SESSION['user'] = $userRow;
    $_SESSION['last_activity'] = time();
    $_SESSION['play_welcome_chime'] = true;

    logActivity('Fingerprint/Face ID ने Login केलं', 'user', $cred['id'], $cred['device_label'] ?? '');

    echo json_encode(['success' => true, 'redirect' => $mustChange ? 'force-password-change.php' : 'index.php']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
