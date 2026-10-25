<?php
// ============================================================
// OVERDUE TASKS CRON — रोज एकदा चालवा (उदा. सकाळी 9:00), किंवा
// cron/run-all.php मधून आपोआप.
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/check-overdue-tasks.php
//
// Target-दिनांक उलटून गेलेले, पूर्ण न झालेले टास्क —
//   - ज्याला टास्क दिला (assignee) त्याला: "तुमचे N टास्क उशिरा"
//   - ज्याने टास्क दिला (assigner) त्याला: "तुम्ही दिलेले N टास्क उशिरा"
// एका व्यक्तीला दिवसातून एकच (एकत्रित) सूचना — cron_digest_log वरून.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/cron-helpers.php';

$today = cronToday();
$sent = 0;
cronJobStart($pdo, 'check-overdue-tasks');

try {
    $stmt = $pdo->prepare("
        SELECT t.id, t.organization_id, t.title, t.task_code, t.target_date, t.assigned_to, t.assigned_by, u.name AS assignee_name
        FROM tasks t JOIN users u ON u.id = t.assigned_to
        WHERE t.status <> 'completed' AND t.target_date < ? ORDER BY t.target_date
    ");
    $stmt->execute([$today]);
    $tasks = $stmt->fetchAll();
} catch (PDOException $e) {
    cronJobFinish($pdo, 'check-overdue-tasks', false, 'tasks table not found');
    die("tasks table not found — nothing to do.\n");
}

// प्रत्येक व्यक्तीसाठी — स्वतःचे उशिरा टास्क + स्वतः दिलेले उशिरा टास्क
$perUser = [];
foreach ($tasks as $t) {
    $daysLate = (int)round((strtotime($today) - strtotime($t['target_date'])) / 86400);
    $perUser[$t['assigned_to']]['org'] = $t['organization_id'];
    $perUser[$t['assigned_to']]['mine'][] = "{$t['title']} ({$daysLate} दि.)";
    if ($t['assigned_by'] && (int)$t['assigned_by'] !== (int)$t['assigned_to']) {
        $perUser[$t['assigned_by']]['org'] = $t['organization_id'];
        $perUser[$t['assigned_by']]['given'][] = "{$t['title']} — {$t['assignee_name']}";
    }
}

foreach ($perUser as $uid => $d) {
    $mine = $d['mine'] ?? [];
    $given = $d['given'] ?? [];
    if (!cronDigestClaim($pdo, 'overdue_tasks', $uid, $today, count($mine) + count($given))) { continue; }

    $parts = [];
    if ($mine) { $parts[] = 'तुमचे ' . count($mine) . ' टास्क उशिरा: ' . cronDigestList($mine); }
    if ($given) { $parts[] = 'तुम्ही दिलेले ' . count($given) . ' टास्क उशिरा: ' . cronDigestList($given); }
    $title = '⏰ उशिरा टास्क — ' . (count($mine) + count($given)) . ' बाकी';
    $link = $mine ? 'tasks/my-tasks.php' : 'tasks/index.php';
    notifyUser($uid, $d['org'], $title, implode(' | ', $parts), $link);
    $sent++;
}

$out = "Done. $sent overdue-task digests sent for $today (" . count($tasks) . " overdue tasks)";
cronJobFinish($pdo, 'check-overdue-tasks', true, $out);
echo $out . "\n";
