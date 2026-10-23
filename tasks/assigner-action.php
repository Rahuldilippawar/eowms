<?php
// टास्क Assign करणाऱ्याच्या कृती — मुदत/तपशील बदलणे, रद्द करणे, पूर्ण टास्कची पडताळणी किंवा पुन्हा उघडणे
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
requireMigratedTable($pdo, 'tasks', '../', 'verified_at');

$back = 'index.php' . (!empty($_POST['back_qs']) && preg_match('/^[\w=&%\-]*$/', $_POST['back_qs']) ? '?' . $_POST['back_qs'] : '');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $back);
    exit;
}

$taskId = (int)($_POST['task_id'] ?? 0);
$action = $_POST['action'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM tasks WHERE id = ? AND organization_id = ?");
$stmt->execute([$taskId, activeOrgId()]);
$task = $stmt->fetch();

// फक्त ज्याने Assign केलं तो (किंवा Super Admin)
if (!$task || ((int)$task['assigned_by'] !== (int)$user['id'] && !hasRole('super_admin'))) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">हा टास्क तुम्ही Assign केलेला नाही. <a href="index.php">परत जा</a></div>');
}

$remark = trim($_POST['remark'] ?? '');
$label = "{$task['task_code']} — {$task['title']}";

if ($action === 'edit') {
    $targetDate = $_POST['target_date'] ?? '';
    $description = trim($_POST['description'] ?? '');
    if (!DateTime::createFromFormat('!Y-m-d', $targetDate)) {
        $_SESSION['flash'] = 'अवैध Target तारीख — बदल झाला नाही.';
    } elseif (in_array($task['status'], ['completed', 'cancelled'], true)) {
        $_SESSION['flash'] = 'पूर्ण/रद्द टास्क बदलता येत नाही.';
    } else {
        $pdo->prepare("UPDATE tasks SET target_date = ?, description = ? WHERE id = ? AND organization_id = ?")
            ->execute([$targetDate, $description, $taskId, activeOrgId()]);
        if ($targetDate !== $task['target_date']) {
            notifyUser($task['assigned_to'], $task['organization_id'], 'टास्कची मुदत बदलली', "$label (नवीन Target: " . date('d M Y', strtotime($targetDate)) . ")", 'tasks/my-tasks.php');
        }
        logActivity('टास्क बदलला', 'task', $taskId, $label);
        $_SESSION['flash'] = 'टास्क अद्ययावत झाला.';
    }
} elseif ($action === 'cancel') {
    if ($task['status'] === 'cancelled' || !empty($task['verified_at'])) {
        $_SESSION['flash'] = 'हा टास्क आधीच रद्द/पडताळलेला आहे.';
    } else {
        $pdo->prepare("UPDATE tasks SET status = 'cancelled', verification_remarks = ? WHERE id = ? AND organization_id = ?")
            ->execute([$remark ?: null, $taskId, activeOrgId()]);
        notifyUser($task['assigned_to'], $task['organization_id'], 'टास्क रद्द केला', $label . ($remark ? " — $remark" : ''), 'tasks/my-tasks.php');
        logActivity('टास्क रद्द केला', 'task', $taskId, $label);
        $_SESSION['flash'] = 'टास्क रद्द केला.';
    }
} elseif ($action === 'verify') {
    if ($task['status'] !== 'completed' || !empty($task['verified_at'])) {
        $_SESSION['flash'] = 'फक्त पूर्ण (व अजून न पडताळलेला) टास्कच पडताळता येतो.';
    } else {
        $pdo->prepare("UPDATE tasks SET verified_at = NOW(), verified_by = ?, verification_remarks = ? WHERE id = ? AND organization_id = ?")
            ->execute([$user['id'], $remark ?: null, $taskId, activeOrgId()]);
        notifyUser($task['assigned_to'], $task['organization_id'], '✅ टास्क पडताळला गेला', $label . ($remark ? " — $remark" : ''), 'tasks/my-tasks.php');
        logActivity('टास्क पडताळला', 'task', $taskId, $label);
        $_SESSION['flash'] = 'टास्क पडताळला गेला.';
    }
} elseif ($action === 'reopen') {
    if ($task['status'] !== 'completed') {
        $_SESSION['flash'] = 'फक्त पूर्ण टास्कच पुन्हा उघडता येतो.';
    } elseif ($remark === '') {
        $_SESSION['flash'] = 'पुन्हा उघडण्याचं कारण (टिप्पणी) लिहा.';
    } else {
        $pdo->prepare("UPDATE tasks SET status = 'in_progress', completed_at = NULL, verified_at = NULL, verified_by = NULL, verification_remarks = ? WHERE id = ? AND organization_id = ?")
            ->execute([$remark, $taskId, activeOrgId()]);
        notifyUser($task['assigned_to'], $task['organization_id'], 'टास्क पुन्हा उघडला', "$label — $remark", 'tasks/my-tasks.php');
        logActivity('टास्क पुन्हा उघडला', 'task', $taskId, "$label — $remark");
        $_SESSION['flash'] = 'टास्क पुन्हा उघडला — कर्मचाऱ्याला कळवलं.';
    }
} else {
    $_SESSION['flash'] = 'अवैध कृती.';
}

header('Location: ' . $back);
exit;
