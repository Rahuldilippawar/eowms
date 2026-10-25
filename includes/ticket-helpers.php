<?php
/**
 * एकत्रित विनंती (Ticket) प्रणाली — requests/ (सर्वसाधारण) आणि IT Requests
 * दोन्ही आता work_requests या एकाच Table मध्ये (category = general/it/...).
 * SLA, Ageing, परवानगी तपासणी, Action-Log — सगळं इथे एकाच ठिकाणी.
 */

// प्राधान्यानुसार SLA (तास) — urgent 8 तास, high 1 दिवस, normal 3 दिवस, low 7 दिवस
const TICKET_SLA_HOURS = ['urgent' => 8, 'high' => 24, 'normal' => 72, 'low' => 168];
const TICKET_REOPEN_DAYS = 7;   // पूर्ण झाल्यापासून इतक्या दिवसांत पुन्हा उघडता येते
const TICKET_OPEN_STATUSES = ['pending', 'in_progress'];

const TICKET_CATEGORIES = [
    'general' => '📋 सर्वसाधारण',
    'it' => '🖥️ IT',
    'maintenance' => '🔧 देखभाल/दुरुस्ती',
    'housekeeping' => '🧹 स्वच्छता',
    'other' => '📄 इतर',
];
const TICKET_IT_SUB_CATEGORIES = [
    'internet_network' => '🌐 Internet/Network (WiFi, LAN, Speed)',
    'software' => '💿 Software (Install/Update/Error)',
    'new_hardware' => '🖥️ नवीन Hardware हवं आहे',
    'account_access' => '🔑 Account/Access (Email, Login, Password)',
    'printer_scanner' => '🖨️ Printer/Scanner',
    'other' => '📄 इतर',
];
const TICKET_PRIORITIES = ['low' => 'कमी', 'normal' => 'सर्वसाधारण', 'high' => '⚠️ जास्त', 'urgent' => '🚨 तातडीचं'];
const TICKET_STATUS_LABELS = [
    'pending' => '⏳ प्रलंबित', 'in_progress' => '🔧 सुरू आहे', 'completed' => '✅ पूर्ण',
    'closed' => '🔒 बंद', 'cancelled' => '✖ रद्द',
];
const TICKET_STATUS_CHIPS = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'closed' => 'done', 'cancelled' => 'overdue'];

function ticketSlaHours($priority) {
    return TICKET_SLA_HOURS[$priority] ?? TICKET_SLA_HOURS['normal'];
}

/** SLA मुदत — $from (default आत्ता) पासून */
function ticketDueAt($priority, $from = null) {
    $base = $from ? strtotime($from) : time();
    return date('Y-m-d H:i:s', $base + ticketSlaHours($priority) * 3600);
}

/** संस्थेचा IT विभाग (code = 'IT') — नसेल तर null */
function ticketItDepartmentId($pdo, $orgId) {
    $st = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'IT' AND is_active = 1 ORDER BY id LIMIT 1");
    $st->execute([$orgId]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

/** किती जुनी — "3 दिवस 4 तास" / "5 तास" / "20 मिनिटं" */
function ticketAgeLabel($from, $to = null) {
    $secs = max(0, ($to ? strtotime($to) : time()) - strtotime($from));
    $d = intdiv($secs, 86400);
    $hrs = intdiv($secs % 86400, 3600);
    if ($d > 0) { return $d . ' दिवस' . ($hrs ? " $hrs तास" : ''); }
    if ($hrs > 0) { return $hrs . ' तास'; }
    return max(1, intdiv($secs, 60)) . ' मिनिटं';
}

/** उघड्या Ticket ची SLA-स्थिती: ['overdue' => bool, 'html' => छोटा Badge] */
function ticketSlaBadge(array $req) {
    if (!in_array($req['status'], TICKET_OPEN_STATUSES, true) || empty($req['due_at'])) {
        return ['overdue' => false, 'html' => ''];
    }
    $overdue = strtotime($req['due_at']) < time();
    $html = $overdue
        ? '<span class="status-chip overdue" style="font-size:10px;">⏰ SLA ओलांडली — ' . h(ticketAgeLabel($req['due_at'])) . '</span>'
        : '<span class="status-chip pending" style="font-size:10px;">⏳ मुदत: ' . h(ticketAgeLabel(date('Y-m-d H:i:s'), $req['due_at'])) . ' बाकी</span>';
    return ['overdue' => $overdue, 'html' => $html];
}

/**
 * ही व्यक्ती ही विनंती "हाताळू" (स्थिती/नेमणूक/खर्च) शकते का.
 * नियम: Super Admin; नेमलेली व्यक्ती ($allowAssignee); IT विनंती + manage_it;
 * नाहीतर — पाहण्यासारखीच परवानगी: विभाग-स्तरीय (स्वतःचा + Extra विभाग) किंवा
 * manage_requests_org_wide असलेले Management.
 */
function ticketCanManage(array $req, $allowAssignee = true) {
    $u = currentUser();
    if (!$u) { return false; }
    if (hasRole('super_admin')) { return true; }
    if ($allowAssignee && !empty($req['assigned_to_user_id']) && (int)$req['assigned_to_user_id'] === (int)$u['id']) { return true; }
    if (($req['category'] ?? '') === 'it' && hasPermission('manage_it')) { return true; }
    if (!isDeptScoped() && !hasPermission('manage_requests_org_wide')) { return false; }
    $visible = getVisibleDepartmentIds('manage_requests_org_wide');
    if ($visible === null) { return true; }
    return !empty($req['department_id']) && in_array((int)$req['department_id'], $visible, true);
}

function ticketIsRequester(array $req) {
    $u = currentUser();
    return $u && !empty($req['requested_by_user_id']) && (int)$req['requested_by_user_id'] === (int)$u['id'];
}

function ticketCanView(array $req) {
    return ticketIsRequester($req) || ticketCanManage($req);
}

/** पूर्ण झाल्यापासून 7 दिवसांच्या आत — पुन्हा उघडता येईल का */
function ticketCanReopen(array $req) {
    return in_array($req['status'], ['completed', 'closed'], true)
        && !empty($req['completed_at'])
        && strtotime($req['completed_at']) >= time() - TICKET_REOPEN_DAYS * 86400;
}

/** Action-Log (work_request_actions) — स्थिती-इतिहास */
function ticketLog($pdo, $requestId, $type, $oldStatus, $newStatus, $remark, $userId) {
    try {
        $pdo->prepare("INSERT INTO work_request_actions (request_id, action_type, old_status, new_status, remark, changed_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$requestId, $type, $oldStatus, $newStatus, $remark, $userId]);
    } catch (PDOException $e) { /* table not migrated yet */ }
}

/** ही विनंती कोणाला नेमता येईल — स्वीकारणाऱ्या विभागातले सक्रिय कर्मचारी (IT विभाग नसेल तर manage_it असलेले) */
function ticketAssignableUsers($pdo, array $req, $orgId) {
    if (!empty($req['department_id'])) {
        $st = $pdo->prepare("SELECT id, name FROM users WHERE department_id = ? AND is_active = 1 ORDER BY name");
        $st->execute([$req['department_id']]);
        return $st->fetchAll();
    }
    $ids = notifyUserIdsByPermission($orgId, 'manage_it', false);
    if (!$ids) { return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("SELECT id, name FROM users WHERE id IN ($ph) ORDER BY name");
    $st->execute($ids);
    return $st->fetchAll();
}

/** नवीन विनंतीची सूचना — नेमलेली व्यक्ती, नाहीतर विभाग-प्रमुख, नाहीतर विभाग; IT विभागच नसेल तर manage_it असलेले */
function ticketNotifyReceivers($pdo, array $req, $orgId, $title, $message) {
    $link = 'requests/view.php?id=' . (int)$req['id'];
    if (!empty($req['assigned_to_user_id'])) {
        notifyUser($req['assigned_to_user_id'], $orgId, $title, $message, $link);
        return;
    }
    if (!empty($req['department_id'])) {
        $st = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
        $st->execute([$req['department_id']]);
        $hodId = $st->fetchColumn();
        if ($hodId) { notifyUser($hodId, $orgId, $title, $message, $link); }
        else { notifyDepartment($req['department_id'], $orgId, $title, $message, $link); }
        return;
    }
    notifyUsers(notifyUserIdsByPermission($orgId, 'manage_it', false), $orgId, $title, $message, $link);
}
