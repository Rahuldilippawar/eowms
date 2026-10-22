<?php
// ============================================================
// HOSTEL ATTENDANCE CHECK CRON — run this once daily (evening,
// e.g. 8:00 PM, after all 3 sessions should be done) via a
// scheduled cron job (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/check-hostel-attendance.php
//
// For every active hostel, checks whether TODAY's attendance was
// marked for all 3 sessions (सकाळ/दुपार/संध्याकाळ). If any session
// was never marked, notifies:
//   - The hostel's allocated staff + the "वसतिगृह" department HOD
//   - Management-tier users of that organization
// Avoids duplicate notifications if run more than once on the same day.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';

$today = date('Y-m-d');
$sessions = ['morning' => 'सकाळ', 'afternoon' => 'दुपार', 'evening' => 'संध्याकाळ'];
$sentCount = 0;

try {
    $hostels = $pdo->query("SELECT h.*, d.hod_id AS dept_hod_id FROM hostels h LEFT JOIN departments d ON d.id = h.department_id WHERE h.is_active = 1")->fetchAll();
} catch (PDOException $e) {
    die("hostels table not found — nothing to do.\n");
}

foreach ($hostels as $hostel) {
    // Only bother checking hostels that actually have active students
    $studentCount = $pdo->prepare("SELECT COUNT(*) c FROM hostel_students WHERE hostel_id = ? AND is_active = 1");
    $studentCount->execute([$hostel['id']]);
    if ((int)$studentCount->fetch()['c'] === 0) continue;

    $missingSessions = [];
    foreach ($sessions as $key => $label) {
        try {
            $check = $pdo->prepare("SELECT COUNT(*) c FROM hostel_student_attendance WHERE hostel_id = ? AND attendance_date = ? AND session = ?");
            $check->execute([$hostel['id'], $today, $key]);
            if ((int)$check->fetch()['c'] === 0) {
                $missingSessions[] = $label;
            }
        } catch (PDOException $e) {
            break 2; // hostel_student_attendance table not migrated — stop entirely
        }
    }

    if (empty($missingSessions)) continue;

    $link = 'hostels/attendance.php?hostel_id=' . $hostel['id'];
    $title = "⚠️ {$hostel['name']} — हजेरी नोंदवलेली नाही";
    $message = implode(', ', $missingSessions) . ' सत्राची विद्यार्थी हजेरी आज अजून नोंदवलेली नाही.';

    // Dedup — don't re-notify the same people again today for this hostel
    $dupCheck = $pdo->prepare("SELECT COUNT(*) c FROM notifications WHERE link = ? AND DATE(created_at) = ?");
    $dupCheck->execute([$link, $today]);
    if ((int)$dupCheck->fetch()['c'] > 0) continue;

    $recipients = [];

    // Hostel department HOD
    if (!empty($hostel['dept_hod_id'])) $recipients[] = $hostel['dept_hod_id'];

    // Allocated hostel staff
    try {
        $staffStmt = $pdo->prepare("SELECT user_id FROM hostel_staff WHERE hostel_id = ?");
        $staffStmt->execute([$hostel['id']]);
        foreach ($staffStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) { $recipients[] = $uid; }
    } catch (PDOException $e) {}

    // Management-tier users of this organization
    $mgmtStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1 AND role IN ('super_admin','president','executive_president','general_manager')");
    $mgmtStmt->execute([$hostel['organization_id']]);
    foreach ($mgmtStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) { $recipients[] = $uid; }

    $recipients = array_unique($recipients);
    foreach ($recipients as $uid) {
        try {
            $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)")
                ->execute([$hostel['organization_id'], $uid, $title, $message, $link]);
            $sentCount++;
        } catch (PDOException $e) {}
    }
}

echo "Done. $sentCount notifications sent for " . date('Y-m-d H:i:s') . "\n";
