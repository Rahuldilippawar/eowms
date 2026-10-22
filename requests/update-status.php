<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? 'pending';
if (!in_array($status, ['pending', 'in_progress', 'completed'], true)) { $status = 'pending'; }

$stmt = $pdo->prepare("SELECT * FROM work_requests WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, activeOrgId()]);
$request = $stmt->fetch();

if (!$request) {
    header('Location: index.php');
    exit;
}

// Department-scoped users can only update requests for their own department
if (isDeptScoped() && (int)$request['department_id'] !== (int)$user['department_id']) {
    http_response_code(403);
    die(t('s519'));
}

$completedBy = $status === 'completed' ? $user['id'] : null;
$completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
$remark = trim($_POST['remark'] ?? '') ?: null;
$costIncurred = ($_POST['cost_incurred'] ?? '') !== '' ? (float)$_POST['cost_incurred'] : null;

$update = $pdo->prepare("UPDATE work_requests SET status = ?, completed_by = ?, completed_at = ?, completion_remarks = COALESCE(?, completion_remarks), cost_incurred = COALESCE(?, cost_incurred) WHERE id = ?");
$update->execute([$status, $completedBy, $completedAt, $remark, $costIncurred, $id]);

if ($status !== $request['status'] || $remark) {
    try {
        $pdo->prepare("INSERT INTO work_request_actions (request_id, action_type, old_status, new_status, remark, changed_by) VALUES (?, 'status_changed', ?, ?, ?, ?)")
            ->execute([$id, $request['status'], $status, $remark, $user['id']]);
    } catch (PDOException $e) {
        // work_request_actions table not migrated yet — status update above already succeeded
    }
}

// विनंती पाठवणारा जर आपलाच Logged-in Staff असेल (Internal Request) —
// त्याला स्थिती बदलल्याची Notification पाठवा.
if (!empty($request['requested_by_user_id']) && $status !== $request['status']) {
    $statusLabelsForNotify = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाली'];
    notifyUser(
        $request['requested_by_user_id'], activeOrgId(),
        'तुमच्या विनंतीची स्थिती बदलली',
        $request['request_code'] . ' — आता "' . ($statusLabelsForNotify[$status] ?? $status) . '"',
        'requests/my-requests.php'
    );
}

$_SESSION['flash'] = t('s520');
header('Location: index.php');
exit;
