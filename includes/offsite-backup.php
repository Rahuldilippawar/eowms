<?php
/**
 * ☁️ Backup दुसऱ्या ठिकाणी — Email वर, Password ने Encrypt केलेली Backup File.
 * Hostinger Server ला काही झालं तरी Data Email Inbox मध्ये सुरक्षित राहतो.
 *
 * File Format (.eadmin): "EADMINBK1" + salt(16) + iv(12) + tag(16) + AES-256-GCM( gzip(JSON) )
 * Key = PBKDF2-SHA256(Password, salt, 200000). Password शिवाय File उघडत नाही —
 * Email चोरीला गेला तरी कर्मचाऱ्यांची माहिती (आधार, बँक खाते) वाचता येत नाही.
 * Restore: Backup → Restore मध्ये .eadmin File + Password, किंवा tools/decrypt-backup.php.
 */
require_once __DIR__ . '/app-settings.php';
require_once __DIR__ . '/backup-helpers.php';
require_once __DIR__ . '/mailer.php';

const OFFSITE_MAGIC = 'EADMINBK1';
const OFFSITE_KDF_ITER = 200000;
const OFFSITE_MAX_BYTES = 18 * 1024 * 1024; // बहुतेक Email सेवांची मर्यादा ~20-25 MB

function offsiteEncrypt($plain, $password) {
    $salt = random_bytes(16);
    $iv = random_bytes(12);
    $key = hash_pbkdf2('sha256', $password, $salt, OFFSITE_KDF_ITER, 32, true);
    $ct = openssl_encrypt(gzencode($plain, 9), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return OFFSITE_MAGIC . $salt . $iv . $tag . $ct;
}

/** .eadmin File उघडतो — चुकीचा Password / बिघडलेली File असेल तर null */
function offsiteDecrypt($blob, $password) {
    $m = strlen(OFFSITE_MAGIC);
    if (strlen($blob) < $m + 44 || substr($blob, 0, $m) !== OFFSITE_MAGIC) { return null; }
    $salt = substr($blob, $m, 16);
    $iv = substr($blob, $m + 16, 12);
    $tag = substr($blob, $m + 28, 16);
    $key = hash_pbkdf2('sha256', (string)$password, $salt, OFFSITE_KDF_ITER, 32, true);
    $gz = openssl_decrypt(substr($blob, $m + 44), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($gz === false) { return null; }
    $out = @gzdecode($gz);
    return $out === false ? null : $out;
}

function offsiteIsEncryptedFile($path) {
    $fh = @fopen($path, 'rb');
    if (!$fh) { return false; }
    $head = fread($fh, strlen(OFFSITE_MAGIC));
    fclose($fh);
    return $head === OFFSITE_MAGIC;
}

/** संस्थेची Offsite Backup सेटिंग्ज */
function offsiteSettings(PDO $pdo, $orgId) {
    return [
        'enabled' => orgSetting($pdo, $orgId, 'offsite_enabled', '0') === '1',
        'emails' => parseEmailList(orgSetting($pdo, $orgId, 'offsite_emails', '')),
        'frequency' => orgSetting($pdo, $orgId, 'offsite_frequency', 'weekly') === 'daily' ? 'daily' : 'weekly',
        'has_password' => (bool)secretDecrypt(orgSetting($pdo, $orgId, 'offsite_password', '')),
        'last_at' => orgSetting($pdo, $orgId, 'offsite_last_at'),
        'last_status' => orgSetting($pdo, $orgId, 'offsite_last_status'),
        'last_msg' => orgSetting($pdo, $orgId, 'offsite_last_msg'),
        'last_ok_at' => orgSetting($pdo, $orgId, 'offsite_last_ok_at'),
    ];
}

/** आज पाठवायचं आहे का (Cron साठी) */
function offsiteDue(array $s) {
    if (!$s['enabled'] || !$s['emails'] || !$s['has_password']) { return false; }
    if (!$s['last_at']) { return true; }
    $last = strtotime($s['last_at']);
    // अयशस्वी झालं असेल तर दुसऱ्या दिवशी पुन्हा प्रयत्न
    if ($s['frequency'] === 'daily' || $s['last_status'] !== 'success') { return date('Y-m-d', $last) < date('Y-m-d'); }
    return time() - $last >= 6.5 * 86400;
}

/**
 * Backup तयार करून Email करतो. परत: ['ok' => bool, 'msg' => string, 'size_kb' => int]
 */
function offsiteRun(PDO $pdo, $orgId) {
    $s = offsiteSettings($pdo, $orgId);
    $password = secretDecrypt(orgSetting($pdo, $orgId, 'offsite_password', ''));
    $org = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
    $org->execute([$orgId]);
    $orgName = $org->fetchColumn() ?: 'E-Admin';
    $result = ['ok' => false, 'msg' => '', 'size_kb' => 0];

    if (!$s['emails']) { $result['msg'] = 'Email पत्ता सेट केलेला नाही'; }
    elseif (!$password) { $result['msg'] = 'Backup Password सेट केलेला नाही (किंवा config/app-secret.php बदलल्यामुळे वाचता येत नाही — पुन्हा सेट करा)'; }
    else {
        try {
            $data = buildOrgBackup($pdo, $orgId);
            $records = 0;
            foreach ($data as $v) { if (is_array($v) && array_is_list($v)) { $records += count($v); } }
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            unset($data);
            if ($json === false) { throw new RuntimeException('JSON तयार झाला नाही: ' . json_last_error_msg()); }
            $blob = offsiteEncrypt($json, $password);
            $result['size_kb'] = (int)ceil(strlen($blob) / 1024);
            if (strlen($blob) > OFFSITE_MAX_BYTES) {
                $result['msg'] = 'Backup ' . round(strlen($blob) / 1048576, 1) . ' MB झाला — Email मर्यादेपेक्षा मोठा. Backup → Download वापरा.';
            } else {
                $slug = trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $orgName), '-') ?: 'org';
                $fname = 'e-admin-backup-' . $slug . '-' . date('Y-m-d') . '.eadmin';
                $sizeTxt = $result['size_kb'] >= 1024 ? round($result['size_kb'] / 1024, 1) . ' MB' : $result['size_kb'] . ' KB';
                $url = siteBaseUrl();
                $html = "<div style='font-family:Arial,sans-serif;max-width:560px;margin:0 auto;padding:24px;background:#F3F4F7;'>
                  <div style='background:#191C3A;padding:18px 24px;border-radius:10px 10px 0 0;'><div style='color:#fff;font-size:18px;font-weight:bold;'>☁️ " . htmlspecialchars($orgName) . "</div><div style='color:#C9CCE0;font-size:12.5px;'>E-Admin — सुरक्षित Backup प्रत</div></div>
                  <div style='background:#fff;padding:22px 24px;border-radius:0 0 10px 10px;font-size:14px;color:#1A1D29;line-height:1.7;'>
                    <p>नमस्कार,</p>
                    <p>संपूर्ण Data चा आजचा Backup सोबत जोडला आहे — <b>" . date('d M Y, h:i A') . "</b>.</p>
                    <table style='width:100%;border-collapse:collapse;font-size:13px;margin:10px 0;'>
                      <tr><td style='padding:6px 0;color:#666B7D;'>File</td><td style='text-align:right;'><b>$fname</b></td></tr>
                      <tr><td style='padding:6px 0;color:#666B7D;'>आकार</td><td style='text-align:right;'><b>$sizeTxt</b></td></tr>
                      <tr><td style='padding:6px 0;color:#666B7D;'>नोंदी</td><td style='text-align:right;'><b>" . number_format($records) . "</b></td></tr>
                    </table>
                    <div style='background:#FCE9DE;border-radius:8px;padding:12px 14px;font-size:13px;'>🔒 ही File <b>Password ने Encrypt</b> केलेली आहे. Password या Email मध्ये नाही — तो फक्त Super Admin ला माहीत आहे.</div>
                    <p style='font-size:13px;margin-top:14px;'><b>हा Email Delete करू नका.</b> Server बिघडला / Data गेला तर: E-Admin → Backup → Restore मध्ये ही File आणि Password देऊन संपूर्ण Data परत मिळतो.</p>
                    " . ($url ? "<a href='{$url}backup/offsite.php' style='display:inline-block;background:#D9530F;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:bold;'>Backup सेटिंग्ज</a>" : '') . "
                    <p style='font-size:11.5px;color:#8A90AC;margin-top:18px;'>!! आत्मा मालिक !! — हा ईमेल आपोआप पाठवला गेला आहे.</p>
                  </div></div>";
                $sent = eowmsSendMail($s['emails'], '☁️ Backup — ' . $orgName . ' — ' . date('d M Y'), $html,
                    [['name' => $fname, 'data' => $blob, 'type' => 'application/octet-stream']], $orgName . ' — E-Admin');
                if ($sent > 0) {
                    $result['ok'] = true;
                    $result['msg'] = $sizeTxt . ' Backup ' . $sent . ' पत्त्यावर पाठवला';
                } else {
                    $result['msg'] = 'Server वरून Email जाऊ शकला नाही (mail() अयशस्वी)';
                }
            }
        } catch (Throwable $e) {
            $result['msg'] = 'Backup तयार करताना त्रुटी: ' . mb_substr($e->getMessage(), 0, 200);
        }
    }
    try {
        orgSettingSet($pdo, $orgId, 'offsite_last_at', date('Y-m-d H:i:s'));
        orgSettingSet($pdo, $orgId, 'offsite_last_status', $result['ok'] ? 'success' : 'failed');
        orgSettingSet($pdo, $orgId, 'offsite_last_msg', $result['msg']);
        if ($result['ok']) { orgSettingSet($pdo, $orgId, 'offsite_last_ok_at', date('Y-m-d H:i:s')); }
    } catch (PDOException $e) {}
    return $result;
}
