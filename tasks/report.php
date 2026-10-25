<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
requirePermission('view_task_tracker', '../'); // Tracker प्रमाणेच — Report सुद्धा

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    // ---------------- फॉर्म दाखवणे ----------------
    $pageTitle = 'Task — Advance Report';
    $rootPath = '../';
    require_once __DIR__ . '/../includes/header.php';

    $departments = [];
    $campusesForCascade = [];
    if (!isDeptScoped()) {
        $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
        $stmt->execute([$orgId]);
        $departments = $stmt->fetchAll();
        $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
        $cStmt->execute([$orgId]);
        $campusesForCascade = $cStmt->fetchAll();
    }
    ?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 Task — Advance Report</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>टास्क असाइनमेंट</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">तुम्हाला जे-जे हवं आहे — तेच निवडून, तसाच Report तयार करा.</p>

<div class="panel" style="max-width:700px;">
  <form method="POST" action="report.php" target="_blank" style="padding:22px 24px;">
    <?php if (!isDeptScoped()): ?>
    <?php if (count($campusesForCascade) > 1): ?>
    <label class="form-label-custom">College/School</label>
    <select id="campusSelect" class="form-select-custom mb-2" onchange="loadDeptsCascade_taskRep(this.value)">
      <option value="">-- निवडा --</option>
      <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <label class="form-label-custom">विभाग</label>
    <select name="department_id" id="departmentSelect" class="form-select-custom mb-3">
      <option value="">-- सगळे विभाग --</option>
      <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
    </select>
    <?php if (count($campusesForCascade) > 1): ?>
    <script>
    function loadDeptsCascade_taskRep(campusId) {
      const sel = document.getElementById('departmentSelect');
      if (!campusId) return;
      sel.disabled = true;
      fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
        .then(r => r.json()).then(data => {
          sel.innerHTML = '<option value="">-- सगळे विभाग --</option>';
          (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
          sel.disabled = false;
        });
    }
    </script>
    <?php endif; ?>
    <?php endif; ?>

    <div class="row g-3 mb-3">
      <div class="col-md-6"><label class="form-label-custom">पासून दिनांक</label><input type="date" name="from_date" class="form-control-custom" value="<?= date('Y-m-01') ?>"></div>
      <div class="col-md-6"><label class="form-label-custom">पर्यंत दिनांक</label><input type="date" name="to_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
    </div>

    <label class="form-label-custom">स्थिती</label>
    <select name="status" class="form-select-custom mb-3">
      <option value="all">सगळ्या</option>
      <option value="pending">प्रलंबित</option>
      <option value="in_progress">सुरू आहे</option>
      <option value="completed">पूर्ण</option>
    </select>

    <label class="form-label-custom">📋 Report मध्ये कोणते Columns हवेत — निवडा</label>
    <div style="background:var(--canvas);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="heading" checked style="width:16px;height:16px;"> कामाचं शीर्षक</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="details" checked style="width:16px;height:16px;"> तपशील/वर्णन (Description)</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="remarks" checked style="width:16px;height:16px;"> टिप्पणी (Completion Remarks)</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="cost" checked style="width:16px;height:16px;"> झालेला खर्च (Cost Incurred)</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="dept" checked style="width:16px;height:16px;"> विभाग</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="person" checked style="width:16px;height:16px;"> नेमलेली व्यक्ती</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="priority" checked style="width:16px;height:16px;"> प्राधान्य (Priority)</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="dates" checked style="width:16px;height:16px;"> दिनांक (तयार/पूर्ण)</label>
      <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;"><input type="checkbox" name="cols[]" value="status" checked style="width:16px;height:16px;"> स्थिती</label>
    </div>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">📄 Report तयार करा</button>
  </form>
</div>

<?php
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

// ==================================================================
// ---------------- POST — प्रत्यक्ष Report तयार करणे ----------------
// ==================================================================
$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_POST['department_id'] ?? '');
$fromDate = ($_POST['from_date'] ?? '') ?: date('Y-m-01');
$toDate = ($_POST['to_date'] ?? '') ?: date('Y-m-d');
$filterStatus = $_POST['status'] ?? 'all';
if (!in_array($filterStatus, ['all', 'pending', 'in_progress', 'completed', 'cancelled'], true)) { $filterStatus = 'all'; }
$cols = (array)($_POST['cols'] ?? ['heading', 'details', 'remarks', 'cost', 'dept', 'person', 'priority', 'dates', 'status']);
$colSet = array_flip($cols);

$sql = "
    SELECT t.*, d.name_local AS dept_name, au.name AS assigned_to_name, ab.name AS assigned_by_name
    FROM tasks t JOIN departments d ON d.id = t.department_id
    LEFT JOIN users au ON au.id = t.assigned_to LEFT JOIN users ab ON ab.id = t.assigned_by
    WHERE t.organization_id = ? AND DATE(t.created_at) BETWEEN ? AND ?
";
$params = [$orgId, $fromDate, $toDate];
if ($filterDeptId) { $sql .= " AND t.department_id = ?"; $params[] = $filterDeptId; }
if ($filterStatus !== 'all') { $sql .= " AND t.status = ?"; $params[] = $filterStatus; }
$sql .= " ORDER BY t.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tasksData = $stmt->fetchAll();

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण', 'cancelled' => 'रद्द'];
$priorityLabels = ['low' => 'कमी', 'normal' => 'सर्वसाधारण', 'high' => 'जास्त', 'urgent' => 'तातडीचं'];
$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
$totalCost = array_sum(array_map(fn($t) => (float)($t['cost_incurred'] ?? 0), $tasksData));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>Task Report</title>
<style>
  *{box-sizing:border-box;}
  body{font-family:'Segoe UI',sans-serif;color:#1A1D29;margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:#10162B;padding:14px 24px;display:flex;justify-content:space-between;z-index:10;}
  .toolbar button, .toolbar a{color:#fff;text-decoration:none;font-size:13px;background:#1B8A83;border:none;border-radius:8px;padding:9px 20px;font-weight:600;cursor:pointer;}
  .doc{max-width:1100px;margin:24px auto;background:#fff;padding:32px 40px;border-radius:6px;}
  .org-name{font-weight:700;font-size:18px;text-align:center;}
  .report-title{text-align:center;font-weight:600;font-size:14px;margin-top:4px;color:#1B8A83;}
  .period{text-align:center;font-size:11.5px;color:#666B7D;margin-top:2px;}
  table{width:100%;border-collapse:collapse;margin-top:14px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10.5px;text-transform:uppercase;color:#666B7D;}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:11.5px;vertical-align:top;}
  .total-box{background:#DCEFEC;border-radius:8px;padding:10px 16px;text-align:right;font-weight:700;margin-top:10px;}
  @media print{ .toolbar{display:none;} body{background:#fff;} .doc{margin:0;} }
</style>
</head>
<body>
<div class="toolbar">
  <a href="report.php">← मागे</a>
  <button onclick="window.print()">PDF डाउनलोड करा</button>
</div>
<div class="doc">
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">📋 Task Assignment — Report</div>
  <div class="period"><?= date('d M Y', strtotime($fromDate)) ?> ते <?= date('d M Y', strtotime($toDate)) ?><?= $filterStatus !== 'all' ? ' · ' . $statusLabels[$filterStatus] : '' ?> · एकूण <?= count($tasksData) ?></div>

  <table>
    <thead><tr>
      <?php if (isset($colSet['heading'])): ?><th>शीर्षक</th><?php endif; ?>
      <?php if (isset($colSet['details'])): ?><th>तपशील</th><?php endif; ?>
      <?php if (isset($colSet['dept'])): ?><th>विभाग</th><?php endif; ?>
      <?php if (isset($colSet['person'])): ?><th>नेमलेलं</th><?php endif; ?>
      <?php if (isset($colSet['priority'])): ?><th>प्राधान्य</th><?php endif; ?>
      <?php if (isset($colSet['dates'])): ?><th>तयार/पूर्ण दिनांक</th><?php endif; ?>
      <?php if (isset($colSet['status'])): ?><th>स्थिती</th><?php endif; ?>
      <?php if (isset($colSet['remarks'])): ?><th>टिप्पणी</th><?php endif; ?>
      <?php if (isset($colSet['cost'])): ?><th>खर्च</th><?php endif; ?>
    </tr></thead>
    <tbody>
      <?php if (empty($tasksData)): ?><tr><td colspan="9" style="text-align:center;color:#999;padding:24px;">या निकषांनुसार काहीही सापडलं नाही.</td></tr><?php endif; ?>
      <?php foreach ($tasksData as $t): ?>
        <tr>
          <?php if (isset($colSet['heading'])): ?><td style="font-weight:600;"><?= h($t['title']) ?></td><?php endif; ?>
          <?php if (isset($colSet['details'])): ?><td><?= h($t['description'] ?: '—') ?></td><?php endif; ?>
          <?php if (isset($colSet['dept'])): ?><td><?= h($t['dept_name']) ?></td><?php endif; ?>
          <?php if (isset($colSet['person'])): ?><td><?= h($t['assigned_to_name']) ?><br><span style="color:#999;">नेमलं: <?= h($t['assigned_by_name']) ?></span></td><?php endif; ?>
          <?php if (isset($colSet['priority'])): ?><td><?= h($priorityLabels[$t['priority']] ?? $t['priority']) ?></td><?php endif; ?>
          <?php if (isset($colSet['dates'])): ?><td><?= date('d M Y', strtotime($t['created_at'])) ?><?= $t['completed_at'] ? '<br>पूर्ण: ' . date('d M Y', strtotime($t['completed_at'])) : '' ?></td><?php endif; ?>
          <?php if (isset($colSet['status'])): ?><td><?= h($statusLabels[$t['status']] ?? $t['status']) ?></td><?php endif; ?>
          <?php if (isset($colSet['remarks'])): ?><td><?= h($t['completion_remarks'] ?: '—') ?></td><?php endif; ?>
          <?php if (isset($colSet['cost'])): ?><td><?= $t['cost_incurred'] ? '₹' . number_format($t['cost_incurred'], 0) : '—' ?></td><?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (isset($colSet['cost']) && $totalCost > 0): ?>
    <div class="total-box">एकूण खर्च: ₹<?= number_format($totalCost, 0) ?></div>
  <?php endif; ?>
</div>
</body>
</html>
