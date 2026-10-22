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

$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->execute([$id]);
$org = $stmt->fetch();

if (!$org) {
    $_SESSION['form_error'] = t('s440');
    header('Location: index.php');
    exit;
}

// Require the admin to type the organization's name to confirm — prevents accidental deletion
$confirmText = trim($_POST['confirm_name'] ?? '');
if ($confirmText !== $org['name']) {
    $_SESSION['form_error'] = t('s537');
    header('Location: index.php');
    exit;
}

// सुरक्षितता: Super Admin कधीही, कुठल्याही मार्गाने (अगदी संपूर्ण संस्था
// डिलीट करूनही) पूर्णपणे नष्ट होऊ नये — मग सिस्टीममध्ये कोणीच उरणार नाही.
// या संस्थेतले Super Admin वजा करून, बाकी संस्थांमध्ये किमान एक तरी
// Super Admin शिल्लक राहतो का ते तपासा.
$totalSuperAdmins = (int)$pdo->query("SELECT COUNT(*) c FROM users WHERE role = 'super_admin' AND is_active = 1")->fetch()['c'];
$saStmt = $pdo->prepare("SELECT COUNT(*) c FROM users WHERE role = 'super_admin' AND is_active = 1 AND organization_id = ?");
$saStmt->execute([$id]);
$superAdminsInThisOrg = (int)$saStmt->fetch()['c'];

if (($totalSuperAdmins - $superAdminsInThisOrg) < 1) {
    $_SESSION['form_error'] = "'{$org['name']}' डिलीट करता येणार नाही — यामुळे सिस्टीममध्ये एकही Super Admin शिल्लक राहणार नाही. आधी दुसऱ्या एखाद्या संस्थेत किमान एक Super Admin तयार करा, मग ही संस्था डिलीट करा.";
    header('Location: index.php');
    exit;
}

// FK cascades (ON DELETE CASCADE) will remove all campuses, departments, users,
// work_records, renewal_items, and tasks under this organization.
$pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$id]);

// If the acting Super Admin just deleted their own organization, log them out.
if ((int)$user['organization_id'] === $id) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

logActivity('संस्था कायमची डिलीट केली', 'organization', $id, $org['name']);
$_SESSION['flash'] = "'{$org['name']}' आणि तिच्याशी संबंधित सर्व डेटा कायमचा डिलीट केला गेला.";
header('Location: index.php');
exit;
