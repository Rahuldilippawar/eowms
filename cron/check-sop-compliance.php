<?php
/**
 * रोज संध्याकाळी (उदा. 8 PM) चालवा — SOP Compliance तपासून नोंदवते आणि
 * Manual पायरी पूर्ण न झाल्यास त्या विभागाच्या HOD ला Notification पाठवते.
 * "रोज"च्या पायऱ्या रोज तपासल्या जातात; "आठवड्यातून"/"महिन्यातून" च्या
 * पायऱ्या फक्त त्या आठवड्याच्या/महिन्याच्या शेवटच्या दिवशीच तपासल्या
 * जातात (रोज इशारा देत राहणं टाळण्यासाठी).
 * Hostinger cPanel -> Cron Jobs मध्ये टाका:
 *   0 20 * * *  php /home/xxxx/public_html/cron/check-sop-compliance.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sop-helpers.php';

$today = date('Y-m-d');
$isLastDayOfWeek = ((int)date('N', strtotime($today)) === 7); // रविवार
$isLastDayOfMonth = ((int)date('j', strtotime($today)) === (int)date('t', strtotime($today)));

$orgsStmt = $pdo->query("SELECT id FROM organizations WHERE is_active = 1");
foreach ($orgsStmt->fetchAll() as $org) {
    $orgId = $org['id'];

    $sopsStmt = $pdo->prepare("SELECT * FROM sops WHERE organization_id = ? AND is_active = 1");
    $sopsStmt->execute([$orgId]);
    $sops = $sopsStmt->fetchAll();

    foreach ($sops as $sop) {
        $deptsStmt = $pdo->prepare("
            SELECT d.* FROM sop_department_assignments sda JOIN departments d ON d.id = sda.department_id
            WHERE sda.sop_id = ?
        ");
        $deptsStmt->execute([$sop['id']]);
        $depts = $deptsStmt->fetchAll();

        $stepsStmt = $pdo->prepare("SELECT * FROM sop_steps WHERE sop_id = ?");
        $stepsStmt->execute([$sop['id']]);
        $steps = $stepsStmt->fetchAll();

        foreach ($depts as $dept) {
            foreach ($steps as $step) {
                // "आठवड्यातून"/"महिन्यातून" च्या पायऱ्या — फक्त त्या
                // कालावधीच्या शेवटच्या दिवशीच तपासा, बाकी दिवशी वगळा.
                if ($step['frequency'] === 'weekly' && !$isLastDayOfWeek) { continue; }
                if ($step['frequency'] === 'monthly' && !$isLastDayOfMonth) { continue; }

                [$periodStart, $periodEnd] = sopGetPeriodRange($step['frequency'], $today);

                if ($step['detection_type'] === 'auto') {
                    // Auto पायऱ्यांसाठी — या कालावधीची स्थिती कायमची नोंदवून ठेवा (इतिहासासाठी)
                    $isDone = sopCheckAutoComplianceInRange($pdo, $step['auto_source_module'], $dept['id'], $periodStart, $periodEnd);
                    if ($isDone === null) { continue; }
                    $pdo->prepare("
                        INSERT INTO sop_compliance_logs (sop_step_id, department_id, log_date, status)
                        VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status)
                    ")->execute([$step['id'], $dept['id'], $today, $isDone ? 'done' : 'not_done']);

                    if (!$isDone && $dept['hod_id']) {
                        notifyUser($dept['hod_id'], $orgId, 'SOP पालन झालं नाही', "{$sop['title']} — {$step['step_description']}", '../sop/checklist.php?department_id=' . $dept['id']);
                    }
                } else {
                    // Manual पायरी — या संपूर्ण कालावधीत कधीही टिक झाली आहे का ते बघा
                    $checkStmt = $pdo->prepare("
                        SELECT id FROM sop_compliance_logs
                        WHERE sop_step_id = ? AND department_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
                        LIMIT 1
                    ");
                    $checkStmt->execute([$step['id'], $dept['id'], $periodStart, $periodEnd]);
                    $doneInPeriod = $checkStmt->fetch();

                    if (!$doneInPeriod) {
                        // आजच्या तारखेखाली "not_done" नोंदवा (already असेल तर पुन्हा नाही)
                        $existsStmt = $pdo->prepare("SELECT id FROM sop_compliance_logs WHERE sop_step_id = ? AND department_id = ? AND log_date = ?");
                        $existsStmt->execute([$step['id'], $dept['id'], $today]);
                        if (!$existsStmt->fetch()) {
                            $pdo->prepare("INSERT INTO sop_compliance_logs (sop_step_id, department_id, log_date, status) VALUES (?, ?, ?, 'not_done')")
                                ->execute([$step['id'], $dept['id'], $today]);
                        }
                        if ($dept['hod_id']) {
                            notifyUser($dept['hod_id'], $orgId, 'SOP पायरी पूर्ण झाली नाही', "{$sop['title']} — {$step['step_description']}", '../sop/checklist.php?department_id=' . $dept['id']);
                        }
                    }
                }
            }
        }
    }
}

echo "SOP compliance check complete: $today\n";
