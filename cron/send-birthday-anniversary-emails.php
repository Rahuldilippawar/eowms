<?php
// ============================================================
// BIRTHDAY व WORK ANNIVERSARY EMAIL CRON — दररोज एकदा चालवायचं
// (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule (दिवसातून एकदा, उदा. सकाळी 7:00):
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/send-birthday-anniversary-emails.php
//
// आजचा वाढदिवस/कामाचा वर्धापनदिन असलेल्या सगळ्या Staff ला Email
// पाठवते. Welcome Page (welcome.php) वर हेच लोक Photo सह आधीच
// दिसतात — हे त्याला पूरक, Email-द्वारे वैयक्तिक शुभेच्छा.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/mailer.php';

$sentBirthday = 0;
$sentAnniversary = 0;

try {
    $orgs = $pdo->query("SELECT id, name FROM organizations")->fetchAll();
} catch (PDOException $e) {
    die("organizations table not found — nothing to do.\n");
}

foreach ($orgs as $org) {
    // ---------------- वाढदिवस ----------------
    try {
        $bStmt = $pdo->prepare("
            SELECT id, name, email FROM users
            WHERE organization_id = ? AND is_active = 1 AND date_of_birth IS NOT NULL AND email IS NOT NULL
              AND MONTH(date_of_birth) = MONTH(CURDATE()) AND DAY(date_of_birth) = DAY(CURDATE())
        ");
        $bStmt->execute([$org['id']]);
        foreach ($bStmt->fetchAll() as $person) {
            if (sendBirthdayEmail($person['email'], $person['name'], $org['name'])) {
                $sentBirthday++;
            }
        }
    } catch (PDOException $e) { /* users table issue — skip this org */ }

    // ---------------- कामाचा वर्धापनदिन ----------------
    try {
        $aStmt = $pdo->prepare("
            SELECT id, name, email, TIMESTAMPDIFF(YEAR, joining_date, CURDATE()) AS years_completed
            FROM users
            WHERE organization_id = ? AND is_active = 1 AND joining_date IS NOT NULL AND email IS NOT NULL
              AND MONTH(joining_date) = MONTH(CURDATE()) AND DAY(joining_date) = DAY(CURDATE())
              AND YEAR(joining_date) < YEAR(CURDATE())
        ");
        $aStmt->execute([$org['id']]);
        foreach ($aStmt->fetchAll() as $person) {
            if (sendWorkAnniversaryEmail($person['email'], $person['name'], (int)$person['years_completed'], $org['name'])) {
                $sentAnniversary++;
            }
            // Management लाही कळवतो — जेणेकरून त्यांना अभिनंदन करायला आठवण राहील
            try {
                $mgmtStmt = $pdo->prepare("
                    SELECT u.id FROM users u JOIN role_definitions rd ON rd.role_key = u.role
                    WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'management'
                ");
                $mgmtStmt->execute([$org['id']]);
                foreach ($mgmtStmt->fetchAll(PDO::FETCH_COLUMN) as $mid) {
                    notifyUser($mid, $org['id'], "🎊 आज {$person['name']} यांचा कामाचा वर्धापनदिन", "{$person['years_completed']} वर्षं पूर्ण — त्यांना अभिनंदन करा!", '../welcome.php');
                }
            } catch (Throwable $e) {}
        }
    } catch (PDOException $e) { /* users table issue — skip this org */ }
}

echo "Done. $sentBirthday birthday emails, $sentAnniversary anniversary emails sent for " . date('Y-m-d H:i:s') . "\n";
