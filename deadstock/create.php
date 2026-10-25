<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
requireLogin('../');
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_dead_stock_master');
$error = null;

// Department scoping: staff/HOD — स्वतःचा विभाग (+ Extra दिलेले विभाग);
// Super Admin/Management — जे विभाग दिसतात त्यापैकी कुठलाही (null = सगळे).
$visibleDeptIds = getVisibleDepartmentIds();
$canPickAnyDept = $visibleDeptIds === null;
$lockedDeptId = (!$canPickAnyDept && count($visibleDeptIds) === 1) ? (int)$visibleDeptIds[0] : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $departmentId = $lockedDeptId ?: (int)($_POST['department_id'] ?? 0);
    // विभाग — याच संस्थेचा व या व्यक्तीला दिसणारा असावा
    $deptOkStmt = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ? AND is_active = 1");
    $deptOkStmt->execute([$departmentId, $orgId]);
    if (!$deptOkStmt->fetch() || (!$canPickAnyDept && !in_array($departmentId, $visibleDeptIds, true))) {
        $departmentId = 0;
    }
    $itemMasterId = (int)($_POST['item_master_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $unit = trim($_POST['unit'] ?? '') ?: null;
    $purchaseDate = ($_POST['purchase_date'] ?? '') ?: null;
    $purchaseCost = ($_POST['purchase_cost'] ?? '') !== '' ? (float)$_POST['purchase_cost'] : null;
    $isNewPurchase = ($_POST['is_new_purchase'] ?? '1') === '1' ? 1 : 0;
    $condition = $_POST['condition'] ?? 'चांगली';
    $location = trim($_POST['location'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '') ?: null;

    $masterStmt = $pdo->prepare("SELECT * FROM dead_stock_item_master WHERE id = ? AND organization_id = ? AND is_active = 1");
    $masterStmt->execute([$itemMasterId, $orgId]);
    $masterItem = $masterStmt->fetch();

    if (!$departmentId || !$masterItem) {
        $error = 'कृपया विभाग व यादीतून वस्तू निवडा.';
    } elseif (!in_array($condition, ['चांगली', 'दुरुस्तीयोग्य', 'खराब', 'भंगार'], true)
        || ($purchaseDate && !DateTime::createFromFormat('!Y-m-d', $purchaseDate))) {
        $error = 'स्थिती किंवा खरेदी दिनांक अवैध आहे.';
    } else {
        $itemName = $masterItem['item_name'];
        $category = $masterItem['category'];

        $deptCodeStmt = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
        $deptCodeStmt->execute([$departmentId]);
        $deptCode = $deptCodeStmt->fetch()['code'] ?? 'GEN';

        $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM dead_stock_items WHERE department_id = ?");
        $countStmt->execute([$departmentId]);
        $seq = $countStmt->fetch()['c'] + 1;
        $itemCode = generateUniqueCode($pdo, 'dead_stock_items', 'item_code', 'DS-%s-%04d', $seq, $deptCode);

        $insert = $pdo->prepare("
            INSERT INTO dead_stock_items (organization_id, department_id, item_master_id, item_code, item_name, category, quantity, unit, purchase_date, purchase_cost, is_new_purchase, `condition`, location, remarks, added_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$orgId, $departmentId, $itemMasterId, $itemCode, $itemName, $category, $quantity, $unit, $purchaseDate, $purchaseCost, $isNewPurchase, $condition, $location, $remarks, $user['id']]);
        $newItemId = $pdo->lastInsertId();

        // फक्त खरोखर नवीन खरेदी असेल तरच Budget मधून वजा होतं — आधीपासून
        // अस्तित्वात असलेली मालमत्ता फक्त नोंदवली जातेय, तो नवीन खर्च नाही.
        if ($isNewPurchase) {
            recordBudgetTransaction($pdo, $orgId, $departmentId, $purchaseCost, 'नवीन खरेदी', 'dead_stock', $newItemId, "$itemName ($itemCode)", $user['id'], $purchaseDate);
        }

        logActivity('Dead Stock नोंद जोडली', 'dead_stock_item', $newItemId, "$itemCode — $itemName");
        $_SESSION['flash'] = "'$itemName' ($itemCode) नोंदवली गेली.";
        header('Location: index.php?department_id=' . $departmentId);
        exit;
    }
}

$departments = [];
$campusesForCascade = [];
if ($canPickAnyDept) {
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $stmt->execute([$orgId]);
    $departments = $stmt->fetchAll();
    $cStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
    $cStmt->execute([$orgId]);
    $campusesForCascade = $cStmt->fetchAll();
} elseif (!$lockedDeptId && !empty($visibleDeptIds)) {
    // स्वतःचा + Extra दिलेले विभाग — त्यातूनच निवड
    $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 AND id IN ($ph) ORDER BY name_local");
    $stmt->execute(array_merge([$orgId], $visibleDeptIds));
    $departments = $stmt->fetchAll();
} elseif (!$lockedDeptId) {
    http_response_code(403);
    die('तुम्हाला कोणताही विभाग नेमलेला नाही — Dead Stock नोंदवण्यासाठी विभाग असणे आवश्यक आहे.');
}

// Master item catalog — same list for every department, managed centrally by Super Admin
$stmt = $pdo->prepare("SELECT * FROM dead_stock_item_master WHERE organization_id = ? AND is_active = 1 ORDER BY category, item_name");
$stmt->execute([$orgId]);
$masterItems = $stmt->fetchAll();

$pageTitle = 'नवीन Dead Stock नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">नवीन Dead Stock नोंद</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <?php if (!$canPickAnyDept && !$lockedDeptId): ?>
        <div class="col-md-6">
          <label class="form-label-custom">विभाग</label>
          <select name="department_id" class="form-select-custom" required>
            <?php foreach ($departments as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
      <?php endif; ?>
      <?php if ($canPickAnyDept): ?>
        <div class="col-md-3">
          <label class="form-label-custom">College/School</label>
          <select id="campusSelect" class="form-select-custom" onchange="loadDeptsCascade(this.value, 'deptSelect')">
            <option value="">-- निवडा --</option>
            <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-3">
          <label class="form-label-custom">विभाग</label>
          <select name="department_id" id="deptSelect" class="form-select-custom" required disabled>
            <option value="">आधी College/School निवडा</option>
          </select>
        </div>
        <script>
        function loadDeptsCascade(campusId, targetId) {
          const sel = document.getElementById(targetId);
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
      <div class="col-md-6">
        <label class="form-label-custom">वस्तू निवडा (यादीतून)</label>
        <select name="item_master_id" id="itemMasterSelect" class="form-select-custom" required>
          <option value="">-- निवडा --</option>
          <?php $lastCat = null; foreach ($masterItems as $mi): if ($mi['category'] !== $lastCat) { if ($lastCat !== null) echo '</optgroup>'; echo '<optgroup label="' . h($mi['category'] ?: 'इतर') . '">'; $lastCat = $mi['category']; } ?>
            <option value="<?= $mi['id'] ?>" data-unit="<?= h($mi['default_unit'] ?? '') ?>"><?= h($mi['item_name']) ?></option>
          <?php endforeach; if (!empty($masterItems)) echo '</optgroup>'; ?>
        </select>
        <?php if (empty($masterItems)): ?>
          <div style="font-size:11px;color:var(--rose);margin-top:3px;">यादी अजून रिकामी आहे — Super Admin ला "वस्तू यादी" मधून वस्तू जोडायला सांगा.</div>
        <?php endif; ?>
        <?php if (hasRole('super_admin')): ?>
          <a href="item-master.php" style="font-size:11px;color:var(--rail);">+ नवीन वस्तू यादीत जोडा</a>
        <?php endif; ?>
      </div>

      <div class="col-md-3">
        <label class="form-label-custom">संख्या</label>
        <input type="number" name="quantity" class="form-control-custom" value="1" min="1">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">एकक (Unit)</label>
        <input type="text" name="unit" class="form-control-custom" placeholder="नग, सेट">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">खरेदी दिनांक</label>
        <input type="date" name="purchase_date" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">खरेदी किंमत (₹)</label>
        <input type="number" step="0.01" name="purchase_cost" class="form-control-custom">
      </div>
      <div class="col-12">
        <label class="form-label-custom">ही वस्तू कोणत्या प्रकारची आहे?</label>
        <div class="d-flex gap-4">
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
            <input type="radio" name="is_new_purchase" value="1" checked> 🆕 नवीन खरेदी <span style="color:var(--text-dim);font-size:11px;">(विभागाच्या Budget मधून वजा होईल)</span>
          </label>
          <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
            <input type="radio" name="is_new_purchase" value="0"> 📋 आधीपासून अस्तित्वात आहे <span style="color:var(--text-dim);font-size:11px;">(फक्त नोंद — Budget मधून वजा होणार नाही)</span>
          </label>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label-custom">सध्याची स्थिती</label>
        <select name="condition" class="form-select-custom">
          <option value="चांगली">चांगली</option>
          <option value="दुरुस्तीयोग्य">दुरुस्तीयोग्य</option>
          <option value="खराब">खराब</option>
          <option value="भंगार">भंगार</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">ठिकाण (कुठे आहे)</label>
        <input type="text" name="location" class="form-control-custom" placeholder="उदा. मुख्य कार्यालय, खोली क्र. 2">
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
document.getElementById('itemMasterSelect').addEventListener('change', function () {
  var opt = this.options[this.selectedIndex];
  var unitField = document.querySelector('input[name="unit"]');
  if (opt.dataset.unit && !unitField.value) { unitField.value = opt.dataset.unit; }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
