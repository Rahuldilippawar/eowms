<?php
// ============================================================
// BIOMETRIC ATTENDANCE PROCESSING CRON
// दर 15-30 मिनिटांनी चालवा (Hostinger Cron Jobs) — Machine कडून
// आलेल्या Raw Punches ना, प्रत्येक Staff च्या रोजच्या Attendance
// नोंदीत रूपांतरित करते (पहिली वेळ = आलो, शेवटची वेळ = गेलो).
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/process-biometric-attendance.php
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->query("SELECT 1 FROM biometric_punch_logs LIMIT 1");
} catch (PDOException $e) {
    die("biometric_punch_logs table not found — Migration अजून चालवलेली नाही.\n");
}

// अजून Process न झालेले सगळे Punches — कोणत्या (संस्था + PIN + Date) Combination
// साठी आहेत ते शोधतो. फक्त मंजूर व सक्रिय Devices चे Punches, आणि PIN फक्त
// त्या Device च्याच संस्थेतल्या Staff शी जुळवायचा (दुसऱ्या संस्थेतल्या याच PIN शी नाही).
$unprocessedStmt = $pdo->query("
    SELECT DISTINCT d.organization_id, l.biometric_pin, DATE(l.punch_time) AS punch_date
    FROM biometric_punch_logs l
    JOIN biometric_devices d ON d.serial_number = l.device_serial AND d.is_active = 1 AND d.is_approved = 1
    WHERE l.processed = 0
");
$groups = $unprocessedStmt->fetchAll();

$processedCount = 0;
$unmatchedCount = 0;

foreach ($groups as $g) {
    $pin = $g['biometric_pin'];
    $date = $g['punch_date'];
    $deviceOrgId = (int)$g['organization_id'];

    // हा PIN त्याच संस्थेतल्या कोणत्या Staff चा आहे ते शोधतो
    $userStmt = $pdo->prepare("SELECT id, organization_id FROM users WHERE biometric_pin = ? AND organization_id = ? AND is_active = 1 LIMIT 1");
    $userStmt->execute([$pin, $deviceOrgId]);
    $userRow = $userStmt->fetch();

    if (!$userRow) {
        // अजून कोणाशी जुळलेला नाही — Punches तसेच ठेवतो (PIN Mapping
        // झाल्यावर पुढच्या वेळी आपोआप Process होतील), फक्त मोजतो.
        $unmatchedCount++;
        continue;
    }

    // त्या दिवसाचे (याच संस्थेच्या Devices वरचे) सगळे Punches — पहिली आणि शेवटची वेळ काढतो
    $punchesStmt = $pdo->prepare("
        SELECT MIN(l.punch_time) AS first_p, MAX(l.punch_time) AS last_p, COUNT(*) AS cnt
        FROM biometric_punch_logs l
        JOIN biometric_devices d ON d.serial_number = l.device_serial AND d.organization_id = ? AND d.is_active = 1 AND d.is_approved = 1
        WHERE l.biometric_pin = ? AND DATE(l.punch_time) = ?
    ");
    $punchesStmt->execute([$deviceOrgId, $pin, $date]);
    $agg = $punchesStmt->fetch();

    $firstTime = date('H:i:s', strtotime($agg['first_p']));
    $lastTime = date('H:i:s', strtotime($agg['last_p']));

    // या व्यक्तीची Shift-Setting शोधतो — नसेल तर Default (09:30-17:30, 15 मि. Grace) वापरतो
    $shiftStmt = $pdo->prepare("SELECT shift_start, shift_end, grace_minutes, half_day_hours FROM staff_shift_settings WHERE user_id = ?");
    $shiftStmt->execute([$userRow['id']]);
    $shift = $shiftStmt->fetch() ?: ['shift_start' => '09:30:00', 'shift_end' => '17:30:00', 'grace_minutes' => 15, 'half_day_hours' => 4];

    $shiftStartWithGrace = strtotime($date . ' ' . $shift['shift_start']) + ((int)$shift['grace_minutes'] * 60);
    $isLate = strtotime($date . ' ' . $firstTime) > $shiftStartWithGrace ? 1 : 0;
    $isEarlyLeave = strtotime($date . ' ' . $lastTime) < strtotime($date . ' ' . $shift['shift_end']) ? 1 : 0;

    $hoursWorked = (strtotime($date . ' ' . $lastTime) - strtotime($date . ' ' . $firstTime)) / 3600;
    $status = $hoursWorked < (float)$shift['half_day_hours'] ? 'half_day' : 'present';

    $pdo->prepare("
        INSERT INTO staff_attendance (organization_id, user_id, attendance_date, first_punch_time, last_punch_time, total_punches, status, is_late, is_early_leave, source)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'biometric')
        ON DUPLICATE KEY UPDATE first_punch_time = VALUES(first_punch_time), last_punch_time = VALUES(last_punch_time), total_punches = VALUES(total_punches), status = IF(source='manual', status, VALUES(status)), is_late = VALUES(is_late), is_early_leave = VALUES(is_early_leave)
    ")->execute([$userRow['organization_id'], $userRow['id'], $date, $firstTime, $lastTime, $agg['cnt'], $status, $isLate, $isEarlyLeave]);

    // हे Punches Process झाले म्हणून चिन्हांकित करतो
    $pdo->prepare("
        UPDATE biometric_punch_logs l
        JOIN biometric_devices d ON d.serial_number = l.device_serial AND d.organization_id = ?
        SET l.processed = 1 WHERE l.biometric_pin = ? AND DATE(l.punch_time) = ?
    ")->execute([$deviceOrgId, $pin, $date]);

    $processedCount++;
}

echo "Done. $processedCount (PIN+Date) groups processed. $unmatchedCount PIN(s) still unmatched (need mapping). " . date('Y-m-d H:i:s') . "\n";
