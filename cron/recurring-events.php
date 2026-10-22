<?php
// ============================================================
// Recurring Events — पुढच्या वर्षीचा/महिन्याचा कार्यक्रम आपोआप तयार करणं
// रोज एकदा चालवा (उदा. रात्री) — जो कार्यक्रम आज संपला आणि Recurring
// आहे, त्याचा पुढचा Occurrence तयार करतो (आधीच तयार नसेल तरच).
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/recurring-events.php
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';

$today = date('Y-m-d');
$createdCount = 0;

try {
    $stmt = $pdo->prepare("
        SELECT * FROM events
        WHERE is_recurring = 1 AND event_date < ?
        AND id NOT IN (SELECT parent_event_id FROM events WHERE parent_event_id IS NOT NULL)
    ");
    $stmt->execute([$today]);
    $pastRecurringEvents = $stmt->fetchAll();

    foreach ($pastRecurringEvents as $ev) {
        $nextDate = $ev['recurrence_pattern'] === 'monthly'
            ? date('Y-m-d', strtotime($ev['event_date'] . ' +1 month'))
            : date('Y-m-d', strtotime($ev['event_date'] . ' +1 year'));

        // पुढचा Occurrence भविष्यात असेल तरच तयार करतो (भूतकाळातले
        // अनेक Occurrences एकदम तयार होऊ नयेत म्हणून)
        if (strtotime($nextDate) < strtotime($today)) continue;

        $pdo->prepare("
            INSERT INTO events (organization_id, campus_id, name, description, event_date, start_time, end_time, venue, responsible_user_id, is_recurring, recurrence_pattern, parent_event_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?, ?)
        ")->execute([
            $ev['organization_id'], $ev['campus_id'], $ev['name'], $ev['description'], $nextDate,
            $ev['start_time'], $ev['end_time'], $ev['venue'], $ev['responsible_user_id'],
            $ev['recurrence_pattern'], $ev['id'], $ev['created_by'],
        ]);
        $createdCount++;
    }
} catch (PDOException $e) {
    echo "Error (शक्यतो Migration अजून चालवलेली नाही): " . $e->getMessage() . "\n";
    exit;
}

echo "Done. $createdCount recurring event(s) चा पुढचा Occurrence तयार झाला. " . date('Y-m-d H:i:s') . "\n";
