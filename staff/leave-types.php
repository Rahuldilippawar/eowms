<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $nameLocal = trim($_POST['name_local'] ?? '');
        $quota = (float)($_POST['annual_quota'] ?? 12);
        $isPaid = isset($_POST['is_paid']) ? 1 : 0;
        if ($name && $nameLocal) {
            $pdo->prepare("INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $name, $nameLocal, $quota, $isPaid]);
            $_SESSION['flash'] = 'Leave-प्रकार जोडला गेला.';
        }
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE leave_types SET is_active = NOT is_active WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        $_SESSION['flash'] = 'स्थिती बदलली.';
    } elseif ($action === 'seed_standard') {
        // तुम्ही मागितलेले 5 Standard Leave-Types — आधीच नावाने असेल तर वगळतो
        $standardTypes = [
            ['CL', 'CL — प्रासंगिक रजा (Casual Leave)', 12, 1],
            ['Medical Leave', 'Medical — वैद्यकीय/आजारपणाची रजा', 12, 1],
            ['Vacation', 'Vacation — सुट्टीकालीन रजा', 30, 1],
            ['CO', 'CO — नुकसान भरपाई सुट्टी (Compensatory Off)', 0, 1],
            ['LWP', 'LWP — विनावेतन रजा (Leave Without Pay)', 0, 0],
        ];
        $existingStmt = $pdo->prepare("SELECT name FROM leave_types WHERE organization_id = ?");
        $existingStmt->execute([$orgId]);
        $existingNames = $existingStmt->fetchAll(PDO::FETCH_COLUMN);
        $added = 0;
        foreach ($standardTypes as [$name, $nameLocal, $quota, $isPaid]) {
            if (in_array($name, $existingNames, true)) continue;
            $pdo->prepare("INSERT INTO leave_types (organization_id, name, name_local, annual_quota, is_paid) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $name, $nameLocal, $quota, $isPaid]);
            $added++;
        }
        $_SESSION['flash'] = "✅ $added नवीन Leave-प्रकार जोडले गेले." . ($added < count($standardTypes) ? ' (बाकीचे आधीच अस्तित्वात होते.)' : '');
    }
    header('Location: leave-types.php');
    exit;
}

$pageTitle = 'रजा-प्रकार Master';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$typesStmt = $pdo->prepare("SELECT * FROM leave_types WHERE organization_id = ? ORDER BY id");
$typesStmt->execute([$orgId]);
$leaveTypes = $typesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏖️ रजा-प्रकार Master</div>
  <div class="d-flex gap-2">
    <form method="POST"><input type="hidden" name="action" value="seed_standard">
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:9px 18px;font-weight:600;">✨ CL, Medical, Vacation, CO, LWP — आपोआप तयार करा</button>
    </form>
    <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन रजा-प्रकार जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="add">
    <div class="row g-2">
      <div class="col-md-3"><input type="text" name="name_local" class="form-control-custom" placeholder="मराठी नाव (उदा. प्रासंगिक रजा)" required></div>
      <div class="col-md-3"><input type="text" name="name" class="form-control-custom" placeholder="English (उदा. Casual Leave)" required></div>
      <div class="col-md-2"><input type="number" step="0.5" name="annual_quota" class="form-control-custom" placeholder="वार्षिक दिवस" value="12" required></div>
      <div class="col-md-2" style="display:flex;align-items:center;"><label style="font-size:12.5px;"><input type="checkbox" name="is_paid" checked style="margin-right:6px;">पगारी रजा</label></div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">जोडा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>वार्षिक कोटा</th><th>प्रकार</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($leaveTypes)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही रजा-प्रकार जोडलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($leaveTypes as $lt): ?>
        <tr>
          <td style="font-weight:600;"><?= h($lt['name_local']) ?><div style="font-size:10px;color:var(--text-dim);"><?= h($lt['name']) ?></div></td>
          <td class="font-mono"><?= $lt['annual_quota'] ?> दिवस</td>
          <td><?= $lt['is_paid'] ? '💰 पगारी' : '⚪ बिनपगारी' ?></td>
          <td><span class="status-chip <?= $lt['is_active'] ? 'done' : 'overdue' ?>"><?= $lt['is_active'] ? '✅ Active' : '⚪ बंद' ?></span></td>
          <td>
            <form method="POST"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $lt['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;"><?= $lt['is_active'] ? 'बंद करा' : 'सुरू करा' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
