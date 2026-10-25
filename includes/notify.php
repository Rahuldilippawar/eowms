<?php
/**
 * सूचना (Notifications) — In-App नोंद त्वरित, आणि Push (Web Push / Android FCM)
 * एका छोट्या Queue (notification_push_queue) मधून नंतर पाठवतो — त्यामुळे
 * 500 जणांना परिपत्रक पाठवलं तरी पान लगेच परत येतं.
 * Queue दोन ठिकाणी रिकामी होते:
 *   (a) cron/send-push-queue.php (दर मिनिटाला)
 *   (b) Response पाठवून झाल्यावर (fastcgi_finish_request उपलब्ध असेल तर) — छोटा Batch, Lock सह
 * auth.php आणि Cron Scripts दोन्हीकडून वापरता येतं — Session/currentUser() लागत नाही.
 */

const NOTIFY_PUSH_BATCH = 25;          // एका वेळी किती Push पाठवायचे (Response नंतर)
const NOTIFY_PUSH_MAX_ATTEMPTS = 3;
const NOTIFY_READ_RETENTION_DAYS = 90; // वाचलेल्या सूचना इतक्या दिवसांनी Cron काढून टाकतो

/**
 * Link एकसारखा करतो — नेहमी App-Root पासूनचा (root-relative) पत्ता:
 * '../letters/view.php?id=6' / '/letters/view.php?id=6' → 'letters/view.php?id=6'.
 * बाहेरचा पत्ता (http:, //, javascript:) किंवा '..' असलेला path — null.
 */
function notifyNormalizeLink($link) {
    $link = trim((string)$link);
    if ($link === '') { return null; }
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $link) || strpos($link, '\\') !== false) { return null; }
    do {
        $before = $link;
        if (strpos($link, '//') === 0) { return null; }
        if (strpos($link, '../') === 0) { $link = substr($link, 3); }
        elseif (strpos($link, './') === 0) { $link = substr($link, 2); }
        elseif (strpos($link, '/') === 0) { $link = substr($link, 1); }
    } while ($link !== $before);
    $path = preg_split('/[?#]/', $link, 2)[0];
    if (preg_match('#(^|/)\.\.(/|$)#', $path)) { return null; }
    return $link === '' ? null : $link;
}

/** या संस्थेसाठी Push (VAPID किंवा Firebase) Setup झालं आहे का — नसेल तर Queue मध्ये टाकायची गरजच नाही */
function notifyPushConfigured($orgId) {
    global $pdo;
    static $cache = [];
    $orgId = (int)$orgId;
    if (isset($cache[$orgId])) { return $cache[$orgId]; }
    require_once __DIR__ . '/fcm.php';
    $ok = (bool)fcmServiceAccount();
    if (!$ok) {
        try {
            $st = $pdo->prepare("SELECT vapid_public_key, vapid_private_key FROM organizations WHERE id = ?");
            $st->execute([$orgId]);
            $o = $st->fetch();
            $ok = !empty($o['vapid_public_key']) && !empty($o['vapid_private_key']);
        } catch (PDOException $e) { $ok = false; }
    }
    return $cache[$orgId] = $ok;
}

/**
 * एका व्यक्तीला In-App सूचना + Push (Queue मधून). Fails silently if not migrated yet.
 * $urgent = true (उदा. SOS) — Queue मध्ये पुढे; परत आलेला Queue-ID वापरून लगेच पाठवता येतो.
 * परत: Queue ID (Push Queue मध्ये टाकलं असेल तर), नाहीतर null.
 */
function notifyUser($userId, $orgId, $title, $message = null, $link = null, $urgent = false) {
    global $pdo;
    $link = notifyNormalizeLink($link);
    $notificationId = null;
    try {
        $stmt = $pdo->prepare("INSERT INTO notifications (organization_id, user_id, title, message, link) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$orgId, $userId, mb_substr((string)$title, 0, 255), $message, $link]);
        $notificationId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // notifications table not migrated yet — skip silently
    }
    return notifyEnqueuePush($userId, $orgId, $title, $message, $link, $notificationId, $urgent);
}

/** Push Queue मध्ये नोंद. Queue Table अजून नसेल तर जुन्या पद्धतीने (त्वरित) पाठवतो. */
function notifyEnqueuePush($userId, $orgId, $title, $message, $link, $notificationId = null, $urgent = false) {
    global $pdo;
    if (!notifyPushConfigured($orgId)) { return null; }
    try {
        $pdo->prepare("INSERT INTO notification_push_queue (organization_id, user_id, notification_id, title, message, link, is_urgent) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $userId, $notificationId, mb_substr((string)$title, 0, 255), $message, $link, $urgent ? 1 : 0]);
        $queueId = (int)$pdo->lastInsertId();
        notifyScheduleFlush();
        return $queueId;
    } catch (PDOException $e) {
        // Queue Table अजून Migrate झालेली नाही — जुन्या पद्धतीने लगेच पाठवा
        notifyDeliverOne(['organization_id' => $orgId, 'user_id' => $userId, 'notification_id' => $notificationId,
            'title' => $title, 'message' => $message, 'link' => $link], 6);
        return null;
    }
}

/**
 * अनेक व्यक्तींना एकच सूचना (परिपत्रक, विभाग इ.) — एकाच Transaction मध्ये,
 * त्यामुळे शेकडो जणांनाही लगेच. परत: किती जणांना पाठवली.
 */
function notifyUsers(array $userIds, $orgId, $title, $message = null, $link = null, $excludeUserId = null) {
    global $pdo;
    $ids = [];
    foreach ($userIds as $uid) {
        $uid = (int)$uid;
        if ($uid > 0 && $uid !== (int)$excludeUserId) { $ids[$uid] = true; }
    }
    if (!$ids) { return 0; }
    $ownTx = !$pdo->inTransaction();
    try {
        if ($ownTx) { $pdo->beginTransaction(); }
        foreach (array_keys($ids) as $uid) { notifyUser($uid, $orgId, $title, $message, $link); }
        if ($ownTx) { $pdo->commit(); }
    } catch (Throwable $e) {
        if ($ownTx && $pdo->inTransaction()) { $pdo->rollBack(); }
    }
    return count($ids);
}

/** Notify every active staff member (and HOD) of a department. */
function notifyDepartment($departmentId, $orgId, $title, $message = null, $link = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE department_id = ? AND is_active = 1");
        $stmt->execute([$departmentId]);
        notifyUsers($stmt->fetchAll(PDO::FETCH_COLUMN), $orgId, $title, $message, $link);
    } catch (PDOException $e) {
        // ignore
    }
}

/** Response पाठवून झाल्यावर Queue चा छोटा Batch पाठवण्यासाठी — एकदाच Register */
function notifyScheduleFlush() {
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') { return; }
    $registered = true;
    register_shutdown_function(function () {
        // PHP-FPM नसेल (उदा. mod_php) तर Visitor ला थांबवायचं नाही — Cron पाठवेल
        if (!function_exists('fastcgi_finish_request')) { return; }
        if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
        fastcgi_finish_request();
        ignore_user_abort(true);
        notifyDeliverPushQueue(NOTIFY_PUSH_BATCH, 15);
    });
}

/**
 * Queue मधले Push पाठवतो. $onlyIds दिल्यास फक्त तेच (SOS साठी — लगेच).
 * एकाच वेळी दोन Requests/Cron चालले तरी — प्रत्येक नोंद आधी "sending" म्हणून
 * Claim होते (status='pending' अट), त्यामुळे एकही Push दोनदा जात नाही.
 * परत: किती पाठवले.
 */
function notifyDeliverPushQueue($limit = NOTIFY_PUSH_BATCH, $timeBudgetSec = 20, array $onlyIds = null, $timeout = null) {
    global $pdo;
    $started = microtime(true);
    $sent = 0;
    $haveLock = false;
    try {
        if ($onlyIds === null) {
            // सामान्य Batch — दुसरा Process आधीच पाठवत असेल तर इथे थांबतो
            $haveLock = (int)$pdo->query("SELECT GET_LOCK('eowms_push_queue', 0)")->fetchColumn() === 1;
            if (!$haveLock) { return 0; }
            // मध्येच बंद पडलेल्या Process ने अडकवलेल्या नोंदी परत रांगेत
            $pdo->exec("UPDATE notification_push_queue SET status = 'pending' WHERE status = 'sending' AND locked_at < NOW() - INTERVAL 10 MINUTE");
            $st = $pdo->prepare("SELECT * FROM notification_push_queue WHERE status = 'pending' ORDER BY is_urgent DESC, id LIMIT " . (int)$limit);
            $st->execute();
        } else {
            $onlyIds = array_values(array_filter(array_map('intval', $onlyIds)));
            if (!$onlyIds) { return 0; }
            $ph = implode(',', array_fill(0, count($onlyIds), '?'));
            $st = $pdo->prepare("SELECT * FROM notification_push_queue WHERE status = 'pending' AND id IN ($ph) ORDER BY id");
            $st->execute($onlyIds);
        }
        $rows = $st->fetchAll();

        $claim = $pdo->prepare("UPDATE notification_push_queue SET status = 'sending', locked_at = NOW(), attempts = attempts + 1 WHERE id = ? AND status = 'pending'");
        $done = $pdo->prepare("UPDATE notification_push_queue SET status = ?, sent_at = IF(? = 'sent', NOW(), sent_at) WHERE id = ?");
        foreach ($rows as $row) {
            if (microtime(true) - $started > $timeBudgetSec) { break; }
            $claim->execute([$row['id']]);
            if ($claim->rowCount() === 0) { continue; } // दुसऱ्या Process ने आधीच घेतलं
            $ok = notifyDeliverOne($row, $timeout);
            $status = $ok ? 'sent' : (((int)$row['attempts'] + 1) >= NOTIFY_PUSH_MAX_ATTEMPTS ? 'failed' : 'pending');
            $done->execute([$status, $status, $row['id']]);
            if ($ok) { $sent++; }
        }
    } catch (PDOException $e) {
        // Queue Table नाही / DB अडचण — पुढच्या वेळी
    } finally {
        if ($haveLock) {
            try { $pdo->query("SELECT RELEASE_LOCK('eowms_push_queue')"); } catch (PDOException $e) {}
        }
    }
    return $sent;
}

/** एका नोंदीचा Push — Web Push + Android FCM. true = प्रयत्न पूर्ण (त्रुटी नाही). */
function notifyDeliverOne(array $row, $timeout = null) {
    try {
        // Push वर Click केल्यावर — ती सूचना "वाचली" म्हणून नोंदवून मूळ पानावर
        $webLink = !empty($row['notification_id']) ? 'notifications/open.php?id=' . (int)$row['notification_id'] : $row['link'];
        sendPushNotification($row['user_id'], $row['organization_id'], $row['title'], $row['message'], $webLink, $timeout);
        require_once __DIR__ . '/fcm.php';
        sendFcmToUser($row['user_id'], $row['title'], $row['message'] ?? '', $row['link'], $timeout);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** App बंद असतानाही Mobile वर थेट सूचना पाठवण्यासाठी (Web Push). */
function sendPushNotification($userId, $orgId, $title, $message = null, $link = null, $timeout = null) {
    global $pdo;
    try {
        $orgStmt = $pdo->prepare("SELECT vapid_public_key, vapid_private_key FROM organizations WHERE id = ?");
        $orgStmt->execute([$orgId]);
        $org = $orgStmt->fetch();
        if (empty($org['vapid_public_key']) || empty($org['vapid_private_key'])) {
            return; // Super Admin ने अजून Push Setup केलेलं नाही
        }

        $subStmt = $pdo->prepare("SELECT * FROM push_subscriptions WHERE user_id = ?");
        $subStmt->execute([$userId]);
        $subscriptions = $subStmt->fetchAll();
        if (empty($subscriptions)) { return; }

        require_once __DIR__ . '/webpush/WebPush.php';
        $webPush = new WebPush($org['vapid_public_key'], $org['vapid_private_key'], 'mailto:admin@eowms.local');
        if ($timeout) { $webPush->timeout = (int)$timeout; }

        foreach ($subscriptions as $sub) {
            $result = $webPush->send($sub['endpoint'], $sub['p256dh'], $sub['auth'], [
                'title' => $title,
                'body' => $message ?: '',
                'url' => $link ? (defined('APP_ROOT_URL') ? APP_ROOT_URL . $link : $link) : '/',
            ]);
            if ($result['should_delete']) {
                $pdo->prepare("DELETE FROM push_subscriptions WHERE id = ?")->execute([$sub['id']]);
            }
        }
    } catch (Exception $e) {
        // Push कधीही अपयशी झाला तरी — मुख्य In-App Notification आधीच Save
        // झालेली असते, त्यामुळे इथे शांतपणे वगळणं सुरक्षित आहे.
    }
}

/** Cron — वाचलेल्या जुन्या सूचना (90 दिवस) आणि पाठवलेल्या Queue नोंदी (7 दिवस) काढून टाकतो. परत: [notifications, queue] */
function notifyPurgeOld() {
    global $pdo;
    $n = 0; $q = 0;
    try {
        do {
            $st = $pdo->prepare("DELETE FROM notifications WHERE is_read = 1 AND created_at < NOW() - INTERVAL " . (int)NOTIFY_READ_RETENTION_DAYS . " DAY LIMIT 5000");
            $st->execute();
            $n += $st->rowCount();
        } while ($st->rowCount() === 5000);
    } catch (PDOException $e) {}
    try {
        $st = $pdo->prepare("DELETE FROM notification_push_queue WHERE status IN ('sent','failed') AND created_at < NOW() - INTERVAL 7 DAY");
        $st->execute();
        $q = $st->rowCount();
    } catch (PDOException $e) {}
    return [$n, $q];
}

/** संस्थेतील सक्रिय व्यक्ती — दिलेल्या Role-Category (management/hod/staff/super_admin) च्या. Cron मधूनही चालतं. */
function notifyUserIdsByCategory($orgId, array $categories) {
    global $pdo;
    if (!$categories) { return []; }
    $ph = implode(',', array_fill(0, count($categories), '?'));
    try {
        $st = $pdo->prepare("SELECT u.id FROM users u LEFT JOIN role_definitions rd ON rd.role_key = u.role
            WHERE u.organization_id = ? AND u.is_active = 1 AND (rd.category IN ($ph)" . (in_array('super_admin', $categories, true) ? " OR u.role = 'super_admin'" : "") . ")");
        $st->execute(array_merge([(int)$orgId], $categories));
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}

/** ज्यांच्या Role ला ही Permission दिली आहे (role_permissions) अशा सक्रिय व्यक्ती; Super Admin कडे सगळ्याच Permissions असतात */
function notifyUserIdsByPermission($orgId, $permissionKey, $includeSuperAdmin = true) {
    global $pdo;
    try {
        $st = $pdo->prepare("SELECT DISTINCT u.id FROM users u LEFT JOIN role_permissions rp ON rp.role_key = u.role AND rp.permission_key = ?
            WHERE u.organization_id = ? AND u.is_active = 1 AND (rp.id IS NOT NULL" . ($includeSuperAdmin ? " OR u.role = 'super_admin'" : "") . ")");
        $st->execute([$permissionKey, (int)$orgId]);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {
        return [];
    }
}
