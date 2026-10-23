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

// सुरक्षितता: संस्था डिलीट केल्यास FK Cascade मुळे तिचे सगळे कॅम्पस, विभाग,
// कर्मचारी व त्यांचा सगळा डेटा कायमचा नष्ट होतो — म्हणून संस्थेत अजून
// एकही कर्मचारी/कॅम्पस/विभाग असेल तर डिलीट नाकारतो.
$cntStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM users WHERE organization_id = ?) AS users_count,
    (SELECT COUNT(*) FROM campuses WHERE organization_id = ?) AS campuses_count,
    (SELECT COUNT(*) FROM departments WHERE organization_id = ?) AS depts_count");
$cntStmt->execute([$id, $id, $id]);
$cnt = $cntStmt->fetch();
if ((int)$cnt['users_count'] > 0 || (int)$cnt['campuses_count'] > 0 || (int)$cnt['depts_count'] > 0) {
    $_SESSION['form_error'] = "'{$org['name']}' डिलीट करता येणार नाही — या संस्थेत अजून {$cnt['users_count']} कर्मचारी, {$cnt['campuses_count']} कॅम्पस आणि {$cnt['depts_count']} विभाग जोडलेले आहेत. डिलीट केल्यास हा सगळा डेटा कायमचा नष्ट होईल. आधी कर्मचारी दुसरीकडे हलवा / काढून टाका, कॅम्पस व विभाग काढा — किंवा संस्था फक्त निष्क्रिय (Inactive) करा.";
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM organizations WHERE id = ?")->execute([$id]);

// If the acting Super Admin just deleted their own organization, log them out.
if ((int)$user['organization_id'] === $id) {
    session_destroy();
    header('Location: ../login.php');
    exit;
}

// ज्या संस्थेत Switch केलं होतं तीच डिलीट झाली — परत स्वतःच्या संस्थेवर या
if ((int)($_SESSION['active_org_id'] ?? 0) === $id) {
    $_SESSION['active_org_id'] = (int)$user['organization_id'];
}

logActivity('संस्था कायमची डिलीट केली', 'organization', $id, $org['name']);
$_SESSION['flash'] = "'{$org['name']}' आणि तिच्याशी संबंधित सर्व डेटा कायमचा डिलीट केला गेला.";
header('Location: index.php');
exit;
