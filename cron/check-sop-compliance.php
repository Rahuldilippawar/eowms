<?php
/**
 * रोज संध्याकाळी (उदा. 8 PM) चालवा — SOP Compliance तपासून नोंदवते आणि
 * पूर्ण न झालेल्या पायऱ्यांची — प्रत्येक HOD ला रात्रीतून एकच (Digest) Notification पाठवते.
 * "रोज"च्या पायऱ्या रोज तपासल्या जातात (रविवार/सुट्टीच्या दिवशी नाही);
 * "आठवड्यातून"/"महिन्यातून" च्या पायऱ्या फक्त त्या आठवड्याच्या/महिन्याच्या
 * शेवटच्या दिवशीच तपासल्या जातात (रोज इशारा देत राहणं टाळण्यासाठी).
 * Hostinger cPanel -> Cron Jobs मध्ये टाका:
 *   0 20 * * *  php /home/xxxx/public_html/cron/check-sop-compliance.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/sop-helpers.php';

$today = date('Y-m-d');
$isLastDayOfWeek = ((int)date('N', strtotime($today)) === 7); // रविवार
$isLastDayOfMonth = ((int)date('j', strtotime($today)) === (int)date('t', strtotime($today)));

$logInsert = $pdo->prepare("
    INSERT INTO sop_compliance_logs (sop_step_id, department_id, log_date, status)
    VALUES (?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE status = VALUES(status)
");
$manualCheck = $pdo->prepare("
    SELECT id FROM sop_compliance_logs
    WHERE sop_step_id = ? AND department_id = ? AND log_date BETWEEN ? AND ? AND status = 'done'
    LIMIT 1
");
$existsStmt = $pdo->prepare("SELECT id FROM sop_compliance_logs WHERE sop_step_id = ? AND department_id = ? AND log_date = ?");
$notifiedCount = 0;

$orgsStmt = $pdo->query("SELECT id FROM organizations WHERE is_active = 1");
foreach ($orgsStmt->fetchAll() as $org) {
    $orgId = $org['id'];

    // HOD => ['depts' => [deptId => true], 'lines' => [...]] — रात्रीतून एकच Notification
    $digest = [];
    $offDayCache = [];

    $sopsStmt = $pdo->prepare("SELECT * FROM sops WHERE organization_id = ? AND is_active = 1");
    $sopsStmt->execute([$orgId]);
    $sops = $sopsStmt->fetchAll();

    foreach ($sops as $sop) {
        $deptsStmt = $pdo->prepare("
            SELECT d.* FROM sop_department_assignments sda JOIN departments d ON d.id = sda.department_id
            WHERE sda.sop_id = ? AND d.organization_id = ?
        ");
        $deptsStmt->execute([$sop['id'], $orgId]);
        $depts = $deptsStmt->fetchAll();

        $stepsStmt = $pdo->prepare("SELECT * FROM sop_steps WHERE sop_id = ?");
        $stepsStmt->execute([$sop['id']]);
        $steps = $stepsStmt->fetchAll();

        foreach ($depts as $dept) {
            $campusKey = (int)($dept['campus_id'] ?? 0);
            if (!isset($offDayCache[$campusKey])) {
                $offDayCache[$campusKey] = isset(sopOffDays($pdo, $orgId, $dept['campus_id'], $today, $today)[$today]);
            }
            $isOffToday = $offDayCache[$campusKey];

            foreach ($steps as $step) {
                // "रोज"च्या पायऱ्या — रविवार/सुट्टीला तपासत नाही
                if ($step['frequency'] === 'daily' && $isOffToday) { continue; }
                // "आठवड्यातून"/"महिन्यातून" च्या पायऱ्या — फक्त त्या कालावधीच्या शेवटच्या दिवशीच तपासा
                if ($step['frequency'] === 'weekly' && !$isLastDayOfWeek) { continue; }
                if ($step['frequency'] === 'monthly' && !$isLastDayOfMonth) { continue; }

                [$periodStart, $periodEnd] = sopGetPeriodRange($step['frequency'], $today);
                $missed = false;

                if ($step['detection_type'] === 'auto') {
                    // Auto पायऱ्यांसाठी — या कालावधीची स्थिती कायमची नोंदवून ठेवा (इतिहासासाठी)
                    $isDone = sopCheckAutoComplianceInRange($pdo, $step['auto_source_module'], $dept['id'], $periodStart, $periodEnd);
                    if ($isDone === null) { continue; }
                    $logInsert->execute([$step['id'], $dept['id'], $today, $isDone ? 'done' : 'not_done']);
                    $missed = !$isDone;
                } else {
                    // Manual पायरी — या संपूर्ण कालावधीत कधीही टिक झाली आहे का ते बघा
                    $manualCheck->execute([$step['id'], $dept['id'], $periodStart, $periodEnd]);
                    if (!$manualCheck->fetch()) {
                        // आजच्या तारखेखाली "not_done" नोंदवा (already असेल तर पुन्हा नाही)
                        $existsStmt->execute([$step['id'], $dept['id'], $today]);
                        if (!$existsStmt->fetch()) {
                            $pdo->prepare("INSERT INTO sop_compliance_logs (sop_step_id, department_id, log_date, status) VALUES (?, ?, ?, 'not_done')")
                                ->execute([$step['id'], $dept['id'], $today]);
                        }
                        $missed = true;
                    }
                }

                if ($missed && $dept['hod_id']) {
                    $hodId = (int)$dept['hod_id'];
                    $digest[$hodId]['depts'][$dept['id']] = true;
                    $digest[$hodId]['lines'][] = "• {$sop['title']} — {$step['step_description']}" . ' (' . ($dept['name_local'] ?: $dept['name']) . ')';
                }
            }
        }
    }

    // प्रत्येक HOD ला — एकच सारांश Notification
    foreach ($digest as $hodId => $info) {
        $count = count($info['lines']);
        $lines = array_slice($info['lines'], 0, 15);
        if ($count > 15) { $lines[] = '… आणखी ' . ($count - 15); }
        $deptIds = array_keys($info['depts']);
        $link = count($deptIds) === 1 ? 'sop/checklist.php?department_id=' . $deptIds[0] : 'sop/checklist.php';
        notifyUser($hodId, $orgId, "SOP — $count पायऱ्या पूर्ण झाल्या नाहीत", implode("\n", $lines), $link);
        $notifiedCount++;
    }
}

echo "SOP compliance check complete: $today ($notifiedCount digest notifications)\n";
