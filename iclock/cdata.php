<?php
// ============================================================
// eSSL/ZKTeco ADMS ("iClock Push") Protocol Endpoint
// ============================================================
// Machine च्या Settings मध्ये "Server URL/Address" म्हणून हा पत्ता द्या:
//   http://<तुमचं-Domain>/iclock/cdata.php
// (जर Machine ने आपोआप '/iclock/cdata' — .php शिवाय — पाठवायचा प्रयत्न
// केला, तर याच Folder मधल्या .htaccess ने आपोआप इथे वळवलं जाईल.)
//
// हे Endpoint कुठलाही Login/Session मागत नाही — Machine थेट, स्वतःहून
// Internet वरून याला Request पाठवते, त्यामुळे ते उघडंच असावं लागतं
// (पण फक्त Attendance-Data स्वीकारतं, बाकी काहीही करत नाही — सुरक्षित).
// ============================================================

require_once __DIR__ . '/../config/db.php';

$serialNumber = trim($_GET['SN'] ?? '');
if (!$serialNumber) {
    http_response_code(400);
    echo "ERROR: SN missing";
    exit;
}

// हे Device आधीच नोंदवलेलं (Registered) आहे का ते तपासतो — नसेल तर आपोआप
// एक "मंजुरी-प्रलंबित" (is_active = 0, is_approved = 0) नोंद तयार करतो.
// सुरक्षा: अनोळखी Machine चे Punches Super Admin ने Devices पानावरून
// मंजूर करेपर्यंत अजिबात स्वीकारले जात नाहीत.
$deviceApproved = false;
try {
    $devStmt = $pdo->prepare("SELECT id, organization_id, is_active, is_approved FROM biometric_devices WHERE serial_number = ?");
    $devStmt->execute([$serialNumber]);
    $device = $devStmt->fetch();
    if (!$device) {
        // कुठल्या Organization शी जोडायचं हे आधी माहीत नसतं — पहिल्या Active
        // Organization खाली (फक्त यादीत दिसण्यासाठी) निष्क्रिय नोंद करतो.
        $firstOrgStmt = $pdo->query("SELECT id FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1");
        $firstOrgId = $firstOrgStmt->fetch()['id'] ?? 1;
        $pdo->prepare("INSERT INTO biometric_devices (organization_id, serial_number, device_name, last_seen_at, is_active, is_approved) VALUES (?, ?, ?, NOW(), 0, 0)")
            ->execute([$firstOrgId, mb_substr($serialNumber, 0, 50), 'नवीन Device — मंजुरी प्रलंबित']);
    } else {
        $pdo->prepare("UPDATE biometric_devices SET last_seen_at = NOW() WHERE id = ?")->execute([$device['id']]);
        $deviceApproved = (int)$device['is_active'] === 1 && (int)$device['is_approved'] === 1;
    }
} catch (PDOException $e) {
    // Migration अजून चाललेली नसेल तरी Machine ला Error न देता शांतपणे पुढे जातो
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // ---------------- Handshake (Machine "मी कशी Settings वापरू?" विचारते) ----------------
    header('Content-Type: text/plain');
    echo "GET OPTION FROM: $serialNumber\r\n";
    echo "Stamp=9999\r\n";
    echo "OpStamp=9999\r\n";
    echo "ErrorDelay=30\r\n";
    echo "Delay=10\r\n";
    echo "TransFlag=TransData AttLog OpLog\r\n";
    echo "TimeZone=5.5\r\n";
    echo "Realtime=1\r\n";
    echo "Encrypt=0\r\n";
    exit;
}

if ($method === 'POST') {
    // ---------------- प्रत्यक्ष Attendance Data (Punch Records) ----------------
    $table = $_GET['table'] ?? '';
    $body = file_get_contents('php://input');

    if (strtoupper($table) === 'ATTLOG' && $body && !$deviceApproved) {
        // मंजूर न झालेलं / निष्क्रिय Device — Punches दुर्लक्षित
        header('Content-Type: text/plain');
        echo "OK: 0";
        exit;
    }

    if (strtoupper($table) === 'ATTLOG' && $body) {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        $inserted = 0;
        foreach ($lines as $line) {
            if (!trim($line)) continue;
            // सामान्यतः Tab-Separated: PIN, वेळ, Status, Verify-Mode, ...
            $parts = preg_split('/\t/', $line);
            if (count($parts) < 2) continue;
            $pin = trim($parts[0]);
            $punchTime = trim($parts[1]);
            $verifyMode = $parts[3] ?? null;

            $ts = strtotime($punchTime);
            if (!$pin || !$ts) continue;
            $punchDateTime = date('Y-m-d H:i:s', $ts);

            try {
                // INSERT IGNORE — Machine ने तोच Punch पुन्हा पाठवला तरी दुप्पट नोंद नाही (Unique Key)
                $insStmt = $pdo->prepare("INSERT IGNORE INTO biometric_punch_logs (device_serial, biometric_pin, punch_time, verify_mode) VALUES (?, ?, ?, ?)");
                $insStmt->execute([$serialNumber, $pin, $punchDateTime, $verifyMode]);
                $inserted += $insStmt->rowCount();
            } catch (PDOException $e) {
                // Migration अजून चाललेली नसेल — शांतपणे वगळतो
            }
        }
        header('Content-Type: text/plain');
        echo "OK: $inserted";
        exit;
    }

    // इतर Table-प्रकार (OPLOG, इ.) — सध्या वापरत नाही, फक्त Acknowledge करतो
    header('Content-Type: text/plain');
    echo "OK";
    exit;
}

http_response_code(405);
echo "Method Not Allowed";
