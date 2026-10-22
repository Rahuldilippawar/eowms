<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// IT विभागाला वेगळी, समर्पित Request System आहे (general-request.php) —
// त्यामुळे इथून (सर्वसाधारण आंतर-विभागीय विनंती) IT ला विनंती पाठवता येऊ
// नये — नाहीतर IT Staff ला दोन वेगळ्या ठिकाणी बघावं लागेल.
$itDeptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'IT' LIMIT 1");
$itDeptStmt->execute([$orgId]);
$itDeptIdForJs = $itDeptStmt->fetch()['id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deptId = (int)($_POST['department_id'] ?? 0);
    $assignedToUserId = ($_POST['assigned_to_user_id'] ?? '') !== '' ? (int)$_POST['assigned_to_user_id'] : null;
    $workDescription = trim($_POST['work_description'] ?? '');

    $deptCheckStmt = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ? AND is_active = 1");
    $deptCheckStmt->execute([$deptId, $orgId]);
    $deptIsValid = (bool)$deptCheckStmt->fetch();

    if ($deptId && $itDeptIdForJs && (int)$deptId === (int)$itDeptIdForJs) {
        $_SESSION['form_error'] = 'IT विभागाला विनंती/तक्रार पाठवण्यासाठी कृपया "IT तक्रार/विनंती नोंदवा" हे स्वतंत्र Page वापरा (Sidebar मध्ये उपलब्ध).';
    } elseif ($deptId && $deptIsValid && (int)$deptId === (int)($user['department_id'] ?? 0)) {
        $_SESSION['form_error'] = 'तुम्ही तुमच्याच विभागाला विनंती पाठवू शकत नाही.';
    } elseif ($deptId && $deptIsValid && $workDescription) {
        try {
            // जर विशिष्ट व्यक्ती निवडली असेल — ती खरंच याच विभागाची आहे ना ते पडताळतो
            if ($assignedToUserId) {
                $personCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND department_id = ? AND organization_id = ?");
                $personCheck->execute([$assignedToUserId, $deptId, $orgId]);
                if (!$personCheck->fetch()) { $assignedToUserId = null; }
            }

            $requestCode = 'REQ-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $pdo->prepare("
                INSERT INTO work_requests (request_code, organization_id, department_id, requester_name, requester_contact, requested_by_user_id, requesting_department_id, assigned_to_user_id, work_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$requestCode, $orgId, $deptId, $user['name'], $user['mobile'] ?? null, $user['id'], $user['department_id'] ?? null, $assignedToUserId, $workDescription]);
            $newRequestId = $pdo->lastInsertId();

            try {
                $pdo->prepare("INSERT INTO work_request_actions (request_id, action_type, new_status, changed_by) VALUES (?, 'created', 'pending', ?)")
                    ->execute([$newRequestId, $user['id']]);
            } catch (PDOException $e) { /* table not migrated yet */ }

            // विशिष्ट व्यक्ती निवडली असेल तर तिला थेट Notification, नाहीतर विभागाच्या HOD ला
            if ($assignedToUserId) {
                notifyUser($assignedToUserId, $orgId, 'तुम्हाला कामाची विनंती आली आहे', $user['name'] . ' यांनी विनंती केली — ' . mb_substr($workDescription, 0, 60), 'requests/index.php');
            } else {
                $hodStmt = $pdo->prepare("SELECT hod_id, name_local FROM departments WHERE id = ?");
                $hodStmt->execute([$deptId]);
                $deptRow = $hodStmt->fetch();
                if (!empty($deptRow['hod_id'])) {
                    notifyUser($deptRow['hod_id'], $orgId, 'नवीन कामाची विनंती', $user['name'] . ' यांनी विनंती केली — ' . mb_substr($workDescription, 0, 60), 'requests/index.php');
                }
            }

            logActivity('आंतर-विभागीय कामाची विनंती पाठवली', 'work_request', $newRequestId, $requestCode);
            $_SESSION['flash'] = "विनंती यशस्वीरित्या पाठवली गेली — Request ID: $requestCode";
            header('Location: my-requests.php');
            exit;
        } catch (PDOException $e) {
            // work_requests चे नवीन Columns अजून Database मध्ये तयार झालेले नाहीत —
            // म्हणजे database/migration-internal-work-requests.sql किंवा
            // migration-work-request-person.sql अजून चालवलेली नाही.
            $_SESSION['form_error'] = 'Database अजून पूर्णपणे तयार नाही — Super Admin ला संबंधित Migration चालवायला सांगा.';
        }
    } else {
        $_SESSION['form_error'] = 'कृपया विभाग निवडा आणि कामाचं वर्णन लिहा.';
    }
}

$pageTitle = 'नवीन कामाची विनंती';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$campusesStmt = $pdo->prepare("SELECT id, name, college_type FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📤 दुसऱ्या विभागाला कामाची विनंती पाठवा</div>
  <a href="my-requests.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list-check me-1"></i>माझ्या विनंत्या</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">तुमच्या स्वतःच्या विभागाव्यतिरिक्त, इतर कुठल्याही विभागाला काम-सहाय्यासाठी विनंती करता येईल.</p>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:640px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">1️⃣ कोणतं College/School (किंवा Trust-स्तरीय)</label>
    <select id="campusSelect" class="form-select-custom mb-3">
      <option value="">-- निवडा --</option>
      <option value="0">🏛️ Trust-स्तरीय विभाग</option>
      <?php foreach ($campuses as $c): if ($c['college_type'] === 'Trust HQ') continue; ?>
        <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>

    <label class="form-label-custom">2️⃣ कोणता विभाग</label>
    <select name="department_id" id="deptSelect" class="form-select-custom mb-2" required disabled>
      <option value="">-- आधी College/School निवडा --</option>
    </select>
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:14px;">💡 <strong>IT/Internet/Software संबंधित</strong> असल्यास — <a href="../it/general-request.php" style="color:var(--rail);font-weight:600;">इथे थेट IT ला कळवा</a> (जास्त जलद, Category/प्राधान्यसह).</p>

    <label class="form-label-custom">3️⃣ कोणाकडे पाठवायचं</label>
    <select name="assigned_to_user_id" id="personSelect" class="form-select-custom mb-3" disabled>
      <option value="">-- आधी विभाग निवडा --</option>
    </select>

    <label class="form-label-custom">काय काम हवं आहे — तपशीलवार लिहा</label>
    <textarea name="work_description" class="form-control-custom mb-3" rows="4" placeholder="उदा. आमच्या वर्गखोलीतला Fan बंद पडलाय, कृपया दुरुस्त करा" required></textarea>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">विनंती पाठवा</button>
  </form>
</div>

<script>
(function () {
  var rootPath = '../';
  var myDeptId = <?= json_encode((int)($user['department_id'] ?? 0)) ?>;
  var itDeptId = <?= json_encode((int)($itDeptIdForJs ?? 0)) ?>;
  var campusSelect = document.getElementById('campusSelect');
  var deptSelect = document.getElementById('deptSelect');
  var personSelect = document.getElementById('personSelect');

  campusSelect.addEventListener('change', function () {
    var campusId = campusSelect.value;
    deptSelect.disabled = true;
    personSelect.disabled = true;
    personSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
    if (campusId === '') { deptSelect.innerHTML = '<option value="">-- आधी College/School निवडा --</option>'; return; }
    deptSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
    fetch(rootPath + 'ajax-cascade.php?action=departments_for_campus&campus_id=' + (campusId === '0' ? '' : campusId))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var depts = (data.departments || []).filter(function (d) { return String(d.id) !== String(myDeptId) && String(d.id) !== String(itDeptId); });
        if (depts.length === 0) {
          deptSelect.innerHTML = '<option value="">-- या अंतर्गत कोणताही विभाग नाही --</option>';
          return;
        }
        deptSelect.innerHTML = '<option value="">-- निवडा --</option>' + depts.map(function (d) {
          return '<option value="' + d.id + '">' + d.name + '</option>';
        }).join('');
        deptSelect.disabled = false;
      });
  });

  deptSelect.addEventListener('change', function () {
    var deptId = deptSelect.value;
    personSelect.disabled = true;
    if (!deptId) {
      personSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
      return;
    }
    personSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
    fetch(rootPath + 'ajax-cascade.php?action=staff_for_department&department_id=' + deptId)
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var staff = data.staff || [];
        var hodId = data.hod_id;
        var opts = '';
        staff.forEach(function (s) {
          var isHod = hodId && String(s.id) === String(hodId);
          var label = (isHod ? '👔 ' : '') + s.name + (s.designation ? ' — ' + s.designation : '') + (isHod ? ' (विभाग-प्रमुख)' : '');
          opts += '<option value="' + s.id + '"' + (isHod ? ' selected' : '') + '>' + label + '</option>';
        });
        if (!hodId) { opts = '<option value="">-- व्यक्ती निवडा --</option>' + opts; }
        personSelect.innerHTML = opts;
        personSelect.disabled = false;
      });
  });
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
