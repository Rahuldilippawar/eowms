<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
$canApprove = $isMgmt || hasCategory('hod');

if (!$canApprove) {
    http_response_code(403);
    die('तुम्हाला रजा-अर्ज मंजूर करण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $appId = (int)($_POST['app_id'] ?? 0);
    $decision = $_POST['decision'] ?? 'rejected';
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');

    $appStmt = $pdo->prepare("SELECT * FROM leave_applications WHERE id = ? AND organization_id = ? AND status = 'pending'");
    $appStmt->execute([$appId, $orgId]);
    $app = $appStmt->fetch();

    if ($app) {
        $pdo->prepare("UPDATE leave_applications SET status = ?, approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ?")
            ->execute([$decision, $user['id'], $decision === 'rejected' ? $rejectionReason : null, $appId]);

        if ($decision === 'approved') {
            $year = (int)date('Y', strtotime($app['start_date']));
            // शिल्लक कमी करतो
            $pdo->prepare("
                INSERT INTO leave_balances (user_id, leave_type_id, year, allocated_days, used_days) VALUES (?, ?, ?, 0, ?)
                ON DUPLICATE KEY UPDATE used_days = used_days + VALUES(used_days)
            ")->execute([$app['user_id'], $app['leave_type_id'], $year, $app['total_days']]);

            // Attendance मध्ये त्या सगळ्या दिवसांसाठी "रजेवर" नोंदवतो
            $current = strtotime($app['start_date']);
            $end = strtotime($app['end_date']);
            while ($current <= $end) {
                $d = date('Y-m-d', $current);
                $pdo->prepare("
                    INSERT INTO staff_attendance (organization_id, user_id, attendance_date, status, source)
                    VALUES (?, ?, ?, 'on_leave', 'manual')
                    ON DUPLICATE KEY UPDATE status = 'on_leave', source = 'manual'
                ")->execute([$orgId, $app['user_id'], $d]);
                $current = strtotime('+1 day', $current);
            }
        }

        notifyUser($app['user_id'], $orgId, 'तुमचा रजा-अर्ज ' . ($decision === 'approved' ? 'मंजूर' : 'नामंजूर') . ' झाला', "{$app['start_date']} ते {$app['end_date']}", 'staff/my-leaves.php');
        logActivity('रजा-अर्ज ' . ($decision === 'approved' ? 'मंजूर' : 'नामंजूर') . ' केला', 'leave_application', $appId);
        $_SESSION['flash'] = 'निर्णय जतन झाला.';
    }
    header('Location: leave-approvals.php');
    exit;
}

$pageTitle = 'रजा-अर्ज मंजुरी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sql = "
    SELECT la.*, u.name AS staff_name, d.name_local AS dept_name, lt.name_local AS leave_type_name
    FROM leave_applications la JOIN users u ON u.id = la.user_id LEFT JOIN departments d ON d.id = u.department_id
    JOIN leave_types lt ON lt.id = la.leave_type_id
    WHERE la.organization_id = ? AND la.status = 'pending'
";
$params = [$orgId];
if (!$isMgmt) { $sql .= " AND u.department_id = ?"; $params[] = $user['department_id'] ?? 0; }
$sql .= " ORDER BY la.created_at";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$pending = $stmt->fetchAll();

$histSql = str_replace("la.status = 'pending'", "la.status != 'pending'", $sql) . " ORDER BY la.approved_at DESC LIMIT 30";
$histStmt = $pdo->prepare($histSql);
$histStmt->execute($params);
$history = $histStmt->fetchAll();

$statusLabels = ['approved' => '✅ मंजूर', 'rejected' => '❌ नामंजूर'];
$statusChips = ['approved' => 'done', 'rejected' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">✅ रजा-अर्ज मंजुरी</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">⏳ मंजुरीसाठी प्रलंबित (<?= count($pending) ?>)</div></div>
  <table class="rtable">
    <thead><tr><th>व्यक्ती</th><th>विभाग</th><th>प्रकार</th><th>कालावधी</th><th>दिवस</th><th>कारण</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($pending)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलाही प्रलंबित अर्ज नाही</td></tr><?php endif; ?>
      <?php foreach ($pending as $p): ?>
        <tr>
          <td style="font-weight:600;"><?= h($p['staff_name']) ?></td>
          <td><?= h($p['dept_name'] ?: '—') ?></td>
          <td><?= h($p['leave_type_name']) ?></td>
          <td><?= date('d M', strtotime($p['start_date'])) ?> — <?= date('d M Y', strtotime($p['end_date'])) ?></td>
          <td class="font-mono"><?= $p['total_days'] ?></td>
          <td style="max-width:180px;"><?= h($p['reason']) ?></td>
          <td>
            <form method="POST" class="d-flex gap-1">
              <input type="hidden" name="app_id" value="<?= $p['id'] ?>">
              <button type="submit" name="decision" value="approved" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:6px;font-size:10.5px;padding:4px 10px;">मंजूर</button>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:6px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#rejModal<?= $p['id'] ?>">नामंजूर</button>
            </form>
          </td>
        </tr>
        <div class="modal fade" id="rejModal<?= $p['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="app_id" value="<?= $p['id'] ?>"><input type="hidden" name="decision" value="rejected">
              <div class="modal-body" style="padding:20px;">
                <label class="form-label-custom">नामंजूर करण्याचं कारण</label>
                <textarea name="rejection_reason" class="form-control-custom" rows="2" required></textarea>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;">नामंजूर करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">इतिहास</div></div>
  <table class="rtable">
    <thead><tr><th>व्यक्ती</th><th>प्रकार</th><th>कालावधी</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php if (empty($history)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही निर्णय झालेला नाही</td></tr><?php endif; ?>
      <?php foreach ($history as $h): ?>
        <tr>
          <td><?= h($h['staff_name']) ?></td>
          <td><?= h($h['leave_type_name']) ?></td>
          <td><?= date('d M', strtotime($h['start_date'])) ?> — <?= date('d M Y', strtotime($h['end_date'])) ?></td>
          <td><span class="status-chip <?= $statusChips[$h['status']] ?>"><?= $statusLabels[$h['status']] ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
