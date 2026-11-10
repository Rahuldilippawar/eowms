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
    $own = $pdo->prepare("SELECT sd.id FROM satsang_duties sd JOIN satsang_events se ON se.id = sd.satsang_event_id WHERE sd.id = ? AND se.organization_id = ?");
    $own->execute([$id, activeOrgId()]);
    if ($own->fetchColumn()) { recycleCapture($pdo, 'satsang_duties', $id, null, activeOrgId()); }   // 🗑️ Recycle Bin
    $pdo->prepare("DELETE sd FROM satsang_duties sd JOIN satsang_events se ON se.id = sd.satsang_event_id WHERE sd.id = ? AND se.organization_id = ?")->execute([$id, activeOrgId()]);
    $_SESSION['flash'] = t('s621');
}

header('Location: event.php?id=' . $eventId);
exit;
