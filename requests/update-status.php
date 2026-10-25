<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';
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

// पाहण्याइतकीच परवानगी — स्वतःचा/Extra विभाग, नेमलेली व्यक्ती, किंवा
// manage_requests_org_wide असलेले Management (त्याशिवाय Management ला नाही)
if (!ticketCanManage($request)) {
    http_response_code(403);
    die(t('s519'));
}
// बंद/रद्द झालेली विनंती इथून बदलता येत नाही (पुन्हा उघडणं — फक्त विनंती करणाऱ्याकडून)
if (!in_array($request['status'], TICKET_OPEN_STATUSES, true)) {
    header('Location: view.php?id=' . $id);
    exit;
}

$completedBy = $status === 'completed' ? $user['id'] : null;
$completedAt = $status === 'completed' ? date('Y-m-d H:i:s') : null;
$remark = trim($_POST['remark'] ?? '') ?: null;
$costIncurred = ($_POST['cost_incurred'] ?? '') !== '' ? (float)$_POST['cost_incurred'] : null;

$update = $pdo->prepare("UPDATE work_requests SET status = ?, completed_by = ?, completed_at = ?, completion_remarks = COALESCE(?, completion_remarks), cost_incurred = COALESCE(?, cost_incurred) WHERE id = ?");
$update->execute([$status, $completedBy, $completedAt, $remark, $costIncurred, $id]);

if ($status !== $request['status'] || $remark) {
    ticketLog($pdo, $id, 'status_changed', $request['status'], $status, $remark, $user['id']);
}

// विनंती पाठवणारा जर आपलाच Logged-in Staff असेल (Internal Request) —
// त्याला स्थिती बदलल्याची Notification पाठवा.
if (!empty($request['requested_by_user_id']) && $status !== $request['status']) {
    $statusLabelsForNotify = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाली'];
    notifyUser(
        $request['requested_by_user_id'], activeOrgId(),
        'तुमच्या विनंतीची स्थिती बदलली',
        $request['request_code'] . ' — आता "' . ($statusLabelsForNotify[$status] ?? $status) . '"',
        'requests/view.php?id=' . $id
    );
}

$_SESSION['flash'] = t('s520');
$back = $_POST['back'] ?? '';
header('Location: ' . (preg_match('/^index\.php(\?[\w=&%\-]*)?$/', $back) ? $back : 'index.php'));
exit;
