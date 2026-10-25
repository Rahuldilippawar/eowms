<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'dept_material_purchases', '../');
require_once __DIR__ . '/../includes/dept-finance-helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_dept_finance') || hasPermission('manage_budget') || hasCategory('hod') || isManagementOrAbove();
// विभाग नेमलेला नसलेल्या (आणि Org-wide Permission नसलेल्या) व्यक्तीला — काहीच नाही ("सगळं" नाही)
if (!$canManage || deptFinanceAccessibleDeptId($user) === 0) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $accessibleDeptId = deptFinanceAccessibleDeptId($user);

    if ($action === 'add_purchase') {
        // HOD स्वतःच्याच Department साठी जोडू शकतो, Management कोणत्याही (पण याच संस्थेचा)
        $deptId = $accessibleDeptId ?? (int)($_POST['department_id'] ?? 0);
        $deptOk = $pdo->prepare("SELECT id FROM departments WHERE id = ? AND organization_id = ?");
        $deptOk->execute([$deptId, $orgId]);
        if (!$deptOk->fetch()) { $deptId = 0; }

        $materialName = trim($_POST['material_name'] ?? '');
        $vendorName = trim($_POST['vendor_name'] ?? '');
        $vendorContact = trim($_POST['vendor_contact'] ?? '') ?: null;
        $quantity = (float)($_POST['quantity'] ?? 0);
        $unit = trim($_POST['unit'] ?? '') ?: null;
        $rate = (float)($_POST['rate'] ?? 0);
        $transportCost = (float)($_POST['transport_cost'] ?? 0);
        $gstPercent = (float)($_POST['gst_percent'] ?? 0);
        $purchaseDate = ($_POST['purchase_date'] ?? '') ?: date('Y-m-d');
        $remarks = trim($_POST['remarks'] ?? '') ?: null;
        $campusId = (int)($_POST['campus_id'] ?? 0);
        // College/School सुद्धा याच संस्थेचं असावं
        $campusOk = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
        $campusOk->execute([$campusId, $orgId]);
        if (!$campusOk->fetch()) { $campusId = 0; }
        if ($purchaseDate && !strtotime($purchaseDate)) { $purchaseDate = date('Y-m-d'); }

        if ($materialName && $vendorName && $quantity > 0 && $rate > 0 && $deptId && $campusId) {
            $baseAmount = round($quantity * $rate, 2);
            $gstAmount = round($baseAmount * $gstPercent / 100, 2);
            $totalAmount = round($baseAmount + $transportCost + $gstAmount, 2);
            $pdo->prepare("
                INSERT INTO dept_material_purchases (organization_id, campus_id, department_id, material_name, vendor_name, vendor_contact, quantity, unit, rate, base_amount, transport_cost, gst_percent, gst_amount, total_amount, purchase_date, remarks, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$orgId, $campusId, $deptId, $materialName, $vendorName, $vendorContact, $quantity, $unit, $rate, $baseAmount, $transportCost, $gstPercent, $gstAmount, $totalAmount, $purchaseDate, $remarks, $user['id']]);
            $newPurchaseId = (int)$pdo->lastInsertId();
            // खर्च एकदाच — विभाग Finance (Budget) Ledger मध्ये, खरेदी-दिनांकाच्या आर्थिक वर्षात
            recordBudgetTransaction($pdo, $orgId, $deptId, $totalAmount, 'नवीन खरेदी', 'material_purchase', $newPurchaseId, $materialName . ' — ' . $vendorName, $user['id'], $purchaseDate);
            logActivity('Material Purchase नोंदवली', 'dept_material_purchase', $newPurchaseId, $materialName);
            $_SESSION['flash'] = 'खरेदी-नोंद जोडली गेली.';
        } else {
            $_SESSION['flash'] = 'नोंद जतन झाली नाही — विभाग/College व सगळी माहिती तपासा.';
        }
    } elseif ($action === 'add_payment') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $paymentDate = ($_POST['payment_date'] ?? '') ?: date('Y-m-d');
        $paymentType = in_array($_POST['payment_type'] ?? '', ['Advance','Partial','Final'], true) ? $_POST['payment_type'] : 'Partial';
        $paymentMode = trim($_POST['payment_mode'] ?? '') ?: null;
        $remarks = trim($_POST['remarks'] ?? '') ?: null;

        // हा Purchase खरंच या Organization/Department चाच आहे का — तपासतो
        $checkStmt = $pdo->prepare("SELECT department_id FROM dept_material_purchases WHERE id = ? AND organization_id = ?");
        $checkStmt->execute([$sourceId, $orgId]);
        $srcDeptId = $checkStmt->fetch()['department_id'] ?? null;

        if ($srcDeptId && $amount > 0 && deptFinanceCanAccessDept($user, $srcDeptId)) {
            $pdo->prepare("INSERT INTO dept_payment_ledger (organization_id, department_id, source_type, source_id, amount, payment_date, payment_type, payment_mode, remarks, recorded_by) VALUES (?, ?, 'material_purchase', ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $srcDeptId, $sourceId, $amount, $paymentDate, $paymentType, $paymentMode, $remarks, $user['id']]);
            $_SESSION['flash'] = 'Payment नोंदवलं गेलं.';
        }
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'विभाग Finance — Material Purchase';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$accessibleDeptId = deptFinanceAccessibleDeptId($user);
$filterDeptId = $accessibleDeptId ?? (int)($_GET['department_id'] ?? 0);

$sql = "
    SELECT dmp.*, d.name_local AS dept_name, c.name AS campus_name
    FROM dept_material_purchases dmp JOIN departments d ON d.id = dmp.department_id JOIN campuses c ON c.id = dmp.campus_id
    WHERE dmp.organization_id = ?
";
$params = [$orgId];
if ($filterDeptId) { $sql .= " AND dmp.department_id = ?"; $params[] = $filterDeptId; }
$sql .= " ORDER BY dmp.purchase_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$purchases = $stmt->fetchAll();

$paidTotals = deptFinancePaidTotalsBulk($pdo, 'material_purchase', array_column($purchases, 'id'));

$grandTotal = array_sum(array_column($purchases, 'total_amount'));
$grandPaid = array_sum($paidTotals);
$grandDue = $grandTotal - $grandPaid;

if ($accessibleDeptId === null) {
    $deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
    $deptsStmt->execute([$orgId]);
    $allDepts = $deptsStmt->fetchAll();
}
$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🛒 विभाग Finance — Material Purchase</div>
  <div class="d-flex gap-2">
    <a href="dashboard.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;">📊 Budget vs Actual Dashboard →</a>
    <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addPurchaseModal">+ नवीन खरेदी</button>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($accessibleDeptId === null): ?>
<form method="GET" class="mb-3">
  <select name="department_id" class="form-select-custom" style="width:auto;" onchange="this.form.submit()">
    <option value="">-- सगळे विभाग --</option>
    <?php foreach ($allDepts as $d): ?><option value="<?= $d['id'] ?>" <?= $filterDeptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
  </select>
</form>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण खरेदी</div><div class="val">₹<?= number_format($grandTotal, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">✅ Paid</div><div class="val" style="color:var(--rail);">₹<?= number_format($grandPaid, 0) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ बाकी (Due)</div><div class="val" style="color:<?= $grandDue > 0 ? 'var(--rose)' : 'var(--rail)' ?>;">₹<?= number_format($grandDue, 0) ?></div></div></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Material</th><th>Vendor</th><th>Qty×Rate</th><th>Transport+GST</th><th>एकूण</th><th>स्थिती</th><?php if ($accessibleDeptId === null): ?><th>विभाग</th><?php endif; ?><th></th></tr></thead>
    <tbody>
      <?php if (empty($purchases)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही खरेदी-नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($purchases as $p):
        $paid = $paidTotals[$p['id']] ?? 0;
        [$statusLabel, $statusChip] = deptFinanceStatusLabel($p['total_amount'], $paid);
        $due = $p['total_amount'] - $paid;
      ?>
        <tr>
          <td style="font-weight:600;"><?= h($p['material_name']) ?><div style="font-size:10.5px;color:var(--text-dim);"><?= date('d M Y', strtotime($p['purchase_date'])) ?></div></td>
          <td><?= h($p['vendor_name']) ?></td>
          <td class="font-mono" style="font-size:12px;"><?= number_format($p['quantity'], 1) ?> <?= h($p['unit']) ?> × ₹<?= number_format($p['rate'], 0) ?></td>
          <td class="font-mono" style="font-size:12px;">₹<?= number_format($p['transport_cost'], 0) ?> + ₹<?= number_format($p['gst_amount'], 0) ?></td>
          <td class="font-mono" style="font-weight:700;">₹<?= number_format($p['total_amount'], 0) ?></td>
          <td>
            <span class="status-chip <?= $statusChip ?>"><?= $statusLabel ?></span>
            <?php if ($due > 0): ?><div style="font-size:10px;color:var(--rose);margin-top:2px;">Due: ₹<?= number_format($due, 0) ?></div><?php endif; ?>
          </td>
          <?php if ($accessibleDeptId === null): ?><td style="font-size:12px;color:var(--text-dim);"><?= h($p['dept_name']) ?></td><?php endif; ?>
          <td>
            <?php if ($due > 0): ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:6px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#addPaymentModal"
                onclick="document.getElementById('paySourceId').value='<?= $p['id'] ?>';document.getElementById('payMaterialName').textContent='<?= h($p['material_name']) ?>';document.getElementById('payAmount').value='<?= $due ?>';document.getElementById('payAmount').max='<?= $due ?>';">
                💰 Payment
              </button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="modal fade" id="addPurchaseModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_purchase">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन खरेदी नोंदवा</div>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">Material चं नाव</label><input type="text" name="material_name" class="form-control-custom" required></div>
          <div class="col-md-6"><label class="form-label-custom">Vendor चं नाव</label><input type="text" name="vendor_name" class="form-control-custom" required></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-3"><label class="form-label-custom">Quantity</label><input type="number" step="0.01" name="quantity" id="dmpQty" class="form-control-custom" required oninput="dmpCalc()"></div>
          <div class="col-md-3"><label class="form-label-custom">Unit</label><input type="text" name="unit" class="form-control-custom" placeholder="Kg/Nos/Ltr"></div>
          <div class="col-md-3"><label class="form-label-custom">Rate (₹)</label><input type="number" step="0.01" name="rate" id="dmpRate" class="form-control-custom" required oninput="dmpCalc()"></div>
          <div class="col-md-3"><label class="form-label-custom">Vendor Contact</label><input type="text" name="vendor_contact" class="form-control-custom"></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-4"><label class="form-label-custom">Transport (₹)</label><input type="number" step="0.01" name="transport_cost" id="dmpTransport" class="form-control-custom" value="0" oninput="dmpCalc()"></div>
          <div class="col-md-4"><label class="form-label-custom">GST %</label><input type="number" step="0.01" name="gst_percent" id="dmpGst" class="form-control-custom" value="0" oninput="dmpCalc()"></div>
          <div class="col-md-4"><label class="form-label-custom">एकूण किंमत (Auto)</label><input type="text" id="dmpTotal" class="form-control-custom" readonly style="background:var(--canvas);font-weight:700;"></div>
        </div>
        <div class="row g-2 mb-2">
          <?php if ($accessibleDeptId === null): ?>
          <div class="col-md-4">
            <label class="form-label-custom">विभाग</label>
            <select name="department_id" class="form-select-custom" required>
              <option value="">-- निवडा --</option>
              <?php foreach ($allDepts as $d): ?><option value="<?= $d['id'] ?>"><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <?php endif; ?>
          <div class="col-md-4">
            <label class="form-label-custom">College/School</label>
            <select name="campus_id" class="form-select-custom" required>
              <option value="">-- निवडा --</option>
              <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4"><label class="form-label-custom">खरेदी दिनांक</label><input type="date" name="purchase_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <label class="form-label-custom">टिप्पणी</label>
        <input type="text" name="remarks" class="form-control-custom">
        <div id="dmpBudgetInfo" style="display:none;margin-top:12px;border-radius:9px;padding:10px 14px;font-size:12.5px;"></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="addPaymentModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_payment">
      <input type="hidden" name="source_id" id="paySourceId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Payment नोंदवा — <span id="payMaterialName"></span></div>
        <label class="form-label-custom">रक्कम (₹)</label>
        <input type="number" step="0.01" name="amount" id="payAmount" class="form-control-custom mb-2" required>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label-custom">प्रकार</label>
            <select name="payment_type" class="form-select-custom"><option value="Advance">Advance</option><option value="Partial" selected>Partial</option><option value="Final">Final</option></select>
          </div>
          <div class="col-md-6"><label class="form-label-custom">दिनांक</label><input type="date" name="payment_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
        </div>
        <label class="form-label-custom">पद्धत (ऐच्छिक)</label>
        <input type="text" name="payment_mode" class="form-control-custom mb-2" placeholder="Cash/Cheque/Online">
        <label class="form-label-custom">टिप्पणी</label>
        <input type="text" name="remarks" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>

<script>
function dmpCalc() {
  const qty = parseFloat(document.getElementById('dmpQty').value) || 0;
  const rate = parseFloat(document.getElementById('dmpRate').value) || 0;
  const transport = parseFloat(document.getElementById('dmpTransport').value) || 0;
  const gstPct = parseFloat(document.getElementById('dmpGst').value) || 0;
  const base = qty * rate;
  const gstAmt = base * gstPct / 100;
  const total = base + transport + gstAmt;
  document.getElementById('dmpTotal').value = '₹' + total.toLocaleString('en-IN', { maximumFractionDigits: 2 });
  dmpBudgetCheck(total);
}
// नवीन खर्चाने विभागाचं शिल्लक Budget ओलांडलं जाईल का — आधीच इशारा
var dmpBudgetTimer = null;
function dmpBudgetCheck(total) {
  clearTimeout(dmpBudgetTimer);
  dmpBudgetTimer = setTimeout(function () {
    var form = document.querySelector('#addPurchaseModal form');
    var deptSel = form.querySelector('[name=department_id]');
    var deptId = deptSel ? deptSel.value : '<?= (int)($accessibleDeptId ?? 0) ?>';
    var date = form.querySelector('[name=purchase_date]').value;
    var box = document.getElementById('dmpBudgetInfo');
    if (!deptId || deptId === '0') { box.style.display = 'none'; return; }
    fetch('budget-check.php?department_id=' + encodeURIComponent(deptId) + '&date=' + encodeURIComponent(date) + '&amount=' + total)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok || d.remaining === null) { box.style.display = 'none'; return; }
        box.style.display = 'block';
        box.style.background = d.exceeds ? '#F7E2E3' : '#DCEFEC';
        box.style.color = d.exceeds ? 'var(--rose)' : 'var(--rail)';
        box.textContent = (d.exceeds ? '⚠️ ही खरेदी विभागाचं शिल्लक Budget ओलांडेल — ' : '✅ ') + 'FY ' + d.fy + ' शिल्लक: ₹' + Math.round(d.remaining).toLocaleString('en-IN');
      });
  }, 300);
}
document.addEventListener('change', function (e) {
  if (e.target.closest && e.target.closest('#addPurchaseModal') && (e.target.name === 'department_id' || e.target.name === 'purchase_date')) { dmpCalc(); }
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
