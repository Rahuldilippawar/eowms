<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? '';
    $endDate = $_POST['end_date'] ?? '';
    $reason = trim($_POST['reason'] ?? '');

    if ($leaveTypeId && $startDate && $endDate && $reason && $startDate <= $endDate) {
        $totalDays = (strtotime($endDate) - strtotime($startDate)) / 86400 + 1;

        $pdo->prepare("INSERT INTO leave_applications (organization_id, user_id, leave_type_id, start_date, end_date, total_days, reason) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $user['id'], $leaveTypeId, $startDate, $endDate, $totalDays, $reason]);
        $newAppId = $pdo->lastInsertId();

        try {
            $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ?");
            $hodStmt->execute([$user['department_id'] ?? 0]);
            $hodId = $hodStmt->fetch()['hod_id'] ?? null;
            if ($hodId) { notifyUser($hodId, $orgId, 'नवीन रजा-अर्ज', "{$user['name']} — $startDate ते $endDate ($totalDays दिवस)", 'staff/leave-approvals.php'); }
        } catch (Exception $e) {}

        logActivity('रजेसाठी अर्ज केला', 'leave_application', $newAppId, "$startDate ते $endDate");
        $_SESSION['flash'] = 'रजा-अर्ज पाठवला गेला.';
        header('Location: my-leaves.php');
        exit;
    } else {
        $_SESSION['form_error'] = 'कृपया सगळी माहिती बरोबर भरा (शेवटचा दिनांक सुरुवातीच्या दिनांकानंतरचाच असावा).';
    }
}

$pageTitle = 'रजेसाठी अर्ज करा';
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
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏖️ रजेसाठी अर्ज करा</div>
  <a href="my-leaves.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list-check me-1"></i>माझ्या रजा</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <?php foreach ($leaveTypes as $lt): $remaining = $lt['allocated_days'] - $lt['used_days']; ?>
    <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl"><?= h($lt['name_local']) ?></div><div class="val" style="color:<?= $remaining <= 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $remaining ?> <span style="font-size:12px;">/<?= $lt['allocated_days'] ?></span></div></div></div>
  <?php endforeach; ?>
</div>

<div class="panel" style="max-width:600px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">रजा-प्रकार</label>
    <select name="leave_type_id" class="form-select-custom mb-3" required>
      <option value="">-- निवडा --</option>
      <?php foreach ($leaveTypes as $lt): ?><option value="<?= $lt['id'] ?>"><?= h($lt['name_local']) ?> (शिल्लक: <?= $lt['allocated_days'] - $lt['used_days'] ?> दिवस)</option><?php endforeach; ?>
    </select>

    <div class="row g-3 mb-3">
      <div class="col-md-6"><label class="form-label-custom">सुरुवात दिनांक</label><input type="date" name="start_date" class="form-control-custom" required></div>
      <div class="col-md-6"><label class="form-label-custom">शेवटचा दिनांक</label><input type="date" name="end_date" class="form-control-custom" required></div>
    </div>

    <label class="form-label-custom">कारण</label>
    <textarea name="reason" class="form-control-custom mb-3" rows="3" required></textarea>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">अर्ज पाठवा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
