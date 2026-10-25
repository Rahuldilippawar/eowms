<?php
// ============================================================
// PUSH QUEUE CRON — दर मिनिटाला चालवा (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule (every minute):
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/send-push-queue.php
//
// 1) notification_push_queue मधले Push (Web Push + Android FCM) पाठवतो
//    — notifyUser() फक्त Queue मध्ये टाकतो, त्यामुळे पानं लगेच परत येतात.
// 2) 🆘 SOS — 5 मिनिटांत कोणीच "मी येत आहे" म्हटलं नसेल तर Management ला पुन्हा.
// 3) वाचलेल्या 90 दिवसांपेक्षा जुन्या सूचना आणि पाठवलेल्या जुन्या Queue नोंदी काढतो.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/emergency-helpers.php';

$started = time();
$sent = 0;
// पुढचा Cron सुरू होण्याआधी (≈50 सेकंदात) थांबतो; Lock मुळे दोन Cron एकत्र चालले तरी Push दुप्पट जात नाही
do {
    $batch = notifyDeliverPushQueue(100, 50 - (time() - $started));
    $sent += $batch;
} while ($batch > 0 && time() - $started < 50);

$escalated = emergencyEscalateUnacknowledged($pdo);
[$purgedNotifications, $purgedQueue] = notifyPurgeOld();

echo "Done. push sent=$sent, sos escalated=$escalated, purged notifications=$purgedNotifications, purged queue=$purgedQueue — " . date('Y-m-d H:i:s') . "\n";
