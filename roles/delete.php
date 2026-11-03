<?php
// न वापरलेलं (कोणालाही दिलेलं नसलेलं) Custom पद हटवणे — System पदे कधीच नाही.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

$roleKey = (string)($_POST['role_key'] ?? '');
$st = $pdo->prepare("SELECT * FROM role_definitions WHERE role_key = ?");
$st->execute([$roleKey]);
$role = $st->fetch();
$st = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
$st->execute([$roleKey]);
$holders = (int)$st->fetchColumn();

if (!$role) {
    $_SESSION['form_error'] = 'पद सापडले नाही.';
} elseif ($role['is_system']) {
    $_SESSION['form_error'] = 'System पद हटवता येत नाही.';
} elseif ($holders > 0) {
    $_SESSION['form_error'] = "हे पद अजून {$holders} व्यक्तींना दिलेलं आहे — आधी त्यांचं पद बदला.";
} else {
    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM role_permissions WHERE role_key = ?")->execute([$roleKey]);
    $pdo->prepare("DELETE FROM role_definitions WHERE role_key = ? AND is_system = 0")->execute([$roleKey]);
    $pdo->commit();
    logActivity('पद हटवले', 'role', null, $roleKey . ' — ' . ($role['label_local'] ?: $role['label']));
    $_SESSION['flash'] = "'" . ($role['label_local'] ?: $role['label']) . "' हे पद हटवले.";
}
header('Location: index.php');
exit;
