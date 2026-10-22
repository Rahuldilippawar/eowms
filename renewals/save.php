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

$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ?");
$deptStmt->execute([$departmentId]);
$department = $deptStmt->fetch();

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

$_SESSION['flash'] = t('s458');
header('Location: index.php');
exit;
