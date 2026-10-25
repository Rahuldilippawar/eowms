<?php
// ============================================================
// RENEWAL REMINDER CRON — run this once daily via a scheduled
// cron job (Hostinger hPanel -> Advanced -> Cron Jobs), किंवा
// cron/run-all.php मधून आपोआप.
//
// Command to schedule (once a day, e.g. 8:00 AM):
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/send-renewal-reminders.php
//
// मुदत संपण्याआधी 90/60/30/15/7/3/1/0 दिवस — आणि मुदत संपल्यावर
// 1 दिवसाने, मग दर 7 दिवसांनी (नूतनीकरण होईपर्यंत) — In-App
// Notification + Email पाठवते.
//   - जबाबदार व्यक्ती (नसेल तर संपूर्ण विभाग)
//   - 7 दिवस किंवा कमी उरले / मुदत संपली — + विभाग HOD
//   - मुदत संपून 7 दिवसांपेक्षा जास्त — + Management (role_definitions.category)
// एकाच नोंदीसाठी एकाच Milestone ची सूचना (त्याच expiry_date साठी)
// कधीही दुसऱ्यांदा जात नाही — renewal_reminder_log मध्ये नोंद ठेवतो.
// Renew केल्यावर expiry_date बदलते, त्यामुळे पुढच्या Cycle चे Reminders
// पुन्हा आपोआप सुरू होतात.
//
// Testing: php send-renewal-reminders.php --date=2026-12-01
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';
require_once __DIR__ . '/../includes/cron-helpers.php';

const RENEWAL_MILESTONES = [90, 60, 30, 15, 7, 3, 1, 0];
const RENEWAL_CATCHUP_DAYS = 2;      // Cron एखादा दिवस चुकला तर — 2 दिवसांपर्यंत चुकलेला Milestone पाठवतो
const RENEWAL_ESCALATE_AFTER_DAYS = 7;

$today = cronToday();
$sent = 0;
cronJobStart($pdo, 'send-renewal-reminders');

/** आजच्या उरलेल्या दिवसांवरून — कोणता Milestone लागू आहे (नसेल तर null) */
function renewalDueMilestone($daysLeft) {
    if ($daysLeft >= 0) {
        foreach (array_reverse(RENEWAL_MILESTONES) as $m) { // 0,1,3,7,... — सगळ्यात जवळचा आधी
            if ($m >= $daysLeft && $m - $daysLeft <= RENEWAL_CATCHUP_DAYS) { return 'd' . $m; }
            if ($m > $daysLeft + RENEWAL_CATCHUP_DAYS) { break; }
        }
        return null;
    }
    $overdue = -$daysLeft;
    $k = 1 + 7 * intdiv($overdue - 1, 7); // 1, 8, 15, 22 ...
    return ($overdue - $k <= RENEWAL_CATCHUP_DAYS) ? 'o' . $k : null;
}

try {
    $items = $pdo->query("SELECT r.* FROM renewal_items r WHERE r.status <> 'inactive'")->fetchAll();
} catch (PDOException $e) {
    cronJobFinish($pdo, 'send-renewal-reminders', false, 'renewal_items table not found');
    die("renewal_items table not found — nothing to do.\n");
}

$orgNames = [];
foreach ($items as $item) {
    $daysLeft = (int)round((strtotime($item['expiry_date']) - strtotime($today)) / 86400);
    $milestone = renewalDueMilestone($daysLeft);
    if ($milestone === null) { continue; }

    // Dedupe — item + milestone + expiry_date (Renew केल्यावर नवीन expiry_date = नवीन Cycle)
    try {
        $claim = $pdo->prepare("INSERT IGNORE INTO renewal_reminder_log (renewal_item_id, milestone, expiry_date) VALUES (?, ?, ?)");
        $claim->execute([$item['id'], $milestone, $item['expiry_date']]);
        if ($claim->rowCount() === 0) { continue; }
    } catch (PDOException $e) {
        die("renewal_reminder_log table not found — Database Update चालवा.\n");
    }

    $overdueDays = max(0, -$daysLeft);
    $label = $daysLeft < 0 ? "मुदत संपून $overdueDays दिवस झाले" : ($daysLeft === 0 ? 'आज मुदत संपते' : "$daysLeft दिवसात मुदत संपणार");
    $title = ($daysLeft < 0 ? '⚠️ ' : '🔔 ') . "नूतनीकरण सूचना — {$item['item_name']}";
    $message = $label . ' (' . date('d M Y', strtotime($item['expiry_date'])) . ')';
    $link = 'renewals/index.php?item=' . $item['id'];

    // ------------- Escalating Reminder -------------
    $notifyIds = [];
    try {
        if (!empty($item['responsible_user_id'])) {
            $notifyIds[] = (int)$item['responsible_user_id'];
        } else {
            $staffStmt = $pdo->prepare("SELECT id FROM users WHERE department_id = ? AND is_active = 1");
            $staffStmt->execute([$item['department_id']]);
            $notifyIds = array_merge($notifyIds, array_map('intval', $staffStmt->fetchAll(PDO::FETCH_COLUMN)));
        }

        if ($daysLeft <= 7) {
            $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
            $hodStmt->execute([$item['department_id']]);
            $hodId = $hodStmt->fetch()['hod_id'] ?? null;
            if ($hodId) { $notifyIds[] = (int)$hodId; }
        }

        if ($overdueDays > RENEWAL_ESCALATE_AFTER_DAYS) {
            $notifyIds = array_merge($notifyIds, cronManagementUserIds($pdo, $item['organization_id']));
            $title = "🚨 Escalation — {$item['item_name']}";
        }

        // विभागात कोणीच नसेल (HOD/Staff नाही) — तर सूचना कुठेच न जाता हरवू नये म्हणून Management ला
        if (!array_filter($notifyIds)) {
            $notifyIds = cronManagementUserIds($pdo, $item['organization_id']);
        }
        $notifyIds = array_values(array_unique(array_filter($notifyIds)));

        if (!isset($orgNames[$item['organization_id']])) {
            $orgStmt = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
            $orgStmt->execute([$item['organization_id']]);
            $orgNames[$item['organization_id']] = $orgStmt->fetch()['name'] ?? 'E-Admin';
        }
        $emailLink = rtrim(SITE_BASE_URL, '/') . '/' . $link;

        foreach ($notifyIds as $uid) {
            notifyUser($uid, $item['organization_id'], $title, $message, $link);
            $sent++;

            // In-App Notification बरोबरच — त्याच व्यक्तीला Email सुद्धा पाठवतो
            try {
                $uStmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ? AND is_active = 1");
                $uStmt->execute([$uid]);
                $u = $uStmt->fetch();
                if ($u && $u['email']) {
                    sendRenewalReminderEmail($u['email'], $u['name'], $item['item_name'], $daysLeft, date('d M Y', strtotime($item['expiry_date'])), $orgNames[$item['organization_id']], $emailLink);
                }
            } catch (Throwable $e) {
                // Email पाठवता आला नाही तरी In-App Notification आधीच गेली आहे — पुढे चालू ठेवतो
            }
        }
        $pdo->prepare("UPDATE renewal_reminder_log SET recipients = ? WHERE renewal_item_id = ? AND milestone = ? AND expiry_date = ?")
            ->execute([count($notifyIds), $item['id'], $milestone, $item['expiry_date']]);
    } catch (PDOException $e) {
        // skip this item on error, continue with the rest
    }
}

$out = "Done. $sent renewal notifications sent for $today (" . date('Y-m-d H:i:s') . ")";
cronJobFinish($pdo, 'send-renewal-reminders', true, $out);
echo $out . "\n";
