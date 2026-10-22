<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
if (!isManagementOrAbove() && !hasPermission('manage_meditation')) {
    http_response_code(403);
    die(t('s620'));
}
$user = currentUser();

$id = (int)($_POST['id'] ?? 0);
$eventId = (int)($_POST['event_id'] ?? 0);

if ($id) {
    $pdo->prepare("DELETE FROM satsang_duties WHERE id = ?")->execute([$id]);
    $_SESSION['flash'] = t('s621');
}

header('Location: event.php?id=' . $eventId);
exit;
