<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    $appId = (int)($_POST['app_id'] ?? 0);
    $pdo->prepare("UPDATE leave_applications SET status = 'cancelled' WHERE id = ? AND user_id = ? AND status = 'pending'")
        ->execute([$appId, $user['id']]);
    $_SESSION['flash'] = 'अर्ज रद्द केला.';
    header('Location: my-leaves.php');
    exit;
}

$pageTitle = 'माझ्या रजा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$year = date('Y');
$typesStmt = $pdo->prepare("
    SELECT lt.*, COALESCE(lb.allocated_days, 0) AS allocated_days, COALESCE(lb.used_days, 0) AS used_days
    FROM leave_types lt LEFT JOIN leave_balances lb ON lb.leave_type_id = lt.id AND lb.user_id = ? AND lb.year = ?
    WHERE lt.organization_id = ? AND lt.is_active = 1 ORDER BY lt.id
");
$typesStmt->execute([$user['id'], $year, $orgId]);
$leaveTypes = $typesStmt->fetchAll();

$appsStmt = $pdo->prepare("
    SELECT la.*, lt.name_local AS leave_type_name, au.name AS approver_name
    FROM leave_applications la JOIN leave_types lt ON lt.id = la.leave_type_id LEFT JOIN users au ON au.id = la.approved_by
    WHERE la.user_id = ? ORDER BY la.created_at DESC
");
$appsStmt->execute([$user['id']]);
$myApps = $appsStmt->fetchAll();

$statusLabels = ['pending' => '⏳ प्रलंबित', 'approved' => '✅ मंजूर', 'rejected' => '❌ नामंजूर', 'cancelled' => '⚪ रद्द केलं'];
$statusChips = ['pending' => 'pending', 'approved' => 'done', 'rejected' => 'overdue', 'cancelled' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏖️ माझ्या रजा</div>
  <a href="leave-apply.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन अर्ज</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($leaveTypes as $lt): $remaining = $lt['allocated_days'] - $lt['used_days']; ?>
    <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h($lt['name_local']) ?></div><div class="val" style="color:<?= $remaining <= 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $remaining ?> <span style="font-size:12px;">/<?= $lt['allocated_days'] ?></span></div></div></div>
  <?php endforeach; ?>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>प्रकार</th><th>कालावधी</th><th>दिवस</th><th>कारण</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($myApps)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही अर्ज केलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($myApps as $a): ?>
        <tr>
          <td style="font-weight:600;"><?= h($a['leave_type_name']) ?></td>
          <td><?= date('d M', strtotime($a['start_date'])) ?> — <?= date('d M Y', strtotime($a['end_date'])) ?></td>
          <td class="font-mono"><?= $a['total_days'] ?></td>
          <td style="max-width:180px;">
            <?= h($a['reason']) ?>
            <?php if ($a['status'] === 'rejected' && $a['rejection_reason']): ?><div style="font-size:10.5px;color:var(--rose);">कारण: <?= h($a['rejection_reason']) ?></div><?php endif; ?>
          </td>
          <td><span class="status-chip <?= $statusChips[$a['status']] ?>"><?= $statusLabels[$a['status']] ?></span></td>
          <td>
            <?php if ($a['status'] === 'pending'): ?>
              <form method="POST" onsubmit="return confirm('अर्ज रद्द करायचा?')"><input type="hidden" name="action" value="cancel"><input type="hidden" name="app_id" value="<?= $a['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">✕ रद्द करा</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
