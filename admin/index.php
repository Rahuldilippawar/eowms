<?php
// 🛡️ Super Admin Control Center — System चं आरोग्य एका पानावर: Backup, Cron, Database Update, Errors, जागा, Push, Email, सुरक्षा, Data दर्जा
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/two-factor.php';
$orgId = activeOrgId();
$q1 = function ($sql, $p = []) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetch(PDO::FETCH_ASSOC) ?: []; } catch (PDOException $e) { return []; } };
$qa = function ($sql, $p = []) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (PDOException $e) { return []; } };
$ago = function ($ts) {
    if (!$ts) { return 'कधीच नाही'; }
    $s = time() - strtotime($ts);
    if ($s < 3600) { return max(1, (int)floor($s / 60)) . ' मिनिटांपूर्वी'; }
    if ($s < 86400) { return (int)floor($s / 3600) . ' तासांपूर्वी'; }
    return (int)floor($s / 86400) . ' दिवसांपूर्वी';
};
$checks = [];   // [tone(ok|warn|bad), icon, title, value, detail, link]

// 💾 Backup
$lastBk = $q1("SELECT created_at, status, error_message FROM scheduled_backups WHERE organization_id = ? ORDER BY created_at DESC LIMIT 1", [$orgId]);
$offOk = orgSetting($pdo, $orgId, 'offsite_last_ok_at');
$bkTimes = array_filter([$lastBk['created_at'] ?? null, $offOk]);
$bkLast = $bkTimes ? max(array_map('strtotime', $bkTimes)) : null;
$bkAge = $bkLast ? (time() - $bkLast) / 86400 : null;
$checks[] = [$bkAge === null ? 'bad' : ($bkAge > 7 ? 'bad' : ($bkAge > 2 ? 'warn' : 'ok')), '💾', 'शेवटचा Backup',
    $bkLast ? $ago(date('Y-m-d H:i:s', $bkLast)) : 'कधीच नाही',
    ($lastBk && ($lastBk['status'] ?? '') !== 'success' && !empty($lastBk['error_message']) ? '⚠ शेवटचा अयशस्वी: ' . mb_strimwidth($lastBk['error_message'], 0, 60, '…') : 'आपोआप + Email Backup'), '../backup/index.php'];

// ⏱️ Cron
$crons = $qa("SELECT job_key, last_finished_at, last_status, last_started_at FROM cron_job_runs");
$cronLast = $crons ? max(array_map(fn($c) => strtotime($c['last_finished_at'] ?: $c['last_started_at'] ?: '1970-01-01'), $crons)) : null;
$cronFail = array_filter($crons, fn($c) => !in_array($c['last_status'], ['success', 'running', 'ok', null], true));
$cronAge = $cronLast ? (time() - $cronLast) / 3600 : null;
$checks[] = [$cronAge === null ? 'bad' : ($cronAge > 26 || $cronFail ? 'bad' : ($cronAge > 3 ? 'warn' : 'ok')), '⏱️', 'आपोआप कामं (Cron)',
    $cronLast ? $ago(date('Y-m-d H:i:s', $cronLast)) : 'कधीच चाललं नाही',
    $cronFail ? count($cronFail) . ' काम अयशस्वी: ' . implode(', ', array_column($cronFail, 'job_key')) : ($crons ? count($crons) . ' कामं नोंदलेली' : 'Hostinger मध्ये Cron लावा'), '../system-update/index.php'];

// 🛠️ Database Update — Migration मधले Tables नाहीत?
$missing = [];
$migFile = __DIR__ . '/../database/RUN-ALL-MIGRATIONS.sql';
if (is_file($migFile) && preg_match_all('/CREATE TABLE (?:IF NOT EXISTS )?`?(\w+)`?/i', file_get_contents($migFile), $mm)) {
    $have = array_flip($pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN));
    $missing = array_values(array_unique(array_filter($mm[1], fn($t) => !isset($have[$t]))));
}
$checks[] = [$missing ? 'bad' : 'ok', '🛠️', 'Database Update', $missing ? count($missing) . ' Tables बाकी' : 'अद्ययावत',
    $missing ? 'नवीन सुविधा चालण्यासाठी आत्ता चालवा: ' . mb_strimwidth(implode(', ', $missing), 0, 70, '…') : 'सगळे Tables आहेत', '../system-update/db-update.php'];

// ⚠️ आजच्या त्रुटी
$errToday = 0; $errLast = '';
if (defined('EOWMS_ERROR_LOG_DIR') && is_file(EOWMS_ERROR_LOG_DIR . '/errors-' . date('Y-m-d') . '.log')) {
    $lines = @file(EOWMS_ERROR_LOG_DIR . '/errors-' . date('Y-m-d') . '.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $l) { $r = json_decode($l, true); if ($r && ($r['level'] ?? '') !== 'warning') { $errToday++; $errLast = $r['message'] ?? $errLast; } }
}
$checks[] = [$errToday > 20 ? 'bad' : ($errToday ? 'warn' : 'ok'), '⚠️', 'आजच्या त्रुटी', (string)$errToday, $errToday ? mb_strimwidth((string)$errLast, 0, 80, '…') : 'एकही त्रुटी नाही', '../system-update/error-log.php'];

// 💽 जागा
$free = @disk_free_space(__DIR__); $totalDisk = @disk_total_space(__DIR__);
$dbSize = (float)($q1("SELECT SUM(data_length + index_length) s FROM information_schema.TABLES WHERE table_schema = DATABASE()")['s'] ?? 0);
$up = 0; $upFiles = 0;
$updir = realpath(__DIR__ . '/../assets/uploads');
if ($updir) { try { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($updir, FilesystemIterator::SKIP_DOTS)) as $f) { $up += $f->getSize(); if (++$upFiles > 50000) { break; } } } catch (Throwable $e) {} }
$mb = fn($b) => $b >= 1073741824 ? round($b / 1073741824, 1) . ' GB' : round($b / 1048576, 1) . ' MB';
$freePct = ($free && $totalDisk) ? $free * 100 / $totalDisk : null;
$checks[] = [$freePct !== null && $freePct < 5 ? 'bad' : ($freePct !== null && $freePct < 15 ? 'warn' : 'ok'), '💽', 'जागा (Storage)',
    'DB ' . $mb($dbSize) . ' · Files ' . $mb($up), $free !== false && $free !== null ? 'मोकळी: ' . $mb($free) . ($freePct !== null ? ' (' . round($freePct) . '%)' : '') : 'मोकळी जागा कळली नाही', '../backup/index.php'];

// 📲 Push
$vapid = $q1("SELECT vapid_public_key FROM organizations WHERE id = ?", [$orgId]);
$subs = (int)($q1("SELECT COUNT(DISTINCT ps.user_id) c FROM push_subscriptions ps JOIN users u ON u.id = ps.user_id WHERE u.organization_id = ?", [$orgId])['c'] ?? 0);
$activeStaff = (int)($q1("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND is_active = 1", [$orgId])['c'] ?? 0);
$checks[] = [empty($vapid['vapid_public_key']) ? 'bad' : ($activeStaff && $subs / $activeStaff < .3 ? 'warn' : 'ok'), '📲', 'Mobile सूचना (Push)',
    empty($vapid['vapid_public_key']) ? 'Setup झालेलं नाही' : $subs . '/' . $activeStaff . ' जणांनी चालू केल्या',
    empty($vapid['vapid_public_key']) ? 'App बंद असताना सूचना जात नाहीत' : 'उरलेल्यांना Banner दिसतो', '../system-update/push-setup.php'];

// ✉️ Email
$drMsg = (string)orgSetting($pdo, $orgId, 'daily_report_last_msg', '');
$drAt = orgSetting($pdo, $orgId, 'daily_report_last_at');
$mailOk = function_exists('mail');
$emailBad = !$mailOk || ($drMsg !== '' && preg_match('/अयशस्वी|त्रुटी|fail|error/iu', $drMsg));
$checks[] = [$emailBad ? 'bad' : ($drAt ? 'ok' : 'warn'), '✉️', 'Email पाठवणं', $drAt ? 'दैनिक अहवाल ' . $ago($drAt) : 'अजून पाठवलं नाही',
    $mailOk ? ($drMsg ? mb_strimwidth($drMsg, 0, 70, '…') : 'Server वर mail() उपलब्ध') : 'Server वर mail() बंद आहे', '../reports/daily.php'];

// 🔐 सुरक्षा
$sess = saTableReady($pdo, 'user_sessions') ? (int)($q1("SELECT COUNT(DISTINCT user_id) c FROM user_sessions WHERE organization_id = ? AND ended_at IS NULL AND last_seen >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)", [$orgId])['c'] ?? 0) : 0;
$failedToday = (int)($q1("SELECT COUNT(*) c FROM activity_logs WHERE organization_id = ? AND action = 'Login अयशस्वी' AND created_at >= CURDATE()", [$orgId])['c'] ?? 0);
$lockedNow = (int)($q1("SELECT COUNT(*) c FROM users WHERE organization_id = ? AND locked_until > NOW()", [$orgId])['c'] ?? 0);
$adminsNoTfa = count(array_filter($qa("SELECT u.tfa_method FROM users u LEFT JOIN role_definitions rd ON rd.role_key = u.role WHERE u.organization_id = ? AND u.is_active = 1 AND (u.role = 'super_admin' OR rd.category = 'management')", [$orgId]), fn($r) => !tfaEnabled($r)));
$checks[] = [$lockedNow || $failedToday > 10 ? 'bad' : ($failedToday || $adminsNoTfa ? 'warn' : 'ok'), '🚨', 'सुरक्षा',
    $lockedNow ? $lockedNow . ' खाती Lock' : ($failedToday ? $failedToday . ' अयशस्वी Login आज' : 'सुरक्षित'), ($adminsNoTfa ? $adminsNoTfa . ' Admin/Management ना 2-Step नाही · ' : '') . 'आत्ता ' . $sess . ' जण Online', 'security.php'];

$loginsToday = (int)($q1("SELECT COUNT(DISTINCT user_id) c FROM activity_logs WHERE organization_id = ? AND action LIKE 'Login केलं%' AND created_at >= CURDATE()", [$orgId])['c'] ?? 0);
$recycleN = saTableReady($pdo, 'recycle_bin') ? (int)($q1("SELECT COUNT(*) c FROM recycle_bin WHERE organization_id = ? AND restored_at IS NULL", [$orgId])['c'] ?? 0) : 0;
$order = ['bad' => 0, 'warn' => 1, 'ok' => 2];
$counts = array_count_values(array_column($checks, 0));
$overall = !empty($counts['bad']) ? 'bad' : (!empty($counts['warn']) ? 'warn' : 'ok');
usort($checks, fn($a, $b) => $order[$a[0]] <=> $order[$b[0]]);
$recent = $qa("SELECT actor_name, action, created_at FROM activity_logs WHERE organization_id = ? ORDER BY id DESC LIMIT 8", [$orgId]);

$pageTitle = 'Control Center';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
$tone = ['ok' => ['#137A4B', '#E7F6EF', 'ठीक'], 'warn' => ['#B45309', '#FEF3C7', 'लक्ष द्या'], 'bad' => ['#B42318', '#FDECEC', 'तातडीने']];
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">🛡️ Control Center</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">System चं आरोग्य एका नजरेत — लाल आधी. प्रत्येक कार्डवर Click करून तिथेच दुरुस्त करा.</div>
<?= saNav('index') ?>

<div class="cc-hero" style="background:<?= $tone[$overall][1] ?>;border-color:<?= $tone[$overall][0] ?>;">
  <div class="big" style="color:<?= $tone[$overall][0] ?>;"><?= $overall === 'ok' ? '✅' : ($overall === 'warn' ? '⚠️' : '🔴') ?></div>
  <div><b style="color:<?= $tone[$overall][0] ?>;"><?= $overall === 'ok' ? 'सगळं व्यवस्थित चालू आहे' : (($counts['bad'] ?? 0) ? ($counts['bad'] . ' गोष्टींकडे तातडीने लक्ष द्या') : ($counts['warn'] . ' गोष्टींकडे लक्ष द्या')) ?></b>
    <small><?= (int)($counts['ok'] ?? 0) ?> ठीक · <?= (int)($counts['warn'] ?? 0) ?> लक्ष द्या · <?= (int)($counts['bad'] ?? 0) ?> तातडीने · आज <?= $loginsToday ?> जणांनी Login केलं · आत्ता <?= $sess ?> Online</small></div>
</div>

<div class="cc-grid">
  <?php foreach ($checks as [$t, $icon, $title, $val, $det, $link]): ?>
    <a class="cc-card" href="<?= h($link) ?>" style="border-left-color:<?= $tone[$t][0] ?>;">
      <div class="top"><span class="ic"><?= $icon ?></span><span class="tt"><?= h($title) ?></span><span class="pill" style="background:<?= $tone[$t][1] ?>;color:<?= $tone[$t][0] ?>;"><?= $tone[$t][2] ?></span></div>
      <div class="val"><?= h($val) ?></div>
      <div class="det"><?= h($det) ?></div>
    </a>
  <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="panel h-100"><div class="panel-head"><div class="title">🧰 Super Admin साधनं</div></div>
      <div class="cc-tools">
        <a href="view-as.php"><b>👁️ View as</b><small>कर्मचाऱ्याच्या नजरेतून पाहा</small></a>
        <a href="sessions.php"><b>🔐 Active Sessions</b><small><?= $sess ?> Online · Force Logout</small></a>
        <a href="security.php"><b>🚨 सुरक्षा इशारे</b><small><?= $failedToday ?> अयशस्वी Login आज</small></a>
        <a href="broadcast.php"><b>📢 Broadcast</b><small>सगळ्यांना एकदम सूचना</small></a>
        <a href="usage.php"><b>📊 Module वापर</b><small>कोण काय वापरतं</small></a>
        <a href="data-quality.php"><b>🗂️ Data दर्जा</b><small>अपूर्ण / डुप्लिकेट नोंदी</small></a>
        <a href="recycle-bin.php"><b>🗑️ Recycle Bin</b><small><?= $recycleN ?> परत आणता येतील</small></a>
        <a href="../roles/index.php"><b>🎖️ पदे व Access</b><small>अधिकार, व्यक्तीनुसार Access</small></a>
        <a href="../backup/index.php"><b>💾 Backup</b><small>Download / Restore</small></a>
        <a href="../system-update/maintenance.php"><b>🛠️ Maintenance Mode</b><small>Site तात्पुरती बंद</small></a>
        <a href="../activity-log/index.php"><b>📜 Activity Log</b><small>कोणी काय केलं</small></a>
        <a href="../translations/index.php"><b>🌐 भाषांतर</b><small>हिंदी / English</small></a>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel h-100"><div class="panel-head"><div class="title">🕘 नुकत्याच घडामोडी</div><div class="sub"><a href="../activity-log/index.php" style="color:var(--rail);">सगळं →</a></div></div>
      <?php foreach ($recent as $r): ?><div class="cc-act"><span><b><?= h($r['actor_name'] ?: '—') ?></b> <?= h(mb_strimwidth($r['action'], 0, 60, '…')) ?></span><small><?= h($ago($r['created_at'])) ?></small></div><?php endforeach; ?>
    </div>
  </div>
</div>
<style>
  .cc-hero { display: flex; gap: 14px; align-items: center; border: 1px solid; border-radius: 14px; padding: 14px 18px; margin-bottom: 14px; }
  .cc-hero .big { font-size: 30px; } .cc-hero b { display: block; font-size: 16px; } .cc-hero small { font-size: 12px; color: var(--text-dim); }
  .cc-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 10px; }
  .cc-card { display: block; background: var(--panel, #fff); border: 1px solid var(--line); border-left: 4px solid; border-radius: 12px; padding: 12px 14px; text-decoration: none; color: inherit; transition: transform .12s, box-shadow .12s; }
  .cc-card:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(16,22,43,.08); }
  .cc-card .top { display: flex; align-items: center; gap: 8px; } .cc-card .ic { font-size: 18px; } .cc-card .tt { flex: 1; font-size: 12.5px; color: var(--text-dim); font-weight: 600; }
  .cc-card .pill { font-size: 10.5px; font-weight: 700; padding: 2px 8px; border-radius: 999px; }
  .cc-card .val { font-size: 17px; font-weight: 700; margin: 6px 0 2px; } .cc-card .det { font-size: 11.5px; color: var(--text-dim); line-height: 1.4; }
  .cc-tools { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; padding: 12px 16px 16px; }
  .cc-tools a { border: 1px solid var(--line); border-radius: 10px; padding: 9px 11px; text-decoration: none; color: inherit; } .cc-tools a:hover { border-color: var(--rail); }
  .cc-tools b { display: block; font-size: 13px; } .cc-tools small { font-size: 11px; color: var(--text-dim); }
  .cc-act { display: flex; justify-content: space-between; gap: 10px; padding: 7px 16px; border-bottom: 1px solid var(--line); font-size: 12.5px; } .cc-act small { color: var(--text-dim); white-space: nowrap; font-size: 11px; }
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
