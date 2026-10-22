<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("SELECT * FROM workflow_templates WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$template = $stmt->fetch();
if (!$template) { die('टेम्प्लेट सापडला नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_step') {
        $maxOrder = $pdo->prepare("SELECT COALESCE(MAX(step_order),0) m FROM workflow_steps WHERE workflow_template_id = ?");
        $maxOrder->execute([$id]);
        $nextOrder = $maxOrder->fetch()['m'] + 1;

        $type = in_array($_POST['approver_type'] ?? '', ['department_hod', 'specific_user', 'letter_dept_hod'], true) ? $_POST['approver_type'] : 'department_hod';
        $deptId = ($_POST['step_department_id'] ?? '') !== '' ? (int)$_POST['step_department_id'] : null;
        $userId = ($_POST['step_user_id'] ?? '') !== '' ? (int)$_POST['step_user_id'] : null;
        $stepName = trim($_POST['step_name'] ?? '');

        if ($type === 'department_hod' && !$deptId) { $stepName = ''; $_SESSION['form_error'] = 'या पायरीसाठी विभाग निवडा.'; }
        if ($type === 'specific_user' && !$userId) { $stepName = ''; $_SESSION['form_error'] = 'या पायरीसाठी व्यक्ती निवडा.'; }
        if ($stepName) {
            $pdo->prepare("INSERT INTO workflow_steps (workflow_template_id, step_order, step_name, approver_type, department_id, user_id) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$id, $nextOrder, $stepName, $type, $type === 'department_hod' ? $deptId : null, $type === 'specific_user' ? $userId : null]);
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_step') {
        $pdo->prepare("DELETE FROM workflow_steps WHERE id = ? AND workflow_template_id = ?")->execute([(int)$_POST['step_id'], $id]);
    } else {
        $name = trim($_POST['name'] ?? '');
        $isActive = (int)($_POST['is_active'] ?? 1);
        $pdo->prepare("UPDATE workflow_templates SET name=?, is_active=? WHERE id=? AND organization_id=?")->execute([$name, $isActive, $id, $orgId]);
        $_SESSION['flash'] = 'टेम्प्लेट अद्ययावत केला.';
        header('Location: workflows.php');
        exit;
    }
    header('Location: workflow-edit.php?id=' . $id);
    exit;
}


$stmt = $pdo->prepare("
    SELECT ws.*, d.name_local AS dept_name, u.name AS user_name
    FROM workflow_steps ws
    LEFT JOIN departments d ON d.id = ws.department_id
    LEFT JOIN users u ON u.id = ws.user_id
    WHERE ws.workflow_template_id = ? ORDER BY ws.step_order
");
$stmt->execute([$id]);
$steps = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

$approverTypeLabels = ['department_hod' => 'विभागाचा HOD', 'letter_dept_hod' => 'पत्राच्या विभागाचा HOD', 'specific_user' => 'विशिष्ट व्यक्ती'];

$pageTitle = 'टेम्प्लेट संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h($template['name']) ?> — संपादित करा</div>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <form method="POST" style="padding:20px;">
    <div class="row g-3">
      <div class="col-md-8"><label class="form-label-custom">नाव</label><input type="text" name="name" class="form-control-custom" value="<?= h($template['name']) ?>" required></div>
      <div class="col-md-4">
        <label class="form-label-custom">स्थिती</label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $template['is_active'] ? 'selected' : '' ?>>सक्रिय</option>
          <option value="0" <?= !$template['is_active'] ? 'selected' : '' ?>>निष्क्रिय</option>
        </select>
      </div>
      <div class="col-12 d-flex justify-content-end"><button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">जतन करा</button></div>
    </div>
  </form>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">पायऱ्या (क्रमाने)</div></div>
  <table class="rtable">
    <thead><tr><th>क्र.</th><th>नाव</th><th>कोणाकडे</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($steps)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:18px;">कोणतीही पायरी नाही</td></tr><?php endif; ?>
      <?php foreach ($steps as $s): ?>
        <tr>
          <td class="font-mono"><?= $s['step_order'] ?></td>
          <td style="font-weight:500;"><?= h($s['step_name']) ?></td>
          <td><?= $approverTypeLabels[$s['approver_type']] ?> <?= $s['dept_name'] ? '— ' . h($s['dept_name']) : '' ?> <?= $s['user_name'] ? '— ' . h($s['user_name']) : '' ?></td>
          <td>
            <form method="POST" style="display:inline;" onsubmit="return confirm('ही पायरी काढायची?');">
              <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="delete_step"><input type="hidden" name="step_id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11px;padding:4px 10px;"><i class="fa-solid fa-trash"></i></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <form method="POST" style="padding:16px 20px;border-top:1px solid var(--line);">
    <input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="add_step">
    <div class="row g-2 align-items-end">
      <div class="col-md-3"><label class="form-label-custom">पायरीचे नाव</label><input type="text" name="step_name" class="form-control-custom" required></div>
      <div class="col-md-3">
        <label class="form-label-custom">कोणाकडे</label>
        <select name="approver_type" class="form-select-custom" id="newStepType">
          <option value="department_hod">विशिष्ट विभागाचा HOD</option>
          <option value="letter_dept_hod">पत्राच्या विभागाचा HOD</option>
          <option value="specific_user">विशिष्ट व्यक्ती</option>
        </select>
      </div>
      <div class="col-md-3" id="newStepDeptWrap">
        <label class="form-label-custom">विभाग</label>
        <select name="step_department_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3" id="newStepUserWrap" style="display:none;">
        <label class="form-label-custom">व्यक्ती</label>
        <select name="step_user_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">पायरी जोडा</button></div>
    </div>
  </form>
</div>

<script>
document.getElementById('newStepType').addEventListener('change', function () {
  document.getElementById('newStepDeptWrap').style.display = this.value === 'department_hod' ? 'block' : 'none';
  document.getElementById('newStepUserWrap').style.display = this.value === 'specific_user' ? 'block' : 'none';
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
