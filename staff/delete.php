<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    $_SESSION['form_error'] = t('s511');
    header('Location: index.php');
    exit;
}

// Hard rule: a Super Admin account can NEVER be deleted, by anyone, ever —
// regardless of how many other Super Admins exist. This guarantees the
// system always keeps at least its original admin access path intact.
if ($target['role'] === 'super_admin') {
    $_SESSION['form_error'] = t('s569');
    header('Location: index.php');
    exit;
}

// Require typing the name to confirm — this is a permanent delete (cascades their work_records)
$confirmText = trim($_POST['confirm_name'] ?? '');
if ($confirmText !== $target['name']) {
    $_SESSION['form_error'] = t('s570');
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

logActivity('कर्मचारी कायमचा डिलीट केला', 'user', $id, $target['name']);
$_SESSION['flash'] = "'{$target['name']}' कायमचा डिलीट केला गेला.";
header('Location: index.php');
exit;
