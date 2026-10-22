<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
if (!hasPermission('manage_sop')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला SOP व्यवस्थापन करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$orgId = activeOrgId();
$sopId = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_step') {
        $stepDesc = trim($_POST['step_description'] ?? '');
        $frequency = $_POST['frequency'] ?? 'daily';
        $detectionType = $_POST['detection_type'] ?? 'manual';
        $autoSource = $detectionType === 'auto' ? ($_POST['auto_source_module'] ?? null) : null;
        if ($stepDesc) {
            $orderStmt = $pdo->prepare("SELECT COALESCE(MAX(step_order),0)+1 n FROM sop_steps WHERE sop_id = ?");
            $orderStmt->execute([$sopId]);
            $nextOrder = $orderStmt->fetch()['n'];
            $pdo->prepare("INSERT INTO sop_steps (sop_id, step_order, step_description, frequency, detection_type, auto_source_module) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$sopId, $nextOrder, $stepDesc, $frequency, $detectionType, $autoSource]);
            $_SESSION['flash'] = 'पायरी जोडली गेली.';
        }
    } elseif ($action === 'delete_step') {
        $pdo->prepare("DELETE FROM sop_steps WHERE id = ? AND sop_id = ?")->execute([(int)$_POST['step_id'], $sopId]);
        $_SESSION['flash'] = 'पायरी काढली.';
    } elseif ($action === 'assign_dept') {
        $deptId = (int)($_POST['department_id'] ?? 0);
        if ($deptId) {
            try {
                $pdo->prepare("INSERT INTO sop_department_assignments (sop_id, department_id, assigned_by) VALUES (?, ?, ?)")
                    ->execute([$sopId, $deptId, $user['id']]);
                $_SESSION['flash'] = 'विभाग जोडला गेला.';
            } catch (PDOException $e) {
                $_SESSION['form_error'] = 'हा विभाग आधीच जोडलेला आहे.';
            }
        }
    } elseif ($action === 'unassign_dept') {
        $pdo->prepare("DELETE FROM sop_department_assignments WHERE id = ? AND sop_id = ?")->execute([(int)$_POST['assignment_id'], $sopId]);
        $_SESSION['flash'] = 'विभाग काढला गेला.';
    }
    header('Location: edit.php?id=' . $sopId);
    exit;
}

$pageTitle = 'SOP संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sopStmt = $pdo->prepare("SELECT * FROM sops WHERE id = ? AND organization_id = ?");
$sopStmt->execute([$sopId, $orgId]);
$sop = $sopStmt->fetch();
if (!$sop) { die('SOP सापडलं नाही.'); }

$stepsStmt = $pdo->prepare("SELECT * FROM sop_steps WHERE sop_id = ? ORDER BY step_order");
$stepsStmt->execute([$sopId]);
$steps = $stepsStmt->fetchAll();

$assignedStmt = $pdo->prepare("
    SELECT sda.*, d.name_local AS dept_name FROM sop_department_assignments sda
    JOIN departments d ON d.id = sda.department_id WHERE sda.sop_id = ?
");
$assignedStmt->execute([$sopId]);
$assignedDepts = $assignedStmt->fetchAll();
$assignedDeptIds = array_column($assignedDepts, 'department_id');

$allDeptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$allDeptsStmt->execute([$orgId]);
$allDepts = $allDeptsStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();

$freqLabels = ['daily' => 'रोज', 'weekly' => 'आठवड्यातून', 'monthly' => 'महिन्यातून'];
$autoSourceOptions = [
    'daily_work' => 'दैनंदिन कामाचा अहवाल',
    'hostel_attendance' => 'वसतिगृह हजेरी (3 सत्रं)',
    'vehicle_trip' => 'वाहन Trip Log',
    'maintenance_log' => 'देखभाल नोंदवही',
];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-1">✅ <?= h($sop['title']) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व SOP</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📋 पायऱ्या (Steps)</div></div>
      <div style="padding:16px 20px;">
        <?php if (empty($steps)): ?><p style="font-size:12.5px;color:var(--text-dim);">अजून कोणतीही पायरी जोडलेली नाही.</p><?php endif; ?>
        <?php foreach ($steps as $st): ?>
          <div style="display:flex;justify-content:space-between;align-items:flex-start;padding:10px 0;border-bottom:1px solid var(--line);">
            <div>
              <div style="font-size:13px;font-weight:500;"><?= h($st['step_description']) ?></div>
              <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">
                <?= h($freqLabels[$st['frequency']] ?? $st['frequency']) ?>
                <?php if ($st['detection_type'] === 'auto'): ?>
                  · <span style="color:var(--rail);font-weight:600;">🤖 Auto — <?= h($autoSourceOptions[$st['auto_source_module']] ?? $st['auto_source_module']) ?></span>
                <?php else: ?>
                  · <span style="color:var(--amber);font-weight:600;">✍️ Manual (staff स्वतः टिक करेल)</span>
                <?php endif; ?>
              </div>
            </div>
            <form method="POST" onsubmit="return confirm('ही पायरी काढायची?');">
              <input type="hidden" name="action" value="delete_step">
              <input type="hidden" name="step_id" value="<?= $st['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;cursor:pointer;">काढा</button>
            </form>
          </div>
        <?php endforeach; ?>

        <form method="POST" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);">
          <input type="hidden" name="action" value="add_step">
          <label class="form-label-custom">नवीन पायरी</label>
          <input type="text" name="step_description" class="form-control-custom mb-2" placeholder="उदा. रोज संध्याकाळी हजेरी भरा" required>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label-custom">वारंवारता</label>
              <select name="frequency" class="form-select-custom">
                <option value="daily">रोज</option>
                <option value="weekly">आठवड्यातून एकदा</option>
                <option value="monthly">महिन्यातून एकदा</option>
              </select>
            </div>
            <div class="col-md-4">
              <label class="form-label-custom">कशी तपासायची</label>
              <select name="detection_type" id="detectionTypeSelect" class="form-select-custom" onchange="document.getElementById('autoSourceField').style.display=this.value==='auto'?'block':'none';">
                <option value="manual">✍️ Manual (staff टिक करेल)</option>
                <option value="auto">🤖 Auto (आधीच्या Module वरून)</option>
              </select>
            </div>
            <div class="col-md-4" id="autoSourceField" style="display:none;">
              <label class="form-label-custom">कोणत्या Module वरून</label>
              <select name="auto_source_module" class="form-select-custom">
                <?php foreach ($autoSourceOptions as $key => $label): ?>
                  <option value="<?= $key ?>"><?= h($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <button type="submit" class="btn mt-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">पायरी जोडा</button>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">🏢 कोणत्या विभागांना लागू</div></div>
      <div style="padding:16px 20px;">
        <?php foreach ($assignedDepts as $ad): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);">
            <span style="font-size:13px;"><?= h($ad['dept_name']) ?></span>
            <form method="POST">
              <input type="hidden" name="action" value="unassign_dept">
              <input type="hidden" name="assignment_id" value="<?= $ad['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;cursor:pointer;">काढा</button>
            </form>
          </div>
        <?php endforeach; ?>

        <form method="POST" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line);">
          <input type="hidden" name="action" value="assign_dept">
          <?php if (count($campusesForCascade) > 1): ?>
            <select id="campusSelect" class="form-select-custom mb-2" onchange="loadDeptsCascade_sopEdit(this.value)">
              <option value="">-- College/School --</option>
              <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          <?php endif; ?>
          <select name="department_id" id="departmentSelect" class="form-select-custom mb-2">
            <option value="">-- विभाग निवडा --</option>
            <?php foreach ($allDepts as $d): if (in_array($d['id'], $assignedDeptIds)) continue; ?>
              <option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (count($campusesForCascade) > 1): ?>
          <script>
          function loadDeptsCascade_sopEdit(campusId) {
            const sel = document.getElementById('departmentSelect');
            if (!campusId) return;
            sel.disabled = true;
            var assignedIds = <?= json_encode(array_map('intval', $assignedDeptIds)) ?>;
            fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
              .then(r => r.json()).then(data => {
                sel.innerHTML = '<option value="">-- विभाग निवडा --</option>';
                (data.departments || []).forEach(d => { if (assignedIds.indexOf(parseInt(d.id)) === -1) { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); } });
                sel.disabled = false;
              });
          }
          </script>
          <?php endif; ?>
          <button type="submit" class="btn w-100" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:12.5px;padding:8px;">+ विभाग जोडा</button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
