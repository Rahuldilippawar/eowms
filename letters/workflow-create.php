<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
    $stepNames = $_POST['step_name'] ?? [];
    $approverTypes = $_POST['approver_type'] ?? [];
    $deptIds = $_POST['step_department_id'] ?? [];
    $userIds = $_POST['step_user_id'] ?? [];

    if (!$name || empty($stepNames)) {
        $error = 'कृपया नाव आणि किमान एक पायरी भरा.';
    } else {
        $insert = $pdo->prepare("INSERT INTO workflow_templates (organization_id, name, category_id) VALUES (?, ?, ?)");
        $insert->execute([$orgId, $name, $categoryId]);
        $templateId = $pdo->lastInsertId();

        $stepInsert = $pdo->prepare("INSERT INTO workflow_steps (workflow_template_id, step_order, step_name, approver_type, department_id, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $order = 1;
        foreach ($stepNames as $i => $stepName) {
            $stepName = trim($stepName);
            if (!$stepName) continue;
            $type = in_array($approverTypes[$i] ?? '', ['department_hod', 'specific_user', 'letter_dept_hod'], true) ? $approverTypes[$i] : 'department_hod';
            $deptId = ($deptIds[$i] ?? '') !== '' ? (int)$deptIds[$i] : null;
            $userId = ($userIds[$i] ?? '') !== '' ? (int)$userIds[$i] : null;
            if (($type === 'department_hod' && !$deptId) || ($type === 'specific_user' && !$userId)) continue; // अर्धवट पायरी — ज्याला कोणी अधिकारीच नाही
            $stepInsert->execute([$templateId, $order, $stepName, $type, $type === 'department_hod' ? $deptId : null, $type === 'specific_user' ? $userId : null]);
            $order++;
        }

        logActivity('कार्यप्रवाह टेम्प्लेट तयार केला', 'workflow_template', $templateId, $name);
        $_SESSION['flash'] = "'$name' टेम्प्लेट तयार झाला.";
        header('Location: workflows.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM letter_categories WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

$pageTitle = 'नवीन कार्यप्रवाह टेम्प्लेट';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">नवीन कार्यप्रवाह टेम्प्लेट</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title">पायऱ्या (Steps) — क्रमाने</div><div class="sub">पत्र या क्रमानेच पुढे जाईल — प्रत्येक पायरीवर कोण approve करेल ते ठरवा</div></div>
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3 mb-3">
      <div class="col-md-7">
        <label class="form-label-custom">टेम्प्लेटचे नाव</label>
        <input type="text" name="name" class="form-control-custom" placeholder="उदा. सर्वसाधारण मंजुरी प्रवाह" required>
      </div>
      <div class="col-md-5">
        <label class="form-label-custom">कोणत्या पत्र प्रकारासाठी (ऐच्छिक)</label>
        <select name="category_id" class="form-select-custom">
          <option value="">-- सर्व प्रकार --</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name_local'] ?: $c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div id="stepsWrap">
      <div class="step-row row g-2 align-items-end mb-2" style="background:var(--canvas);padding:12px;border-radius:10px;">
        <div class="col-md-3"><label class="form-label-custom">पायरीचे नाव</label><input type="text" name="step_name[]" class="form-control-custom" placeholder="उदा. HOD मंजुरी"></div>
        <div class="col-md-3">
          <label class="form-label-custom">कोणाकडे जाईल</label>
          <select name="approver_type[]" class="form-select-custom step-type-select">
            <option value="department_hod">विशिष्ट विभागाचा HOD</option>
            <option value="letter_dept_hod">पत्र ज्या विभागाचं आहे त्याचा HOD</option>
            <option value="specific_user">विशिष्ट व्यक्ती</option>
          </select>
        </div>
        <div class="col-md-3 step-dept-wrap">
          <label class="form-label-custom">विभाग</label>
          <select name="step_department_id[]" class="form-select-custom">
            <option value="">-- निवडा --</option>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3 step-user-wrap" style="display:none;">
          <label class="form-label-custom">व्यक्ती</label>
          <select name="step_user_id[]" class="form-select-custom">
            <option value="">-- निवडा --</option>
            <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
    <button type="button" id="addStepBtn" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12.5px;padding:7px 16px;margin-bottom:16px;"><i class="fa-solid fa-plus me-1"></i>आणखी पायरी जोडा</button>

    <div class="d-flex justify-content-end gap-2 mt-3">
      <a href="workflows.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">टेम्प्लेट तयार करा</button>
    </div>
  </form>
</div>

<script>
document.getElementById('addStepBtn').addEventListener('click', function () {
  var wrap = document.getElementById('stepsWrap');
  var newRow = wrap.children[0].cloneNode(true);
  newRow.querySelectorAll('input, select').forEach(function (el) { el.value = ''; });
  wrap.appendChild(newRow);
  bindStepTypeChange(newRow);
});

function bindStepTypeChange(row) {
  var select = row.querySelector('.step-type-select');
  var deptWrap = row.querySelector('.step-dept-wrap');
  var userWrap = row.querySelector('.step-user-wrap');
  select.addEventListener('change', function () {
    deptWrap.style.display = this.value === 'department_hod' ? 'block' : 'none';
    userWrap.style.display = this.value === 'specific_user' ? 'block' : 'none';
  });
}
document.querySelectorAll('.step-row').forEach(bindStepTypeChange);
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
