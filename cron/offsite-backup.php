<?php
/**
 * ☁️ Backup दुसऱ्या ठिकाणी (Email) — रोज/आठवड्यातून एकदा, Super Admin ने
 * Backup → "Email Backup" मध्ये ठरवल्याप्रमाणे. run-all.php मधून रोज सकाळी 3 नंतर चालतो;
 * प्रत्येक संस्थेची वेळ आलेली असेल तरच पाठवतो.  --force = वेळ न बघता आत्ता पाठवा.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php'; // 🕘 IST — PHP व Database दोन्हीची वेळ
require_once __DIR__ . '/../includes/cron-helpers.php';
require_once __DIR__ . '/../includes/offsite-backup.php';

cronJobStart($pdo, 'offsite-backup');
if (!orgSettingsReady($pdo)) { cronJobFinish($pdo, 'offsite-backup', true, 'org_settings table not found — Database Update चालवा'); exit("org_settings table not found — Database Update चालवा.\n"); }
$force = in_array('--force', $argv ?? [], true);
$out = '';
$allOk = true;
foreach ($pdo->query("SELECT id, name FROM organizations WHERE is_active = 1")->fetchAll() as $org) {
    $s = offsiteSettings($pdo, $org['id']);
    if (!$s['enabled'] || (!$force && !offsiteDue($s))) { continue; }
    $r = offsiteRun($pdo, $org['id']);
    $allOk = $allOk && $r['ok'];
    $out .= ($r['ok'] ? '[OK] ' : '[FAILED] ') . $org['name'] . ' — ' . $r['msg'] . "\n";
}
echo $out ?: "आज पाठवायचं काही नाही.\n";
cronJobFinish($pdo, 'offsite-backup', $allOk, $out);
exit($allOk ? 0 : 1);
