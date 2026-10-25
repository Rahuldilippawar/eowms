<?php
// नवीन खर्च नोंदवण्याआधी — विभागाचं (त्या दिनांकाच्या आर्थिक वर्षातलं) शिल्लक Budget, JSON मध्ये
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/budget-helpers.php';
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
header('Content-Type: application/json; charset=utf-8');

$deptId = (int)($_GET['department_id'] ?? 0);
$amount = (float)($_GET['amount'] ?? 0);
$fy = financialYearForDate(($_GET['date'] ?? '') ?: null);

$deptOk = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
$deptOk->execute([$deptId, $orgId]);
if (!$deptOk->fetch() || !deptFinanceCanAccessDept($user, $deptId)) {
    echo json_encode(['ok' => false]);
    exit;
}

$remaining = budgetRemainingFor($pdo, $deptId, $fy);
echo json_encode([
    'ok' => true,
    'fy' => $fy,
    'remaining' => $remaining,
    'exceeds' => $remaining !== null && $amount > $remaining,
]);
