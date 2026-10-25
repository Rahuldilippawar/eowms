<?php
// ============================================================
// GAUSHALA VACCINATION DUE CRON — रोज एकदा चालवा (उदा. सकाळी 8:00),
// किंवा cron/run-all.php मधून आपोआप.
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/gaushala-vaccination-due.php
//
// सक्रिय गोवंशाच्या Vaccination नोंदीतली पुढची तारीख (next_due_date)
// 3 दिवसांत येत असेल / उलटून गेली असेल, आणि त्यानंतर तीच लस पुन्हा
// नोंदवलेली नसेल — तर त्या गोवंशाचा Caretaker व "manage_gaushala"
// परवानगी असलेल्यांना (नसेल तर Management) सूचना.
// एका व्यक्तीला दिवसातून एकच एकत्रित सूचना — cron_digest_log वरून.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cron-helpers.php';

const VACCINATION_DUE_WITHIN_DAYS = 3;

$today = cronToday();
$sent = 0;
cronJobStart($pdo, 'gaushala-vaccination-due');

try {
    if (!$pdo->query("SHOW COLUMNS FROM gaushala_health_records LIKE 'next_due_date'")->fetch()) {
        throw new PDOException('next_due_date column missing');
    }
    $stmt = $pdo->prepare("
        SELECT h.id, h.cattle_id, h.description, h.next_due_date, c.organization_id, c.caretaker_id, c.tag_number, c.name AS cattle_name
        FROM gaushala_health_records h JOIN gaushala_cattle c ON c.id = h.cattle_id
        WHERE h.record_type = 'Vaccination' AND h.next_due_date IS NOT NULL AND h.next_due_date <= DATE_ADD(?, INTERVAL " . VACCINATION_DUE_WITHIN_DAYS . " DAY)
          AND c.status = 'active'
          AND NOT EXISTS (
              SELECT 1 FROM gaushala_health_records h2
              WHERE h2.cattle_id = h.cattle_id AND h2.record_type = 'Vaccination' AND h2.id <> h.id
                AND h2.description = h.description AND h2.record_date > h.record_date
          )
        ORDER BY h.next_due_date
    ");
    $stmt->execute([$today]);
    $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    cronJobFinish($pdo, 'gaushala-vaccination-due', true, 'gaushala tables/column not present — skipped');
    die("gaushala_health_records / next_due_date not found — nothing to do.\n");
}

// "manage_gaushala" परवानगी असलेले — संस्था-निहाय
$managersByOrg = [];
$perUser = [];
foreach ($rows as $r) {
    $orgId = (int)$r['organization_id'];
    if (!isset($managersByOrg[$orgId])) {
        try {
            $m = $pdo->prepare("SELECT DISTINCT u.id FROM users u JOIN role_permissions rp ON rp.role_key = u.role
                                WHERE u.organization_id = ? AND u.is_active = 1 AND rp.permission_key = 'manage_gaushala'");
            $m->execute([$orgId]);
            $managersByOrg[$orgId] = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) { $managersByOrg[$orgId] = []; }
        if (!$managersByOrg[$orgId]) { $managersByOrg[$orgId] = cronManagementUserIds($pdo, $orgId); }
    }
    $recipients = $managersByOrg[$orgId];
    if ($r['caretaker_id']) { $recipients[] = (int)$r['caretaker_id']; }

    $daysLeft = (int)round((strtotime($r['next_due_date']) - strtotime($today)) / 86400);
    $when = $daysLeft < 0 ? abs($daysLeft) . ' दिवस उशिरा' : ($daysLeft === 0 ? 'आज' : "$daysLeft दिवसांत");
    $label = trim(($r['tag_number'] ?: '') . ' ' . ($r['cattle_name'] ?: '')) ?: ('#' . $r['cattle_id']);
    $line = "$label — " . mb_substr($r['description'], 0, 40) . " ($when)";
    foreach (array_unique($recipients) as $uid) {
        $perUser[$uid]['org'] = $orgId;
        $perUser[$uid]['lines'][] = $line;
    }
}

foreach ($perUser as $uid => $d) {
    if (!cronDigestClaim($pdo, 'gaushala_vaccination', $uid, $today, count($d['lines']))) { continue; }
    notifyUser($uid, $d['org'], '💉 गोशाळा लसीकरण बाकी — ' . count($d['lines']), cronDigestList($d['lines'], 4), 'gaushala/cattle.php');
    $sent++;
}

$out = "Done. $sent vaccination digests sent for $today (" . count($rows) . " due)";
cronJobFinish($pdo, 'gaushala-vaccination-due', true, $out);
echo $out . "\n";
