<?php
// 🖐️ ADMS — Machine दर काही सेकंदांनी विचारते "माझ्यासाठी काही आदेश आहे का?"
// (Website वरून रांगेत टाकलेले Remote आदेश इथून Machine ला जातात: "C:<id>:<आदेश>")
// INFO=Ver,Users,बोटं,Logs,IP,... — Machine ची माहिती पण इथेच येते.
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/timezone.php';
require_once __DIR__ . '/../includes/bio-helpers.php';

header('Content-Type: text/plain');
if (!bioReady($pdo)) { echo 'OK'; exit; }

$sn = trim((string)($_GET['SN'] ?? ''));
$dev = bioValidSerial($sn) ? bioDeviceBySerial($pdo, $sn) : null;
if (!$dev) { echo 'OK'; exit; }
bioTouchDevice($pdo, $dev, bioParseInfo($_GET['INFO'] ?? ''));

if ($dev['status'] !== 'active') { echo 'OK'; exit; } // मंजूर Machine लाच आदेश
// 🔁 जुन्या Software ला — मिनिटाला एकदा (तिथे Machine Online दिसावी). तिथले आदेश इथून चालवत नाही.
register_shutdown_function(function () use ($pdo, $dev) { bioFinishResponse(); try { bioForward($pdo, $dev, 'getrequest', 'GET'); } catch (Throwable $e) {} });

// रांगेतले आदेश (एकावेळी 20) — पाठवले म्हणून 'sent'; 10 मिनिटांत उत्तर न आलेले पुन्हा पाठवतो
$st = $pdo->prepare("SELECT id, command FROM bio_commands WHERE device_id = ? AND (status = 'queued' OR (status = 'sent' AND sent_at < NOW() - INTERVAL 10 MINUTE)) ORDER BY id LIMIT 20");
$st->execute([(int)$dev['id']]);
$cmds = $st->fetchAll();
if (!$cmds) { echo 'OK'; exit; }
$upd = $pdo->prepare("UPDATE bio_commands SET status = 'sent', sent_at = NOW() WHERE id = ?");
$out = '';
foreach ($cmds as $c) {
    $out .= 'C:' . (int)$c['id'] . ':' . $c['command'] . "\r\n";
    $upd->execute([(int)$c['id']]);
}
echo $out;
