<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $year = (int)($_POST['year'] ?? date('Y'));

    if ($action === 'set_one') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $leaveTypeId = (int)($_POST['leave_type_id'] ?? 0);
        $allocated = (float)($_POST['allocated_days'] ?? 0);
        $pdo->prepare("
            INSERT INTO leave_balances (user_id, leave_type_id, year, allocated_days) VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE allocated_days = VALUES(allocated_days)
        ")->execute([$userId, $leaveTypeId, $year, $allocated]);
        $_SESSION['flash'] = 'Balance जतन झाला.';
    } elseif ($action === 'bulk_allocate') {
        // सगळ्या Active Staff ला, सगळ्या Active Leave-प्रकारांचा त्यांचा Default
        // Annual-Quota या वर्षासाठी आपोआप Allocate करतो (आधीच असेल तर वगळतो)
        $staffStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1");
        $staffStmt->execute([$orgId]);
        $staffIds = $staffStmt->fetchAll(PDO::FETCH_COLUMN);

        $typesStmt = $pdo->prepare("SELECT id, annual_quota FROM leave_types WHERE organization_id = ? AND is_active = 1");
        $typesStmt->execute([$orgId]);
        $types = $typesStmt->fetchAll();

        $count = 0;
        foreach ($staffIds as $uid) {
            foreach ($types as $lt) {
                $pdo->prepare("INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated_days) VALUES (?, ?, ?, ?)")
                    ->execute([$uid, $lt['id'], $year, $lt['annual_quota']]);
                $count++;
            }
        }
        $_SESSION['flash'] = "$year सालासाठी सगळ्या Staff ला Default Quota आपोआप Allocate केला (आधीच असलेले वगळले).";
    }
    header('Location: leave-balances.php?year=' . $year);
    exit;
}

$pageTitle = 'रजा-शिल्लक (Leave Balances)';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$year = (int)($_GET['year'] ?? date('Y'));

$typesStmt = $pdo->prepare("SELECT * FROM leave_types WHERE organization_id = ? AND is_active = 1 ORDER BY id");
$typesStmt->execute([$orgId]);
$leaveTypes = $typesStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$staffList = $staffStmt->fetchAll();

$balStmt = $pdo->prepare("SELECT * FROM leave_balances WHERE year = ? AND user_id IN (SELECT id FROM users WHERE organization_id = ?)");
$balStmt->execute([$year, $orgId]);
$balByUserType = [];
foreach ($balStmt->fetchAll() as $b) { $balByUserType[$b['user_id']][$b['leave_type_id']] = $b; }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 रजा-शिल्लक (Leave Balances)</div>
  <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <form method="GET">
    <select name="year" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
      <?php for ($y = (int)date('Y') + 1; $y >= (int)date('Y') - 1; $y--): ?><option value="<?= $y ?>" <?= $year === $y ? 'selected' : '' ?>><?= $y ?> साल</option><?php endfor; ?>
    </select>
  </form>
  <form method="POST"><input type="hidden" name="action" value="bulk_allocate"><input type="hidden" name="year" value="<?= $year ?>">
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:9px 18px;font-weight:600;">📦 सगळ्यांना Default Quota Allocate करा</button>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead>
      <tr>
        <th>नाव</th>
        <?php foreach ($leaveTypes as $lt): ?><th><?= h($lt['name_local']) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($staffList as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?></td>
          <?php foreach ($leaveTypes as $lt):
            $bal = $balByUserType[$s['id']][$lt['id']] ?? null;
            $allocated = $bal['allocated_days'] ?? 0;
            $used = $bal['used_days'] ?? 0;
            $remaining = $allocated - $used;
          ?>
            <td>
              <button type="button" class="btn btn-sm" style="border:none;background:none;font-size:12px;padding:2px;" data-bs-toggle="modal" data-bs-target="#balModal<?= $s['id'] ?>_<?= $lt['id'] ?>">
                <span class="font-mono" style="color:<?= $remaining <= 0 ? 'var(--rose)' : 'var(--rail)' ?>;font-weight:600;"><?= $remaining ?></span>/<?= $allocated ?>
              </button>
              <div class="modal fade" id="balModal<?= $s['id'] ?>_<?= $lt['id'] ?>" tabindex="-1">
                <div class="modal-dialog modal-sm"><div class="modal-content" style="border-radius:14px;">
                  <form method="POST">
                    <input type="hidden" name="action" value="set_one"><input type="hidden" name="year" value="<?= $year ?>">
                    <input type="hidden" name="user_id" value="<?= $s['id'] ?>"><input type="hidden" name="leave_type_id" value="<?= $lt['id'] ?>">
                    <div class="modal-body" style="padding:18px;">
                      <div style="font-weight:600;font-size:12.5px;margin-bottom:8px;"><?= h($s['name']) ?> — <?= h($lt['name_local']) ?></div>
                      <label class="form-label-custom">एकूण दिवस (<?= $year ?>)</label>
                      <input type="number" step="0.5" name="allocated_days" class="form-control-custom" value="<?= $allocated ?>">
                      <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">आधीच <?= $used ?> दिवस वापरलेले आहेत</div>
                    </div>
                    <div class="modal-footer" style="border-top:1px solid var(--line);padding:10px;">
                      <button type="submit" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
                    </div>
                  </form>
                </div></div>
              </div>
            </td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
