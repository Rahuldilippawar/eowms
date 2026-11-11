<?php
// ============================================================
// 🖐️ eSSL / ZKTeco ADMS ("iClock Push") — मुख्य Endpoint (Biometric v2)
// ============================================================
// Machine → Menu → Comm. → Cloud Server / ADMS Setting:
//   Server Address = तुमचं Domain (उदा. amd.babashree.com), Port = 80, Domain Name = ON
// Machine इथे येते: /iclock/cdata?SN=… (iclock/.htaccess ने cdata.php कडे वळवलं जातं)
//
//  GET            — Handshake: Machine कोणत्या Settings ने Data पाठवू ते विचारते
//  POST ATTLOG    — हजेरीचे Punches  → bio_punches + लगेच हिशोब + कर्मचाऱ्याला सूचना
//  POST OPERLOG   — Machine वरचे कर्मचारी / बोटं / चेहरे → bio_device_users
//
// Login लागत नाही (Machine स्वतःहून पाठवते). सुरक्षा: अनोळखी Machine "मंजुरी-प्रलंबित" —
// तिचे Punches जपून ठेवतो पण Super Admin ने मंजूर करेपर्यंत हजेरीत धरत नाही.
// ============================================================

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php';
require_once __DIR__ . '/../includes/bio-helpers.php';

header('Content-Type: text/plain');
$reply = function ($text, $code = 200) { http_response_code($code); echo $text; exit; };

if (!bioReady($pdo)) { $reply('OK'); } // Database Update बाकी — Machine ला Error नको
bioEnsureV2Reset($pdo);

$sn = trim((string)($_GET['SN'] ?? ''));
if (!bioValidSerial($sn)) { $reply('ERROR: SN', 400); }

$dev = bioDeviceBySerial($pdo, $sn);
if (!$dev) { $reply('OK'); } // मर्यादा ओलांडली — शांतपणे दुर्लक्ष
bioTouchDevice($pdo, $dev, ['push_version' => $_GET['pushver'] ?? null]);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Stamp — Machine ने शेवटचा कोणता Log पाठवला; None = सगळे पुन्हा पाठव ("सगळे Logs पुन्हा" आदेश)
    $att = $dev['att_stamp'] !== null && $dev['att_stamp'] !== '' ? $dev['att_stamp'] : 'None';
    $op = $dev['op_stamp'] !== null && $dev['op_stamp'] !== '' ? $dev['op_stamp'] : 'None';
    echo "GET OPTION FROM: $sn\r\n"
        . "ATTLOGStamp=$att\r\nOPERLOGStamp=$op\r\nATTPHOTOStamp=None\r\n"
        . "ErrorDelay=30\r\nDelay=10\r\nTransTimes=00:00;14:05\r\nTransInterval=1\r\n"
        . "TransFlag=TransData AttLog OpLog EnrollUser ChgUser EnrollFP ChgFP FACE\r\n"
        . "TimeZone=5.5\r\nRealtime=1\r\nEncrypt=None\r\nServerVer=2.4.1 EOWMS\r\n";
    bioFinishResponse();
    try { bioForward($pdo, $dev, 'cdata', 'GET'); } catch (Throwable $e) {} // 🔁 जुन्या Software ला — Machine Online दिसावी
    exit;
}

if ($method !== 'POST') { $reply('Method Not Allowed', 405); }

$body = file_get_contents('php://input', false, null, 0, 4 * 1024 * 1024); // 4 MB पर्यंत
$table = strtoupper((string)($_GET['table'] ?? ''));
$stamp = preg_match('/^\d{1,20}$/', (string)($_GET['Stamp'] ?? '')) ? $_GET['Stamp'] : null;

if ($table === 'ATTLOG') {
    $res = bioIngestAttlog($pdo, $dev, $body);
    if ($stamp !== null) { $pdo->prepare("UPDATE bio_devices SET att_stamp = ? WHERE id = ?")->execute([$stamp, (int)$dev['id']]); }
    echo 'OK: ' . $res['count'];
    // Machine ला लगेच उत्तर; मग हिशोब + सूचना + जुन्या Software ला पुढे
    bioFinishResponse();
    try { bioProcessAndNotify($pdo, $dev, $res['new']); } catch (Throwable $e) { error_log('bio process: ' . $e->getMessage()); }
    try { bioForward($pdo, $dev, 'cdata', 'POST'); } catch (Throwable $e) { error_log('bio forward: ' . $e->getMessage()); }
    exit;
}

if ($table === 'OPERLOG') {
    $n = bioIngestOperlog($pdo, $dev, $body);
    if ($stamp !== null) { $pdo->prepare("UPDATE bio_devices SET op_stamp = ? WHERE id = ?")->execute([$stamp, (int)$dev['id']]); }
    echo 'OK: ' . $n;
    bioFinishResponse();
    try { bioForward($pdo, $dev, 'cdata', 'POST'); } catch (Throwable $e) {}
    exit;
}

if ($table === 'OPTIONS' || isset($_GET['options'])) {
    // काही Machines स्वतःची माहिती "~DeviceName=..,FWVersion=..,UserCount=.." अशी पाठवतात
    $info = [];
    foreach (preg_split('/[,\r\n]+/', (string)$body) as $kv) {
        if (strpos($kv, '=') === false) { continue; }
        [$k, $v] = array_map('trim', explode('=', $kv, 2));
        $k = ltrim($k, '~');
        $map = ['FWVersion' => 'firmware', 'UserCount' => 'user_count', 'FPCount' => 'fp_count', 'TransactionCount' => 'att_count', 'FaceCount' => 'face_count'];
        if (isset($map[$k])) { $info[$map[$k]] = $v; }
    }
    if ($info) { bioTouchDevice($pdo, $dev, $info); }
}

// ATTPHOTO, BIODATA इ. — सध्या साठवत नाही, फक्त स्वीकारतो (जुन्या Software ला मात्र पुढे पाठवतो)
echo 'OK';
bioFinishResponse();
try { bioForward($pdo, $dev, 'cdata', 'POST'); } catch (Throwable $e) {}
exit;
