<?php
// ============================================================
// 🖐️ BIOMETRIC v2 CRON — दर 5 मिनिटांनी (cron/run-all.php मधून)
//  1) न जोडलेले Punches — PIN जोडले असतील तर कर्मचाऱ्याशी जोडून हजेरीचा हिशोब
//     (Machine Punch पाठवते तेव्हाच हिशोब होतो; हा फक्त राहिलेल्यांसाठी)
//  2) 30+ मिनिटं संपर्क नसलेल्या Machines — Admin ला "Offline" इशारा
//  3) 90 दिवसांपेक्षा जुने, पूर्ण झालेले आदेश काढतो
// Command: php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/bio-process.php
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php';
require_once __DIR__ . '/../includes/bio-helpers.php';

if (!bioReady($pdo)) { exit("bio_* tables नाहीत — System Update → Database Update चालवा.\n"); }
bioEnsureV2Reset($pdo);
$r = bioProcessPending($pdo);
$off = bioCheckOffline($pdo);
$pdo->exec("DELETE FROM bio_commands WHERE status IN ('done','failed') AND created_at < NOW() - INTERVAL 90 DAY");
echo "Done. {$r['done']} (कर्मचारी+दिवस) हिशोब · {$r['unmatched']} PIN अजून न जोडलेले · {$off} Offline इशारे · " . date('Y-m-d H:i:s') . "\n";
