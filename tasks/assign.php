<?php
$pageTitle = 'टास्क असाइन करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('assign_tasks', '../');

// विभाग प्रमुख (HOD) — फक्त स्वतःच्याच विभागातल्या कर्मचाऱ्यांना Task देऊ
// शकतात. ज्यांच्याकडे 'assign_tasks_org_wide' Permission आहे (Management/
// Super Admin, किंवा Super Admin ने खास दिलेली कुठलीही Role) त्यांना
// सगळे विभाग दिसतात — हे आता "Roles व्यवस्थापन" मधून पूर्णपणे ठरवता येतं.
$isDeptScopedAssigner = !hasPermission('assign_tasks_org_wide');

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1" . ($isDeptScopedAssigner ? " AND id = ?" : "") . " ORDER BY name_local");
$deptParams = $isDeptScopedAssigner ? [activeOrgId(), $user['department_id'] ?? 0] : [activeOrgId()];
$stmt->execute($deptParams);
$departments = $stmt->fetchAll();
$campusesForCascade = [];
if (!$isDeptScopedAssigner) {
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([activeOrgId()]);
    $campusesForCascade = $cStmt->fetchAll();
}

$stmt = $pdo->prepare("SELECT id, name, department_id FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([activeOrgId()]);
$allStaff = $stmt->fetchAll();

$staffByDept = [];
foreach ($allStaff as $s) {
    $staffByDept[$s['department_id']][] = $s;
}

// 🧠 प्रत्येक कर्मचाऱ्याकडे सध्या किती उघडे / मुदत संपलेले Tasks — Assign करताना भार दिसावा
// आणि त्याच व्यक्तीकडे आधीच असाच उघडा Task आहे का ते तपासता यावं (दुहेरी काम टाळण्यासाठी)
$staffLoad = []; $openTitles = [];
try {
    $ldStmt = $pdo->prepare("SELECT assigned_to, title, target_date FROM tasks WHERE organization_id = ? AND status IN ('pending','in_progress')");
    $ldStmt->execute([activeOrgId()]);
    foreach ($ldStmt->fetchAll() as $ld) {
        $u = (int)$ld['assigned_to'];
        $staffLoad[$u]['open'] = ($staffLoad[$u]['open'] ?? 0) + 1;
        if ($ld['target_date'] < date('Y-m-d')) { $staffLoad[$u]['overdue'] = ($staffLoad[$u]['overdue'] ?? 0) + 1; }
        if (count($openTitles[$u] ?? []) < 40) { $openTitles[$u][] = $ld['title']; }
    }
} catch (PDOException $e) {}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s207')) ?></div>
  <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">
    <i class="fa-solid fa-list-check me-1"></i>सर्व टास्क पहा
  </a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger mt-3" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mt-3">
  <div class="panel-head">
    <div class="title"><?= h(t('s208')) ?></div>
    <div class="sub"><?= h(t('s209')) ?></div>
  </div>
  <form method="POST" action="save.php" style="padding:22px 24px;">
    <div class="row g-3">
      <?php if (!$isDeptScopedAssigner && count($campusesForCascade) > 1): ?>
        <div class="col-md-4">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_taskAsn(this.value)">
            <option value="">-- निवडा --</option>
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" id="task_department_id" class="form-select-custom" required <?= $isDeptScopedAssigner ? 'disabled' : '' ?>>
          <option value=""><?= h(t('s65')) ?></option>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= $isDeptScopedAssigner ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if ($isDeptScopedAssigner): ?>
          <input type="hidden" name="department_id" value="<?= $departments[0]['id'] ?? '' ?>">
          <div style="font-size:11px;color:var(--text-dim);margin-top:4px;">तुम्ही फक्त तुमच्याच विभागाला Task देऊ शकता</div>
        <?php endif; ?>
        <?php if (!$isDeptScopedAssigner && count($campusesForCascade) > 1): ?>
        <script>
        function loadDeptsCascade_taskAsn(campusId) {
          const sel = document.getElementById('task_department_id');
          if (!campusId) return;
          sel.disabled = true;
          fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
            .then(r => r.json()).then(data => {
              sel.innerHTML = '<option value=""><?= h(t('s65')) ?></option>';
              (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
              sel.disabled = false;
            });
        }
        </script>
        <?php endif; ?>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s6')) ?></label>
        <select name="assigned_to" id="task_assigned_to" class="form-select-custom" required>
          <option value=""><?= h(t('s210')) ?></option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s20')) ?></label>
        <select name="priority" class="form-select-custom" required>
          <option value="urgent"><?= h(t('s84')) ?></option>
          <option value="high"><?= h(t('s85')) ?></option>
          <option value="normal" selected><?= h(t('s86')) ?></option>
          <option value="low"><?= h(t('s87')) ?></option>
        </select>
      </div>

      <div class="col-md-8">
        <label class="form-label-custom"><?= h(t('s211')) ?></label>
        <input type="text" name="title" class="form-control-custom" placeholder="उदा. सर्व वर्गखोल्यांचे विद्युत सुरक्षा परीक्षण" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s212')) ?></label>
        <input type="date" name="target_date" class="form-control-custom" min="<?= date('Y-m-d') ?>" required>
      </div>

      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s88')) ?></label>
        <textarea name="description" class="form-control-custom" rows="3" placeholder="टास्कचे संपूर्ण वर्णन लिहा…"></textarea>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s213')) ?></button>
      </div>
    </div>
  </form>
</div>

<script>
const deptStaff = <?= json_encode(array_map(fn($rows) => array_map(fn($s) => ['id' => (int)$s['id'], 'name' => $s['name'], 'open' => (int)($staffLoad[(int)$s['id']]['open'] ?? 0), 'overdue' => (int)($staffLoad[(int)$s['id']]['overdue'] ?? 0)], $rows), $staffByDept), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const openTitles = <?= json_encode($openTitles, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;

const assigneeSel = document.getElementById('task_assigned_to');
const loadBox = document.createElement('div'); loadBox.className = 'as-load'; loadBox.hidden = true;
assigneeSel.parentNode.appendChild(loadBox);

// विभाग निवडल्यावर — कर्मचारी कमी भारानुसार क्रमाने, प्रत्येकाचा भार नावासोबत, सर्वात कमी भाराला "सुचवलेलं"
document.getElementById('task_department_id').addEventListener('change', function () {
  const staff = (deptStaff[this.value] || []).slice().sort((a, b) => (a.overdue - b.overdue) || (a.open - b.open) || a.name.localeCompare(b.name));
  assigneeSel.innerHTML = '';
  if (!staff.length) {
    const o = document.createElement('option'); o.value = ''; o.textContent = <?= json_encode(t('s214'), JSON_UNESCAPED_UNICODE) ?>; assigneeSel.appendChild(o);
  }
  staff.forEach((st, i) => {
    const o = document.createElement('option');
    o.value = st.id;
    o.textContent = (i === 0 && staff.length > 1 ? '⭐ ' : '') + st.name + ' — ' + (st.open ? st.open + ' उघडे' + (st.overdue ? ' · ' + st.overdue + ' मुदतबाह्य' : '') : 'मोकळे');
    assigneeSel.appendChild(o);
  });
  showLoad();
});

function showLoad() {
  const deptSel = document.getElementById('task_department_id');
  const st = (deptStaff[deptSel.value] || []).find(x => String(x.id) === assigneeSel.value);
  if (!st) { loadBox.hidden = true; return; }
  loadBox.hidden = false;
  loadBox.className = 'as-load' + (st.open >= 5 || st.overdue ? ' heavy' : '');
  loadBox.textContent = st.open
    ? '📋 ' + st.name + ' यांच्याकडे सध्या ' + st.open + ' उघडे Tasks' + (st.overdue ? ' (' + st.overdue + ' मुदत संपलेले)' : '') + (st.open >= 5 || st.overdue ? ' — भार जास्त आहे, दुसऱ्याचा विचार करा' : '')
    : '✅ ' + st.name + ' यांच्याकडे सध्या कोणताही उघडा Task नाही';
  checkDup();
}
assigneeSel.addEventListener('change', showLoad);

// दुहेरी Task इशारा — त्याच व्यक्तीकडे आधीच जवळजवळ असाच उघडा Task आहे का
const titleInput = document.querySelector('input[name="title"]');
const dupBox = document.createElement('div'); dupBox.className = 'as-dup'; dupBox.hidden = true;
titleInput.parentNode.appendChild(dupBox);
const tokens = t => (t || '').toLowerCase().replace(/[^\p{L}\p{N}\s]/gu, ' ').split(/\s+/).filter(Boolean);
const norm = t => tokens(t).filter(w => w.length > 2 && !/^\d+$/.test(w));
const nums = t => tokens(t).filter(w => /^\d+$/.test(w));
function checkDup() {
  const words = norm(titleInput.value), myNums = nums(titleInput.value);
  const list = openTitles[assigneeSel.value] || [];
  let best = null, bestScore = 0;
  if (words.length >= 2) {
    list.forEach(t => {
      const tw = norm(t); if (!tw.length) return;
      // दोन्हीमध्ये क्रमांक असतील आणि वेगळे असतील (उदा. खोली 101 / 102) — तर वेगळं काम
      const tn = nums(t);
      if (myNums.length && tn.length && !myNums.some(n => tn.includes(n))) return;
      const common = words.filter(w => tw.includes(w)).length;
      const score = common >= 2 ? common / Math.max(words.length, tw.length) : 0;
      if (score > bestScore) { bestScore = score; best = t; }
    });
  }
  dupBox.hidden = !(best && bestScore >= 0.6);
  if (!dupBox.hidden) dupBox.textContent = '⚠️ या व्यक्तीकडे आधीच असाच उघडा Task आहे: "' + best + '" — दुहेरी काम तर नाही ना?';
}
let dupT; titleInput.addEventListener('input', () => { clearTimeout(dupT); dupT = setTimeout(checkDup, 250); });

// प्राधान्यानुसार मुदत सुचवा + झटपट तारखा
const dateInput = document.querySelector('input[name="target_date"]');
const prioSel = document.querySelector('select[name="priority"]');
const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
const plus = n => { const d = new Date(); d.setDate(d.getDate() + n); return ymd(d); };
const prioDays = { urgent: 1, high: 3, normal: 7, low: 14 };
let dateTouched = false;
dateInput.addEventListener('change', () => { dateTouched = true; });
function suggestDate() { if (!dateTouched) dateInput.value = plus(prioDays[prioSel.value] ?? 7); }
prioSel.addEventListener('change', suggestDate);
suggestDate();
const chips = document.createElement('div'); chips.className = 'as-chips';
[['आज', 0], ['उद्या', 1], ['३ दिवस', 3], ['१ आठवडा', 7], ['१५ दिवस', 15], ['१ महिना', 30]].forEach(([l, n]) => {
  const b = document.createElement('button'); b.type = 'button'; b.textContent = l;
  b.addEventListener('click', () => { dateInput.value = plus(n); dateTouched = true; });
  chips.appendChild(b);
});
dateInput.parentNode.appendChild(chips);

<?php if ($isDeptScopedAssigner && !empty($departments)): ?>
  // विभाग प्रमुखांसाठी — पानच उघडताच त्यांच्या एकमेव विभागाचे कर्मचारी आपोआप दाखवा
  document.getElementById('task_department_id').dispatchEvent(new Event('change'));
<?php endif; ?>
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
