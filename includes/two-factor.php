<?php
/**
 * 🔐 2-Step Login (Two-Factor Authentication)
 *  - Authenticator App (Google Authenticator / Microsoft Authenticator इ.) — TOTP, RFC 6238
 *  - किंवा Email वर येणारा 6 अंकी Code
 *  - 10 Recovery Codes (फोन हरवला तर) — प्रत्येक एकदाच वापरता येतो
 *  - "हा Device लक्षात ठेवा" — 30 दिवस त्या Browser वर पुन्हा Code विचारत नाही
 * Super Admin साठी अनिवार्य; Management/HOD साठी Super Admin अनिवार्य करू शकतो.
 */
require_once __DIR__ . '/app-settings.php';

const TFA_TRUST_DAYS = 30;
const TFA_TRUST_COOKIE = 'eowms_tfa_dev';
const TFA_EMAIL_CODE_TTL = 600; // 10 मिनिटं

// ---------- Base32 + TOTP ----------
function tfaBase32Encode($bin) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split($bin) as $c) { $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) { $out .= $alpha[bindec(str_pad($chunk, 5, '0'))]; }
    return $out;
}

function tfaBase32Decode($b32) {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
    $bits = '';
    foreach (str_split($b32) as $c) { $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT); }
    $out = '';
    foreach (str_split($bits, 8) as $byte) { if (strlen($byte) === 8) { $out .= chr(bindec($byte)); } }
    return $out;
}

function tfaNewSecret() { return tfaBase32Encode(random_bytes(20)); }

/** एका 30-सेकंदाच्या टप्प्याचा 6 अंकी Code */
function tfaTotpAt($secretB32, $step) {
    $hash = hash_hmac('sha1', pack('J', $step), tfaBase32Decode($secretB32), true);
    $o = ord($hash[19]) & 0x0f;
    $num = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
    return str_pad((string)($num % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * Code तपासतो (फोनचं घड्याळ थोडं पुढे-मागे असेल म्हणून ±1 टप्पा).
 * जुळला तर तो टप्पा परत करतो — तोच Code पुन्हा वापरता येऊ नये म्हणून $lastStep पेक्षा मोठा हवा.
 */
function tfaTotpVerify($secretB32, $code, $lastStep = null) {
    $code = preg_replace('/\D/', '', (string)$code);
    if (strlen($code) !== 6) { return false; }
    $now = intdiv(time(), 30);
    for ($i = -1; $i <= 1; $i++) {
        $step = $now + $i;
        if ($lastStep !== null && $step <= (int)$lastStep) { continue; }
        if (hash_equals(tfaTotpAt($secretB32, $step), $code)) { return $step; }
    }
    return false;
}

function tfaOtpauthUri($secretB32, $accountEmail, $issuer) {
    return 'otpauth://totp/' . rawurlencode($issuer . ':' . $accountEmail)
        . '?secret=' . $secretB32 . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
}

/** Authenticator App साठी QR (SVG) */
function tfaQrSvg($text) {
    require_once __DIR__ . '/../lib/vendor/autoload.php';
    $opts = new \chillerlan\QRCode\QROptions([
        'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
        'outputBase64' => false,
        'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
        'addQuietzone' => true,
        'svgViewBoxSize' => null,
        'drawLightModules' => false,
    ]);
    return (new \chillerlan\QRCode\QRCode($opts))->render($text);
}

// ---------- Recovery Codes ----------
function tfaRecoveryHash($code) {
    return hash_hmac('sha256', 'tfa-recovery|' . strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)), appSecret());
}

/** 10 नवीन Codes — [दाखवायचे Codes, DB मध्ये ठेवायचा JSON] */
function tfaNewRecoveryCodes() {
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // गोंधळ होणारी अक्षरं (0/O, 1/I) नाहीत
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $c = '';
        for ($j = 0; $j < 8; $j++) { $c .= $alpha[random_int(0, strlen($alpha) - 1)]; }
        $codes[] = substr($c, 0, 4) . '-' . substr($c, 4);
    }
    return [$codes, json_encode(array_map('tfaRecoveryHash', $codes))];
}

/** Recovery Code वापरतो — जुळला तर उरलेल्या Codes चा JSON (नाहीतर false) */
function tfaUseRecoveryCode($storedJson, $code) {
    $list = json_decode((string)$storedJson, true) ?: [];
    $h = tfaRecoveryHash($code);
    foreach ($list as $i => $x) {
        if (hash_equals($x, $h)) { unset($list[$i]); return json_encode(array_values($list)); }
    }
    return false;
}

function tfaRecoveryLeft($storedJson) { return count(json_decode((string)$storedJson, true) ?: []); }

// ---------- स्थिती / धोरण ----------
/** users Table मध्ये 2-Step चे Columns आहेत का (Database Update झालं का) */
function tfaReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { $pdo->query("SELECT tfa_method FROM users LIMIT 1"); $pdo->query("SELECT 1 FROM user_trusted_devices LIMIT 1"); return $ok = true; }
    catch (PDOException $e) { return $ok = false; }
}

function tfaEnabled(array $user) { return !empty($user['tfa_method']) && in_array($user['tfa_method'], ['totp', 'email'], true); }

/** या व्यक्तीसाठी 2-Step अनिवार्य आहे का — Super Admin नेहमी; इतर Super Admin च्या सेटिंगनुसार */
function tfaRequiredFor(PDO $pdo, array $user) {
    if (($user['role'] ?? '') === 'super_admin') { return true; }
    $cats = array_filter(explode(',', (string)orgSetting($pdo, $user['organization_id'] ?? 0, 'tfa_required_categories', '')));
    if (!$cats) { return false; }
    $cat = function_exists('roleCategory') ? roleCategory($user['role'] ?? '') : null;
    return $cat && in_array($cat, $cats, true);
}

// ---------- Email Code ----------
function tfaSendEmailCode(array $user, $orgName) {
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['tfa_email'] = ['uid' => (int)$user['id'], 'hash' => hash_hmac('sha256', $code, appSecret()), 'exp' => time() + TFA_EMAIL_CODE_TTL, 'sent' => time()];
    require_once __DIR__ . '/mailer.php';
    $name = htmlspecialchars($user['name'] ?? '');
    $org = htmlspecialchars($orgName);
    $body = "<div style='font-family:Arial,sans-serif;max-width:520px;margin:0 auto;padding:24px;background:#F3F4F7;'>
      <div style='background:#191C3A;padding:18px 24px;border-radius:10px 10px 0 0;'><div style='color:#fff;font-size:18px;font-weight:bold;'>$org</div><div style='color:#C9CCE0;font-size:12.5px;'>2-Step Login — Verification Code</div></div>
      <div style='background:#fff;padding:24px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;'>नमस्कार <strong>$name</strong>,</p>
        <p style='font-size:14px;color:#1A1D29;line-height:1.6;'>तुमचा Login पूर्ण करण्यासाठी हा Code वापरा:</p>
        <div style='background:#FCE9DE;border-radius:8px;padding:16px;margin:16px 0;text-align:center;font-size:32px;font-weight:bold;letter-spacing:8px;color:#B6420A;'>$code</div>
        <p style='font-size:12.5px;color:#666B7D;'>हा Code 10 मिनिटं वैध आहे. तुम्ही Login करत नसाल तर तुमचा पासवर्ड कोणालातरी माहीत आहे — लगेच पासवर्ड बदला.</p>
        <p style='font-size:11.5px;color:#8A90AC;margin-top:18px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
      </div></div>";
    return eowmsSendMail($user['email'] ?? '', '🔐 Login Code: ' . $code, $body, [], $orgName);
}

function tfaCheckEmailCode($uid, $code) {
    $s = $_SESSION['tfa_email'] ?? null;
    $code = preg_replace('/\D/', '', (string)$code);
    if (!$s || (int)$s['uid'] !== (int)$uid || time() > $s['exp'] || strlen($code) !== 6) { return false; }
    if (!hash_equals($s['hash'], hash_hmac('sha256', $code, appSecret()))) { return false; }
    unset($_SESSION['tfa_email']);
    return true;
}

// ---------- विश्वासू Device (30 दिवस) ----------
function tfaTrustDevice(PDO $pdo, $userId) {
    $token = bin2hex(random_bytes(32));
    $ua = mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);
    $pdo->prepare("INSERT INTO user_trusted_devices (user_id, token_hash, label, ip, expires_at) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL " . TFA_TRUST_DAYS . " DAY))")
        ->execute([(int)$userId, hash('sha256', $token), tfaDeviceLabel($ua), function_exists('clientIp') ? clientIp() : '']);
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(TFA_TRUST_COOKIE, $userId . '.' . $token, ['expires' => time() + TFA_TRUST_DAYS * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

/** हा Browser या व्यक्तीसाठी विश्वासू आहे का */
function tfaDeviceTrusted(PDO $pdo, $userId) {
    $c = $_COOKIE[TFA_TRUST_COOKIE] ?? '';
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $c, $m) || (int)$m[1] !== (int)$userId) { return false; }
    try {
        $st = $pdo->prepare("SELECT id FROM user_trusted_devices WHERE user_id = ? AND token_hash = ? AND expires_at > NOW()");
        $st->execute([(int)$userId, hash('sha256', $m[2])]);
        $id = $st->fetchColumn();
        if ($id) { $pdo->prepare("UPDATE user_trusted_devices SET last_used_at = NOW() WHERE id = ?")->execute([$id]); }
        return (bool)$id;
    } catch (PDOException $e) { return false; }
}

function tfaDeviceLabel($ua) {
    $os = preg_match('/Android/i', $ua) ? 'Android' : (preg_match('/iPhone|iPad/i', $ua) ? 'iPhone/iPad' : (preg_match('/Windows/i', $ua) ? 'Windows' : (preg_match('/Mac OS/i', $ua) ? 'Mac' : (preg_match('/Linux/i', $ua) ? 'Linux' : 'Device'))));
    $br = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
    return "$br · $os";
}

/** 2-Step बंद / Reset — Secret, Recovery Codes आणि सगळे विश्वासू Devices काढतो */
function tfaDisable(PDO $pdo, $userId) {
    $pdo->prepare("UPDATE users SET tfa_method = NULL, tfa_secret = NULL, tfa_recovery = NULL, tfa_last_step = NULL, tfa_enabled_at = NULL WHERE id = ?")->execute([(int)$userId]);
    $pdo->prepare("DELETE FROM user_trusted_devices WHERE user_id = ?")->execute([(int)$userId]);
}

/** Session मध्ये ठेवू नयेत असे गुप्त Columns */
function tfaStripSecrets(array $user) {
    unset($user['password'], $user['tfa_secret'], $user['tfa_recovery'], $user['tfa_last_step']);
    return $user;
}

/**
 * पासवर्ड (आणि लागल्यास 2-Step) बरोबर झाल्यावर — Session तयार करून योग्य पानावर पाठवतो.
 * login.php आणि login-2fa.php दोघेही हेच वापरतात. $rootPath = App च्या मुळाकडे.
 */
function eowmsCompleteLogin(PDO $pdo, array $user, $how, $rootPath = '') {
    $pdo->prepare("UPDATE users SET failed_login_attempts = 0, locked_until = NULL, last_login_at = NOW() WHERE id = ?")->execute([$user['id']]);
    session_regenerate_id(true); // prevents session-fixation attacks
    unset($_SESSION['tfa_pending'], $_SESSION['tfa_email']);
    $mustChange = !empty($user['must_change_password']);
    $needTfaSetup = tfaReady($pdo) && !tfaEnabled($user) && tfaRequiredFor($pdo, $user);
    $_SESSION['user'] = tfaStripSecrets($user);
    $_SESSION['last_activity'] = time();
    $_SESSION['user_recheck_at'] = time();
    $_SESSION['play_welcome_chime'] = true;
    // संस्थेने ठरवलेली "निष्क्रियतेनंतर Logout" वेळ (मिनिटं)
    $idle = (int)orgSetting($pdo, $user['organization_id'] ?? 0, 'session_idle_minutes', '30');
    $_SESSION['idle_limit_min'] = in_array($idle, SESSION_IDLE_CHOICES, true) ? $idle : 30;
    if ($needTfaSetup) { $_SESSION['tfa_setup_required'] = true; } else { unset($_SESSION['tfa_setup_required']); }
    logActivity('Login केलं (' . $how . ')', 'user', $user['id'], 'IP: ' . clientIp());

    // 🛠️ Site Maintenance Mode — Super Admin सोडून बाकी कोणालाही
    // Maintenance सुरू असताना नव्याने Login करता येणार नाही.
    if (($user['role'] ?? '') !== 'super_admin') {
        $mCheckStmt = $pdo->prepare("SELECT maintenance_mode FROM organizations WHERE id = ?");
        $mCheckStmt->execute([$user['organization_id']]);
        if ((int)($mCheckStmt->fetch()['maintenance_mode'] ?? 0) === 1) {
            header('Location: ' . $rootPath . 'maintenance.php');
            exit;
        }
    }

    $loginRedirect = 'index.php';
    if ($mustChange) {
        $loginRedirect = 'force-password-change.php';
    } elseif ($needTfaSetup) {
        $loginRedirect = 'account/two-factor.php';
    } elseif (!empty($_SESSION['login_next']) && ($nx = safeNextPath($_SESSION['login_next']))) {
        $loginRedirect = $nx; // Auto-Logout आधी ज्या पानावर होता तिथेच परत
    } else {
        $restrictedModulesAtLogin = getRestrictedModules();
        if ($restrictedModulesAtLogin !== null && !empty($restrictedModulesAtLogin)) {
            $registryAtLogin = getModuleRegistry();
            $firstModuleAtLogin = $registryAtLogin[$restrictedModulesAtLogin[0]] ?? null;
            if ($firstModuleAtLogin) { $loginRedirect = $firstModuleAtLogin['dashboard']; }
        }
    }
    unset($_SESSION['login_next']);
    header('Location: ' . $rootPath . $loginRedirect);
    exit;
}
