<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_dead_stock_master', '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM dead_stock_items WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$item = $stmt->fetch();

if ($item) {
    $pdo->prepare("DELETE FROM dead_stock_items WHERE id = ?")->execute([$id]);
    logActivity('Dead Stock नोंद डिलीट केली', 'dead_stock_item', $id, $item['item_code'] . ' — ' . $item['item_name']);
    $_SESSION['flash'] = "'{$item['item_name']}' नोंद डिलीट केली गेली.";
}

header('Location: index.php');
exit;
