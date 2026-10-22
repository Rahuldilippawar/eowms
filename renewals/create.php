<?php
$pageTitle = 'नवीन नूतनीकरण नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1" . (isDeptScoped() ? " AND id = ?" : "") . " ORDER BY name_local");
$stmt->execute(isDeptScoped() ? [activeOrgId(), $user['department_id']] : [activeOrgId()]);
$departments = $stmt->fetchAll();
$campusesForCascade = [];
if (!isDeptScoped()) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([activeOrgId()]);
    $campusesForCascade = $cStmt->fetchAll();
}

$staffStmt = $pdo->prepare("SELECT id, name, department_id FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([activeOrgId()]);
$staffPool = $staffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s169')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <div class="title"><?= h(t('s170')) ?></div>
    <div class="sub"><?= h(t('s171')) ?></div>
  </div>
  <form method="POST" action="save.php" style="padding:22px 24px;">
    <div class="row g-3">
      <?php if (!isDeptScoped() && count($campusesForCascade) > 1): ?>
        <div class="col-md-4">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_renCr(this.value)">
            <option value="">-- निवडा --</option>
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" id="departmentSelect" class="form-select-custom" required>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (!isDeptScoped() && count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_renCr(campusId) {
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
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s172')) ?></label>
        <input type="text" name="item_type" class="form-control-custom" placeholder="उदा. vehicle_insurance, fire_noc" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">Regulatory Body <span style="font-weight:400;color:var(--text-dim);">(शासकीय Compliance असल्यास)</span></label>
        <select name="regulatory_body" class="form-select-custom">
          <option value="">-- लागू नाही / निवडा --</option>
          <option value="CBSE">CBSE</option>
          <option value="State Board">State Board (राज्य मंडळ)</option>
          <option value="AICTE">AICTE</option>
          <option value="DTE Maharashtra">DTE Maharashtra</option>
          <option value="MSBTE">MSBTE</option>
          <option value="University">विद्यापीठ (University)</option>
          <option value="UGC">UGC</option>
          <option value="NAAC">NAAC</option>
          <option value="RTE">RTE</option>
          <option value="RTO/Transport">RTO/Transport (वाहन)</option>
          <option value="Other Government">इतर शासकीय</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s173')) ?></label>
        <input type="text" name="item_name" class="form-control-custom" placeholder="उदा. बस क्र. ०७ विमा" required>
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s174')) ?></label>
        <input type="text" name="reference_no" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s175')) ?></label>
        <input type="date" name="issued_date" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s30')) ?></label>
        <input type="date" name="expiry_date" class="form-control-custom" required>
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s70')) ?></label>
        <input type="number" step="0.01" name="renewal_cost" class="form-control-custom">
      </div>
      <div class="col-12">
        <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_recurring_monthly" id="renCrRecurring" value="1" style="width:16px;height:16px;" onchange="document.getElementById('renCrRecurringFields').style.display = this.checked ? 'flex' : 'none';"> 🔁 हे Monthly Bill आहे का? (उदा. Internet, Monthly Subscription — वार्षिक Contract, पण Payment दर महिन्याला)</label>
      </div>
      <div class="col-12 row g-3" id="renCrRecurringFields" style="display:none;margin-left:0;">
        <div class="col-md-4">
          <label class="form-label-custom">दर महिन्याची रक्कम (₹)</label>
          <input type="number" step="0.01" name="monthly_amount" class="form-control-custom">
        </div>
        <div class="col-md-4">
          <label class="form-label-custom">कोणत्या महिन्यापासून सुरू</label>
          <input type="month" name="recurring_start_month" class="form-control-custom">
        </div>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s176')) ?></label>
        <input type="text" name="vendor_name" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">Vendor संपर्क (फोन/ईमेल)</label>
        <input type="text" name="vendor_contact" class="form-control-custom" placeholder="9876543210">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom">जबाबदार व्यक्ती <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक — Escalating Reminder साठी)</span></label>
        <select name="responsible_user_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s177')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
