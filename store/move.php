<?php
// ➕ भांडार — आवक (खरेदी) / जावक (वाटप) / परत / दुरुस्ती — एकाच नोंदीत अनेक वस्तू
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();
$types = storeMoveTypes();
unset($types['work']);   // कामात वापर — दैनंदिन नोंदीतून आपोआप

if (!storeStockReady($pdo)) {
    $pageTitle = 'आवक / जावक'; $rootPath = '../';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="alert alert-warning" style="border-radius:10px;">Stock साठी Database अद्ययावत करायचा आहे — Super Admin ने <b>System Update → Database Update</b> चालवा.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$itemsStmt = $pdo->prepare("SELECT id, item_name, unit, category FROM store_items WHERE organization_id = ? AND is_active = 1 ORDER BY category IS NULL OR category = '', category, item_name");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();
$itemById = [];
foreach ($items as $i) $itemById[(int)$i['id']] = $i;
$balances = storeBalances($pdo, $orgId);

$vendorsStmt = $pdo->prepare("SELECT id, name FROM store_vendors WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$vendorsStmt->execute([$orgId]);
$vendors = $vendorsStmt->fetchAll();
$deptsStmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$depts = $deptsStmt->fetchAll();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $type = $_POST['move_type'] ?? '';
    $date = $_POST['move_date'] ?? date('Y-m-d');
    $vendorId = (int)($_POST['vendor_id'] ?? 0) ?: null;
    $deptId = (int)($_POST['department_id'] ?? 0) ?: null;
    $billNo = trim($_POST['bill_no'] ?? '') ?: null;
    $issuedTo = trim($_POST['issued_to'] ?? '') ?: null;
    $notes = trim($_POST['notes'] ?? '') ?: null;
    $adjDir = ($_POST['adjust_dir'] ?? 'minus') === 'plus' ? 1 : -1;

    if (!isset($types[$type])) $errors[] = 'नोंदीचा प्रकार निवडा.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $date > date('Y-m-d')) $errors[] = 'दिनांक योग्य नाही (भविष्यातला चालणार नाही).';
    if ($vendorId && !in_array($vendorId, array_map(fn($v) => (int)$v['id'], $vendors), true)) $vendorId = null;
    if ($deptId && !in_array($deptId, array_map(fn($d) => (int)$d['id'], $depts), true)) $deptId = null;
    if ($type === 'issue' && !$deptId && !$issuedTo) $errors[] = 'वाटप कोणाला केलं — विभाग किंवा व्यक्तीचं नाव द्या.';
    if ($type === 'adjust' && !$notes) $errors[] = 'दुरुस्तीचं कारण (नोंद) लिहा.';

    $rows = [];
    foreach ((array)($_POST['item_id'] ?? []) as $k => $iid) {
        $iid = (int)$iid;
        $qty = (float)($_POST['qty'][$k] ?? 0);
        $rate = ($_POST['rate'][$k] ?? '') !== '' ? (float)$_POST['rate'][$k] : null;
        if (!$iid && $qty <= 0) continue;
        if (!isset($itemById[$iid])) { $errors[] = 'एक वस्तू यादीत सापडली नाही.'; continue; }
        if ($qty <= 0) { $errors[] = "'{$itemById[$iid]['item_name']}' चं प्रमाण शून्यापेक्षा जास्त हवं."; continue; }
        if ($rate !== null && $rate < 0) $rate = null;
        $rows[] = [$iid, $qty, $rate];
    }
    if (!$rows && !$errors) $errors[] = 'किमान एक वस्तू व प्रमाण द्या.';

    // जावक — शिल्लकपेक्षा जास्त देता येणार नाही (एकाच नोंदीत एकच वस्तू दोनदा असली तरी)
    $sign = $type === 'adjust' ? $adjDir : $types[$type][1] ?? 0;
    if (!$errors && $sign < 0) {
        $need = [];
        foreach ($rows as [$iid, $qty]) $need[$iid] = ($need[$iid] ?? 0) + $qty;
        foreach ($need as $iid => $q) {
            $have = (float)($balances[$iid]['bal'] ?? 0);
            if ($q > $have + 0.0001) $errors[] = "'{$itemById[$iid]['item_name']}' — शिल्लक फक्त " . storeQty($have) . " {$itemById[$iid]['unit']} आहे, " . storeQty($q) . " देता येणार नाही.";
        }
    }

    if (!$errors) {
        $ins = $pdo->prepare("INSERT INTO store_stock_moves (organization_id, item_id, move_type, quantity, rate, move_date, vendor_id, bill_no, department_id, issued_to, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $pdo->beginTransaction();
        foreach ($rows as [$iid, $qty, $rate]) {
            $ins->execute([$orgId, $iid, $type, $sign * $qty, in_array($type, ['purchase', 'opening'], true) ? $rate : null, $date,
                $type === 'purchase' ? $vendorId : null, $type === 'purchase' ? $billNo : null,
                in_array($type, ['issue', 'return'], true) ? $deptId : null, $type === 'issue' ? $issuedTo : null, $notes, $user['id']]);
        }
        $pdo->commit();
        logActivity('भांडार नोंद: ' . $types[$type][0], 'store_stock', null, count($rows) . ' वस्तू');
        $_SESSION['flash'] = '✅ ' . $types[$type][0] . ' — ' . count($rows) . ' वस्तूंची नोंद झाली.';
        header('Location: ' . (($_POST['again'] ?? '') ? 'move.php?type=' . urlencode($type) : 'ledger.php'));
        exit;
    }
}

$type = $_POST['move_type'] ?? ($_GET['type'] ?? 'purchase');
if (!isset($types[$type])) $type = 'purchase';
$preItem = (int)($_GET['item'] ?? 0);

$pageTitle = 'आवक / जावक';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$balJs = [];
foreach ($items as $i) $balJs[(int)$i['id']] = ['b' => (float)($balances[(int)$i['id']]['bal'] ?? 0), 'u' => $i['unit'], 'r' => $balances[(int)$i['id']]['last_rate'] ?? null];
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">➕ आवक / जावक नोंद</div>
<?= storeNav('move') ?>

<?php if ($errors): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?php foreach ($errors as $e): ?><div>• <?= h($e) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if (!$items): ?><div class="alert alert-warning" style="border-radius:10px;">आधी <a href="items.php">वस्तू यादी</a> मध्ये वस्तू जोडा.</div><?php endif; ?>

<form method="POST" class="panel" id="mvForm" style="padding:18px 22px;">
  <div class="mv-types">
    <?php foreach ($types as $k => [$label]): ?>
      <label class="mv-t"><input type="radio" name="move_type" value="<?= $k ?>" <?= $k === $type ? 'checked' : '' ?>><span><?= h($label) ?></span></label>
    <?php endforeach; ?>
  </div>
  <div class="row g-3 mt-1">
    <div class="col-md-2"><label class="form-label-custom">दिनांक</label><input type="date" name="move_date" class="form-control-custom" value="<?= h($_POST['move_date'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-3 f-purchase"><label class="form-label-custom">Vendor <a href="vendors.php" style="font-size:11px;">+ नवीन</a></label>
      <select name="vendor_id" class="form-select-custom"><option value="">-- निवडा --</option><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= (int)($_POST['vendor_id'] ?? 0) === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 f-purchase"><label class="form-label-custom">बिल / Invoice क्र.</label><input type="text" name="bill_no" class="form-control-custom" maxlength="60" value="<?= h($_POST['bill_no'] ?? '') ?>"></div>
    <div class="col-md-3 f-issue f-return"><label class="form-label-custom">विभाग</label>
      <select name="department_id" class="form-select-custom"><option value="">-- निवडा --</option><?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= (int)($_POST['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2 f-issue"><label class="form-label-custom">कोणाला दिलं</label><input type="text" name="issued_to" class="form-control-custom" maxlength="150" value="<?= h($_POST['issued_to'] ?? '') ?>" placeholder="नाव"></div>
    <div class="col-md-2 f-adjust"><label class="form-label-custom">दुरुस्ती</label>
      <select name="adjust_dir" class="form-select-custom"><option value="minus">− कमी (तूट / खराब)</option><option value="plus" <?= ($_POST['adjust_dir'] ?? '') === 'plus' ? 'selected' : '' ?>>+ जास्त (मोजणीत सापडलं)</option></select></div>
    <div class="col-md-3"><label class="form-label-custom">नोंद <span class="f-adjust" style="color:var(--rose);">(कारण आवश्यक)</span></label><input type="text" name="notes" class="form-control-custom" maxlength="255" value="<?= h($_POST['notes'] ?? '') ?>"></div>
  </div>

  <div class="table-responsive mt-3">
    <table class="rtable mv-rows" id="mvRows">
      <thead><tr><th style="min-width:240px;">वस्तू</th><th>शिल्लक</th><th style="width:130px;">प्रमाण</th><th class="f-purchase f-opening" style="width:130px;">दर (₹ / एकक)</th><th class="f-purchase f-opening">रक्कम</th><th></th></tr></thead>
      <tbody></tbody>
      <tfoot><tr><td colspan="4"><button type="button" class="btn btn-sm" id="mvAdd" style="border:1px dashed var(--line);border-radius:8px;">+ आणखी वस्तू</button></td><td class="f-purchase f-opening font-mono" id="mvTotal" style="font-weight:700;"></td><td></td></tr></tfoot>
    </table>
  </div>
  <div class="d-flex gap-2 flex-wrap mt-3">
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;padding:9px 22px;font-weight:600;">💾 नोंद जतन करा</button>
    <button type="submit" name="again" value="1" class="btn" style="border:1px solid var(--line);border-radius:9px;padding:9px 18px;">जतन करा व आणखी एक</button>
  </div>
</form>

<template id="mvRowTpl"><tr>
  <td><select name="item_id[]" class="form-select-custom mv-item" data-smart="1"><option value="">-- वस्तू निवडा --</option>
    <?php $cat = null; foreach ($items as $i): $c = trim((string)$i['category']) ?: 'इतर'; if ($c !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $c; ?><optgroup label="<?= h($c) ?>"><?php endif; ?>
      <option value="<?= $i['id'] ?>"><?= h($i['item_name']) ?> (<?= h($i['unit']) ?>)</option>
    <?php endforeach; if ($cat !== null) echo '</optgroup>'; ?></select></td>
  <td class="font-mono mv-bal" style="white-space:nowrap;">—</td>
  <td><input type="number" name="qty[]" class="form-control-custom mv-qty" step="0.01" min="0.01"></td>
  <td class="f-purchase f-opening"><input type="number" name="rate[]" class="form-control-custom mv-rate" step="0.01" min="0"></td>
  <td class="f-purchase f-opening font-mono mv-amt"></td>
  <td><button type="button" class="btn btn-sm mv-del" title="काढा" style="color:var(--rose);">✕</button></td>
</tr></template>

<style>
.mv-types{display:flex;flex-wrap:wrap;gap:8px;}
.mv-t input{display:none;}
.mv-t span{display:inline-block;padding:8px 14px;border-radius:10px;border:1.5px solid var(--line);font-size:13px;font-weight:600;cursor:pointer;}
.mv-t input:checked+span{border-color:var(--rail);background:color-mix(in srgb,var(--rail) 12%,transparent);color:var(--rail);}
.mv-rows td{vertical-align:middle;}
.mv-bal.low{color:var(--rose);font-weight:700;}
</style>
<script>
(function () {
  var BAL = <?= json_encode($balJs, JSON_UNESCAPED_UNICODE) ?>, pre = <?= $preItem ?>;
  var form = document.getElementById('mvForm'), body = document.querySelector('#mvRows tbody'), tpl = document.getElementById('mvRowTpl');
  var fmt = function (n) { return (Math.round(n * 100) / 100).toLocaleString('en-IN'); };
  function type() { var r = form.querySelector('[name=move_type]:checked'); return r ? r.value : 'purchase'; }
  function showFields() {
    var t = type();
    form.querySelectorAll('.f-purchase,.f-issue,.f-return,.f-adjust,.f-opening').forEach(function (el) {
      el.style.display = el.classList.contains('f-' + t) ? '' : 'none';
    });
    recalc();
  }
  function recalc() {
    var total = 0, t = type();
    body.querySelectorAll('tr').forEach(function (tr) {
      var id = tr.querySelector('.mv-item').value, q = parseFloat(tr.querySelector('.mv-qty').value) || 0, r = parseFloat(tr.querySelector('.mv-rate').value) || 0;
      var b = BAL[id], cell = tr.querySelector('.mv-bal');
      cell.textContent = b ? fmt(b.b) + ' ' + b.u : '—';
      cell.classList.toggle('low', !!b && (t === 'issue' || t === 'adjust') && q > b.b);
      tr.querySelector('.mv-amt').textContent = q && r ? '₹' + fmt(q * r) : '';
      total += q * r;
    });
    document.getElementById('mvTotal').textContent = total ? 'एकूण ₹' + fmt(total) : '';
  }
  function addRow(itemId) {
    var tr = tpl.content.firstElementChild.cloneNode(true);
    body.appendChild(tr);
    var sel = tr.querySelector('.mv-item');
    if (itemId) sel.value = itemId;
    sel.addEventListener('change', function () {
      var b = BAL[sel.value], rate = tr.querySelector('.mv-rate');
      if (b && b.r && !rate.value) rate.value = b.r;   // शेवटचा खरेदी दर सुचवा
      recalc();
    });
    tr.querySelectorAll('input').forEach(function (i) { i.addEventListener('input', recalc); });
    tr.querySelector('.mv-del').onclick = function () { if (body.children.length > 1) { tr.remove(); recalc(); } };
    if (window.eowmsSmartSelects) window.eowmsSmartSelects(tr);
    showFields();
    return tr;
  }
  document.getElementById('mvAdd').onclick = function () { addRow().querySelector('.mv-item').focus(); };
  form.querySelectorAll('[name=move_type]').forEach(function (r) { r.addEventListener('change', showFields); });
  addRow(pre || null); if (!pre) { addRow(); addRow(); }
  <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): /* चूक झाल्यास भरलेल्या ओळी परत */ ?>
  body.textContent = '';
  <?php foreach ((array)($_POST['item_id'] ?? []) as $k => $iid): ?>
  (function (tr) { tr.querySelector('.mv-qty').value = <?= json_encode((string)($_POST['qty'][$k] ?? '')) ?>; tr.querySelector('.mv-rate').value = <?= json_encode((string)($_POST['rate'][$k] ?? '')) ?>; })(addRow(<?= (int)$iid ?: 'null' ?>));
  <?php endforeach; ?>
  if (!body.children.length) addRow();
  <?php endif; ?>
  showFields();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
