<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemName = trim($_POST['item_name'] ?? '');
    $category = trim($_POST['category'] ?? '');
    $departmentId = ($_POST['department_id'] ?? '') !== '' ? (int)$_POST['department_id'] : null;
    $unit = trim($_POST['unit'] ?? '');
    $reorderLevel = (float)($_POST['reorder_level'] ?? 0);
    if ($itemName && $unit) {
        $pdo->prepare("INSERT INTO store_items (organization_id, item_name, category, department_id, unit, reorder_level, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $itemName, $category, $departmentId, $unit, $reorderLevel, $user['id']]);
        $_SESSION['flash'] = "'$itemName' यादीत जोडली गेली.";
    }
    header('Location: items.php');
    exit;
}

$pageTitle = 'भांडार वस्तू यादी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$itemsStmt = $pdo->prepare("
    SELECT si.*, d.name_local AS dept_name FROM store_items si LEFT JOIN departments d ON d.id = si.department_id
    WHERE si.organization_id = ? ORDER BY si.item_name
");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();

$deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$deptsForItems = $deptsStmt->fetchAll();
$campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesForCascade->execute([$orgId]);
$campusesForCascade = $campusesForCascade->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📋 भांडार वस्तू यादी</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>भांडार विभाग</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन वस्तू जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">वस्तूचं नाव</label><input type="text" name="item_name" class="form-control-custom" required></div>
      <div class="col-md-2"><label class="form-label-custom">प्रकार</label><input type="text" name="category" class="form-control-custom" placeholder="धान्य, स्टेशनरी"></div>
      <?php if (count($campusesForCascade) > 1): ?>
        <div class="col-md-2">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade_stItems(this.value)">
            <option value="">-- निवडा --</option>
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label-custom">कोणत्या विभागासाठी <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label>
        <select name="department_id" id="departmentSelect" class="form-select-custom">
          <option value="">-- सामायिक/इतर --</option>
          <?php foreach ($deptsForItems as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_stItems(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '<option value="">-- सामायिक/इतर --</option>';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
      <div class="col-md-2"><label class="form-label-custom">एकक</label><input type="text" name="unit" class="form-control-custom" placeholder="किलो, नग" required></div>
      <div class="col-md-1"><label class="form-label-custom">किमान</label><input type="number" step="0.01" name="reorder_level" class="form-control-custom"></div>
      <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;padding:9px;">+</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>वस्तू</th><th>प्रकार</th><th>विभाग</th><th>एकक</th><th>किमान मर्यादा</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr><td style="font-weight:500;"><?= h($it['item_name']) ?></td><td><?= h($it['category']) ?></td><td><?= h($it['dept_name'] ?: '⚪ सामायिक') ?></td><td><?= h($it['unit']) ?></td><td><?= number_format($it['reorder_level'], 2) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
