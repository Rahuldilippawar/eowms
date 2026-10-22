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
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM hostels WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$hostel = $stmt->fetch();

if (!$hostel) {
    $_SESSION['form_error'] = t('s430');
    header('Location: index.php');
    exit;
}

$confirmText = trim($_POST['confirm_name'] ?? '');
if ($confirmText !== $hostel['name']) {
    $_SESSION['form_error'] = t('s469');
    header('Location: index.php');
    exit;
}

// FK cascades (ON DELETE CASCADE) will remove hostel_staff allocations and
// hostel_students records under this hostel automatically.
$pdo->prepare("DELETE FROM hostels WHERE id = ?")->execute([$id]);

logActivity('वसतिगृह कायमचे डिलीट केले', 'hostel', $id, $hostel['name']);
$_SESSION['flash'] = "'{$hostel['name']}' आणि त्याच्याशी संबंधित सर्व विद्यार्थी/स्टाफ नोंदी कायमच्या डिलीट झाल्या.";
header('Location: index.php');
exit;
