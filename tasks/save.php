<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('assign_tasks', '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: assign.php');
    exit;
}

$departmentId = (int)($_POST['department_id'] ?? 0);
$assignedTo = (int)($_POST['assigned_to'] ?? 0);
$title = trim($_POST['title'] ?? '');
$description = trim($_POST['description'] ?? '');
$priority = $_POST['priority'] ?? 'normal';
$targetDate = $_POST['target_date'] ?? null;

if (!$departmentId || !$assignedTo || !$title || !$targetDate) {
    $_SESSION['form_error'] = t('s428');
    header('Location: assign.php');
    exit;
}

// Priority व Target तारीख — फक्त वैध मूल्यं
if (!in_array($priority, ['low', 'normal', 'high', 'urgent'], true) || !DateTime::createFromFormat('!Y-m-d', (string)$targetDate)) {
    $_SESSION['form_error'] = 'अवैध प्राधान्य किंवा Target तारीख.';
    header('Location: assign.php');
    exit;
}

// विभाग सक्रिय संस्थेचाच असावा
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ? AND is_active = 1");
$deptStmt->execute([$departmentId, activeOrgId()]);
$department = $deptStmt->fetch();
if (!$department) {
    $_SESSION['form_error'] = 'निवडलेला विभाग सापडला नाही.';
    header('Location: assign.php');
    exit;
}

// टास्क घेणारी व्यक्ती — याच संस्थेची, सक्रिय व निवडलेल्या विभागाचीच असावी
$assigneeCheck = $pdo->prepare("SELECT department_id FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
$assigneeCheck->execute([$assignedTo, activeOrgId()]);
$assigneeRow = $assigneeCheck->fetch();
if (!$assigneeRow || (int)$assigneeRow['department_id'] !== $departmentId) {
    $_SESSION['form_error'] = 'निवडलेली व्यक्ती या विभागाची नाही — कृपया यादीतूनच निवडा.';
    header('Location: assign.php');
    exit;
}

// विभाग प्रमुख (HOD) — फक्त स्वतःच्याच विभागाला Task देऊ शकतात; हे इथे
// Server वरच पुन्हा पडताळतो — कोणी Form मध्ये फेरफार करून वेगळाच
// department_id पाठवला तरी तो चालणार नाही.
$isDeptScopedAssigner = !hasPermission('assign_tasks_org_wide');
if ($isDeptScopedAssigner) {
    if ($departmentId !== (int)($user['department_id'] ?? 0)) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">तुम्ही फक्त तुमच्याच विभागाला Task देऊ शकता. <a href="assign.php">परत जा</a></div>');
    }
}

$countStmt = $pdo->prepare("SELECT COUNT(*) c FROM tasks WHERE organization_id = ?");
$countStmt->execute([$department['organization_id']]);
$seq = $countStmt->fetch()['c'] + 1;
$taskCode = generateUniqueCode($pdo, 'tasks', 'task_code', 'TSK-%s%04d', $seq, date('y'));

$insert = $pdo->prepare("
    INSERT INTO tasks (task_code, organization_id, campus_id, department_id, assigned_by, assigned_to, title, description, priority, target_date, status)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')
");
$insert->execute([
    $taskCode, $department['organization_id'], $department['campus_id'], $departmentId,
    $user['id'], $assignedTo, $title, $description, $priority, $targetDate,
]);

notifyUser($assignedTo, $department['organization_id'], 'नवीन टास्क असाइन झाला', "$title (Target: " . date('d M Y', strtotime($targetDate)) . ")", 'tasks/my-tasks.php');
logActivity('टास्क असाइन केला', 'task', $pdo->lastInsertId(), "$taskCode — $title → user #$assignedTo");

$_SESSION['flash'] = 'टास्क यशस्वीरित्या असाइन केला गेला — ' . $taskCode;
header('Location: index.php');
exit;
