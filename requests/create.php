<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

// एकच विनंती-प्रणाली — ?category=it असेल तर IT चा Form (it/general-request.php इथेच येतो)
$isItForm = (($_GET['category'] ?? $_POST['category'] ?? '') === 'it');
$itDeptIdForJs = ticketItDepartmentId($pdo, $orgId) ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $priority = $_POST['priority'] ?? 'normal';
    if (!isset(TICKET_PRIORITIES[$priority])) { $priority = 'normal'; }
    $title = trim($_POST['title'] ?? '') ?: null;
    $location = trim($_POST['location'] ?? '') ?: null;
    $workDescription = trim($_POST['work_description'] ?? '');
    $assignedToUserId = null;
    $subCategory = null;

    if ($isItForm) {
        $category = 'it';
        $subCategory = $_POST['sub_category'] ?? 'other';
        if (!isset(TICKET_IT_SUB_CATEGORIES[$subCategory])) { $subCategory = 'other'; }
        $deptId = $itDeptIdForJs ?: null; // IT विभाग नसला तरी चालेल — manage_it असलेल्यांना दिसेल
        $deptIsValid = true;
    } else {
        $category = $_POST['ticket_category'] ?? 'general';
        if (!isset(TICKET_CATEGORIES[$category]) || $category === 'it') { $category = 'general'; }
        $deptId = (int)($_POST['department_id'] ?? 0);
        $assignedToUserId = ($_POST['assigned_to_user_id'] ?? '') !== '' ? (int)$_POST['assigned_to_user_id'] : null;
        $deptCheckStmt = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ? AND is_active = 1");
        $deptCheckStmt->execute([$deptId, $orgId]);
        $deptIsValid = (bool)$deptCheckStmt->fetch();
    }

    if (!$isItForm && $deptId && $itDeptIdForJs && (int)$deptId === (int)$itDeptIdForJs) {
        $_SESSION['form_error'] = 'IT विभागाला विनंती पाठवण्यासाठी कृपया "IT तक्रार/विनंती नोंदवा" हा Form वापरा.';
    } elseif (!$isItForm && $deptId && $deptIsValid && (int)$deptId === (int)($user['department_id'] ?? 0)) {
        $_SESSION['form_error'] = 'तुम्ही तुमच्याच विभागाला विनंती पाठवू शकत नाही.';
    } elseif (($isItForm || ($deptId && $deptIsValid)) && $workDescription && (!$isItForm || $title)) {
        try {
            // जर विशिष्ट व्यक्ती निवडली असेल — ती खरंच याच विभागाची आहे ना ते पडताळतो
            if ($assignedToUserId) {
                $personCheck = $pdo->prepare("SELECT id FROM users WHERE id = ? AND department_id = ? AND organization_id = ?");
                $personCheck->execute([$assignedToUserId, $deptId, $orgId]);
                if (!$personCheck->fetch()) { $assignedToUserId = null; }
            }

            $requestCode = ($isItForm ? 'IT-' : 'REQ-') . date('ymd') . '-' . strtoupper(substr(uniqid(), -5));
            $pdo->prepare("
                INSERT INTO work_requests (request_code, organization_id, department_id, category, sub_category, title, location, priority, due_at, requester_name, requester_contact, requested_by_user_id, requesting_department_id, assigned_to_user_id, work_description)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$requestCode, $orgId, $deptId ?: null, $category, $subCategory, $title, $location, $priority, ticketDueAt($priority),
                $user['name'], $user['mobile'] ?? null, $user['id'], ($user['department_id'] ?? null) ?: null, $assignedToUserId, $workDescription]);
            $newRequestId = (int)$pdo->lastInsertId();
            ticketLog($pdo, $newRequestId, 'created', null, 'pending', null, $user['id']);

            $priorityIcon = $priority === 'urgent' ? '🚨 ' : ($priority === 'high' ? '⚠️ ' : '');
            ticketNotifyReceivers($pdo, ['id' => $newRequestId, 'department_id' => $deptId ?: null, 'assigned_to_user_id' => $assignedToUserId], $orgId,
                $priorityIcon . ($isItForm ? "नवीन IT Request — $requestCode" : ($assignedToUserId ? 'तुम्हाला कामाची विनंती आली आहे' : 'नवीन कामाची विनंती')),
                $user['name'] . ' — ' . mb_substr($title ?: $workDescription, 0, 60));

            logActivity($isItForm ? 'IT Request तयार केली' : 'आंतर-विभागीय कामाची विनंती पाठवली', 'work_request', $newRequestId, $requestCode);
            $_SESSION['flash'] = "विनंती यशस्वीरित्या पाठवली गेली — Request ID: $requestCode";
            header('Location: view.php?id=' . $newRequestId);
            exit;
        } catch (PDOException $e) {
            // work_requests चे नवीन Columns अजून तयार नाहीत — migration-notifications-tickets-sos.sql चालवा
            $_SESSION['form_error'] = 'Database अजून पूर्णपणे तयार नाही — Super Admin ला Database Update चालवायला सांगा.';
        }
    } else {
        $_SESSION['form_error'] = $isItForm ? 'कृपया शीर्षक आणि तपशील भरा.' : 'कृपया विभाग निवडा आणि कामाचं वर्णन लिहा.';
    }
    header('Location: create.php' . ($isItForm ? '?category=it' : ''));
    exit;
}

$pageTitle = $isItForm ? 'IT/Internet तक्रार नोंदवा' : 'नवीन कामाची विनंती';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$campusesStmt = $pdo->prepare("SELECT id, name, college_type FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= $isItForm ? '🖥️ IT/Internet तक्रार किंवा विनंती नोंदवा' : '📤 दुसऱ्या विभागाला कामाची विनंती पाठवा' ?></div>
  <a href="my-requests.php<?= $isItForm ? '?category=it' : '' ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list-check me-1"></i>माझ्या विनंत्या</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= $isItForm
  ? 'Internet/Network समस्या, Software हवं आहे, नवीन Hardware, Account/Access — कुठलाही विशिष्ट Asset माहीत नसेल तरी इथे नोंदवा.'
  : 'तुमच्या स्वतःच्या विभागाव्यतिरिक्त, इतर कुठल्याही विभागाला काम-सहाय्यासाठी विनंती करता येईल.' ?></p>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if ($isItForm): ?>
<div class="panel" style="max-width:700px;">
  <form method="POST" action="create.php?category=it" style="padding:22px 24px;">
    <input type="hidden" name="category" value="it">
    <label class="form-label-custom">प्रकार</label>
    <select name="sub_category" class="form-select-custom mb-3" required>
      <?php foreach (TICKET_IT_SUB_CATEGORIES as $k => $lbl): ?><option value="<?= $k ?>"><?= h($lbl) ?></option><?php endforeach; ?>
    </select>

    <label class="form-label-custom">प्राधान्य</label>
    <select name="priority" class="form-select-custom mb-3">
      <?php foreach (TICKET_PRIORITIES as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= h($lbl) ?> (<?= ticketSlaHours($k) >= 24 ? (ticketSlaHours($k) / 24) . ' दिवस' : ticketSlaHours($k) . ' तास' ?>)</option><?php endforeach; ?>
    </select>

    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-3" placeholder="उदा. Lab 2 मध्ये Internet बंद आहे" required maxlength="255">

    <label class="form-label-custom">ठिकाण <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <input type="text" name="location" class="form-control-custom mb-3" placeholder="उदा. प्रयोगशाळा 2, दुसरा मजला" maxlength="255">

    <label class="form-label-custom">संपूर्ण तपशील</label>
    <textarea name="work_description" class="form-control-custom mb-3" rows="5" required></textarea>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">तक्रार/विनंती नोंदवा</button>
  </form>
</div>
<?php else: ?>
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
    <p style="font-size:11px;color:var(--text-dim);margin-bottom:14px;">💡 <strong>IT/Internet/Software संबंधित</strong> असल्यास — <a href="create.php?category=it" style="color:var(--rail);font-weight:600;">इथे थेट IT ला कळवा</a> (जास्त जलद, Category/प्राधान्यसह).</p>

    <label class="form-label-custom">3️⃣ कोणाकडे पाठवायचं</label>
    <select name="assigned_to_user_id" id="personSelect" class="form-select-custom mb-3" disabled>
      <option value="">-- आधी विभाग निवडा --</option>
    </select>

    <div class="row g-2">
      <div class="col-6">
        <label class="form-label-custom">प्रकार</label>
        <select name="ticket_category" class="form-select-custom mb-3">
          <?php foreach (TICKET_CATEGORIES as $k => $lbl): if ($k === 'it') continue; ?><option value="<?= $k ?>"><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-6">
        <label class="form-label-custom">प्राधान्य</label>
        <select name="priority" class="form-select-custom mb-3">
          <?php foreach (TICKET_PRIORITIES as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === 'normal' ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
      </div>
    </div>

    <label class="form-label-custom">शीर्षक <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <input type="text" name="title" class="form-control-custom mb-3" maxlength="255">

    <label class="form-label-custom">काय काम हवं आहे — तपशीलवार लिहा</label>
    <textarea name="work_description" class="form-control-custom mb-3" rows="4" placeholder="उदा. आमच्या वर्गखोलीतला Fan बंद पडलाय, कृपया दुरुस्त करा" required></textarea>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">विनंती पाठवा</button>
  </form>
</div>
<?php endif; ?>

<?php if (!$isItForm): ?>
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
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
