<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
requireLogin('../');
$user = currentUser();

$orgId = activeOrgId();
$error = null;

$deptCodeCheck = $pdo->prepare("SELECT code, has_issue_register FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptRow = $deptCodeCheck->fetch();
$userDeptCode = $userDeptRow['code'] ?? null;
$userDeptHasIssueRegister = !empty($userDeptRow['has_issue_register']);
$isMgmt = hasPermission('manage_maintenance_settings');

if (!hasRole('super_admin') && !$userDeptHasIssueRegister && !$isMgmt) {
    http_response_code(403);
    die('तुम्हाला देखभाल नोंद जोडण्याची परवानगी नाही.');
}

// The department this log is credited to — MTN staff/HOD always log under
// their own department; Super Admin/Management may pick any department.
$lockedDeptId = (!hasRole('super_admin') && !$isMgmt) ? (int)($user['department_id'] ?? 0) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $locationId = (int)($_POST['location_id'] ?? 0);
    $departmentId = $lockedDeptId ?: (int)($_POST['department_id'] ?? 0);
    $itemDescription = trim($_POST['item_description'] ?? '');
    $workType = $_POST['work_type'] ?? 'दुरुस्त केले';
    $workDate = ($_POST['work_date'] ?? '') ?: date('Y-m-d');
    $cost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null;
    $remarks = trim($_POST['remarks'] ?? '') ?: null;

    // जागा व विभाग — सक्रिय संस्थेचेच असावेत; प्रकार व तारीख वैध असावी
    $locOk = $deptOk = false;
    if ($locationId) {
        $locCheck = $pdo->prepare("SELECT id FROM maintenance_locations WHERE id = ? AND organization_id = ? AND is_active = 1");
        $locCheck->execute([$locationId, $orgId]);
        $locOk = (bool)$locCheck->fetch();
    }
    if ($departmentId) {
        $deptCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
        $deptCheck->execute([$departmentId, $orgId]);
        $deptOk = (bool)$deptCheck->fetch();
    }

    if (!$locationId || !$departmentId || !$itemDescription) {
        $error = 'कृपया जागा, विभाग व तपशील भरा.';
    } elseif (!$locOk || !$deptOk) {
        $error = 'निवडलेली जागा किंवा विभाग सापडला नाही.';
    } elseif (!in_array($workType, ['बसवले', 'दुरुस्त केले', 'बदलले', 'तपासणी'], true)) {
        $error = 'कामाचा प्रकार अवैध आहे.';
    } elseif (!DateTime::createFromFormat('!Y-m-d', $workDate) || $workDate > date('Y-m-d')) {
        $error = 'कामाची तारीख अवैध आहे.';
    } else {
        $insert = $pdo->prepare("
            INSERT INTO maintenance_logs (organization_id, location_id, department_id, item_description, work_type, work_date, cost, remarks, performed_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$orgId, $locationId, $departmentId, $itemDescription, $workType, $workDate, $cost, $remarks, $user['id']]);
        $newLogId = $pdo->lastInsertId();

        recordBudgetTransaction($pdo, $orgId, $departmentId, $cost, 'देखभाल', 'maintenance', $newLogId, $itemDescription, $user['id']);

        logActivity('देखभाल नोंद जोडली', 'maintenance_log', $newLogId, "$itemDescription — $workType");
        $_SESSION['flash'] = 'देखभाल नोंद जतन झाली.';
        header('Location: location-history.php?id=' . $locationId);
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM buildings WHERE organization_id = ? ORDER BY name");
$stmt->execute([$orgId]);
$buildings = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM maintenance_locations WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$locations = $stmt->fetchAll();

$departments = [];
$campusesForCascade = [];
if (!$lockedDeptId) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
}

$preselectedLocation = (int)($_GET['location_id'] ?? 0);

$pageTitle = 'नवीन देखभाल नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">नवीन देखभाल नोंद</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">इमारत (Building)</label>
        <select id="buildingSelect" class="form-select-custom">
          <option value="">-- सर्व --</option>
          <?php foreach ($buildings as $b): ?>
            <option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">खोली (Room)</label>
        <select name="location_id" id="locationSelect" class="form-select-custom" required>
          <option value="">-- आधी इमारत निवडा --</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= $loc['id'] ?>" data-building="<?= $loc['building_id'] ?? '' ?>" <?= $preselectedLocation === (int)$loc['id'] ? 'selected' : '' ?>><?= h($loc['name']) ?> (<?= h($loc['location_code']) ?>)</option>
          <?php endforeach; ?>
        </select>
        <?php if (empty($locations) && hasRole('super_admin')): ?>
          <a href="locations.php" style="font-size:11px;color:var(--rail);">+ नवीन खोली जोडा</a>
        <?php endif; ?>
      </div>
      <?php if (!$lockedDeptId): ?>
        <?php if (count($campusesForCascade) > 1): ?>
          <div class="col-md-6">
            <label class="form-label-custom">College/School</label>
            <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_mntCr(this.value)">
              <option value="">-- निवडा --</option>
              <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
        <div class="col-md-6">
          <label class="form-label-custom">विभाग</label>
          <select name="department_id" id="departmentSelect" class="form-select-custom" required <?= count($campusesForCascade) > 1 ? 'disabled' : '' ?>>
            <option value=""><?= count($campusesForCascade) > 1 ? 'आधी College/School निवडा' : '-- निवडा --' ?></option>
            <?php if (count($campusesForCascade) <= 1): foreach ($departments as $d): ?>
              <option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option>
            <?php endforeach; endif; ?>
          </select>
        </div>
        <?php if (count($campusesForCascade) > 1): ?>
        <script>
        function loadDeptsCascade_mntCr(campusId) {
          const sel = document.getElementById('departmentSelect');
          if (!campusId) { sel.innerHTML = '<option value="">आधी College/School निवडा</option>'; sel.disabled = true; return; }
          sel.disabled = true; sel.innerHTML = '<option value="">लोड होत आहे...</option>';
          fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
            .then(r => r.json()).then(data => {
              sel.innerHTML = '<option value="">-- निवडा --</option>';
              (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
              sel.disabled = false;
            });
        }
        </script>
        <?php endif; ?>
      <?php endif; ?>

      <div class="col-md-6">
        <label class="form-label-custom">काय काम केले (तपशील)</label>
        <input type="text" name="item_description" class="form-control-custom" placeholder="उदा. Light बसवला, Switch दुरुस्त केला" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">प्रकार</label>
        <select name="work_type" class="form-select-custom">
          <option value="बसवले">बसवले (नवीन)</option>
          <option value="दुरुस्त केले">दुरुस्त केले</option>
          <option value="बदलले">बदलले</option>
          <option value="तपासणी">फक्त तपासणी</option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="work_date" class="form-control-custom" value="<?= date('Y-m-d') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom">खर्च (₹, ऐच्छिक)</label>
        <input type="number" step="0.01" name="cost" class="form-control-custom">
      </div>
      <div class="col-12">
        <label class="form-label-custom">टिपा (ऐच्छिक)</label>
        <textarea name="remarks" class="form-control-custom" rows="2"></textarea>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">नोंद जतन करा</button>
      </div>
    </div>
  </form>
</div>

<script>
var buildingSelect = document.getElementById('buildingSelect');
var locationSelect = document.getElementById('locationSelect');
var allOptions = Array.from(locationSelect.options).slice(1); // skip the placeholder

function filterLocations() {
  var bId = buildingSelect.value;
  locationSelect.innerHTML = '';
  var placeholder = document.createElement('option');
  placeholder.value = '';
  placeholder.textContent = '-- निवडा --';
  locationSelect.appendChild(placeholder);
  allOptions.forEach(function (opt) {
    if (!bId || opt.dataset.building === bId) {
      locationSelect.appendChild(opt.cloneNode(true));
    }
  });
}
buildingSelect.addEventListener('change', filterLocations);
filterLocations();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
