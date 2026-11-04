<?php
/**
 * 📊 साप्ताहिक व्यवस्थापन अहवाल — दर सोमवारी सकाळी 7 नंतर (run-all.php मधून) प्रत्येक
 * संस्थेच्या Management ला PDF सह Email. Settings: अहवाल → साप्ताहिक अहवाल (reports/weekly.php).
 *   --force  = आज सोमवार नसला / आधीच पाठवला असला तरी आत्ता पाठवा
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cron-helpers.php';
require_once __DIR__ . '/../includes/weekly-report.php';

cronJobStart($pdo, 'weekly-management-report');
$force = in_array('--force', $argv ?? [], true);
$out = '';
$allOk = true;
foreach ($pdo->query("SELECT id, name FROM organizations WHERE is_active = 1")->fetchAll() as $org) {
    if (!weeklyReportEnabled($pdo, $org['id'])) { continue; }
    // एकाच आठवड्यात दोनदा नको (run-all ने चुकून पुन्हा चालवलं तरी)
    $last = orgSetting($pdo, $org['id'], 'weekly_report_last_at');
    if (!$force && $last && strtotime($last) > time() - 5 * 86400 && str_starts_with((string)orgSetting($pdo, $org['id'], 'weekly_report_last_msg', ''), '✓')) { continue; }
    $r = weeklyReportSend($pdo, $org['id']);
    $allOk = $allOk && ($r['ok'] || $r['sent'] === 0 && str_contains($r['msg'], 'पत्ता नाही'));
    $out .= ($r['ok'] ? '[OK] ' : '[SKIP/FAILED] ') . $org['name'] . ' — ' . $r['msg'] . "\n";
}
echo $out ?: "पाठवायचं काही नाही.\n";
cronJobFinish($pdo, 'weekly-management-report', $allOk, $out);
exit($allOk ? 0 : 1);
