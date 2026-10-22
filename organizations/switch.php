<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orgId = (int)($_POST['organization_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id FROM organizations WHERE id = ?");
    $stmt->execute([$orgId]);
    if ($stmt->fetch()) {
        $_SESSION['active_org_id'] = $orgId;
    }
}

// Open Redirect पासून संरक्षण — फक्त याच Website वरचा, Relative Path
// असेल तरच तिकडे परत पाठवा; कोणी बाहेरच्या (Malicious) Website कडे
// निर्देशित करणारा 'back' पाठवला तर तो दुर्लक्षित करून सुरक्षित पानावर जा.
$back = $_POST['back'] ?? '../index.php';
if (!is_string($back) || $back === '' || preg_match('#^(https?:)?//#i', $back) || strpos($back, '\\') !== false) {
    $back = '../index.php';
}
header('Location: ' . $back);
exit;
