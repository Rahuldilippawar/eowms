<?php
// ============================================================
// SLA ESCALATION CRON — दर तासाला चालवा (Hostinger hPanel -> Advanced -> Cron Jobs).
//
// Command to schedule (hourly):
//   php /home/USERNAME/domains/YOURDOMAIN/public_html/cron/escalate-tickets.php
//
// विनंत्या (work_requests — सर्वसाधारण + IT):
//   SLA मुदत (due_at) ओलांडली        → स्वीकारणाऱ्या विभागाचे HOD (Level 1)
//   SLA च्या दुप्पट वेळ ओलांडली      → Management (role_definitions.category) (Level 2)
// तक्रारी (grievances): 7 दिवसांची SLA ओलांडली → नेमलेली व्यक्ती + Management + manage_grievances
// प्रत्येक व्यक्तीला या Run मध्ये एकच (Digest) सूचना — कितीही विनंत्या असल्या तरी.
// ============================================================

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/notify.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';

// $digest[orgId][userId] = ['tickets' => [...], 'grievances' => [...]]
$digest = [];
$addDigest = function ($orgId, $userIds, $kind, $line) use (&$digest) {
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
        if ($uid > 0) { $digest[(int)$orgId][$uid][$kind][] = $line; }
    }
};
$mgmtCache = [];
$mgmtOf = function ($orgId) use (&$mgmtCache) {
    return $mgmtCache[$orgId] ??= notifyUserIdsByCategory($orgId, ['management']);
};

// ---------------- विनंत्या ----------------
$ticketsEscalated = 0;
try {
    $rows = $pdo->query("
        SELECT r.*, d.hod_id FROM work_requests r LEFT JOIN departments d ON d.id = r.department_id
        WHERE r.status IN ('pending','in_progress') AND r.due_at IS NOT NULL AND r.due_at < NOW() AND r.escalation_level < 2
    ")->fetchAll();
} catch (PDOException $e) {
    $rows = [];
    echo "work_requests SLA columns not migrated — skipping tickets.\n";
}
$upd = $pdo->prepare("UPDATE work_requests SET escalation_level = ?, escalated_at = NOW() WHERE id = ? AND escalation_level < ?");
foreach ($rows as $r) {
    $twiceDue = strtotime($r['due_at']) + ticketSlaHours($r['priority']) * 3600; // SLA ची दुप्पट वेळ
    $target = time() >= $twiceDue ? 2 : 1;
    if ($target <= (int)$r['escalation_level']) { continue; }
    $upd->execute([$target, $r['id'], $target]);
    if ($upd->rowCount() === 0) { continue; }

    $line = $r['request_code'] . ' — ' . mb_substr($r['title'] ?: $r['work_description'], 0, 40) . ' (' . ticketAgeLabel($r['created_at']) . ')';
    // Level 1 — HOD (IT विभागच नसेल तर manage_it असलेले)
    $hodIds = $r['hod_id'] ? [$r['hod_id']] : ($r['department_id'] ? [] : notifyUserIdsByPermission($r['organization_id'], 'manage_it', false));
    if ((int)$r['escalation_level'] < 1 || $target === 1) { $addDigest($r['organization_id'], $hodIds, 'tickets', $line); }
    if ($target === 2) { $addDigest($r['organization_id'], $mgmtOf($r['organization_id']), 'tickets', $line); }
    ticketLog($pdo, $r['id'], 'escalated', $r['status'], $r['status'], $target === 2 ? 'SLA दुप्पट ओलांडली — Management कडे' : 'SLA ओलांडली — विभाग-प्रमुखाकडे', null);
    $ticketsEscalated++;
}

// ---------------- तक्रारी ----------------
$grievancesEscalated = 0;
try {
    $gRows = $pdo->query("
        SELECT * FROM grievances
        WHERE status IN ('open','in_progress') AND sla_deadline IS NOT NULL AND sla_deadline < CURDATE() AND escalated_at IS NULL
    ")->fetchAll();
} catch (PDOException $e) {
    $gRows = [];
    echo "grievances escalation column not migrated — skipping grievances.\n";
}
$gUpd = $pdo->prepare("UPDATE grievances SET escalated_at = NOW() WHERE id = ? AND escalated_at IS NULL");
foreach ($gRows as $g) {
    $gUpd->execute([$g['id']]);
    if ($gUpd->rowCount() === 0) { continue; }
    // गुप्त तक्रार — तक्रारदाराचं नाव/ID कधीही Digest मध्ये नाही
    $line = 'तक्रार #' . $g['id'] . ($g['category'] ? ' — ' . mb_substr($g['category'], 0, 30) : '');
    $to = array_merge($mgmtOf($g['organization_id']), notifyUserIdsByPermission($g['organization_id'], 'manage_grievances', false));
    if ($g['assigned_to']) { $to[] = $g['assigned_to']; }
    $addDigest($g['organization_id'], $to, 'grievances', $line);
    $grievancesEscalated++;
}

// ---------------- Digest — प्रत्येकाला एकच सूचना ----------------
$notified = 0;
foreach ($digest as $orgId => $users) {
    foreach ($users as $uid => $kinds) {
        $t = count($kinds['tickets'] ?? []);
        $g = count($kinds['grievances'] ?? []);
        $parts = [];
        if ($t) { $parts[] = "$t विनंत्या"; }
        if ($g) { $parts[] = "$g तक्रारी"; }
        $lines = array_slice(array_merge($kinds['tickets'] ?? [], $kinds['grievances'] ?? []), 0, 5);
        notifyUser($uid, $orgId, '⏰ SLA ओलांडली — ' . implode(', ', $parts), implode("\n", $lines) . (($t + $g) > 5 ? "\n…" : ''),
            $t ? 'requests/index.php?status=overdue' : 'grievances/index.php');
        $notified++;
    }
}

echo "Done. tickets escalated=$ticketsEscalated, grievances escalated=$grievancesEscalated, digests sent=$notified — " . date('Y-m-d H:i:s') . "\n";
