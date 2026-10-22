<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canApprove = hasCategory('hod') || hasPermission('view_org_wide_dashboards') || hasRole('super_admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'request') {
        $date = $_POST['attendance_date'] ?? '';
        $requestedStatus = $_POST['requested_status'] ?? 'present';
        $reason = trim($_POST['reason'] ?? '');
        if ($date && $reason) {
            $pdo->prepare("INSERT INTO attendance_regularization_requests (organization_id, user_id, attendance_date, requested_status, reason) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $user['id'], $date, $requestedStatus, $reason]);
            $newReqId = $pdo->lastInsertId();
            try {
                $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
                $hodStmt->execute([$user['department_id'] ?? 0]);
                $hodId = $hodStmt->fetch()['hod_id'] ?? null;
                if ($hodId) { notifyUser($hodId, $orgId, 'Attendance दुरुस्तीची विनंती', "{$user['name']} — $date", 'staff/regularization.php'); }
            } catch (Exception $e) {}
            logActivity('Attendance Regularization विनंती केली', 'attendance_regularization', $newReqId, $date);
            $_SESSION['flash'] = 'विनंती पाठवली गेली.';
        }
    } elseif ($action === 'decide' && $canApprove) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
        $reqStmt = $pdo->prepare("SELECT r.*, u.department_id AS requester_dept_id FROM attendance_regularization_requests r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.organization_id = ?");
        $reqStmt->execute([$reqId, $orgId]);
        $req = $reqStmt->fetch();
        // HOD फक्त स्वतःच्या विभागाच्या (आणि स्वतःच्या नव्हे) विनंत्यांवर निर्णय घेऊ शकतो
        $isMgmtApprover = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
        if ($req && !$isMgmtApprover && ((int)$req['requester_dept_id'] !== (int)($user['department_id'] ?? 0) || (int)$req['user_id'] === (int)$user['id'])) {
            $req = null;
        }
        if ($req) {
            $pdo->prepare("UPDATE attendance_regularization_requests SET status = ?, approved_by = ?, approved_at = NOW() WHERE id = ?")
                ->execute([$decision, $user['id'], $reqId]);
            if ($decision === 'approved') {
                $pdo->prepare("
                    INSERT INTO staff_attendance (organization_id, user_id, attendance_date, status, source, remarks)
                    VALUES (?, ?, ?, ?, 'manual', 'Regularization द्वारे मंजूर')
                    ON DUPLICATE KEY UPDATE status = VALUES(status), source = 'manual', remarks = VALUES(remarks)
                ")->execute([$orgId, $req['user_id'], $req['attendance_date'], $req['requested_status']]);
            }
            notifyUser($req['user_id'], $orgId, 'तुमची Attendance विनंती ' . ($decision === 'approved' ? 'मंजूर' : 'नामंजूर') . ' झाली', $req['attendance_date'], 'staff/regularization.php');
            $_SESSION['flash'] = 'निर्णय जतन झाला.';
        }
    }
    header('Location: regularization.php');
    exit;
}

$pageTitle = 'Attendance दुरुस्ती विनंत्या';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$myReqStmt = $pdo->prepare("SELECT * FROM attendance_regularization_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
$myReqStmt->execute([$user['id']]);
$myRequests = $myReqStmt->fetchAll();

$pendingApprovals = [];
if ($canApprove) {
    $isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
    $sql = "
        SELECT r.*, u.name AS staff_name, d.name_local AS dept_name
        FROM attendance_regularization_requests r JOIN users u ON u.id = r.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE r.organization_id = ? AND r.status = 'pending'
    ";
    $params = [$orgId];
    if (!$isMgmt) { $sql .= " AND u.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
    $sql .= " ORDER BY r.created_at";
    $pendStmt = $pdo->prepare($sql);
    $pendStmt->execute($params);
    $pendingApprovals = $pendStmt->fetchAll();
}

$statusLabels = ['pending' => '⏳ प्रलंबित', 'approved' => '✅ मंजूर', 'rejected' => '❌ नामंजूर'];
$statusChips = ['pending' => 'pending', 'approved' => 'done', 'rejected' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">📝 Attendance दुरुस्ती विनंत्या</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($canApprove && !empty($pendingApprovals)): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">✅ मंजुरीसाठी प्रलंबित</div></div>
  <table class="rtable">
    <thead><tr><th>व्यक्ती</th><th>विभाग</th><th>दिनांक</th><th>मागणी</th><th>कारण</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pendingApprovals as $p): ?>
        <tr>
          <td style="font-weight:600;"><?= h($p['staff_name']) ?></td>
          <td><?= h($p['dept_name'] ?: '—') ?></td>
          <td><?= date('d M Y', strtotime($p['attendance_date'])) ?></td>
          <td><?= $p['requested_status'] === 'present' ? '✅ हजर' : '🟡 अर्धा दिवस' ?></td>
          <td style="max-width:200px;"><?= h($p['reason']) ?></td>
          <td>
            <form method="POST" class="d-flex gap-1">
              <input type="hidden" name="action" value="decide"><input type="hidden" name="request_id" value="<?= $p['id'] ?>">
              <button type="submit" name="decision" value="approved" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:6px;font-size:10.5px;padding:4px 10px;">मंजूर</button>
              <button type="submit" name="decision" value="rejected" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:6px;font-size:10.5px;padding:4px 10px;">नामंजूर</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन विनंती करा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="request">
    <div class="row g-2">
      <div class="col-md-3"><input type="date" name="attendance_date" class="form-control-custom" max="<?= date('Y-m-d') ?>" required></div>
      <div class="col-md-3">
        <select name="requested_status" class="form-select-custom">
          <option value="present">✅ हजर होतो</option>
          <option value="half_day">🟡 अर्धा दिवस होतो</option>
        </select>
      </div>
      <div class="col-md-4"><input type="text" name="reason" class="form-control-custom" placeholder="कारण (उदा. Machine ने Punch घेतला नाही)" required></div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">पाठवा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">माझ्या विनंत्या</div></div>
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>मागणी</th><th>कारण</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php if (empty($myRequests)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही विनंती नाही</td></tr><?php endif; ?>
      <?php foreach ($myRequests as $r): ?>
        <tr>
          <td><?= date('d M Y', strtotime($r['attendance_date'])) ?></td>
          <td><?= $r['requested_status'] === 'present' ? '✅ हजर' : '🟡 अर्धा दिवस' ?></td>
          <td><?= h($r['reason']) ?></td>
          <td><span class="status-chip <?= $statusChips[$r['status']] ?>"><?= $statusLabels[$r['status']] ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
