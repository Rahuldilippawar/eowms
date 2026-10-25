<?php
/**
 * 🆘 आणीबाणी (SOS) — कोणाला कळवायचं, त्वरित Push/Email, आणि 5 मिनिटांत
 * कोणीच प्रतिसाद दिला नाही तर Management ला पुन्हा (Escalation).
 * Web पानं आणि Cron दोन्हीकडून वापरता येतं (currentUser() लागत नाही).
 */
require_once __DIR__ . '/notify.php';

const EMERGENCY_ACK_ESCALATE_MINUTES = 5;  // इतक्या मिनिटांत "मी येत आहे" नाही → Management ला पुन्हा
const EMERGENCY_RATE_LIMIT_SECONDS = 120;  // एका व्यक्तीकडून 2 मिनिटांत एकच सक्रिय सूचना
const EMERGENCY_PUSH_TIMEOUT = 4;          // SOS Push — लगेच, पण छोटा Timeout
const EMERGENCY_TYPE_LABELS = ['fire' => '🔥 आग', 'medical' => '🚑 वैद्यकीय', 'security' => '🛡️ सुरक्षा', 'other' => '🆘 इतर'];

/** सुरक्षा विभाग (code SEC… / नाव Security / सुरक्षा) चे IDs */
function emergencySecurityDeptIds($pdo, $orgId) {
    $st = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND is_active = 1
        AND (UPPER(code) LIKE 'SEC%' OR name LIKE '%Security%' OR name_local LIKE '%सुरक्षा%')");
    $st->execute([$orgId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * SOS कोणाला जाईल: view_emergency_alerts Permission असलेले + Management +
 * पाठवणाऱ्याच्या विभागाचे HOD + सुरक्षा विभागातले सगळे. पाठवणारा वगळून, एकदाच.
 */
function emergencyRecipientIds($pdo, $orgId, $senderId, $senderDeptId) {
    $ids = array_merge(
        notifyUserIdsByPermission($orgId, 'view_emergency_alerts', true),
        notifyUserIdsByCategory($orgId, ['management'])
    );
    if ($senderDeptId) {
        $st = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
        $st->execute([$senderDeptId]);
        $hod = (int)$st->fetchColumn();
        if ($hod) { $ids[] = $hod; }
    }
    $secDepts = emergencySecurityDeptIds($pdo, $orgId);
    if ($secDepts) {
        $ph = implode(',', array_fill(0, count($secDepts), '?'));
        $st = $pdo->prepare("SELECT id FROM users WHERE is_active = 1 AND department_id IN ($ph)");
        $st->execute($secDepts);
        $ids = array_merge($ids, array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    return array_values(array_filter($ids, fn($id) => $id !== (int)$senderId));
}

/** App मध्ये Mail (sendmail/SMTP) Setup आहे का — नसेल तर Email चा प्रयत्नच नाही */
function emergencyMailConfigured() {
    if (!function_exists('mail')) { return false; }
    if (stripos(PHP_OS, 'WIN') === 0) { return (bool)ini_get('SMTP'); }
    $path = trim((string)ini_get('sendmail_path'));
    if ($path === '') { return false; }
    $bin = strtok($path, ' ');
    return $bin && is_executable($bin);
}

/**
 * SOS सूचना सगळ्या Recipients ना — In-App + Push त्वरित (Queue ची वाट न पाहता), Mail असल्यास Email.
 * $alert = emergency_alerts row (+ sender_name). परत: किती जणांना.
 */
function emergencyDispatch($pdo, array $alert, $senderName, array $recipientIds, $titlePrefix = '🆘 आणीबाणी सूचना') {
    $orgId = (int)$alert['organization_id'];
    $typeLabel = EMERGENCY_TYPE_LABELS[$alert['alert_type']] ?? '🆘';
    $title = "$titlePrefix — $typeLabel";
    $msg = $senderName . ' — ' . ($alert['location'] ?: 'स्थान नमूद नाही') . ($alert['message'] ? ' — ' . $alert['message'] : '');
    $link = 'emergency/view.php?id=' . (int)$alert['id'];

    $queueIds = [];
    $rec = null;
    try { $rec = $pdo->prepare("INSERT IGNORE INTO emergency_alert_recipients (alert_id, user_id) VALUES (?, ?)"); } catch (PDOException $e) {}
    foreach ($recipientIds as $uid) {
        $qid = notifyUser($uid, $orgId, $title, $msg, $link, true);
        if ($qid) { $queueIds[] = $qid; }
        if ($rec) { try { $rec->execute([$alert['id'], $uid]); } catch (PDOException $e) {} }
    }
    // फक्त याच सूचनेचे Push — आत्ताच, छोट्या Timeout सह
    if ($queueIds) { notifyDeliverPushQueue(count($queueIds), 20, $queueIds, EMERGENCY_PUSH_TIMEOUT); }

    if ($recipientIds && emergencyMailConfigured()) {
        $ph = implode(',', array_fill(0, count($recipientIds), '?'));
        $st = $pdo->prepare("SELECT email FROM users WHERE id IN ($ph) AND email IS NOT NULL AND email != ''");
        $st->execute(array_values($recipientIds));
        $base = defined('SITE_BASE_URL') ? rtrim(SITE_BASE_URL, '/') . '/' : '';
        $body = '<div style="font-family:Arial,sans-serif;"><h2 style="color:#B23A48;">' . htmlspecialchars($title) . '</h2><p>' . nl2br(htmlspecialchars($msg)) . '</p>'
            . (!empty($alert['latitude']) ? '<p>📍 <a href="https://www.google.com/maps?q=' . (float)$alert['latitude'] . ',' . (float)$alert['longitude'] . '">नकाशावर बघा</a></p>' : '')
            . '<p><a href="' . htmlspecialchars($base . $link) . '">सूचना उघडा</a></p></div>';
        $headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: E-Admin SOS <no-reply@" . ($_SERVER['HTTP_HOST'] ?? 'localhost') . ">\r\n";
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                try { @mail($email, '=?UTF-8?B?' . base64_encode($title) . '?=', $body, $headers); } catch (Throwable $e) {}
            }
        }
    }
    return count($recipientIds);
}

/**
 * 5 मिनिटांत कोणीच "मी येत आहे" म्हटलं नाही — Management (+ Permission असलेले) ना पुन्हा.
 * $alertId दिल्यास फक्त तीच (Status-Polling मधून); नाहीतर सगळ्या (Cron). स्वस्त Query.
 */
function emergencyEscalateUnacknowledged($pdo, $alertId = null) {
    $done = 0;
    try {
        $sql = "SELECT ea.*, u.name AS sender_name FROM emergency_alerts ea JOIN users u ON u.id = ea.triggered_by
                WHERE ea.status = 'active' AND ea.first_ack_at IS NULL AND ea.escalated_at IS NULL
                  AND ea.created_at < NOW() - INTERVAL " . (int)EMERGENCY_ACK_ESCALATE_MINUTES . " MINUTE";
        $params = [];
        if ($alertId) { $sql .= " AND ea.id = ?"; $params[] = $alertId; }
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $claim = $pdo->prepare("UPDATE emergency_alerts SET escalated_at = NOW() WHERE id = ? AND escalated_at IS NULL");
        foreach ($st->fetchAll() as $alert) {
            $claim->execute([$alert['id']]);
            if ($claim->rowCount() === 0) { continue; } // दुसऱ्या Request ने आधीच केलं
            $ids = array_values(array_unique(array_merge(
                notifyUserIdsByCategory($alert['organization_id'], ['management']),
                notifyUserIdsByPermission($alert['organization_id'], 'view_emergency_alerts', true)
            )));
            $ids = array_values(array_filter($ids, fn($id) => $id !== (int)$alert['triggered_by']));
            emergencyDispatch($pdo, $alert, $alert['sender_name'], $ids, '🚨 प्रतिसाद नाही (' . EMERGENCY_ACK_ESCALATE_MINUTES . ' मि.) — पुन्हा');
            $done++;
        }
    } catch (PDOException $e) { /* not migrated yet */ }
    return $done;
}

/** ही व्यक्ती ही सूचना बघू/प्रतिसाद देऊ शकते का — पाठवणारा, Recipient, किंवा Permission */
function emergencyCanView($pdo, array $alert, $userId) {
    if ((int)$alert['triggered_by'] === (int)$userId || hasPermission('view_emergency_alerts')) { return true; }
    try {
        $st = $pdo->prepare("SELECT 1 FROM emergency_alert_recipients WHERE alert_id = ? AND user_id = ?");
        $st->execute([$alert['id'], $userId]);
        return (bool)$st->fetchColumn();
    } catch (PDOException $e) { return false; }
}
