<?php
// ============================================================
// वारंवार उशीर/गैरहजर — Auto-Alert Cron
// रोज सकाळी एकदा चालवा (उदा. mark-absent-daily.php नंतर लगेच) —
// मागच्या 7 दिवसांत ज्या Staff चे 3+ वेळा गैरहजर/उशीर झाले आहेत,
// त्यांच्या HOD ला आपोआप इशारा पाठवते.
//
// Command:
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/attendance-pattern-alert.php
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';

try {
    $pdo->query("SELECT 1 FROM staff_attendance LIMIT 1");
} catch (PDOException $e) {
    die("staff_attendance table not found.\n");
}

$sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
$today = date('Y-m-d');

// मागच्या 7 दिवसांत — प्रत्येक Staff चे किती Absent + किती Late झाले
$patternStmt = $pdo->query("
    SELECT sa.user_id, u.name, u.organization_id, u.department_id,
      SUM(CASE WHEN sa.status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
      SUM(CASE WHEN sa.is_late = 1 THEN 1 ELSE 0 END) AS late_count
    FROM staff_attendance sa JOIN users u ON u.id = sa.user_id
    WHERE sa.attendance_date BETWEEN '$sevenDaysAgo' AND '$today'
    GROUP BY sa.user_id, u.name, u.organization_id, u.department_id
    HAVING absent_count >= 3 OR late_count >= 3
");

$alertedCount = 0;
foreach ($patternStmt->fetchAll() as $p) {
    // या आठवड्यात आधीच एकदा इशारा पाठवला असेल तर पुन्हा पाठवत नाही
    // (activity_logs मध्ये तपासून) — रोज तोच इशारा वारंवार जाऊ नये
    $alreadySentStmt = $pdo->prepare("
        SELECT COUNT(*) c FROM activity_logs
        WHERE entity_type = 'attendance_pattern_alert' AND entity_id = ? AND created_at >= ?
    ");
    $alreadySentStmt->execute([$p['user_id'], date('Y-m-d 00:00:00', strtotime('-6 days'))]);
    if ($alreadySentStmt->fetch()['c'] > 0) continue;

    $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
    $hodStmt->execute([$p['department_id']]);
    $hodId = $hodStmt->fetch()['hod_id'] ?? null;

    $reason = [];
    if ($p['absent_count'] >= 3) { $reason[] = "{$p['absent_count']} वेळा गैरहजर"; }
    if ($p['late_count'] >= 3) { $reason[] = "{$p['late_count']} वेळा उशीर"; }
    $reasonText = implode(', ', $reason);

    if ($hodId) {
        notifyUser($hodId, $p['organization_id'], "⚠️ {$p['name']} — मागच्या 7 दिवसांत $reasonText", 'कृपया याकडे लक्ष द्या', '../staff/attendance-summary.php');
    }
    // Management लाही कळवतो
    try {
        $mgmtStmt = $pdo->prepare("
            SELECT u.id FROM users u JOIN role_definitions rd ON rd.role_key = u.role
            WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'management'
        ");
        $mgmtStmt->execute([$p['organization_id']]);
        foreach ($mgmtStmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
            notifyUser($mid, $p['organization_id'], "⚠️ {$p['name']} — मागच्या 7 दिवसांत $reasonText", 'कृपया याकडे लक्ष द्या', '../staff/attendance-summary.php');
        }
    } catch (Throwable $e) {}

    logActivity('Attendance Pattern Alert पाठवला', 'attendance_pattern_alert', $p['user_id'], $reasonText);
    $alertedCount++;
}

echo "Done. $alertedCount staff flagged for repeated late/absent pattern.\n";
