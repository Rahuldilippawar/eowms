<?php
/**
 * 🌙 दैनिक व्यवस्थापन अहवाल — run-all.php मधून दर 15 मिनिटांनी तपासतो; प्रत्येक संस्थेच्या
 * ठरलेल्या वेळेनंतर (default संध्याकाळी 8) आजचा अहवाल एकदाच PDF सह पाठवतो.
 * Settings: अहवाल → दैनिक अहवाल (reports/daily.php).   --force = आत्ताच पाठवा
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php'; // 🕘 IST — PHP व Database दोन्हीची वेळ
require_once __DIR__ . '/../includes/cron-helpers.php';
require_once __DIR__ . '/../includes/daily-report.php';
require_once __DIR__ . '/../includes/report-autosend.php';

cronJobStart($pdo, 'daily-management-report');
$force = in_array('--force', $argv ?? [], true);
$today = cronToday();
$out = '';
$allOk = true;
foreach ($pdo->query("SELECT id, name FROM organizations WHERE is_active = 1")->fetchAll() as $org) {
    if (!dailyReportEnabled($pdo, $org['id'])) { continue; }
    if ($force) {
        $r = dailyReportSend($pdo, $org['id'], $today);
    } else {
        // वेळ झाली असेल तर आजचा; काल संध्याकाळी गेला नसेल तर आज सकाळी कालचा — एकदाच, Lock सह (Web Backup मार्गाशी दुप्पट नाही)
        $r = reportAutoSendRun($pdo, $org['id'], 'cron');
        if (empty($r['sent'])) { continue; }
    }
    $allOk = $allOk && ($r['ok'] || str_contains($r['msg'], 'पत्ता नाही'));
    $out .= ($r['ok'] ? '[OK] ' : '[SKIP/FAILED] ') . $org['name'] . ' — ' . $r['msg'] . "\n";
}
if ($out !== '') { echo $out; }
cronJobFinish($pdo, 'daily-management-report', $allOk, $out ?: 'आत्ता पाठवायचं काही नाही');
exit($allOk ? 0 : 1);
