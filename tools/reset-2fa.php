<?php
/**
 * 🔐 आणीबाणी: एखाद्याचं 2-Step Login बंद करा (उदा. Super Admin चा फोन हरवला आणि Recovery Codes पण नाहीत).
 * Server वर (Hostinger → SSH, किंवा एकदाच Cron Job म्हणून) चालवा:
 *   php tools/reset-2fa.php admin@example.com
 * पुढच्या Login ला ती व्यक्ती 2-Step पुन्हा सेट करेल.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/two-factor.php';
$email = trim($argv[1] ?? '');
if ($email === '') { exit("वापर: php tools/reset-2fa.php <email>\n"); }
$st = $pdo->prepare("SELECT id, name, organization_id FROM users WHERE email = ?");
$st->execute([$email]);
$u = $st->fetch();
if (!$u) { exit("हा Email सापडला नाही: $email\n"); }
tfaDisable($pdo, $u['id']);
try {
    $pdo->prepare("INSERT INTO activity_logs (organization_id, user_id, actor_name, action, entity_type, entity_id, details, created_at) VALUES (?, NULL, 'Server (CLI)', '2-Step Login Reset (Server वरून)', 'user', ?, ?, NOW())")
        ->execute([$u['organization_id'], $u['id'], $u['name']]);
} catch (PDOException $e) {}
echo "✅ {$u['name']} ($email) यांचं 2-Step Login बंद केलं. पुढच्या Login ला पुन्हा सेट करावं लागेल.\n";
