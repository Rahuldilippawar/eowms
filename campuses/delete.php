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

// सुरक्षितता: कॅम्पस डिलीट केल्यास FK Cascade मुळे त्याचे सगळे विभाग आणि
// कर्मचारी (users.campus_id ON DELETE CASCADE) कायमचे नष्ट होतात — म्हणून
// एकही कर्मचारी किंवा विभाग जोडलेला असेल तर डिलीट नाकारतो.
$cntStmt = $pdo->prepare("SELECT
    (SELECT COUNT(*) FROM users WHERE campus_id = ?) AS users_count,
    (SELECT COUNT(*) FROM departments WHERE campus_id = ?) AS depts_count");
$cntStmt->execute([$id, $id]);
$cnt = $cntStmt->fetch();
if ((int)$cnt['users_count'] > 0 || (int)$cnt['depts_count'] > 0) {
    $_SESSION['form_error'] = "'{$campus['name']}' डिलीट करता येणार नाही — या कॅम्पसला अजून {$cnt['users_count']} कर्मचारी आणि {$cnt['depts_count']} विभाग जोडलेले आहेत. डिलीट केल्यास ते सगळे कायमचे नष्ट होतील. आधी विभाग व कर्मचारी दुसऱ्या कॅम्पसमध्ये हलवा / निष्क्रिय करा, किंवा कॅम्पस फक्त निष्क्रिय (Inactive) करा.";
    header('Location: index.php');
    exit;
}

$pdo->prepare("DELETE FROM campuses WHERE id = ?")->execute([$id]);

logActivity('कॅम्पस कायमचा डिलीट केला', 'campus', $id, $campus['name']);
$_SESSION['flash'] = "'{$campus['name']}' आणि त्याच्याशी संबंधित सर्व डेटा कायमचा डिलीट केला गेला.";
header('Location: index.php');
exit;
