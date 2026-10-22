<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/webauthn.php';
requireLogin('');
$user = currentUser();

header('Content-Type: application/json');

$challenge = webauthn_generate_challenge();
$_SESSION['webauthn_register_challenge'] = base64_encode($challenge);

echo json_encode([
    'challenge' => base64url_encode($challenge),
    'rp' => ['name' => activeOrgName(), 'id' => webauthn_rp_id()],
    'user' => [
        'id' => base64url_encode((string)$user['id']),
        'name' => $user['email'],
        'displayName' => $user['name'],
    ],
    'pubKeyCredParams' => [
        ['type' => 'public-key', 'alg' => -7], // ES256 — जवळपास सगळे Fingerprint/Face ID Authenticators याच अल्गोरिदमवर चालतात
    ],
    'authenticatorSelection' => [
        'authenticatorAttachment' => 'platform', // फोन/लॅपटॉपचाच बिल्ट-इन Fingerprint/Face ID — बाह्य USB Key नाही
        'userVerification' => 'required',
    ],
    'timeout' => 60000,
    'attestation' => 'none',
]);
