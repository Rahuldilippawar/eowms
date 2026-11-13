<?php
$pageTitle = 'कर्मचारी संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/staff-dept-picker.php';
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
// 👑 एकच Super Admin — जो आधीच Super Admin आहे त्याच्याशिवाय कोणालाही हे पद देता येत नाही
if ($staffMember['role'] !== 'super_admin' && superAdminExistsOtherThan($pdo, (int)$staffMember['id'])) { unset($roles['super_admin']); }
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
        <input type="text" name="name" class="form-control-custom" value="<?= h($staffMember['name']) ?>" data-capname autocapitalize="words" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">जन्मतारीख (वाढदिवस शुभेच्छांसाठी)</label>
        <input type="date" name="date_of_birth" class="form-control-custom" value="<?= h($staffMember['date_of_birth'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">Email (Login ID)</label>
        <input type="email" name="email" class="form-control-custom" value="<?= h($staffMember['email']) ?>" required autocomplete="off" data-orig="<?= h($staffMember['email']) ?>" id="staffEmailField">
        <div id="staffEmailNote" style="display:none;font-size:11.5px;color:var(--amber,#B45309);margin-top:4px;">⚠️ Email बदलल्यावर <?= h($staffMember['name']) ?> यांना नवीन Email ने Login करावं लागेल. जुन्या आणि नवीन दोन्ही पत्त्यांवर सूचना पाठवली जाईल.</div>
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


      <?= staffDeptPicker($pdo, activeOrgId(), $staffMember['campus_id'] !== null ? (int)$staffMember['campus_id'] : null, (int)$staffMember['department_id'], 'col-md-6') ?>
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
      <?php require_once __DIR__ . '/../includes/salary-helpers.php';
      if (salaryReady($pdo)):
        $salCur = salaryCurrent($pdo, activeOrgId(), [$staffId])[$staffId] ?? null;
        $sv = fn($k) => $salCur ? (float)$salCur[$k] : ($k === 'gross' ? (float)$staffMember['salary'] : 0);
        $sf = fn($k) => $salCur ? !empty($salCur[$k]) : !empty($staffMember[$k]); ?>
      <div class="col-12" id="salary">
        <div style="border:1px solid var(--line);border-radius:12px;padding:14px 16px;">
          <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:8px;">
            <b style="font-size:13.5px;">💰 पगार रचना <span style="font-weight:400;font-size:11.5px;color:var(--text-dim);">— 🔒 फक्त Management ला दिसेल</span></b>
            <span style="font-size:11.5px;color:var(--text-dim);"><?= $salCur ? 'लागू: ' . date('d/m/Y', strtotime($salCur['effective_from'])) . ($salCur['source'] === 'import' ? ' · Excel आयात' : '') : 'अजून रचना नाही' ?></span>
          </div>
          <input type="hidden" name="salary" id="salGross" value="<?= h($staffMember['salary']) ?>">
          <input type="hidden" name="sal_struct" value="1">
          <div class="row g-2">
            <?php foreach (['basic' => 'Basic', 'hra' => 'HRA', 'ta' => 'प्रवास भत्ता (TA)', 'medical' => 'वैद्यकीय भत्ता', 'other_allow' => 'इतर भत्ता'] as $k => $l): ?>
              <div class="col-6 col-md"><label class="form-label-custom" style="font-size:11.5px;"><?= h($l) ?></label><input type="number" step="0.01" min="0" name="sal_<?= $k ?>" class="form-control-custom sal-part" value="<?= $sv($k) ? h((string)round($sv($k), 2)) : '' ?>"></div>
            <?php endforeach; ?>
            <div class="col-6 col-md"><label class="form-label-custom" style="font-size:11.5px;">एकूण (Gross)</label><input type="number" step="0.01" min="0" id="salTotal" class="form-control-custom" style="font-weight:700;" value="<?= $sv('gross') ? h((string)round($sv('gross'), 2)) : '' ?>"></div>
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:6px 16px;align-items:center;margin-top:10px;font-size:12.5px;">
            <a href="#" id="salSplit" style="color:var(--rail);font-weight:600;text-decoration:none;">⚡ एकूण वरून आपोआप वाटणी (60/15/15/10)</a>
            <label><input type="checkbox" name="sal_pf" value="1" <?= $sf('pf_applicable') ? 'checked' : '' ?>> PF</label>
            <label><input type="checkbox" name="sal_esic" value="1" <?= $sf('esic_applicable') ? 'checked' : '' ?>> ESIC</label>
            <label><input type="checkbox" name="sal_pt" value="1" <?= !$salCur || !empty($salCur['pt_applicable']) ? 'checked' : '' ?>> व्यवसाय कर</label>
            <label><input type="checkbox" name="sal_hc" value="1" <?= $sf('is_handicap') ? 'checked' : '' ?>> दिव्यांग</label>
            <span class="ms-auto">कधीपासून <input type="date" name="sal_from" value="<?= date('Y-m-01') ?>" style="border:1px solid var(--line);border-radius:7px;padding:3px 6px;"></span>
          </div>
          <div id="salNet" style="font-size:12px;color:var(--text-dim);margin-top:6px;"></div>
        </div>
      </div>
      <script>
      (function () {
        var parts = document.querySelectorAll('.sal-part'), tot = document.getElementById('salTotal'), hid = document.getElementById('salGross'), net = document.getElementById('salNet');
        var g = function (n) { var e = document.querySelector('[name="' + n + '"]'); return e ? parseFloat(e.value) || 0 : 0; };
        function calc() {
          var sum = 0; parts.forEach(function (p) { sum += parseFloat(p.value) || 0; });
          if (sum > 0) tot.value = Math.round(sum * 100) / 100;
          var G = parseFloat(tot.value) || 0, B = g('sal_basic') || G; hid.value = G || '';
          var pf = document.querySelector('[name=sal_pf]').checked ? Math.round(Math.min(B, 15000) * .12) : 0;
          var es = document.querySelector('[name=sal_esic]').checked && G <= 21000 ? Math.ceil(G * .0075) : 0;
          net.textContent = G ? 'अंदाजे कपात: PF ₹' + pf + ' · ESIC ₹' + es + ' · (व्यवसाय कर वेगळा) — हातात ≈ ₹' + Math.round(G - pf - es).toLocaleString('en-IN') : '';
        }
        parts.forEach(function (p) { p.addEventListener('input', calc); });
        document.querySelectorAll('[name=sal_pf],[name=sal_esic]').forEach(function (c) { c.addEventListener('change', calc); });
        tot.addEventListener('input', function () { hid.value = tot.value; calc(); });
        document.getElementById('salSplit').addEventListener('click', function (e) {
          e.preventDefault(); var G = parseFloat(tot.value) || 0; if (!G) { tot.focus(); return; }
          var r = { basic: .6, hra: .15, ta: .15, medical: .1 }, s = 0;
          Object.keys(r).forEach(function (k) { var v = Math.round(G * r[k] * 100) / 100; document.querySelector('[name=sal_' + k + ']').value = v; s += v; });
          var b = document.querySelector('[name=sal_basic]'); b.value = Math.round((parseFloat(b.value) + G - s) * 100) / 100;
          document.querySelector('[name=sal_other_allow]').value = ''; calc();
        });
        calc();
      })();
      </script>
      <?php else: ?>
      <div class="col-md-4">
        <label class="form-label-custom">पगार (₹) — फक्त Management ला दिसेल</label>
        <input type="number" step="0.01" name="salary" class="form-control-custom" value="<?= h($staffMember['salary']) ?>">
      </div>
      <?php endif; ?>
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
      <div class="col-md-4">
        <label class="form-label-custom">🖐️ Biometric PIN <span style="font-weight:400;color:var(--text-dim);">(Machine वरचा नंबर)</span></label>
        <input type="text" name="biometric_pin" inputmode="numeric" pattern="[0-9]{1,9}" maxlength="9" class="form-control-custom" value="<?= h($staffMember['biometric_pin'] ?? '') ?>" placeholder="रिकामा = Biometric नाही">
        <label style="font-size:12px;margin-top:4px;display:flex;gap:6px;align-items:center;"><input type="checkbox" name="bio_resend" value="1"> Machine वर पुन्हा पाठवा</label>
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

<?php require_once __DIR__ . '/../includes/staff-extra-fields.php'; echo staffExtraFieldsHtml($staffMember, true); ?>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php if ($staffMember['role'] !== 'super_admin'): ?>
<!-- 🗑️ कर्मचारी पूर्णपणे हटवा (फक्त Super Admin — हे पानच Super Admin साठी आहे) -->
<div class="panel mt-3" style="border:1px solid var(--rose);padding:16px 20px;">
  <div style="font-weight:700;color:var(--rose);font-size:14.5px;"><i class="fa-solid fa-trash me-1"></i>कर्मचारी पूर्णपणे हटवा</div>
  <div style="font-size:12.5px;color:var(--text-dim);margin:4px 0 10px;">त्यांचा Login, हजेरी, रजा, कामाच्या नोंदी, Tasks, E-Campus कामं, Campus प्रमुख पद आणि फोटो — सगळं कायमचं हटेल. इतिहास ठेवायचा असेल तर वरती "निष्क्रिय" करा.</div>
  <form method="POST" action="delete.php" class="d-flex gap-2 flex-wrap align-items-center" onsubmit="return window.confirm('<?= h($staffMember['name']) ?> — कायमचे हटवायचे? हे परत आणता येणार नाही.');">
    <input type="hidden" name="id" value="<?= (int)$staffMember['id'] ?>">
    <input type="text" name="confirm_name" class="form-control-custom" style="max-width:280px;" autocomplete="off" required placeholder="खात्रीसाठी नाव किंवा हटवा टाइप करा">
    <button type="submit" class="btn" style="background:var(--rose);color:#fff;border-radius:9px;font-size:13px;padding:8px 18px;font-weight:600;"><i class="fa-solid fa-trash me-1"></i>कायमचे हटवा</button>
  </form>
</div>
<?php endif; ?>

<script>
  (function () {
    var f = document.getElementById('staffEmailField'), n = document.getElementById('staffEmailNote'); if (!f) return;
    f.addEventListener('input', function () { n.style.display = f.value.trim().toLowerCase() !== f.dataset.orig.toLowerCase() ? 'block' : 'none'; });
  })();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
