<?php
$pageTitle = 'दैनंदिन कामाचा अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// स्वतःचा विभाग + Super Admin ने दिलेले Extra विभाग (null = सगळे)
$entryVisibleDeptIds = getVisibleDepartmentIds();
$entryDeptFilter = '';
$entryDeptParams = [];
if ($entryVisibleDeptIds !== null) {
    $entryDeptIdsSafe = $entryVisibleDeptIds ?: [0];
    $entryDeptFilter = ' AND %s IN (' . implode(',', array_fill(0, count($entryDeptIdsSafe), '?')) . ')';
    $entryDeptParams = $entryDeptIdsSafe;
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1" . sprintf($entryDeptFilter, 'id') . " ORDER BY name_local");
$stmt->execute(array_merge([activeOrgId()], $entryDeptParams));
$departments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, department_id, designation FROM users WHERE organization_id = ? AND is_active = 1" . sprintf($entryDeptFilter, 'department_id') . " ORDER BY name");
$stmt->execute(array_merge([activeOrgId()], $entryDeptParams));
$allStaff = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
$stmt->execute([activeOrgId()]);
$campusesForEntry = $stmt->fetchAll();
$showCampusPickerForRequester = count($campusesForEntry) > 1;

// Dynamic organization-specific fields — added by Super Admin per organization's own rules
$customFields = [];
try {
    $cfStmt = $pdo->prepare("SELECT * FROM custom_fields WHERE organization_id = ? AND is_active = 1 ORDER BY sort_order, id");
    $cfStmt->execute([activeOrgId()]);
    $customFields = $cfStmt->fetchAll();
} catch (PDOException $e) {
    // custom_fields table not migrated yet — form simply shows no extra fields
}

// Store Item यादी — कामासाठी वापरलेलं साहित्य नोंदवण्यासाठी (ऐच्छिक)
$storeItems = [];
try {
    $itemStmt = $pdo->prepare("SELECT id, item_name, unit FROM store_items WHERE organization_id = ? AND is_active = 1 ORDER BY item_name");
    $itemStmt->execute([activeOrgId()]);
    $storeItems = $itemStmt->fetchAll();
} catch (PDOException $e) { /* store_items not available */ }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start">
  <div class="page-title mb-0"><?= h(t('s66')) ?></div>
  <?php if (hasRole('super_admin')): ?>
    <a href="import.php" style="font-size:11.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-file-import me-1"></i>जुन्या नोंदी Import करा</a>
  <?php endif; ?>
</div>
<div style="font-size:11.5px;color:var(--text-dim);margin-top:2px;margin-bottom:14px;">📴 Internet नसतानाही ही नोंद भरता येते — फोनवर सुरक्षित राहील, Internet आल्यावर आपोआप पाठवली जाईल.</div>

<div class="mode-tabs mb-4" style="display:flex;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:5px;width:fit-content;">
  <a href="entry.php" class="mode-tab active" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;background:var(--ink);color:#fff;text-decoration:none;"><?= h(t('s89')) ?></a>
  <a href="report.php" class="mode-tab" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--text-dim);text-decoration:none;"><?= h(t('s90')) ?></a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <div class="title"><?= h(t('s216')) ?></div>
    <div class="sub"><?= h(t('s217')) ?></div>
  </div>
  <form method="POST" action="save.php" enctype="multipart/form-data" id="workEntryForm" style="padding:22px 24px;" onsubmit="return validateTeamSelection()"
    data-offline-queue="daily-work-entry" data-offline-label="कामाची नोंद" data-offline-redirect="../index.php">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s13')) ?></label>
        <input type="date" name="work_date" class="form-control-custom" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" class="form-select-custom" required>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s218')) ?></label>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer;background:var(--canvas);padding:10px 12px;border-radius:9px;margin-bottom:8px;">
          <input type="checkbox" id="isDailyRoutineCheck" checked onclick="eowmsToggleRequesterPicker(!this.checked)">
          🔁 हे रोजचंच/नियमित काम आहे — कोणी विशिष्ट विनंती केलेली नाही
        </label>
        <div id="requesterPickerBox" style="display:none;">
          <?php if ($showCampusPickerForRequester): ?>
            <select id="reqCampusSelect" class="form-select-custom mb-2" onchange="eowmsOnReqCampusChange(this.value)">
              <option value="">-- College/School/Trust --</option>
              <?php foreach ($campusesForEntry as $c): ?>
                <option value="<?= $c['id'] ?>"><?= $c['college_type'] === 'Trust HQ' ? '🏛️ ' : '🏫 ' ?><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <select id="reqDeptSelect" class="form-select-custom mb-2" onchange="eowmsOnReqDeptChange(this.value)" disabled>
              <option value="">-- आधी College/School निवडा --</option>
            </select>
          <?php else: ?>
            <select id="reqDeptSelect" class="form-select-custom mb-2" onchange="eowmsOnReqDeptChange(this.value)">
              <option value="">-- विभाग निवडा --</option>
              <?php foreach ($departments as $dept): ?><option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option><?php endforeach; ?>
            </select>
          <?php endif; ?>
          <select id="reqEmpSelect" class="form-select-custom mb-2" onchange="eowmsOnReqEmpChange(this)" disabled>
            <option value="">-- आधी विभाग निवडा --</option>
          </select>
          <input type="text" id="reqOtherText" class="form-control-custom" placeholder="किंवा — यादीत नसेल तर, नाव/पद टाईप करा (उदा. प्राचार्य कार्यालय)" oninput="document.getElementById('requestedByHidden').value = this.value;">
        </div>
        <input type="hidden" name="requested_by" id="requestedByHidden" value="">
      </div>
      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s219')) ?> <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(एकापेक्षा जास्त निवडता येतील — टीमने/एकत्र केलेलं काम असल्यास प्रत्येकाला टिक करा)</span></label>
        <div style="border:1px solid var(--line);border-radius:10px;max-height:220px;overflow-y:auto;padding:6px;">
          <?php foreach ($allStaff as $staff): ?>
            <label style="display:flex;align-items:center;gap:10px;padding:10px 12px;border-radius:8px;cursor:pointer;font-size:13.5px;" onmouseover="this.style.background='var(--canvas)'" onmouseout="this.style.background='transparent'">
              <input type="checkbox" name="employee_ids[]" value="<?= $staff['id'] ?>" <?= $staff['id'] === $user['id'] ? 'checked' : '' ?> style="width:18px;height:18px;flex-shrink:0;">
              <span><?= h($staff['name']) ?><?= !empty($staff['designation']) ? ' — ' . h($staff['designation']) : '' ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s20')) ?></label>
        <select name="priority" class="form-select-custom" required>
          <option value="urgent"><?= h(t('s84')) ?></option>
          <option value="high"><?= h(t('s85')) ?></option>
          <option value="normal" selected><?= h(t('s86')) ?></option>
          <option value="low"><?= h(t('s87')) ?></option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s220')) ?></label>
        <select name="status" class="form-select-custom" required>
          <option value="pending"><?= h(t('s7')) ?></option>
          <option value="in_progress" selected><?= h(t('s9')) ?></option>
          <option value="completed"><?= h(t('s10')) ?></option>
        </select>
      </div>

      <div class="col-md-9">
        <label class="form-label-custom"><?= h(t('s221')) ?></label>
        <input type="text" name="title" class="form-control-custom" placeholder="उदा. जनरेटर एएमसी तिमाही सर्व्हिसिंग" required>
      </div>

      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s222')) ?></label>
        <textarea name="description" class="form-control-custom" rows="3" placeholder="केलेल्या कामाचा तपशील लिहा…"></textarea>
      </div>

      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s223')) ?></label>
        <input type="date" name="start_date" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s224')) ?></label>
        <input type="date" name="due_date" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s225')) ?></label>
        <input type="number" step="0.01" name="estimated_cost" class="form-control-custom" placeholder="0">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s226')) ?></label>
        <input type="number" step="0.01" name="actual_cost" class="form-control-custom" placeholder="0">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s227')) ?></label>
        <input type="file" name="before_photo" accept="image/*" class="form-control-custom">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s228')) ?></label>
        <input type="file" name="after_photo" accept="image/*" class="form-control-custom">
      </div>

      <div class="col-12">
        <hr style="border-color:var(--line);">
        <div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--rail);font-weight:600;margin-bottom:6px;">📦 या कामासाठी वापरलेलं साहित्य (Store मधून) — ऐच्छिक</div>
        <?php if (empty($storeItems)): ?>
          <div style="font-size:12px;color:var(--text-dim);background:var(--canvas);border-radius:8px;padding:10px 14px;">
            अजून Store Module मध्ये एकही वस्तू (Item) जोडलेली नाही — त्यामुळे इथे निवडण्यासाठी काहीच नाही.
            <?php if (hasPermission('manage_store') || hasRole('super_admin')): ?>
              आधी <a href="../store/items.php" target="_blank" style="color:var(--rail);font-weight:600;">Store → वस्तू व्यवस्थापन</a> मधून वस्तू जोडा.
            <?php endif; ?>
          </div>
        <?php else: ?>
        <div id="materialRows"></div>
        <button type="button" id="addMaterialRow" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">+ साहित्य जोडा</button>
        <template id="materialRowTemplate">
          <div class="d-flex gap-2 mb-2 material-row">
            <select name="material_item_id[]" class="form-select-custom" style="flex:2;">
              <option value="">-- वस्तू निवडा --</option>
              <?php foreach ($storeItems as $si): ?><option value="<?= $si['id'] ?>"><?= h($si['item_name']) ?> (<?= h($si['unit']) ?>)</option><?php endforeach; ?>
            </select>
            <input type="number" step="0.01" name="material_quantity[]" class="form-control-custom" placeholder="प्रमाण" style="flex:1;">
            <button type="button" class="btn btn-sm remove-material-row" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;padding:6px 12px;">×</button>
          </div>
        </template>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;">💡 उदा. स्वच्छता विभागाने Bathroom Clean करताना Phenyl/Soap वापरलं असेल, तर इथे नोंदवा — याचा वापर-अहवाल आपोआप तयार होईल.</div>
        <script>
        (function () {
          var container = document.getElementById('materialRows');
          var template = document.getElementById('materialRowTemplate');
          document.getElementById('addMaterialRow').addEventListener('click', function () {
            var clone = template.content.cloneNode(true);
            container.appendChild(clone);
          });
          container.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-material-row')) {
              e.target.closest('.material-row').remove();
            }
          });
        })();
        </script>
        <?php endif; ?>
      </div>

      <?php if (!empty($customFields)): ?>
        <div class="col-12"><hr style="border-color:var(--line);"><div style="font-size:11px;letter-spacing:.06em;text-transform:uppercase;color:var(--rail);font-weight:600;margin-bottom:6px;"><?= h(t('s229')) ?></div></div>
        <?php foreach ($customFields as $cf):
          $fieldName = 'custom_' . h($cf['field_key']);
          $req = $cf['is_required'] ? 'required' : '';
        ?>
          <div class="col-md-6">
            <label class="form-label-custom"><?= h($cf['field_label']) ?><?= $cf['is_required'] ? ' *' : '' ?></label>
            <?php if ($cf['field_type'] === 'textarea'): ?>
              <textarea name="<?= $fieldName ?>" class="form-control-custom" rows="2" <?= $req ?>></textarea>
            <?php elseif ($cf['field_type'] === 'select'): ?>
              <select name="<?= $fieldName ?>" class="form-select-custom" <?= $req ?>>
                <option value=""><?= h(t('s36')) ?></option>
                <?php foreach (explode(',', $cf['field_options'] ?? '') as $opt): $opt = trim($opt); if ($opt === '') continue; ?>
                  <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($cf['field_type'] === 'checkbox'): ?>
              <select name="<?= $fieldName ?>" class="form-select-custom">
                <option value="होय"><?= h(t('s40')) ?></option>
                <option value="नाही"><?= h(t('s41')) ?></option>
              </select>
            <?php elseif ($cf['field_type'] === 'date'): ?>
              <input type="date" name="<?= $fieldName ?>" class="form-control-custom" <?= $req ?>>
            <?php elseif ($cf['field_type'] === 'number'): ?>
              <input type="number" name="<?= $fieldName ?>" class="form-control-custom" <?= $req ?>>
            <?php else: ?>
              <input type="text" name="<?= $fieldName ?>" class="form-control-custom" <?= $req ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="../index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s230')) ?></button>
      </div>
    </div>
  </form>
</div>

<script>
function validateTeamSelection() {
  var checked = document.querySelectorAll('input[name="employee_ids[]"]:checked');
  if (checked.length === 0) {
    alert('कृपया किमान एक कर्मचारी निवडा — काम कोणी केलं ते.');
    return false;
  }
  return true;
}

function eowmsToggleRequesterPicker(show) {
  document.getElementById('requesterPickerBox').style.display = show ? 'block' : 'none';
  if (!show) { document.getElementById('requestedByHidden').value = ''; }
}

function eowmsOnReqCampusChange(campusId) {
  var deptSelect = document.getElementById('reqDeptSelect');
  var empSelect = document.getElementById('reqEmpSelect');
  empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
  empSelect.disabled = true;
  if (!campusId) {
    deptSelect.innerHTML = '<option value="">-- आधी College/School निवडा --</option>';
    deptSelect.disabled = true;
    return;
  }
  deptSelect.disabled = true;
  deptSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var depts = data.departments || [];
      if (depts.length === 0) { deptSelect.innerHTML = '<option value="">-- विभाग नाही --</option>'; return; }
      deptSelect.disabled = false;
      deptSelect.innerHTML = '<option value="">-- विभाग निवडा --</option>' + depts.map(function (d) {
        return '<option value="' + d.id + '">' + d.name + '</option>';
      }).join('');
    });
}

function eowmsOnReqDeptChange(deptId) {
  var empSelect = document.getElementById('reqEmpSelect');
  if (!deptId) {
    empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
    empSelect.disabled = true;
    return;
  }
  empSelect.disabled = true;
  empSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=staff_for_department&department_id=' + deptId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var staff = data.staff || [];
      empSelect.disabled = false;
      empSelect.innerHTML = '<option value="">-- व्यक्ती निवडा --</option>' + staff.map(function (s) {
        return '<option value="' + s.id + '" data-name="' + s.name.replace(/"/g,'&quot;') + '">' + s.name + (s.designation ? ' (' + s.designation + ')' : '') + '</option>';
      }).join('');
    });
}

function eowmsOnReqEmpChange(sel) {
  if (sel.value) {
    document.getElementById('requestedByHidden').value = sel.options[sel.selectedIndex].dataset.name || '';
    document.getElementById('reqOtherText').value = '';
  } else {
    document.getElementById('requestedByHidden').value = '';
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
