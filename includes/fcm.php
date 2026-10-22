<?php
/**
 * Firebase Cloud Messaging (HTTP v1) — Android App ला Push पाठवण्यासाठी.
 * Setup: Firebase Console → Project Settings → Service accounts → "Generate new private key"
 * ती JSON फाइल config/firebase-service-account.json म्हणून ठेवा. (config/.htaccess ने Browser access बंद आहे.)
 * फाइल नसेल तर सर्व काही शांतपणे वगळले जाते.
 */

function fcmServiceAccount() {
    static $cfg = false;
    if ($cfg !== false) { return $cfg; }
    $path = __DIR__ . '/../config/firebase-service-account.json';
    $cfg = null;
    if (is_readable($path)) {
        $j = json_decode(file_get_contents($path), true);
        if (!empty($j['client_email']) && !empty($j['private_key']) && !empty($j['project_id'])) { $cfg = $j; }
    }
    return $cfg;
}

function fcmB64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function fcmAccessToken($sa) {
    $cache = sys_get_temp_dir() . '/eowms_fcm_' . md5($sa['client_email']) . '.json';
    if (is_readable($cache)) {
        $c = json_decode(file_get_contents($cache), true);
        if (!empty($c['token']) && ($c['exp'] ?? 0) > time() + 120) { return $c['token']; }
    }
    $now = time();
    $head = fcmB64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim = fcmB64(json_encode([
        'iss' => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => 'https://oauth2.googleapis.com/token',
        'iat' => $now, 'exp' => $now + 3600,
    ]));
    $sig = '';
    if (!openssl_sign("$head.$claim", $sig, $sa['private_key'], 'sha256WithRSAEncryption')) { return null; }
    $jwt = "$head.$claim." . fcmB64($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8,
        CURLOPT_POSTFIELDS => http_build_query(['grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt]),
    ]);
    $res = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    if (empty($res['access_token'])) { return null; }
    @file_put_contents($cache, json_encode(['token' => $res['access_token'], 'exp' => $now + (int)($res['expires_in'] ?? 3000)]));
    return $res['access_token'];
}

/** User च्या सर्व Android devices ना Push पाठवतो. $link = 'letters/view.php?id=6' सारखा relative link. */
function sendFcmToUser($userId, $title, $body = '', $link = null) {
    global $pdo;
    try {
        $sa = fcmServiceAccount();
        if (!$sa) { return; }
        $st = $pdo->prepare("SELECT id, token FROM device_tokens WHERE user_id = ?");
        $st->execute([$userId]);
        $rows = $st->fetchAll();
        if (!$rows) { return; }
        $access = fcmAccessToken($sa);
        if (!$access) { return; }
        $url = 'https://fcm.googleapis.com/v1/projects/' . rawurlencode($sa['project_id']) . '/messages:send';
        foreach ($rows as $r) {
            $msg = ['message' => [
                'token' => $r['token'],
                'notification' => ['title' => mb_substr($title, 0, 120), 'body' => mb_substr((string)$body, 0, 300)],
                'data' => ['link' => (string)($link ?? '')],
                'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'eowms_default']],
            ]];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $access, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($msg, JSON_UNESCAPED_UNICODE),
            ]);
            $out = (string)curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            // जुना/अनइन्स्टॉल केलेला device — token काढून टाका
            if ($code === 404 || ($code === 400 && strpos($out, 'INVALID_ARGUMENT') !== false && strpos($out, 'registration token') !== false)) {
                $pdo->prepare("DELETE FROM device_tokens WHERE id = ?")->execute([$r['id']]);
            }
        }
    } catch (Throwable $e) {
        // Push कधीही मुख्य कामात अडथळा आणू नये
    }
}
