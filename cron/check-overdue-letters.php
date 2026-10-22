<?php
// ============================================================
// OVERDUE LETTERS CHECK CRON — run this once daily (e.g. 9:00 AM)
// via a scheduled cron job (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/check-overdue-letters.php
//
// If a letter has been sitting "in_progress" with its current holder
// for REMINDER_DAYS or more without any movement, reminds the holder
// and the creator. If it's been ESCALATE_DAYS or more, also notifies
// management. Avoids sending the same reminder more than once a day.
// ============================================================

require_once __DIR__ . '/../config/db.php';

const REMINDER_DAYS = 3;
const ESCALATE_DAYS = 7;
$today = date('Y-m-d');
$sent = 0;

try {
    $letters = $pdo->query("SELECT * FROM letters WHERE status = 'in_progress' AND current_holder_id IS NOT NULL")->fetchAll();
} catch (PDOException $e) {
    die("letters table not found — nothing to do.\n");
}

foreach ($letters as $letter) {
    // Find when the file last actually moved (not just when 'seen' was marked)
    $lastAction = $pdo->prepare("SELECT created_at FROM letter_actions WHERE letter_id = ? ORDER BY id DESC LIMIT 1");
    $lastAction->execute([$letter['id']]);
    $lastActionRow = $lastAction->fetch();
    if (!$lastActionRow) continue;

    $daysSince = (int)((strtotime($today) - strtotime(date('Y-m-d', strtotime($lastActionRow['created_at'])))) / 86400);
    if ($daysSince < REMINDER_DAYS) continue;

    $link = 'letters/view.php?id=' . $letter['id'];

    // Dedup — already reminded today about this exact file?
    $dupCheck = $pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE link = ? AND DATE(created_at) = ?");
    $dupCheck->execute([$link, $today]);
    if ((int)$dupCheck->fetch()['c'] > 0) continue;

    $title = "⏰ फाईल प्रलंबित — {$letter['file_number']}";
    $message = "{$letter['subject']} — $daysSince दिवसांपासून कृतीची वाट पाहत आहे.";

    // Remind the current holder
    try {
        $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)")
            ->execute([$letter['organization_id'], $letter['current_holder_id'], $title, $message, $link]);
        $sent++;
    } catch (PDOException $e) {}

    // Also let the creator know (if different from the holder)
    if ((int)$letter['created_by'] !== (int)$letter['current_holder_id']) {
        try {
            $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)")
                ->execute([$letter['organization_id'], $letter['created_by'], $title, $message, $link]);
            $sent++;
        } catch (PDOException $e) {}
    }

    // Escalate to management if it's been sitting even longer
    if ($daysSince >= ESCALATE_DAYS) {
        $mgmtStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1 AND role IN ('super_admin','president','executive_president','general_manager')");
        $mgmtStmt->execute([$letter['organization_id']]);
        foreach ($mgmtStmt->fetchAll(PDO::FETCH_COLUMN) as $mgmtId) {
            if ((int)$mgmtId === (int)$letter['current_holder_id']) continue; // already notified above
            try {
                $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$letter['organization_id'], $mgmtId, "🔴 " . $title . " (Escalated)", $message, $link]);
                $sent++;
            } catch (PDOException $e) {}
        }
    }
}

echo "Done. $sent notifications sent for " . date('Y-m-d H:i:s') . "\n";
