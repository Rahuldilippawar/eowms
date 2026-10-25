<?php
// ============================================================
// RUN-ALL CRON — सगळे Cron Jobs एकाच Cron Line मधून, क्रमाने.
// Hostinger hPanel -> Advanced -> Cron Jobs मध्ये फक्त ही एकच ओळ टाका
// (दर 5 मिनिटांनी):
//   */5 * * * * php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/run-all.php
//
// प्रत्येक Job ची वेळ खाली ठरलेली आहे (रोज ठराविक तासानंतर एकदा, किंवा
// दर N मिनिटांनी) — Job "Due" असेल तरच चालतो. प्रत्येक Job शेवटचं कधी
// चाललं / यशस्वी झालं का — cron_job_runs Table मध्ये नोंदवतो, आणि ते
// Super Admin ला "Automated Backup" पानावर दिसतं (Cron बंद पडला तर लगेच कळतं).
//
// पर्याय:  --force        (वेळ न बघता सगळे Jobs आत्ता चालवा)
//          --only=job-key (फक्त एकच Job)
//          --list         (फक्त Jobs व त्यांची शेवटची वेळ दाखवा)
// प्रत्येक Job वेगळ्या PHP Process मध्ये चालतो — एक Job अयशस्वी/die() झाला
// तरी पुढचे Jobs चालू राहतात.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cron-helpers.php'; // Job-यादी: cronRunAllJobs()

$args = array_slice($argv, 1);
$force = in_array('--force', $args, true);
$listOnly = in_array('--list', $args, true);
$only = null;
$passThrough = [];
foreach ($args as $a) {
    if (str_starts_with($a, '--only=')) { $only = substr($a, 7); }
    if (str_starts_with($a, '--date=')) { $passThrough[] = $a; } // Testing साठी Jobs ना पुढे देतो
}

try {
    $lastRuns = [];
    foreach ($pdo->query("SELECT * FROM cron_job_runs")->fetchAll() as $r) { $lastRuns[$r['job_key']] = $r; }
} catch (PDOException $e) {
    die("cron_job_runs table not found — Database Update चालवा.\n");
}

// आधीचा run-all अजून चालू असेल (उदा. मोठा Backup) — तर हा लगेच थांबतो, Jobs दुप्पट चालत नाहीत
$lockFp = fopen(sys_get_temp_dir() . '/eadmin-run-all.lock', 'c');
if ($lockFp && !flock($lockFp, LOCK_EX | LOCK_NB)) { exit("run-all already running\n"); }

$now = time();
$todayStr = date('Y-m-d');
foreach (cronRunAllJobs() as $job => $sched) {
    if ($only !== null && $only !== $job) { continue; }
    $file = __DIR__ . '/' . $job . '.php';
    $last = $lastRuns[$job] ?? null;
    $lastStart = $last['last_started_at'] ?? null;

    if ($listOnly) {
        printf("%-34s last: %-19s  %s\n", $job, $lastStart ?: '—', $last['last_status'] ?? '');
        continue;
    }
    if (!is_file($file)) { continue; }

    $due = $force || $only !== null;
    if (!$due && isset($sched['every'])) {
        $due = !$lastStart || ($now - strtotime($lastStart)) >= ($sched['every'] * 60 - 30);
    } elseif (!$due && isset($sched['daily'])) {
        $due = (int)date('G') >= $sched['daily'] && (!$lastStart || substr($lastStart, 0, 10) < $todayStr);
    }
    if (!$due) { continue; }

    $pdo->prepare("INSERT INTO cron_job_runs (job_key, last_started_at, last_status) VALUES (?, NOW(), 'running')
                   ON DUPLICATE KEY UPDATE last_started_at = NOW(), last_status = 'running'")->execute([$job]);
    $startedAt = microtime(true);

    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file);
    foreach ($passThrough as $p) { $cmd .= ' ' . escapeshellarg($p); }
    $output = '';
    $exitCode = 1;
    if (function_exists('proc_open')) {
        $env = array_merge(getenv(), ['EADMIN_RUN_ALL' => '1']); // Job स्वतः दुप्पट नोंद करत नाही
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, __DIR__, $env);
        if (is_resource($proc)) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
        }
    } else {
        $output = 'proc_open उपलब्ध नाही — हा Job स्वतंत्र Cron Line ने चालवा.';
    }

    // die("...") ने संपलेले Jobs (उदा. "table not found") — Exit Code 0 असतो, त्यामुळे यशस्वी धरतो
    $ok = ($exitCode === 0) && stripos($output, 'Fatal error') === false;
    $pdo->prepare("UPDATE cron_job_runs SET last_finished_at = NOW(), last_status = ?, last_duration_sec = ?, last_output = ? WHERE job_key = ?")
        ->execute([$ok ? 'success' : 'failed', (int)round(microtime(true) - $startedAt), mb_substr(trim($output), -2000), $job]);
    echo '[' . date('Y-m-d H:i:s') . "] $job — " . ($ok ? 'OK' : "FAILED (exit $exitCode)") . "\n";
}

// run-all स्वतः शेवटचं कधी चाललं (Cron Line बंद पडली का ते कळण्यासाठी)
if (!$listOnly) {
    $pdo->prepare("INSERT INTO cron_job_runs (job_key, last_started_at, last_finished_at, last_status) VALUES ('run-all', NOW(), NOW(), 'success')
                   ON DUPLICATE KEY UPDATE last_started_at = NOW(), last_finished_at = NOW(), last_status = 'success'")->execute();
}
