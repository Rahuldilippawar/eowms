<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/renewal-helpers.php';
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// Soft-Delete — नोंद निष्क्रिय ('inactive') करतो; इतिहास/प्रमाणपत्रे तशीच राहतात
$id = (int)($_POST['id'] ?? 0);
$item = renewalFetchAccessibleItem($pdo, $id);
if (!$item || hasCategory('staff')) {
    $_SESSION['form_error'] = 'ही नोंद सापडली नाही किंवा तुम्हाला ती निष्क्रिय करण्याची परवानगी नाही.';
    header('Location: index.php');
    exit;
}

$pdo->prepare("UPDATE renewal_items SET status = 'inactive' WHERE id = ? AND organization_id = ?")->execute([$id, activeOrgId()]);
logActivity('नूतनीकरण नोंद निष्क्रिय केली', 'renewal_item', $id, $item['item_name']);
$_SESSION['flash'] = '"' . $item['item_name'] . '" ही नोंद निष्क्रिय केली.';
header('Location: index.php');
exit;
