<?php
// ============================================================
// BIRTHDAY व WORK ANNIVERSARY EMAIL CRON — दररोज एकदा चालवायचं
// (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule (दिवसातून एकदा, उदा. सकाळी 7:00):
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/send-birthday-anniversary-emails.php
//
// आजचा वाढदिवस/कामाचा वर्धापनदिन असलेल्या सगळ्या Staff ला Email
// पाठवते. Welcome Page (welcome.php) वर हेच लोक Photo सह आधीच
// दिसतात — हे त्याला पूरक, Email-द्वारे वैयक्तिक शुभेच्छा.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php'; // 🕘 IST — PHP व Database दोन्हीची वेळ
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/cron-helpers.php';

require_once __DIR__ . '/../includes/celebrations.php';
cronJobStart($pdo, 'send-birthday-anniversary-emails');

// कर्मचाऱ्याला Email + App/Phone सूचना; Management व त्याच विभागातल्या सहकाऱ्यांना
// एकत्रित सूचना. प्रत्येक गोष्ट दिवसातून एकदाच (Cron पुन्हा चालला तरी दुप्पट नाही).
$date = cronToday();
$tot = ['email' => 0, 'push' => 0, 'wish' => 0];
try {
    $orgs = $pdo->query("SELECT id, name FROM organizations")->fetchAll();
} catch (PDOException $e) {
    cronJobFinish($pdo, 'send-birthday-anniversary-emails', false, 'organizations table not found');
    die("organizations table not found — nothing to do.\n");
}
foreach ($orgs as $org) {
    try {
        $r = celebrationsSend($pdo, $org, $date);
        foreach ($tot as $k => $_) { $tot[$k] += $r[$k]; }
    } catch (Throwable $e) { error_log('celebrations: ' . $e->getMessage()); }
}
$msg = "Done $date. {$tot['email']} Email, {$tot['push']} शुभेच्छा-सूचना, {$tot['wish']} सहकारी/Management सूचना.";
cronJobFinish($pdo, 'send-birthday-anniversary-emails', true, $msg);
echo $msg . "\n";
