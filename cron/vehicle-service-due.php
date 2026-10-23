<?php
// ============================================================
// VEHICLE SERVICE DUE CRON — रोज एकदा चालवा (उदा. सकाळी 8:00), किंवा
// cron/run-all.php मधून आपोआप.
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/vehicle-service-due.php
//
// प्रत्येक सक्रिय वाहनाच्या शेवटच्या Maintenance नोंदीतली "पुढील Service"
// तारीख (next_due_date) 7 दिवसांत येत असेल / उलटून गेली असेल — (आणि
// next_due_km Column असेल तर Odometer त्याच्या 500 km जवळ आला असेल) —
// तर वाहनाच्या विभागाच्या HOD व Driver ला सूचना (नसेल तर Management).
// एका व्यक्तीला दिवसातून एकच एकत्रित सूचना — cron_digest_log वरून.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cron-helpers.php';

const SERVICE_DUE_WITHIN_DAYS = 7;
const SERVICE_DUE_WITHIN_KM = 500;

$today = cronToday();
$sent = 0;
cronJobStart($pdo, 'vehicle-service-due');

try {
    $hasKm = (bool)$pdo->query("SHOW COLUMNS FROM vehicle_maintenance LIKE 'next_due_km'")->fetch();
    // प्रत्येक वाहनाची सगळ्यात शेवटची Maintenance नोंद
    $rows = $pdo->query("
        SELECT v.id AS vehicle_id, v.organization_id, v.vehicle_number, v.department_id, v.driver_id, v.current_odometer,
               m.type, m.next_due_date" . ($hasKm ? ", m.next_due_km" : ", NULL AS next_due_km") . "
        FROM vehicles v
        JOIN vehicle_maintenance m ON m.id = (
            SELECT m2.id FROM vehicle_maintenance m2 WHERE m2.vehicle_id = v.id ORDER BY m2.maintenance_date DESC, m2.id DESC LIMIT 1
        )
        WHERE v.is_active = 1
    ")->fetchAll();
} catch (PDOException $e) {
    cronJobFinish($pdo, 'vehicle-service-due', false, 'vehicle tables not found');
    die("vehicles / vehicle_maintenance table not found — nothing to do.\n");
}

$perUser = [];
$hodCache = [];
foreach ($rows as $r) {
    $reasons = [];
    if ($r['next_due_date']) {
        $daysLeft = (int)round((strtotime($r['next_due_date']) - strtotime($today)) / 86400);
        if ($daysLeft < 0) { $reasons[] = 'Service ' . abs($daysLeft) . ' दिवस उशिरा'; }
        elseif ($daysLeft <= SERVICE_DUE_WITHIN_DAYS) { $reasons[] = $daysLeft === 0 ? 'Service आज' : "Service $daysLeft दिवसांत"; }
    }
    if ($r['next_due_km'] !== null && (float)$r['next_due_km'] > 0 && (float)$r['current_odometer'] >= (float)$r['next_due_km'] - SERVICE_DUE_WITHIN_KM) {
        $reasons[] = 'Service km (' . number_format((float)$r['next_due_km']) . ') जवळ/ओलांडले';
    }
    if (!$reasons) { continue; }

    $recipients = [];
    if ($r['driver_id']) { $recipients[] = (int)$r['driver_id']; }
    if ($r['department_id']) {
        if (!array_key_exists($r['department_id'], $hodCache)) {
            $h = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
            $h->execute([$r['department_id']]);
            $hodCache[$r['department_id']] = $h->fetch()['hod_id'] ?? null;
        }
        if ($hodCache[$r['department_id']]) { $recipients[] = (int)$hodCache[$r['department_id']]; }
    }
    if (!$recipients) { $recipients = cronManagementUserIds($pdo, $r['organization_id']); }

    foreach (array_unique($recipients) as $uid) {
        $perUser[$uid]['org'] = $r['organization_id'];
        $perUser[$uid]['lines'][] = "{$r['vehicle_number']}: " . implode(', ', $reasons);
    }
}

foreach ($perUser as $uid => $d) {
    if (!cronDigestClaim($pdo, 'vehicle_service', $uid, $today, count($d['lines']))) { continue; }
    notifyUser($uid, $d['org'], '🔧 वाहन Service बाकी — ' . count($d['lines']) . ' वाहने', cronDigestList($d['lines'], 5), 'vehicles/index.php');
    $sent++;
}

$out = "Done. $sent vehicle-service digests sent for $today";
cronJobFinish($pdo, 'vehicle-service-due', true, $out);
echo $out . "\n";
