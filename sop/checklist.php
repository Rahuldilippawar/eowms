<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

require_once __DIR__ . '/../includes/sop-helpers.php';

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_sop');
$deptId = (int)($_GET['department_id'] ?? ($user['department_id'] ?? 0));

// स्वतःचा विभाग + Extra दिलेले विभाग (manage_sop असेल तर सगळे)
$sopVisibleDeptIds = $isMgmt ? null : getVisibleDepartmentIds();
if ($sopVisibleDeptIds !== null && !in_array($deptId, $sopVisibleDeptIds, true)) {
    http_response_code(403);
    die('तुम्हाला दुसऱ्या विभागाची Checklist पाहण्याची परवानगी नाही.');
}
if (!$deptId) {
    die('तुम्हाला कोणताही विभाग नेमलेला नाही.');
}
// विभाग सक्रिय संस्थेचाच असावा
$deptOrgCheck = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
$deptOrgCheck->execute([$deptId, $orgId]);
if (!$deptOrgCheck->fetch()) { die('विभाग सापडला नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_done') {
    $stepId = (int)($_POST['step_id'] ?? 0);
    // पायरी — याच संस्थेच्या, या विभागाला नेमलेल्या सक्रिय SOP ची व "Manual" प्रकारची असावी
    $stepCheck = $pdo->prepare("
        SELECT st.id FROM sop_steps st
        JOIN sops s ON s.id = st.sop_id
        JOIN sop_department_assignments sda ON sda.sop_id = s.id AND sda.department_id = ?
        WHERE st.id = ? AND s.organization_id = ? AND s.is_active = 1 AND st.detection_type = 'manual'
    ");
    $stepCheck->execute([$deptId, $stepId, $orgId]);
    if (!$stepCheck->fetch()) {
        $_SESSION['flash'] = '⚠️ ही पायरी या विभागाच्या Checklist मधली नाही (किंवा आपोआप तपासली जाते).';
        header('Location: checklist.php?department_id=' . $deptId);
        exit;
    }
    $pdo->prepare("
        INSERT INTO sop_compliance_logs (sop_step_id, department_id, log_date, status, completed_by)
        VALUES (?, ?, CURDATE(), 'done', ?)
        ON DUPLICATE KEY UPDATE status = 'done', completed_by = VALUES(completed_by)
    ")->execute([$stepId, $deptId, $user['id']]);
    header('Location: checklist.php?department_id=' . $deptId);
    exit;
}

$pageTitle = 'आजची SOP Checklist';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
$deptStmt->execute([$deptId, $orgId]);
$dept = $deptStmt->fetch();
if (!$dept) { die('विभाग सापडला नाही.'); }

$sopsStmt = $pdo->prepare("
    SELECT s.* FROM sops s
    JOIN sop_department_assignments sda ON sda.sop_id = s.id
    WHERE sda.department_id = ? AND s.is_active = 1
");
$sopsStmt->execute([$deptId]);
$sops = $sopsStmt->fetchAll();

$allDeptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$allDeptsStmt->execute([$orgId]);
$allDepts = $allDeptsStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();
?>

<div class="page-eyebrow"><?= h($dept['name_local'] ?: $dept['name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">✅ आजची SOP Checklist</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;"><?= date('d M Y') ?></div>
  </div>
  <?php if ($isMgmt): ?>
    <form method="GET" class="d-flex gap-2">
      <?php if (count($campusesForCascade) > 1): ?>
        <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_sopChk(this.value)">
          <option value="">-- College/School --</option>
          <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="department_id" id="departmentSelect" class="form-select-custom" onchange="this.form.submit()">
        <?php foreach ($allDepts as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_sopChk(campusId) {
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
    </form>
  <?php endif; ?>
</div>

<?php if (empty($sops)): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">या विभागाला अजून कोणतंही SOP जोडलेलं नाही.</div>
<?php endif; ?>

<?php foreach ($sops as $sop): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">📋 <?= h($sop['title']) ?></div><div class="sub"><?= h($sop['description']) ?></div></div>
    <div style="padding:16px 20px;">
      <?php
        $stepsStmt = $pdo->prepare("SELECT * FROM sop_steps WHERE sop_id = ? ORDER BY FIELD(frequency,'daily','weekly','monthly'), step_order");
        $stepsStmt->execute([$sop['id']]);
        $steps = $stepsStmt->fetchAll();
        $freqLabels = ['daily' => 'रोज', 'weekly' => 'या आठवड्यात (सोम-रवि) एकदा', 'monthly' => 'या महिन्यात एकदा'];
      ?>
      <?php foreach ($steps as $step):
        $status = sopGetStepStatus($pdo, $step, $deptId);
      ?>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);">
          <div style="display:flex;align-items:center;gap:10px;">
            <span style="font-size:16px;"><?= $status === 'done' ? '✅' : ($status === 'unknown' ? '⚪' : '⭕') ?></span>
            <div>
              <div style="font-size:13px;"><?= h($step['step_description']) ?></div>
              <div style="font-size:10.5px;color:var(--text-dim);">
                <?= h($freqLabels[$step['frequency']] ?? $step['frequency']) ?> ·
                <?= $step['detection_type'] === 'auto' ? '🤖 आपोआप तपासलं जातं' : '✍️ स्वतः टिक करा' ?>
              </div>
            </div>
          </div>
          <?php if ($step['detection_type'] === 'manual' && $status !== 'done'): ?>
            <form method="POST">
              <input type="hidden" name="action" value="mark_done">
              <input type="hidden" name="step_id" value="<?= $step['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11.5px;padding:6px 14px;">✓ पूर्ण झालं</button>
            </form>
          <?php elseif ($status === 'done'): ?>
            <span class="status-chip done">पूर्ण</span>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (empty($steps)): ?><p style="font-size:12px;color:var(--text-dim);">या SOP मध्ये अजून कोणतीही पायरी जोडलेली नाही.</p><?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
