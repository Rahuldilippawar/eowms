<?php
$pageTitle = 'कर्मचारी संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$staffId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND organization_id = ?");
$stmt->execute([$staffId, activeOrgId()]);
$staffMember = $stmt->fetch();

if (!$staffMember) {
    die(t('s436'));
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([activeOrgId()]);
$departments = $stmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([activeOrgId()]);
$campusesForCascade = $campusesForCascade->fetchAll();


try {
    $roleDefs = $pdo->query("SELECT * FROM role_definitions ORDER BY FIELD(category,'super_admin','management','hod','staff'), label")->fetchAll();
    $roles = [];
    foreach ($roleDefs as $rd) {
        $roles[$rd['role_key']] = $rd['label_local'] ?: $rd['label'];
    }
} catch (PDOException $e) {
    $roles = ['super_admin' => 'Super Admin', 'president' => 'President', 'executive_president' => 'Executive President',
              'general_manager' => 'General Manager', 'hod' => 'HOD', 'staff' => 'Staff'];
}
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title"><?= h($staffMember['name']) ?> <?= h(t('s11')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title"><?= h(t('s281')) ?></div><div class="sub"><?= h($staffMember['email']) ?></div></div>
  <form method="POST" action="update.php" enctype="multipart/form-data" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $staffMember['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s15')) ?></label>
        <input type="text" name="name" class="form-control-custom" value="<?= h($staffMember['name']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">जन्मतारीख (वाढदिवस शुभेच्छांसाठी)</label>
        <input type="date" name="date_of_birth" class="form-control-custom" value="<?= h($staffMember['date_of_birth'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">फोटो</label>
        <?php if (!empty($staffMember['photo_path'])): ?>
          <div style="margin-bottom:6px;"><img src="../<?= h($staffMember['photo_path']) ?>" style="width:48px;height:48px;border-radius:50%;object-fit:cover;"></div>
        <?php endif; ?>
        <input type="file" name="photo" accept="image/*" class="form-control-custom">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s33')) ?></label>
        <input type="text" name="designation" list="designationList" class="form-control-custom" value="<?= h($staffMember['designation']) ?>">
        <datalist id="designationList">
          <option value="Peon">
          <option value="Watchman">
          <option value="Sweeper">
          <option value="Mali (Gardener)">
          <option value="Attendant">
          <option value="Cook">
          <option value="Cleaner">
          <option value="Driver">
          <option value="Technician">
          <option value="Electrician">
          <option value="Plumber">
          <option value="Mechanic">
          <option value="Operator">
          <option value="Clerk">
          <option value="Store Keeper">
          <option value="Receptionist">
          <option value="Data Entry Operator">
          <option value="Supervisor">
          <option value="Section In-charge">
          <option value="Senior Clerk">
          <option value="Foreman">
          <option value="HOD">
          <option value="Department Head">
          <option value="Warden">
          <option value="Registrar">
          <option value="Assistant General Manager">
          <option value="Deputy General Manager">
          <option value="General Manager">
          <option value="Administrative Officer">
          <option value="Executive President">
          <option value="Vice President">
          <option value="Secretary">
          <option value="Joint Secretary">
          <option value="Treasurer">
          <option value="President">
          <option value="Chairman">
          <option value="Founder President">
          <option value="Working President">
          <option value="Trustee">
          <option value="Super Admin">
          <option value="System Administrator">
        </datalist>
      </div>

      <div class="col-md-6">
        <label class="form-label-custom">Teaching / Non-Teaching</label>
        <select name="teaching_mode" class="form-select-custom">
          <?php foreach (['Teaching' => '👨‍🏫 Teaching', 'Non Teaching' => '🧰 Non-Teaching', 'Other' => '❔ इतर'] as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= ($staffMember['teaching_mode'] ?? 'Non Teaching') === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>


      <?php if (count($campusesForCascade) > 1): ?>
        <div class="col-md-6">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_stfEdit(this.value)">
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$c['id'] === (int)($staffMember['campus_id'] ?? 0) ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" id="departmentSelect" class="form-select-custom">
          <option value="">— विभाग नाही (फक्त Management पदांसाठी) —</option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= (int)$staffMember['department_id'] === (int)$dept['id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_stfEdit(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value="">— विभाग नाही (फक्त Management पदांसाठी) —</option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s32')) ?></label>
        <select name="role" class="form-select-custom" required>
          <?php foreach ($roles as $val => $label): ?>
            <option value="<?= $val ?>" <?= $staffMember['role'] === $val ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;">Peon/Technician/Clerk → Staff · HOD/Warden → HOD · GM/AGM → General Manager · Exec.President/Secretary → Executive President · Chairman/Trustee → President</div>
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $staffMember['is_active'] ? 'selected' : '' ?>><?= h(t('s282')) ?></option>
          <option value="0" <?= !$staffMember['is_active'] ? 'selected' : '' ?>><?= h(t('s283')) ?></option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s284')) ?></label>
        <input type="text" name="new_password" class="form-control-custom" placeholder="••••••">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">पगार (₹) — फक्त Management ला दिसेल</label>
        <input type="number" step="0.01" name="salary" class="form-control-custom" value="<?= h($staffMember['salary']) ?>">
      </div>
      <?php if (hasRole('super_admin')): ?>
      <div class="col-12">
        <label style="display:flex;align-items:center;gap:10px;background:var(--rail-soft);border-radius:10px;padding:12px 16px;cursor:pointer;">
          <input type="checkbox" name="can_edit_daily_work" value="1" style="width:18px;height:18px;" <?= !empty($staffMember['can_edit_daily_work']) ? 'checked' : '' ?>>
          <span style="font-size:13px;"><strong>✏️ दैनंदिन कामाची नोंद — स्वतः Edit करण्याचा Access</strong> — या व्यक्तीला, त्याने/तिने टाकलेली स्वतःची कामाची नोंद चुकून चुकीची भरली गेली असल्यास — स्वतःच "माझी कामे" मधून दुरुस्त करण्याची परवानगी द्या</span>
        </label>
      </div>
      <?php endif; ?>
      <div class="col-md-4">
        <label class="form-label-custom">रुजू दिनांक (Joining Date)</label>
        <input type="date" name="joining_date" class="form-control-custom" value="<?= h($staffMember['joining_date']) ?>">
      </div>
      <?php if ($staffMember['left_date']): ?>
        <div class="col-md-4">
          <label class="form-label-custom">सोडल्याचा दिनांक (Leave Date)</label>
          <input type="text" class="form-control-custom" value="<?= h(date('d M Y', strtotime($staffMember['left_date']))) ?>" disabled style="background:var(--canvas);">
        </div>
      <?php endif; ?>

      <?php if (!empty($staffMember['locked_until']) && strtotime($staffMember['locked_until']) > time()): ?>
        <div class="col-12">
          <div style="background:var(--rose-soft);color:var(--rose);border-radius:9px;padding:12px 16px;font-size:13px;display:flex;justify-content:space-between;align-items:center;">
            <span>🔒 हे खाते चुकीच्या पासवर्ड प्रयत्नांमुळे <?= date('d M Y, h:i A', strtotime($staffMember['locked_until'])) ?> पर्यंत तात्पुरते बंद आहे.</span>
            <label style="display:flex;align-items:center;gap:6px;white-space:nowrap;"><input type="checkbox" name="unlock_account" value="1"> आत्ताच Unlock करा</label>
          </div>
        </div>
      <?php endif; ?>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
