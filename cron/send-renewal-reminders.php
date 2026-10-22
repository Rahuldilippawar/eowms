<?php
// ============================================================
// RENEWAL REMINDER CRON — run this once daily via a scheduled
// cron job (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule (once a day, e.g. 8:00 AM):
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/send-renewal-reminders.php
//
// This does NOT need a browser/login — safe to run from cron.
// It creates IN-APP notifications AND sends EMAIL for each
// department's HOD + staff when a renewal item crosses a
// reminder milestone (90/60/30/15/7/3/1/0 days), without
// sending duplicate notifications for the same item on the
// same day.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/mailer.php';

$milestones = [90, 60, 30, 15, 7, 3, 1, 0];
$today = date('Y-m-d');
$sent = 0;

try {
    $items = $pdo->query("
        SELECT r.*, DATEDIFF(r.expiry_date, CURDATE()) AS days_left
        FROM renewal_items r
        WHERE r.expiry_date >= CURDATE() - INTERVAL 3 DAY
          AND r.status != 'renewed'
    ")->fetchAll();
} catch (PDOException $e) {
    die("renewal_items table not found — nothing to do.\n");
}

foreach ($items as $item) {
    $daysLeft = (int)$item['days_left'];
    if (!in_array($daysLeft, $milestones, true)) {
        continue; // only notify exactly on milestone days, not every single day
    }

    // Skip if we've already notified about this exact item today
    try {
        $check = $pdo->prepare("
            SELECT COUNT(*) c FROM notifications
            WHERE link = ? AND DATE(created_at) = ?
        ");
        $link = 'renewals/index.php?item=' . $item['id'];
        $check->execute([$link, $today]);
        if ($check->fetch()['c'] > 0) {
            continue;
        }
    } catch (PDOException $e) {
        break; // notifications table not migrated — stop entirely
    }

    $label = $daysLeft < 0 ? 'मुदत संपली' : ($daysLeft === 0 ? 'आज मुदत संपते' : "$daysLeft दिवसात मुदत संपणार");
    $title = "नूतनीकरण सूचना — {$item['item_name']}";
    $message = "$label";

    // ------------- Escalating Reminder -------------
    // 30/15 दिवस — फक्त जबाबदार व्यक्तीला (नसेल तर संपूर्ण विभागाला)
    // 7/3 दिवस — जबाबदार व्यक्ती + HOD
    // 1/0/मुदत-संपलेली — जबाबदार व्यक्ती + HOD + Management (सगळ्यात वरची पातळी)
    $notifyIds = [];
    try {
        if (!empty($item['responsible_user_id'])) {
            $notifyIds[] = $item['responsible_user_id'];
        } else {
            $staffStmt = $pdo->prepare("SELECT id FROM users WHERE department_id = ? AND is_active = 1");
            $staffStmt->execute([$item['department_id']]);
            $notifyIds = array_merge($notifyIds, $staffStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($daysLeft <= 7) {
            $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
            $hodStmt->execute([$item['department_id']]);
            $hodId = $hodStmt->fetch()['hod_id'] ?? null;
            if ($hodId) { $notifyIds[] = $hodId; }
        }

        if ($daysLeft <= 1) {
            $mgmtStmt = $pdo->prepare("
                SELECT u.id FROM users u JOIN role_definitions rd ON rd.role_key = u.role
                WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'management'
            ");
            $mgmtStmt->execute([$item['organization_id']]);
            $notifyIds = array_merge($notifyIds, $mgmtStmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $notifyIds = array_unique(array_filter($notifyIds));

        // Email साठी — संस्थेचं नाव एकदाच काढतो
        $orgStmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $orgStmt->execute([$item['organization_id']]);
        $orgName = $orgStmt->fetch()['name'] ?? 'E-Admin';
        $emailLink = rtrim(SITE_BASE_URL, '/') . '/renewals/index.php?item=' . $item['id'];

        foreach ($notifyIds as $uid) {
            $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)")
                ->execute([$item['organization_id'], $uid, $title, $message, $link]);
            $sent++;

            // In-App Notification बरोबरच — त्याच व्यक्तीला Email सुद्धा पाठवतो
            try {
                $uStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
                $uStmt->execute([$uid]);
                $u = $uStmt->fetch();
                if ($u && $u['email']) {
                    sendRenewalReminderEmail($u['email'], $u['name'], $item['item_name'], $daysLeft, date('d M Y', strtotime($item['expiry_date'])), $orgName, $emailLink);
                }
            } catch (Throwable $e) {
                // Email पाठवता आला नाही तरी In-App Notification आधीच गेली आहे — पुढे चालू ठेवतो
            }
        }
    } catch (PDOException $e) {
        // skip this item on error, continue with the rest
    }
}

echo "Done. $sent notifications sent for " . date('Y-m-d H:i:s') . "\n";
