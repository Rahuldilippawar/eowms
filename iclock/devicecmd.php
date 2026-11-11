<?php
// 🖐️ ADMS — Machine आदेश पूर्ण केल्यावर निकाल पाठवते: "ID=12&Return=0&CMD=DATA" (Return 0 = यशस्वी)
// INFO आदेशाच्या निकालात Machine ची माहिती (UserCount=.., FPCount=.. इ.) पण येते.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php';
require_once __DIR__ . '/../includes/bio-helpers.php';

header('Content-Type: text/plain');
if (!bioReady($pdo)) { echo 'OK'; exit; }

$sn = trim((string)($_GET['SN'] ?? ''));
$dev = bioValidSerial($sn) ? bioDeviceBySerial($pdo, $sn, false) : null;
if (!$dev) { echo 'OK'; exit; }

$body = (string)file_get_contents('php://input', false, null, 0, 1024 * 1024);
$info = [];
$map = ['FWVersion' => 'firmware', 'UserCount' => 'user_count', 'FPCount' => 'fp_count', 'TransactionCount' => 'att_count', 'FaceCount' => 'face_count'];
$upd = $pdo->prepare("UPDATE bio_commands SET status = ?, return_code = ?, done_at = NOW() WHERE id = ? AND device_id = ?");
foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
    $line = trim($line);
    if ($line === '') { continue; }
    if (strpos($line, 'ID=') === 0) {
        parse_str($line, $r);
        if (!empty($r['ID']) && ctype_digit((string)$r['ID'])) {
            $ret = (string)($r['Return'] ?? '');
            $upd->execute([$ret !== '' && (int)$ret >= 0 ? 'done' : 'failed', mb_substr($ret, 0, 20), (int)$r['ID'], (int)$dev['id']]);
        }
        continue;
    }
    // INFO निकाल — "~DeviceName=..", "UserCount=12" इ.
    foreach (preg_split('/[,\t]+/', $line) as $kv) {
        if (strpos($kv, '=') === false) { continue; }
        [$k, $v] = array_map('trim', explode('=', $kv, 2));
        $k = ltrim($k, '~');
        if (isset($map[$k])) { $info[$map[$k]] = $v; }
    }
}
bioTouchDevice($pdo, $dev, $info);
echo 'OK';
