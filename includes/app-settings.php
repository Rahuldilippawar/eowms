<?php
/**
 * ⚙️ संस्था-स्तरीय सेटिंग्ज (org_settings Table) + गुप्त किल्ली + Encryption.
 * 2-Step Login, साप्ताहिक अहवाल, दुसऱ्या ठिकाणी Backup — यांच्या सेटिंग्ज इथे.
 */

/** App ची गुप्त किल्ली — config/app-secret.php (Git/Backup/DB मध्ये नाही). नसेल तर तयार करतो. */
function appSecret() {
    static $secret = null;
    if ($secret !== null) { return $secret; }
    $file = __DIR__ . '/../config/app-secret.php';
    $read = function () use ($file) {
        if (!is_readable($file)) { return null; }
        $s = include $file;
        return (is_string($s) && strlen($s) >= 32) ? $s : null;
    };
    if ($s = $read()) { return $secret = $s; }
    $new = bin2hex(random_bytes(32));
    $fh = @fopen($file, 'x'); // एकाच वेळी दोन Requests — एकच File तयार होते
    if ($fh) {
        fwrite($fh, "<?php\n// आपोआप तयार झालेली गुप्त किल्ली (गुप्त तक्रारींची ओळख) — बदलू/Delete करू नका, Git मध्ये टाकू नका.\nreturn '$new';\n");
        fclose($fh);
        return $secret = $new;
    }
    if ($s = $read()) { return $secret = $s; }
    // config/ लिहिता येत नाही — तरीही DB बाहेरची स्थिर किल्ली
    return $secret = hash('sha256', 'eowms-app-secret|' . ($GLOBALS['DB_USER'] ?? '') . '|' . ($GLOBALS['DB_PASS'] ?? '') . '|' . php_uname('n'));
}

/** लहान गुप्त मजकूर (उदा. Authenticator Key, Backup Password) DB मध्ये ठेवण्याआधी Encrypt */
function secretEncrypt($plain) {
    $key = hash('sha256', 'secret-box|' . appSecret(), true);
    $iv = random_bytes(12);
    $ct = openssl_encrypt((string)$plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'v1:' . base64_encode($iv . $tag . $ct);
}

/** secretEncrypt() चं उलट — चुकीची किल्ली/बिघडलेला मजकूर असेल तर null */
function secretDecrypt($stored) {
    if (!is_string($stored) || !str_starts_with($stored, 'v1:')) { return null; }
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) { return null; }
    $key = hash('sha256', 'secret-box|' . appSecret(), true);
    $out = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $out === false ? null : $out;
}

/** org_settings मधून एक सेटिंग — Table नसेल (Database Update बाकी) तर $default */
function orgSetting(PDO $pdo, $orgId, $key, $default = null) {
    static $cache = [];
    $ck = (int)$orgId . '|' . $key;
    if (array_key_exists($ck, $cache)) { return $cache[$ck] ?? $default; }
    try {
        $st = $pdo->prepare("SELECT setting_value FROM org_settings WHERE organization_id = ? AND setting_key = ?");
        $st->execute([(int)$orgId, $key]);
        $v = $st->fetchColumn();
        $cache[$ck] = $v === false ? null : $v;
    } catch (PDOException $e) {
        $cache[$ck] = null;
    }
    return $cache[$ck] ?? $default;
}

function orgSettingSet(PDO $pdo, $orgId, $key, $value) {
    $pdo->prepare("INSERT INTO org_settings (organization_id, setting_key, setting_value) VALUES (?, ?, ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([(int)$orgId, $key, $value === null ? null : (string)$value]);
}

/** org_settings Table आहे का (Database Update झालं का) */
function orgSettingsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { $pdo->query("SELECT 1 FROM org_settings LIMIT 1"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** स्वल्पविराम/ओळींनी वेगळे केलेले Email पत्ते → वैध, वेगळे पत्ते */
function parseEmailList($text) {
    $out = [];
    foreach (preg_split('/[\s,;]+/', (string)$text) as $e) {
        $e = strtolower(trim($e));
        if ($e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL)) { $out[$e] = $e; }
    }
    return array_values($out);
}

/** Site चा पूर्ण पत्ता (Cron मधून Email Links साठी) */
function siteBaseUrl() {
    if (defined('SITE_BASE_URL') && SITE_BASE_URL) { return rtrim(SITE_BASE_URL, '/') . '/'; }
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        return ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . '/';
    }
    return '';
}
