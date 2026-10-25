<?php
// Bell / सूचना-यादी / Push वरून एखादी सूचना उघडली — ती एकच "वाचली" म्हणून
// नोंदवून तिच्या पानावर नेतो. फक्त स्वतःचीच सूचना; Link फक्त याच App मधला.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
$target = 'notifications/index.php';
try {
    $st = $pdo->prepare("SELECT id, link FROM notifications WHERE id = ? AND user_id = ?");
    $st->execute([$id, $user['id']]);
    $n = $st->fetch();
    if ($n) {
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user['id']]);
        $target = notifyNormalizeLink($n['link']) ?? $target;
    }
} catch (PDOException $e) {}

header('Location: ../' . $target);
exit;
