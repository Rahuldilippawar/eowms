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

$departmentId = (int)($_POST['department_id'] ?? 0);
$itemMasterId = (int)($_POST['item_master_id'] ?? 0);
$quantity = max(1, (int)($_POST['quantity'] ?? 1));
$unit = trim($_POST['unit'] ?? '') ?: null;
$purchaseDate = ($_POST['purchase_date'] ?? '') ?: null;
$purchaseCost = ($_POST['purchase_cost'] ?? '') !== '' ? (float)$_POST['purchase_cost'] : null;
$condition = $_POST['condition'] ?? 'चांगली';
$location = trim($_POST['location'] ?? '') ?: null;
$remarks = trim($_POST['remarks'] ?? '') ?: null;

$masterStmt = $pdo->prepare("SELECT * FROM dead_stock_item_master WHERE id = ? AND organization_id = ?");
$masterStmt->execute([$itemMasterId, $orgId]);
$masterItem = $masterStmt->fetch();
$itemName = $masterItem['item_name'] ?? '';
$category = $masterItem['category'] ?? null;

$update = $pdo->prepare("
    UPDATE dead_stock_items SET department_id=?, item_master_id=?, item_name=?, category=?, quantity=?, unit=?, purchase_date=?, purchase_cost=?, `condition`=?, location=?, remarks=?
    WHERE id=? AND organization_id=?
");
$update->execute([$departmentId, $itemMasterId, $itemName, $category, $quantity, $unit, $purchaseDate, $purchaseCost, $condition, $location, $remarks, $id, $orgId]);

logActivity('Dead Stock नोंद संपादित केली', 'dead_stock_item', $id, $itemName);
$_SESSION['flash'] = 'नोंद अद्ययावत केली.';
header('Location: index.php');
exit;
