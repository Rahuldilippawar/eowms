<?php
/**
 * Cron Jobs — Shared Helpers
 *  - आजची तारीख (Testing साठी --date=YYYY-MM-DD ने बदलता येते)
 *  - प्रत्येक Job शेवटचं कधी चाललं (cron_job_runs) — Admin पानावर दिसतं
 *  - दिवसातून एकदाच Digest (cron_digest_log) — एका व्यक्तीला एका Cron कडून दिवसाला एकच सूचना
 */

/**
 * cron/run-all.php ची Job-यादी: daily = रोज या तासानंतर एकदा; every = दर इतक्या मिनिटांनी.
 * नवीन Cron File जोडली तर इथेही जोडा (Admin पानावर Last-Run दिसण्यासाठी).
 */
function cronRunAllJobs() {
    return [
        'send-push-queue'                  => ['every' => 5],
        'process-biometric-attendance'     => ['every' => 15],
        'escalate-tickets'                 => ['every' => 60],
        'scheduled-backup'                 => ['daily' => 2],
        'send-birthday-anniversary-emails' => ['daily' => 7],
        'send-renewal-reminders'           => ['daily' => 8],
        'vehicle-service-due'              => ['daily' => 8],
        'gaushala-vaccination-due'         => ['daily' => 8],
        'check-overdue-tasks'              => ['daily' => 9],
        'check-overdue-letters'            => ['daily' => 9],
        'attendance-pattern-alert'         => ['daily' => 10],
        'check-sop-compliance'             => ['daily' => 20],
        'check-hostel-attendance'          => ['daily' => 20],
        'recurring-events'                 => ['daily' => 22],
        'mark-absent-daily'                => ['daily' => 23],
    ];
}

/** आजची तारीख — CLI वर `--date=2026-01-31` दिल्यास ती (फक्त Testing साठी) */
function cronToday() {
    global $argv;
    foreach ((array)($argv ?? []) as $arg) {
        if (preg_match('/^--date=(\d{4}-\d{2}-\d{2})$/', $arg, $m)) { return $m[1]; }
    }
    return date('Y-m-d');
}

/** Job सुरू झाल्याची नोंद (run-all.php मधून चालत असेल तर तेच नोंद करतं — दुप्पट नाही) */
function cronJobStart($pdo, $jobKey) {
    if (getenv('EADMIN_RUN_ALL')) { return; }
    try {
        $pdo->prepare("INSERT INTO cron_job_runs (job_key, last_started_at, last_status) VALUES (?, NOW(), 'running')
                       ON DUPLICATE KEY UPDATE last_started_at = NOW(), last_status = 'running'")->execute([$jobKey]);
    } catch (PDOException $e) { /* migration not run yet */ }
}

function cronJobFinish($pdo, $jobKey, $ok, $output = '') {
    if (getenv('EADMIN_RUN_ALL')) { return; }
    try {
        $pdo->prepare("UPDATE cron_job_runs SET last_finished_at = NOW(), last_status = ?, last_output = ?,
                       last_duration_sec = TIMESTAMPDIFF(SECOND, last_started_at, NOW()) WHERE job_key = ?")
            ->execute([$ok ? 'success' : 'failed', mb_substr((string)$output, -2000), $jobKey]);
    } catch (PDOException $e) { /* migration not run yet */ }
}

/** या व्यक्तीला आज या Cron ची Digest आधीच गेली आहे का — नसेल तर नोंद करून false देतो */
function cronDigestClaim($pdo, $cronKey, $userId, $date, $itemCount = 0) {
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO cron_digest_log (cron_key, user_id, digest_date, item_count) VALUES (?, ?, ?, ?)");
        $stmt->execute([$cronKey, $userId, $date, $itemCount]);
        return $stmt->rowCount() > 0; // true = आज पहिल्यांदाच, पाठवा
    } catch (PDOException $e) {
        return true; // Table नसेल तर — Dedupe शिवाय पाठवतो
    }
}

/** संस्थेचे Management-tier (role_definitions.category) Users — role_key Hardcode न करता */
function cronManagementUserIds($pdo, $orgId) {
    try {
        $stmt = $pdo->prepare("SELECT u.id FROM users u JOIN role_definitions rd ON rd.role_key = u.role
                               WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'management'");
        $stmt->execute([$orgId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

/** एका यादीतून पहिल्या काही नावांचा छोटा मजकूर (Digest मध्ये) */
function cronDigestList(array $lines, $max = 3) {
    $shown = array_slice($lines, 0, $max);
    $more = count($lines) - count($shown);
    return implode(', ', $shown) . ($more > 0 ? " आणि आणखी $more" : '');
}
