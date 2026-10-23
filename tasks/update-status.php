<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my-tasks.php');
    exit;
}

$taskId = (int)($_POST['task_id'] ?? 0);
$status = $_POST['status'] ?? '';
$completionRemarks = trim($_POST['completion_remarks'] ?? '') ?: null;
$costIncurred = ($_POST['cost_incurred'] ?? '') !== '' ? $_POST['cost_incurred'] : null;

if (!in_array($status, ['pending', 'in_progress', 'completed'], true) || ($costIncurred !== null && !is_numeric($costIncurred))) {
    $_SESSION['flash'] = 'अवैध स्थिती किंवा खर्च — काहीही बदल केला नाही.';
    header('Location: my-tasks.php');
    exit;
}
$costIncurred = $costIncurred !== null ? (float)$costIncurred : null;

$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND organization_id = ?");
$stmt->execute([$taskId, activeOrgId()]);
$task = $stmt->fetch();

if (!$task || (int)$task['assigned_to'] !== (int)$user['id']) {
    http_response_code(403);
    die(t('s486'));
}

// रद्द केलेला किंवा आधीच पडताळलेला टास्क — कर्मचाऱ्याला बदलता येणार नाही
if ($task['status'] === 'cancelled' || !empty($task['verified_at'])) {
    $_SESSION['flash'] = 'हा टास्क रद्द/पडताळलेला आहे — स्थिती बदलता येणार नाही.';
    header('Location: my-tasks.php');
    exit;
}

// पूर्णत्वाची वेळ — पहिल्यांदा "पूर्ण" केल्यावरच लावतो, इतर स्थितीत काढतो
if ($status === 'completed') {
    $completedAt = ($task['status'] === 'completed' && $task['completed_at']) ? $task['completed_at'] : date('Y-m-d H:i:s');
} else {
    $completedAt = null;
}

$update = $pdo->prepare("UPDATE tasks SET status = ?, completed_at = ?, completion_remarks = COALESCE(?, completion_remarks), cost_incurred = COALESCE(?, cost_incurred) WHERE id = ? AND organization_id = ?");
$update->execute([$status, $completedAt, $completionRemarks, $costIncurred, $taskId, activeOrgId()]);

// पूर्ण झाल्यावर — Assign करणाऱ्याला पडताळणीसाठी कळवतो
if ($status === 'completed' && $task['status'] !== 'completed' && (int)$task['assigned_by'] !== (int)$user['id']) {
    notifyUser($task['assigned_by'], $task['organization_id'], 'टास्क पूर्ण झाला — पडताळणी करा', "{$task['task_code']} — {$task['title']} ({$user['name']})", 'tasks/index.php?only_mine=1&status=completed');
}

$_SESSION['flash'] = t('s487');
header('Location: my-tasks.php');
exit;
