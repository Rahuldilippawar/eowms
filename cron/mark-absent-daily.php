<?php
// ============================================================
// AUTO-ABSENT MARKING CRON
// रोज रात्री उशिरा (उदा. 11 PM) एकदा चालवा — त्या दिवशी ज्या Staff
// चा एकही Punch आला नाही, आणि तो दिवस सुट्टी/Weekly-Off नाही —
// त्यांना आपोआप "गैरहजर" म्हणून चिन्हांकित करते.
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/mark-absent-daily.php
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';

$today = date('Y-m-d');
$dayOfWeek = (int)date('w', strtotime($today)); // 0=रविवार ... 6=शनिवार

$orgsStmt = $pdo->query("SELECT id FROM organizations WHERE is_active = 1");
$markedCount = 0;

foreach ($orgsStmt->fetchAll(PDO::FETCH_COLUMN) as $orgId) {
    // आज सुट्टी आहे का — असेल तर कोणालाही Absent मानायचं नाही
    $holidayStmt = $pdo->prepare("SELECT campus_id FROM holidays WHERE organization_id = ? AND holiday_date = ?");
    $holidayStmt->execute([$orgId, $today]);
    $holidayCampuses = $holidayStmt->fetchAll(PDO::FETCH_COLUMN);
    $isOrgWideHoliday = in_array(null, $holidayCampuses, true) || in_array('', $holidayCampuses, true);

    $staffStmt = $pdo->prepare("
        SELECT u.id, u.campus_id,
          (SELECT COUNT(*) FROM staff_attendance WHERE user_id = u.id AND attendance_date = ?) AS already_marked,
          s.weekly_off_1, s.weekly_off_2
        FROM users u LEFT JOIN staff_shift_settings s ON s.user_id = u.id
        WHERE u.organization_id = ? AND u.is_active = 1 AND u.biometric_pin IS NOT NULL
    ");
    $staffStmt->execute([$today, $orgId]);

    foreach ($staffStmt->fetchAll() as $s) {
        if ($s['already_marked'] > 0) continue; // आधीच काहीतरी नोंदवलेलं आहे (Present/Manual/इ.)

        if ($isOrgWideHoliday || in_array($s['campus_id'], $holidayCampuses, true)) {
            $pdo->prepare("INSERT IGNORE INTO staff_attendance (organization_id, user_id, attendance_date, status, source) VALUES (?, ?, ?, 'holiday', 'manual')")
                ->execute([$orgId, $s['id'], $today]);
            continue;
        }

        // Weekly-Off दिवस असेल तर — Absent नाही, फक्त वगळतो (नोंदच करत नाही)
        $off1 = $s['weekly_off_1'] !== null ? (int)$s['weekly_off_1'] : 0; // Default रविवार
        $off2 = $s['weekly_off_2'] !== null ? (int)$s['weekly_off_2'] : null;
        if ($dayOfWeek === $off1 || ($off2 !== null && $dayOfWeek === $off2)) continue;

        $pdo->prepare("INSERT IGNORE INTO staff_attendance (organization_id, user_id, attendance_date, status, source) VALUES (?, ?, ?, 'absent', 'biometric')")
            ->execute([$orgId, $s['id'], $today]);
        $markedCount++;
    }
}

echo "Done. $markedCount staff marked absent for $today.\n";
