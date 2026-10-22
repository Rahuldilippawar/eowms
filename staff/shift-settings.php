<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_one') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $shiftStart = $_POST['shift_start'] ?? '09:30';
        $shiftEnd = $_POST['shift_end'] ?? '17:30';
        $grace = (int)($_POST['grace_minutes'] ?? 15);
        $halfDayHours = (float)($_POST['half_day_hours'] ?? 4);
        $off1 = ($_POST['weekly_off_1'] ?? '') !== '' ? (int)$_POST['weekly_off_1'] : null;
        $off2 = ($_POST['weekly_off_2'] ?? '') !== '' ? (int)$_POST['weekly_off_2'] : null;

        $pdo->prepare("
            INSERT INTO staff_shift_settings (user_id, shift_start, shift_end, grace_minutes, half_day_hours, weekly_off_1, weekly_off_2)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE shift_start=VALUES(shift_start), shift_end=VALUES(shift_end), grace_minutes=VALUES(grace_minutes), half_day_hours=VALUES(half_day_hours), weekly_off_1=VALUES(weekly_off_1), weekly_off_2=VALUES(weekly_off_2)
        ")->execute([$userId, $shiftStart, $shiftEnd, $grace, $halfDayHours, $off1, $off2]);
        $_SESSION['flash'] = 'Shift-Setting जतन झाली.';
    } elseif ($action === 'bulk_apply') {
        $deptId = (int)($_POST['bulk_department_id'] ?? 0);
        $shiftStart = $_POST['bulk_shift_start'] ?? '09:30';
        $shiftEnd = $_POST['bulk_shift_end'] ?? '17:30';
        $grace = (int)($_POST['bulk_grace_minutes'] ?? 15);
        $halfDayHours = (float)($_POST['bulk_half_day_hours'] ?? 4);
        $off1 = ($_POST['bulk_weekly_off_1'] ?? '') !== '' ? (int)$_POST['bulk_weekly_off_1'] : null;
        $off2 = ($_POST['bulk_weekly_off_2'] ?? '') !== '' ? (int)$_POST['bulk_weekly_off_2'] : null;

        $staffStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND department_id = ? AND is_active = 1");
        $staffStmt->execute([$orgId, $deptId]);
        $count = 0;
        foreach ($staffStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
            $pdo->prepare("
                INSERT INTO staff_shift_settings (user_id, shift_start, shift_end, grace_minutes, half_day_hours, weekly_off_1, weekly_off_2)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE shift_start=VALUES(shift_start), shift_end=VALUES(shift_end), grace_minutes=VALUES(grace_minutes), half_day_hours=VALUES(half_day_hours), weekly_off_1=VALUES(weekly_off_1), weekly_off_2=VALUES(weekly_off_2)
            ")->execute([$uid, $shiftStart, $shiftEnd, $grace, $halfDayHours, $off1, $off2]);
            $count++;
        }
        $_SESSION['flash'] = "$count जणांना एकत्र Shift-Setting लागू केली.";
    }
    header('Location: shift-settings.php');
    exit;
}

$pageTitle = 'Shift Settings';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$deptsForShift = $deptsStmt->fetchAll();

$staffStmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation, d.name_local AS dept_name, s.*
    FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN staff_shift_settings s ON s.user_id = u.id
    WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.name
");
$staffStmt->execute([$orgId]);
$staffList = $staffStmt->fetchAll();

$dayNames = ['रविवार', 'सोमवार', 'मंगळवार', 'बुधवार', 'गुरुवार', 'शुक्रवार', 'शनिवार'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">⏰ Shift Settings — Staff Master</div>
  <a href="attendance.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Attendance</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">प्रत्येक Staff ची Shift-वेळ, आठवड्याची सुट्टी — यावरूनच Late/Half-Day/Absent आपोआप ठरतं.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📦 एकत्र (Bulk) — संपूर्ण विभागाला एकच Setting लागू करा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <input type="hidden" name="action" value="bulk_apply">
    <div class="row g-2">
      <div class="col-md-3">
        <select name="bulk_department_id" class="form-select-custom" required>
          <option value="">-- विभाग --</option>
          <?php foreach ($deptsForShift as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><input type="time" name="bulk_shift_start" class="form-control-custom" value="09:30" required></div>
      <div class="col-md-2"><input type="time" name="bulk_shift_end" class="form-control-custom" value="17:30" required></div>
      <div class="col-md-1"><input type="number" name="bulk_grace_minutes" class="form-control-custom" value="15" title="Grace (मिनिटं)"></div>
      <div class="col-md-1"><input type="number" step="0.5" name="bulk_half_day_hours" class="form-control-custom" value="4" title="Half-Day तास"></div>
      <div class="col-md-2">
        <select name="bulk_weekly_off_1" class="form-select-custom">
          <option value="">-- सुट्टी 1 --</option>
          <?php foreach ($dayNames as $i => $dn): ?><option value="<?= $i ?>" <?= $i == 0 ? 'selected' : '' ?>><?= $dn ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12px;">लागू करा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>विभाग</th><th>Shift वेळ</th><th>Grace</th><th>Half-Day तास</th><th>Weekly Off</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($staffList as $s): ?>
        <tr>
          <td style="font-weight:600;"><?= h($s['name']) ?></td>
          <td><?= h($s['dept_name'] ?: '—') ?></td>
          <td class="font-mono"><?= $s['shift_start'] ? date('h:i A', strtotime($s['shift_start'])) . ' - ' . date('h:i A', strtotime($s['shift_end'])) : '⚪ Default (09:30-17:30)' ?></td>
          <td><?= $s['grace_minutes'] ?? 15 ?> मि.</td>
          <td><?= $s['half_day_hours'] ?? 4 ?> तास</td>
          <td><?= $s['weekly_off_1'] !== null ? h($dayNames[$s['weekly_off_1']]) : 'रविवार' ?><?= $s['weekly_off_2'] !== null ? ', ' . h($dayNames[$s['weekly_off_2']]) : '' ?></td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#shiftModal<?= $s['id'] ?>">Edit</button>
          </td>
        </tr>
        <div class="modal fade" id="shiftModal<?= $s['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="action" value="save_one"><input type="hidden" name="user_id" value="<?= $s['id'] ?>">
              <div class="modal-body" style="padding:20px;">
                <div style="font-weight:600;margin-bottom:12px;"><?= h($s['name']) ?></div>
                <div class="row g-2 mb-2">
                  <div class="col-6"><label class="form-label-custom">Shift सुरू</label><input type="time" name="shift_start" class="form-control-custom" value="<?= h($s['shift_start'] ?: '09:30') ?>"></div>
                  <div class="col-6"><label class="form-label-custom">Shift संपली</label><input type="time" name="shift_end" class="form-control-custom" value="<?= h($s['shift_end'] ?: '17:30') ?>"></div>
                </div>
                <div class="row g-2 mb-2">
                  <div class="col-6"><label class="form-label-custom">Grace (मिनिटं)</label><input type="number" name="grace_minutes" class="form-control-custom" value="<?= h($s['grace_minutes'] ?? 15) ?>"></div>
                  <div class="col-6"><label class="form-label-custom">Half-Day तास</label><input type="number" step="0.5" name="half_day_hours" class="form-control-custom" value="<?= h($s['half_day_hours'] ?? 4) ?>"></div>
                </div>
                <div class="row g-2">
                  <div class="col-6">
                    <label class="form-label-custom">सुट्टी 1</label>
                    <select name="weekly_off_1" class="form-select-custom">
                      <option value="">-- नाही --</option>
                      <?php foreach ($dayNames as $i => $dn): ?><option value="<?= $i ?>" <?= (int)($s['weekly_off_1'] ?? 0) === $i ? 'selected' : '' ?>><?= $dn ?></option><?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-6">
                    <label class="form-label-custom">सुट्टी 2 (ऐच्छिक)</label>
                    <select name="weekly_off_2" class="form-select-custom">
                      <option value="">-- नाही --</option>
                      <?php foreach ($dayNames as $i => $dn): ?><option value="<?= $i ?>" <?= isset($s['weekly_off_2']) && (int)$s['weekly_off_2'] === $i ? 'selected' : '' ?>><?= $dn ?></option><?php endforeach; ?>
                    </select>
                  </div>
                </div>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
