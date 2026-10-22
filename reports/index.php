<?php
$pageTitle = 'अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$isManagement = hasPermission('view_org_wide_dashboards');
$canViewOrgReport = hasPermission('view_org_wide_reports');
$isHod = hasCategory('hod');

$departments = [];
$campusesForCascade = [];
if ($isManagement || $isHod) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1" . ($isHod ? " AND id = ?" : "") . " ORDER BY name_local");
    $stmt->execute($isHod ? [activeOrgId(), $user['department_id']] : [activeOrgId()]);
    $departments = $stmt->fetchAll();
    if (!$isHod) {
        $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
        $cStmt->execute([activeOrgId()]);
        $campusesForCascade = $cStmt->fetchAll();
    }
}
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title"><?= h(t('s383')) ?></div>

<div class="panel">
  <div class="panel-head">
    <div class="title"><?= h(t('s384')) ?></div>
    <div class="sub"><?= h(t('s385')) ?></div>
  </div>
  <form method="GET" action="view.php" target="_blank" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s386')) ?></label>
        <select name="scope" id="scope" class="form-select-custom" onchange="toggleDeptSelect()" required>
          <option value="personal"><?= h(t('s387')) ?></option>
          <?php if ($isHod || $isManagement): ?>
            <option value="department"><?= h(t('s388')) ?></option>
          <?php endif; ?>
          <?php if ($canViewOrgReport): ?>
            <option value="organization"><?= h(t('s389')) ?></option>
          <?php endif; ?>
        </select>
      </div>

      <div class="col-md-6" id="deptSelectWrap" style="display:none;">
        <?php if (count($campusesForCascade) > 1): ?>
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom mb-2" onchange="loadDeptsCascade_repIdx(this.value)">
            <option value="">-- निवडा --</option>
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" id="departmentSelect" class="form-select-custom">
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (count($campusesForCascade) > 1): ?>
        <script>
        function loadDeptsCascade_repIdx(campusId) {
          const sel = document.getElementById('departmentSelect');
          if (!campusId) return;
          sel.disabled = true;
          fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
            .then(r => r.json()).then(data => {
              sel.innerHTML = '';
              (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
              sel.disabled = false;
            });
        }
        </script>
        <?php endif; ?>
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s390')) ?></label>
        <select name="period" class="form-select-custom" required>
          <option value="daily"><?= h(t('s391')) ?></option>
          <option value="weekly" selected><?= h(t('s392')) ?></option>
          <option value="monthly"><?= h(t('s393')) ?></option>
          <option value="six_monthly"><?= h(t('s394')) ?></option>
          <option value="yearly"><?= h(t('s395')) ?></option>
          <option value="all"><?= h(t('s396')) ?></option>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label-custom">📋 Report मध्ये कोणते Columns हवेत — निवडा</label>
        <div style="background:var(--canvas);border-radius:10px;padding:14px 16px;">
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="heading" checked style="width:16px;height:16px;"> कामाचं शीर्षक</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="details" checked style="width:16px;height:16px;"> तपशील/वर्णन</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="requester" checked style="width:16px;height:16px;"> विनंती करणारा</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="priority" checked style="width:16px;height:16px;"> प्राधान्य</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="cost" checked style="width:16px;height:16px;"> खर्च (अंदाजित/प्रत्यक्ष)</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="materials" checked style="width:16px;height:16px;"> वापरलेलं साहित्य (Store)</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="dates" checked style="width:16px;height:16px;"> दिनांक</label>
          <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="status" checked style="width:16px;height:16px;"> स्थिती</label>
        </div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 24px;font-weight:600;">
          <i class="fa-solid fa-file-pdf me-1"></i>अहवाल तयार करा
        </button>
      </div>
    </div>
  </form>
</div>

<script>
function toggleDeptSelect() {
  const scope = document.getElementById('scope').value;
  document.getElementById('deptSelectWrap').style.display = (scope === 'department') ? 'block' : 'none';
}
toggleDeptSelect();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
