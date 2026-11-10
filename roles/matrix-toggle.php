<?php
// 🧩 Permission Matrix मधूनच एका Click वर अधिकार द्या / काढा (Super Admin) — JSON
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false]); exit; }

$roleKey = (string)($_POST['role_key'] ?? '');
$permKey = (string)($_POST['permission_key'] ?? '');
$on = !empty($_POST['on']);
$st = $pdo->prepare("SELECT role_key FROM role_definitions WHERE role_key = ? AND role_key <> 'super_admin'");
$st->execute([$roleKey]);
$p = $pdo->prepare("SELECT permission_key FROM permissions WHERE permission_key = ?");
$p->execute([$permKey]);
if (!$st->fetchColumn() || !$p->fetchColumn()) { http_response_code(422); echo json_encode(['ok' => false, 'error' => 'अवैध पद / अधिकार']); exit; }

if ($on) {
    $pdo->prepare("INSERT IGNORE INTO role_permissions (role_key, permission_key) VALUES (?, ?)")->execute([$roleKey, $permKey]);
} else {
    $pdo->prepare("DELETE FROM role_permissions WHERE role_key = ? AND permission_key = ?")->execute([$roleKey, $permKey]);
}
logActivity('पदाचे Permissions बदलले', 'role', null, $roleKey . ($on ? ' · दिले: ' : ' · काढले: ') . $permKey . ' (Matrix)');
$c = $pdo->prepare("SELECT COUNT(*) FROM role_permissions rp JOIN role_definitions rd ON rd.role_key = rp.role_key WHERE rp.permission_key = ? AND rp.role_key <> 'super_admin'");
$c->execute([$permKey]);
echo json_encode(['ok' => true, 'on' => $on, 'count' => (int)$c->fetchColumn()]);
