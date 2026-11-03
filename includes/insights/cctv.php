<?php
// 📹 CCTV — "लक्ष द्या" सूचना: आज तपासणी न झालेले DVR, भरत आलेली Storage,
// कमी दिवसांचे Footage व ७ दिवसांत Backup न झालेले DVR.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT d.id, d.dvr_name,
            (SELECT c.checkup_date FROM cctv_checkups c WHERE c.dvr_id = d.id ORDER BY c.checkup_date DESC LIMIT 1) last_check,
            (SELECT c.storage_used_percent FROM cctv_checkups c WHERE c.dvr_id = d.id ORDER BY c.checkup_date DESC LIMIT 1) storage,
            (SELECT c.footage_days_available FROM cctv_checkups c WHERE c.dvr_id = d.id ORDER BY c.checkup_date DESC LIMIT 1) footage,
            (SELECT MAX(c.checkup_date) FROM cctv_checkups c WHERE c.dvr_id = d.id AND c.backup_done = 1) last_backup
        FROM cctv_dvrs d WHERE d.organization_id = ? AND d.is_active = 1");
    $st->execute([$orgId]);
    $al = []; $notToday = [];
    foreach ($st->fetchAll() as $d) {
        if ($d['last_check'] !== date('Y-m-d')) $notToday[] = $d['dvr_name'];
        if ($d['storage'] !== null && (float)$d['storage'] >= 90) $al[] = ['bad', '💾 ' . $d['dvr_name'] . ' — Storage ' . (float)$d['storage'] . '% भरली', ''];
        if ($d['footage'] !== null && (int)$d['footage'] < 7) $al[] = ['warn', '🎞️ ' . $d['dvr_name'] . ' — फक्त ' . (int)$d['footage'] . ' दिवसांचं Footage', ''];
        if (!$d['last_backup'] || strtotime($d['last_backup']) < strtotime('-7 days')) $al[] = ['warn', '🗄️ ' . $d['dvr_name'] . ' — ७ दिवसांत Backup नाही', ''];
    }
    if ($notToday && (int)date('G') >= 11) array_unshift($al, ['info', '📋 ' . count($notToday) . ' DVR ची आजची तपासणी बाकी: ' . implode(', ', array_slice($notToday, 0, 4)) . (count($notToday) > 4 ? '…' : ''), '']);
    smartAlerts(array_slice($al, 0, 10));
} catch (PDOException $e) {}
