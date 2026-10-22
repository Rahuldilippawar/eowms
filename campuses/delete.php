<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, activeOrgId()]);
$campus = $stmt->fetch();

if (!$campus) {
    $_SESSION['form_error'] = t('s438');
    header('Location: index.php');
    exit;
}

$confirmText = trim($_POST['confirm_name'] ?? '');
if ($confirmText !== $campus['name']) {
    $_SESSION['form_error'] = t('s581');
    header('Location: index.php');
    exit;
}

// FK cascades (ON DELETE CASCADE) will remove all departments, users, work_records,
// renewal_items, and tasks under this campus.
$pdo->prepare("DELETE FROM campuses WHERE id = ?")->execute([$id]);

logActivity('कॅम्पस कायमचा डिलीट केला', 'campus', $id, $campus['name']);
$_SESSION['flash'] = "'{$campus['name']}' आणि त्याच्याशी संबंधित सर्व डेटा कायमचा डिलीट केला गेला.";
header('Location: index.php');
exit;
