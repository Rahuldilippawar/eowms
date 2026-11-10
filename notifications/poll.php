<?php
// 🔔 उघड्या पानासाठी — न वाचलेल्या सूचना व नवीन सूचना (Toast / Bell / App-Badge साठी).
// शिवाय: Cron नसला तरी रांगेतले Push पाठवले जावेत म्हणून — Response नंतर छोटा Batch.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$after = (int)($_GET['after'] ?? 0);
$out = ['unread' => 0, 'last' => $after, 'items' => []];
try {
    $c = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
    $c->execute([$user['id']]);
    $out['unread'] = (int)$c->fetchColumn();
    $m = $pdo->prepare("SELECT COALESCE(MAX(id), 0) FROM notifications WHERE user_id = ?");
    $m->execute([$user['id']]);
    $out['last'] = (int)$m->fetchColumn();
    if ($after > 0) {
        $q = $pdo->prepare("SELECT id, title, message, created_at FROM notifications WHERE user_id = ? AND id > ? AND is_read = 0 ORDER BY id DESC LIMIT 5");
        $q->execute([$user['id'], $after]);
        foreach ($q->fetchAll() as $n) {
            $out['items'][] = ['id' => (int)$n['id'], 'title' => $n['title'], 'message' => (string)$n['message'], 'url' => 'notifications/open.php?id=' . (int)$n['id']];
        }
    }
} catch (PDOException $e) {}

while (ob_get_level()) ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($out, JSON_UNESCAPED_UNICODE);

// रांगेत 20 सेकंदांपेक्षा जुने Push अडकले असतील (Cron नाही) तर — Response पाठवून झाल्यावर पाठवतो
try {
    $pend = (int)$pdo->query("SELECT COUNT(*) FROM notification_push_queue WHERE status = 'pending' AND created_at < NOW() - INTERVAL 20 SECOND")->fetchColumn();
} catch (PDOException $e) { $pend = 0; }
if ($pend > 0) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();
    else flush();
    ignore_user_abort(true);
    notifyDeliverPushQueue(15, 8);
}
exit;
