<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: create.php');
    exit;
}

$departmentId = (int)($_POST['department_id'] ?? 0);
$itemType = trim($_POST['item_type'] ?? '');
$regulatoryBody = trim($_POST['regulatory_body'] ?? '') ?: null;
$itemName = trim($_POST['item_name'] ?? '');
$referenceNo = trim($_POST['reference_no'] ?? '') ?: null;
$issuedDate = ($_POST['issued_date'] ?? '') ?: null;
$expiryDate = $_POST['expiry_date'] ?? null;
$renewalCost = ($_POST['renewal_cost'] ?? '') !== '' ? $_POST['renewal_cost'] : null;
$isRecurringMonthly = isset($_POST['is_recurring_monthly']) ? 1 : 0;
$monthlyAmount = ($isRecurringMonthly && ($_POST['monthly_amount'] ?? '') !== '') ? (float)$_POST['monthly_amount'] : null;
$recurringStartMonth = ($isRecurringMonthly && !empty($_POST['recurring_start_month'])) ? $_POST['recurring_start_month'] . '-01' : null;
$vendorName = trim($_POST['vendor_name'] ?? '') ?: null;
$vendorContact = trim($_POST['vendor_contact'] ?? '') ?: null;
$responsibleUserId = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;

if (!$departmentId || !$itemType || !$itemName || !$expiryDate) {
    $_SESSION['form_error'] = t('s428');
    header('Location: create.php');
    exit;
}

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
    $_SESSION['form_error'] = 'मुदत-दिनांक चुकीचा आहे.';
    header('Location: create.php');
    exit;
}

// विभाग याच संस्थेचा असायला हवा — अवैध ID असेल तर स्पष्ट त्रुटी (Crash नाही)
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
$deptStmt->execute([$departmentId, activeOrgId()]);
$department = $deptStmt->fetch();
if (!$department) {
    $_SESSION['form_error'] = 'निवडलेला विभाग अवैध आहे.';
    header('Location: create.php');
    exit;
}

if ($responsibleUserId) {
    $ruStmt = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ?");
    $ruStmt->execute([$responsibleUserId, activeOrgId()]);
    if (!$ruStmt->fetch()) { $responsibleUserId = null; }
}

if (isDeptScoped() && (int)$user['department_id'] !== $departmentId) {
    http_response_code(403);
    die(t('s434'));
}

$insert = $pdo->prepare("
    INSERT INTO renewal_items
      (organization_id, campus_id, department_id, responsible_user_id, item_type, regulatory_body, item_name, reference_no, issued_date, expiry_date, renewal_cost, is_recurring_monthly, monthly_amount, recurring_start_month, vendor_name, vendor_contact, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
");
$insert->execute([
    $department['organization_id'], $department['campus_id'], $departmentId, $responsibleUserId,
    $itemType, $regulatoryBody, $itemName, $referenceNo, $issuedDate, $expiryDate, $renewalCost, $isRecurringMonthly, $monthlyAmount, $recurringStartMonth, $vendorName, $vendorContact,
]);
logActivity('नूतनीकरण नोंद जोडली', 'renewal_item', $pdo->lastInsertId(), $itemName);

$_SESSION['flash'] = t('s458');
header('Location: index.php');
exit;
