<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my-tasks.php');
    exit;
}

$taskId = (int)($_POST['task_id'] ?? 0);
$status = $_POST['status'] ?? 'pending';
if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) { $status = 'pending'; }
$completionRemarks = trim($_POST['completion_remarks'] ?? '') ?: null;
$costIncurred = ($_POST['cost_incurred'] ?? '') !== '' ? (float)$_POST['cost_incurred'] : null;

$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ?");
$stmt->execute([$taskId]);
$task = $stmt->fetch();

if (!$task || (int)$task['assigned_to'] !== (int)$user['id']) {
    http_response_code(403);
    die(t('s486'));
}

$completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;

$update = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = ?, completion_remarks = COALESCE(?, completion_remarks), cost_incurred = COALESCE(?, cost_incurred) WHERE id = ?");
$update->execute([$status, $completedAt, $completionRemarks, $costIncurred, $taskId]);

$_SESSION['flash'] = t('s487');
header('Location: my-tasks.php');
exit;
