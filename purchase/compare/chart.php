<?php
// 📊 तुलनात्मक तक्ता — नवीन / बदल: वस्तू × पुरवठादार दर-जाळी, GST / वाहतूक / इतर / सूट, लगेच हिशोब व L1, Excel मधून Paste
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/purchase-compare.php';
if (!purchaseCmpCanEdit()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">तुलनात्मक तक्ता बनवण्याची परवानगी नाही.</div>'); }
if (!purchaseCmpReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$user = currentUser();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? purchaseCmpLoad($pdo, $orgId, $id) : null;
if ($id && !$c) { header('Location: index.php'); exit; }
$error = '';

$num = function ($v) { $v = trim(str_replace([',', '₹', ' '], '', (string)$v)); return $v === '' || !is_numeric($v) ? null : (float)$v; };

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_chart') {
    $P = json_decode((string)($_POST['payload'] ?? ''), true);
    $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['cmp_date'] ?? '')) ? $_POST['cmp_date'] : date('Y-m-d');
    $vendors = []; $keepV = [];
    foreach ((array)($P['vendors'] ?? []) as $i => $v) {
        $name = trim((string)($v['name'] ?? ''));
        if ($name === '') continue;
        $keepV[] = $i;
        $vendors[] = ['name' => $name, 'gst_pct' => $num($v['gst_pct'] ?? ''), 'gst_amount' => $num($v['gst_amount'] ?? ''), 'gst_incl' => !empty($v['gst_incl']), 'gst_on_transport' => !empty($v['gst_on_transport']),
            'transport' => $num($v['transport'] ?? ''), 'transport_incl' => !empty($v['transport_incl']), 'other_label' => trim((string)($v['other_label'] ?? '')), 'other_amount' => $num($v['other_amount'] ?? ''),
            'discount' => $num($v['discount'] ?? ''), 'remarks' => trim((string)($v['remarks'] ?? ''))];
    }
    $items = [];
    foreach ((array)($P['items'] ?? []) as $it) {
        $part = trim((string)($it['particular'] ?? ''));
        if ($part === '') continue;
        $rates = []; $amts = [];
        foreach ($keepV as $ni => $oi) { $rates[$ni] = $num($it['rates'][$oi] ?? ''); $amts[$ni] = $rates[$ni] === null ? null : $num($it['amounts'][$oi] ?? ''); }
        $items[] = ['particular' => $part, 'qty' => $num($it['qty'] ?? '') ?? 1, 'unit' => trim((string)($it['unit'] ?? '')), 'rates' => $rates, 'amounts' => $amts];
    }
    if (mb_strlen($title) < 3) $error = 'तक्त्याचा विषय (काय खरेदी) लिहा.';
    elseif (!$vendors) $error = 'किमान एका पुरवठादाराचं नाव लिहा.';
    elseif (!$items) $error = 'किमान एक वस्तू लिहा.';
    if (!$error) {
        $reqId = (int)($_POST['request_id'] ?? 0);
        if ($reqId && purchaseReady($pdo)) { $st = $pdo->prepare("SELECT id FROM purchase_requests WHERE id = ? AND organization_id = ?"); $st->execute([$reqId, $orgId]); $reqId = (int)$st->fetchColumn(); }
        $status = in_array($_POST['status'] ?? '', ['draft', 'final'], true) ? $_POST['status'] : 'draft';
        $d = ['title' => $title, 'cmp_date' => $date, 'department' => mb_substr(trim((string)($_POST['department'] ?? '')), 0, 150), 'request_id' => $reqId ?: null,
            'notes' => mb_substr(trim(str_replace("\r", '', (string)($_POST['notes'] ?? ''))), 0, 3000), 'signatories' => mb_substr(trim(str_replace("\r", '', (string)($_POST['signatories'] ?? ''))), 0, 600),
            'status' => $status, 'vendors' => $vendors, 'items' => $items];
        $wasApproved = $c && $c['status'] === 'approved';
        $newId = purchaseCmpSave($pdo, $orgId, (int)$user['id'], $d, $id);
        if ($wasApproved) {
            // मंजूर तक्ता बदलला — मान्यता रद्द, पुन्हा मान्यता हवी
            $pdo->prepare("UPDATE purchase_comparisons SET selected_vendor = NULL, approved_by = NULL, approved_name = NULL, approved_at = NULL, approval_note = NULL, status = 'final' WHERE id = ?")->execute([$newId]);
        }
        logActivity($id ? 'तुलनात्मक तक्ता बदलला' : 'तुलनात्मक तक्ता बनवला', 'purchase_comparison', $newId, $title);
        $_SESSION['flash'] = '✓ तुलनात्मक तक्ता जतन झाला' . ($wasApproved ? ' — बदल झाल्यामुळे मान्यता पुन्हा घ्यावी लागेल.' : '.');
        header('Location: view.php?id=' . $newId); exit;
    }
}

// JS साठी सुरुवातीची माहिती
if ($c) {
    $state = ['vendors' => [], 'items' => []];
    foreach ($c['vendors'] as $v) $state['vendors'][] = ['name' => $v['name'], 'gst_pct' => $v['gst_pct'] === null ? '' : (float)$v['gst_pct'], 'gst_amount' => $v['gst_amount'] === null ? '' : (float)$v['gst_amount'],
        'gst_incl' => (int)$v['gst_incl'], 'gst_on_transport' => (int)$v['gst_on_transport'], 'transport' => $v['transport'] === null ? '' : (float)$v['transport'], 'transport_incl' => (int)$v['transport_incl'],
        'other_label' => (string)$v['other_label'], 'other_amount' => $v['other_amount'] === null ? '' : (float)$v['other_amount'], 'discount' => $v['discount'] === null ? '' : (float)$v['discount'], 'remarks' => (string)$v['remarks']];
    foreach ($c['items'] as $it) {
        $r = []; $a = [];
        foreach ($c['vendors'] as $v) { $x = $c['rates'][$it['id']][$v['id']] ?? null; $r[] = $x === null ? '' : $x; $a[] = $c['amounts'][$it['id']][$v['id']] ?? ''; }
        $state['items'][] = ['particular' => $it['particular'], 'qty' => $it['qty'] === null ? '' : (float)$it['qty'], 'unit' => (string)$it['unit'], 'rates' => $r, 'amounts' => $a];
    }
    $F = ['title' => $c['title'], 'cmp_date' => $c['cmp_date'], 'department' => $c['department'], 'request_id' => $c['request_id'], 'notes' => $c['notes'], 'signatories' => $c['signatories'], 'status' => $c['status'] === 'draft' ? 'draft' : 'final'];
} else {
    $blankV = fn($n) => ['name' => $n, 'gst_pct' => 18, 'gst_amount' => '', 'gst_incl' => 0, 'gst_on_transport' => 0, 'transport' => '', 'transport_incl' => 0, 'other_label' => '', 'other_amount' => '', 'discount' => '', 'remarks' => ''];
    $state = ['vendors' => [$blankV(''), $blankV(''), $blankV('')], 'items' => [['particular' => '', 'qty' => 1, 'unit' => 'Nos', 'rates' => ['', '', ''], 'amounts' => ['', '', '']]]];
    $F = ['title' => '', 'cmp_date' => date('Y-m-d'), 'department' => '', 'request_id' => null, 'notes' => '', 'signatories' => purchaseCmpDefaultSigns(), 'status' => 'draft'];
    // खरेदी बाकी मागणीवरून सुरुवात
    $rq = (int)($_GET['request'] ?? 0);
    if ($rq && purchaseReady($pdo)) {
        $st = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? AND organization_id = ?"); $st->execute([$rq, $orgId]);
        if ($R = $st->fetch()) {
            $F['title'] = $R['title']; $F['department'] = $R['department']; $F['request_id'] = $R['id'];
            $lines = array_values(array_filter(array_map('trim', explode("\n", (string)$R['items']))));
            if ($lines) {
                $state['items'] = [];
                foreach ($lines as $ln) {
                    $q = 1; $u = 'Nos'; $nm = $ln;
                    if (preg_match('/^(.*?)\s*[-–—:]\s*([\d.]+)\s*(\S*)\s*$/u', $ln, $m)) { $nm = $m[1]; $q = (float)$m[2]; $u = $m[3] !== '' ? $m[3] : 'Nos'; }
                    $state['items'][] = ['particular' => $nm, 'qty' => $q, 'unit' => $u, 'rates' => ['', '', ''], 'amounts' => ['', '', '']];
                }
            }
        }
    }
}
if (!empty($P) && $error) { $state = ['vendors' => $P['vendors'] ?? $state['vendors'], 'items' => $P['items'] ?? $state['items']]; $F = array_merge($F, array_intersect_key($_POST, $F)); }
$reqs = [];
if (purchaseReady($pdo)) { $st = $pdo->prepare("SELECT id, sr_no, fy, title FROM purchase_requests WHERE organization_id = ? AND (status = 'pending' OR id = ?) ORDER BY fy DESC, sr_no DESC LIMIT 300"); $st->execute([$orgId, (int)$F['request_id']]); $reqs = $st->fetchAll(); }
$vNames = purchaseSupplierNames($pdo, $orgId);
$IS = purchaseCmpItemSuggest($pdo, $orgId);
$depts = function_exists('purchaseDepartments') ? purchaseDepartments($pdo, $orgId) : [];

$pageTitle = $c ? 'तुलनात्मक तक्ता — बदल' : 'नवीन तुलनात्मक तक्ता';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .cg-wrap{overflow-x:auto;border:1px solid var(--line);border-radius:14px;background:var(--surface);}
  table.cg{border-collapse:separate;border-spacing:0;min-width:100%;font-size:12.5px;}
  table.cg th,table.cg td{border-bottom:1px solid var(--line);border-right:1px solid var(--line);padding:4px;vertical-align:middle;}
  table.cg th{background:color-mix(in srgb,#2563EB 7%,var(--surface));font-weight:700;text-align:center;position:sticky;top:0;}
  table.cg input,table.cg select{width:100%;border:1px solid transparent;border-radius:6px;padding:5px 6px;font:inherit;background:transparent;color:var(--text);min-width:0;}
  table.cg input:focus{border-color:#2563EB;background:var(--surface);outline:0;}
  table.cg .vh input{font-weight:700;text-align:center;border:1px dashed var(--line);}
  table.cg td.amt{text-align:right;font-weight:600;white-space:nowrap;min-width:84px;color:var(--text-dim);}
  table.cg td.amt.min{color:#15803D;background:color-mix(in srgb,#16A34A 9%,transparent);}
  table.cg td.amt.ov{color:#B45309;}
  table.cg .sr{width:34px;text-align:center;color:var(--text-dim);}
  table.cg .x{border:0;background:none;color:#DC2626;cursor:pointer;font-size:13px;padding:0 4px;}
  table.cg tr.ex td{background:color-mix(in srgb,#64748B 5%,transparent);} table.cg tr.ex td.lbl{font-weight:700;text-align:right;color:var(--text-dim);font-size:12px;}
  table.cg tr.tot td{font-weight:800;background:color-mix(in srgb,#2563EB 8%,transparent);} table.cg tr.tot td.l1{color:#fff;background:#16A34A;}
  .cg-btns{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0;}
  .cg-btns button{border:1px solid var(--line);background:var(--surface);border-radius:9px;padding:7px 12px;font-size:12.5px;font-weight:600;color:var(--text);}
  .cg-chk{display:flex;align-items:center;gap:4px;font-size:11px;color:var(--text-dim);white-space:nowrap;} .cg-chk input{width:auto !important;}
  .cg-sum{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;} .cg-sum span{border:1px solid var(--line);border-radius:10px;padding:6px 10px;font-size:12.5px;background:var(--surface);}
</style>

<div class="page-eyebrow">📊 तुलनात्मक तक्ता</div>
<div class="page-title"><?= $c ? '✎ ' . h($c['title']) : '＋ नवीन तुलनात्मक तक्ता' ?></div>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div><?php endif; ?>
<?php if ($c && $c['status'] === 'approved'): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ हा तक्ता मंजूर आहे — बदल जतन केल्यास मान्यता रद्द होईल व पुन्हा मान्यता घ्यावी लागेल.</div><?php endif; ?>

<form method="POST" id="cgForm" data-no-smart-filter>
  <input type="hidden" name="action" value="save_chart"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="payload" id="cgPayload">
  <div class="panel" style="padding:14px 16px;">
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">विषय (काय खरेदी) *</label><input type="text" name="title" class="form-control-custom" value="<?= h($F['title']) ?>" maxlength="255" required placeholder="उदा. Sports Dept — खेळ साहित्य"></div>
      <div class="col-md-2 col-6"><label class="form-label-custom">दिनांक</label><input type="date" name="cmp_date" class="form-control-custom" value="<?= h($F['cmp_date']) ?>"></div>
      <div class="col-md-4 col-6"><label class="form-label-custom">विभाग</label><input type="text" name="department" class="form-control-custom" value="<?= h($F['department']) ?>" list="cgDepts" maxlength="150"></div>
      <div class="col-md-8"><label class="form-label-custom">खरेदी बाकी मागणीशी जोडा</label><select name="request_id" class="form-select-custom"><option value="">— नाही —</option><?php foreach ($reqs as $r): ?><option value="<?= (int)$r['id'] ?>" <?= (int)$F['request_id'] === (int)$r['id'] ? 'selected' : '' ?>>अ.क्र. <?= (int)$r['sr_no'] ?> (<?= h($r['fy']) ?>) — <?= h(mb_strimwidth($r['title'], 0, 90, '…')) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label-custom">स्थिती</label><select name="status" class="form-select-custom"><option value="draft" <?= $F['status'] === 'draft' ? 'selected' : '' ?>>📝 तयार होत आहे</option><option value="final" <?= $F['status'] === 'final' ? 'selected' : '' ?>>⏳ पूर्ण — मान्यतेसाठी पाठवा</option></select></div>
    </div>
  </div>

  <div class="cg-btns">
    <button type="button" id="cgAddItem">＋ वस्तू</button>
    <button type="button" id="cgAddVendor">＋ पुरवठादार</button>
    <button type="button" id="cgPasteBtn">📋 Excel मधून Paste</button>
  </div>
  <div id="cgPaste" style="display:none;" class="panel mb-2 p-2">
    <div style="font-size:12px;color:var(--text-dim);margin-bottom:4px;">Excel मधून ओळी copy करून इथे paste करा — रकाने: <b>वस्तू, नग, Unit, पुरवठादार 1 चा दर, पुरवठादार 2 चा दर, …</b></div>
    <textarea id="cgPasteTxt" class="form-control-custom" rows="4"></textarea>
    <button type="button" id="cgPasteGo" class="btn btn-sm mt-1" style="background:#2563EB;color:#fff;border-radius:8px;">ओळी जोडा</button>
  </div>
  <div class="cg-wrap"><table class="cg" id="cg"></table></div>
  <div class="cg-sum" id="cgSum"></div>

  <div class="panel mt-3" style="padding:14px 16px;">
    <div class="row g-2">
      <div class="col-md-8"><label class="form-label-custom">टीप / निवडीचं कारण</label><textarea name="notes" class="form-control-custom" rows="3" maxlength="3000" placeholder="उदा. सदर दरपत्रकात … यांचे दर कमी असून …"><?= h($F['notes']) ?></textarea></div>
      <div class="col-md-4"><label class="form-label-custom">सही करणारे (प्रत्येक ओळ एक)</label><textarea name="signatories" class="form-control-custom" rows="3" maxlength="600"><?= h($F['signatories']) ?></textarea></div>
    </div>
    <div class="d-flex gap-2 mt-3 flex-wrap">
      <button type="submit" class="btn" style="background:linear-gradient(90deg,#1D4ED8,#2563EB);color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button>
      <a href="<?= $c ? 'view.php?id=' . (int)$c['id'] : 'index.php' ?>" class="btn" style="border:1px solid var(--line);border-radius:10px;">रद्द</a>
    </div>
  </div>
</form>
<datalist id="cgDepts"><?php foreach ($depts as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
<datalist id="cgVendors"><?php foreach ($vNames as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
<datalist id="cgItems"><?php foreach (array_slice(array_keys($IS['items']), 0, 1500) as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
<datalist id="cgUnits"><?php foreach (array_unique(array_merge(['Nos', 'Kg', 'Ltr', 'Mtr', 'Box', 'Pkt', 'Set', 'Pair', 'Sqft', 'Bundle'], $IS['units'])) as $n): ?><option value="<?= h($n) ?>"><?php endforeach; ?></datalist>
<script>
window.addEventListener('load', function () {
  var S = <?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var IU = <?= json_encode($IS['items'] ?: new stdClass(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;   // आधीच्या वस्तू → Unit
  var T = document.getElementById('cg'), SUM = document.getElementById('cgSum');
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var n = function (v) { v = String(v == null ? '' : v).replace(/[,₹\s]/g, ''); return v === '' || isNaN(v) ? null : parseFloat(v); };
  var fmt = function (v) { if (v == null) return '—'; var neg = v < 0; v = Math.abs(Math.round(v * 100) / 100); var p = v.toFixed(2).split('.'), i = p[0], l = i.slice(-3), r = i.slice(0, -3); if (r) l = r.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + l; return (neg ? '-' : '') + '₹' + l + (p[1] !== '00' ? '.' + p[1] : ''); };
  var blankV = function () { return {name: '', gst_pct: 18, gst_amount: '', gst_incl: 0, gst_on_transport: 0, transport: '', transport_incl: 0, other_label: '', other_amount: '', discount: '', remarks: ''}; };
  function render() {
    var V = S.vendors, h = '<thead><tr><th class="sr">#</th><th style="min-width:220px;">वस्तू / तपशील (Particular)</th><th style="width:80px;">नग</th><th style="width:70px;">Unit</th>';
    V.forEach(function (v, vi) { h += '<th colspan="2" class="vh" style="min-width:170px;"><div style="display:flex;gap:2px;"><input data-v="' + vi + '" data-f="name" value="' + esc(v.name) + '" placeholder="पुरवठादार ' + (vi + 1) + '" list="cgVendors"><button type="button" class="x" data-delv="' + vi + '" title="काढा">✕</button></div></th>'; });
    h += '<th></th></tr><tr><th></th><th></th><th></th><th></th>';
    V.forEach(function () { h += '<th style="font-weight:600;">दर (Rate)</th><th style="font-weight:600;">रक्कम</th>'; });
    h += '<th></th></tr></thead><tbody>';
    S.items.forEach(function (it, ii) {
      h += '<tr><td class="sr">' + (ii + 1) + '</td><td><input data-i="' + ii + '" data-f="particular" value="' + esc(it.particular) + '" placeholder="वस्तूचं नाव — टाईप करा" list="cgItems"></td><td><input data-i="' + ii + '" data-f="qty" value="' + esc(it.qty) + '" inputmode="decimal" style="text-align:right;"></td><td><input data-i="' + ii + '" data-f="unit" value="' + esc(it.unit) + '" list="cgUnits"></td>';
      V.forEach(function (v, vi) { h += '<td><input data-i="' + ii + '" data-r="' + vi + '" value="' + esc(it.rates[vi]) + '" inputmode="decimal" style="text-align:right;" placeholder="—"></td><td class="amt" id="a' + ii + '_' + vi + '"></td>'; });
      h += '<td><button type="button" class="x" data-deli="' + ii + '" title="ओळ काढा">✕</button></td></tr>';
    });
    var ex = [['एकूण (Subtotal)', null], ['GST %', 'gst_pct'], ['GST रक्कम (हाताने)', 'gst_amount'], ['वाहतूक / Courier', 'transport'], ['इतर खर्च — नाव', 'other_label'], ['इतर खर्च — रक्कम', 'other_amount'], ['सूट (Discount)', 'discount'], ['शेरा (Payment / Delivery / Warranty)', 'remarks']];
    ex.forEach(function (e) {
      h += '<tr class="ex"><td></td><td class="lbl" colspan="3">' + e[0] + '</td>';
      V.forEach(function (v, vi) {
        if (!e[1]) { h += '<td colspan="2" class="amt" id="sub' + vi + '"></td>'; return; }
        var extra = '';
        if (e[1] === 'gst_pct') extra = '<label class="cg-chk"><input type="checkbox" data-v="' + vi + '" data-f="gst_incl"' + (+v.gst_incl ? ' checked' : '') + '> दरात समाविष्ट</label><label class="cg-chk"><input type="checkbox" data-v="' + vi + '" data-f="gst_on_transport"' + (+v.gst_on_transport ? ' checked' : '') + '> वाहतुकीवरही</label>';
        if (e[1] === 'transport') extra = '<label class="cg-chk"><input type="checkbox" data-v="' + vi + '" data-f="transport_incl"' + (+v.transport_incl ? ' checked' : '') + '> समाविष्ट</label>';
        h += '<td colspan="2"><div style="display:flex;gap:4px;align-items:center;flex-wrap:wrap;"><input data-v="' + vi + '" data-f="' + e[1] + '" value="' + esc(v[e[1]]) + '"' + (e[1] === 'remarks' || e[1] === 'other_label' ? '' : ' inputmode="decimal" style="text-align:right;max-width:110px;"') + ' placeholder="' + (e[1] === 'gst_amount' ? 'आपोआप' : '') + '">' + extra + '</div></td>';
      });
      h += '<td></td></tr>';
    });
    h += '<tr class="tot"><td></td><td colspan="3" style="text-align:right;">एकूण रक्कम (Grand Total)</td>';
    V.forEach(function (v, vi) { h += '<td colspan="2" style="text-align:right;" id="t' + vi + '"></td>'; });
    h += '<td></td></tr></tbody>';
    T.innerHTML = h; calc();
  }
  function calc() {
    var V = S.vendors, tot = [], sub = [], miss = [];
    V.forEach(function (v, vi) { sub[vi] = 0; miss[vi] = 0; });
    S.items.forEach(function (it, ii) {
      var q = n(it.qty); if (q == null) q = 1; var min = null;
      V.forEach(function (v, vi) { var r = n(it.rates[vi]); if (r != null && (min == null || r < min)) min = r; });
      V.forEach(function (v, vi) {
        var r = n(it.rates[vi]), cell = document.getElementById('a' + ii + '_' + vi); if (!cell) return;
        var ov = n(it.amounts && it.amounts[vi]), a = r == null ? null : (ov != null ? ov : r * q);
        if (r == null) miss[vi]++; else sub[vi] += a;
        cell.textContent = a == null ? '—' : fmt(a); cell.className = 'amt' + (r != null && r === min && S.vendors.length > 1 ? ' min' : '') + (ov != null ? ' ov' : '');
        cell.title = ov != null ? 'Excel मधील रक्कम (दर × नग पेक्षा वेगळी)' : '';
      });
    });
    var best = null;
    V.forEach(function (v, vi) {
      var tr = +v.transport_incl ? 0 : (n(v.transport) || 0), oth = n(v.other_amount) || 0, gst;
      if (+v.gst_incl) gst = 0; else if (n(v.gst_amount) != null) gst = n(v.gst_amount); else gst = (sub[vi] + (+v.gst_on_transport ? tr : 0)) * (n(v.gst_pct) || 0) / 100;
      tot[vi] = sub[vi] + gst + tr + oth - Math.abs(n(v.discount) || 0);
      var s = document.getElementById('sub' + vi); if (s) s.textContent = fmt(sub[vi]) + (miss[vi] ? '  ⚠ ' + miss[vi] + ' दर नाहीत' : '');
      if (!miss[vi] && sub[vi] > 0 && (best == null || tot[vi] < tot[best])) best = vi;
    });
    V.forEach(function (v, vi) { var t = document.getElementById('t' + vi); if (t) { t.textContent = fmt(tot[vi]) + (vi === best ? '  🏆 L1' : ''); t.className = vi === best ? 'l1' : ''; } });
    var order = V.map(function (v, i) { return i; }).filter(function (i) { return sub[i] > 0; }).sort(function (a, b) { return (miss[a] > 0) - (miss[b] > 0) || tot[a] - tot[b]; });
    SUM.innerHTML = order.map(function (i, k) { return '<span><b>L' + (k + 1) + '</b> ' + esc(V[i].name || ('पुरवठादार ' + (i + 1))) + ' — ' + fmt(tot[i]) + (miss[i] ? ' ⚠' : '') + '</span>'; }).join('') + (function () { var full = order.filter(function (i) { return !miss[i]; }); return full.length > 1 ? '<span>L1 व सर्वात महाग (पूर्ण दरपत्रक) यांतील फरक: <b>' + fmt(tot[full[full.length - 1]] - tot[full[0]]) + '</b></span>' : ''; })();
  }
  T.addEventListener('input', function (e) {
    var el = e.target, d = el.dataset;
    if (d.r !== undefined) { S.items[+d.i].rates[+d.r] = el.value; if (S.items[+d.i].amounts) S.items[+d.i].amounts[+d.r] = ''; }
    else if (d.i !== undefined) {
      S.items[+d.i][d.f] = el.value; if (d.f === 'qty' && S.items[+d.i].amounts) S.items[+d.i].amounts = S.items[+d.i].amounts.map(function () { return ''; });
      // आधीची वस्तू निवडली → Unit आपोआप
      if (d.f === 'particular' && IU[el.value] && (!S.items[+d.i].unit || S.items[+d.i].unit === 'Nos')) { S.items[+d.i].unit = IU[el.value]; var u = T.querySelector('input[data-i="' + d.i + '"][data-f="unit"]'); if (u) u.value = IU[el.value]; }
    }
    else if (d.v !== undefined) { S.vendors[+d.v][d.f] = el.type === 'checkbox' ? (el.checked ? 1 : 0) : el.value; }
    calc();
  });
  T.addEventListener('change', function (e) { if (e.target.type === 'checkbox') { var d = e.target.dataset; S.vendors[+d.v][d.f] = e.target.checked ? 1 : 0; calc(); } });
  T.addEventListener('click', function (e) {
    var d = e.target.dataset;
    if (d.deli !== undefined) { if (S.items.length > 1) { S.items.splice(+d.deli, 1); render(); } }
    if (d.delv !== undefined) { if (S.vendors.length > 1 && confirm('हा पुरवठादार व त्याचे सगळे दर काढायचे?')) { S.vendors.splice(+d.delv, 1); S.items.forEach(function (it) { it.rates.splice(+d.delv, 1); if (it.amounts) it.amounts.splice(+d.delv, 1); }); render(); } }
  });
  document.getElementById('cgAddItem').onclick = function () { S.items.push({particular: '', qty: 1, unit: 'Nos', rates: S.vendors.map(function () { return ''; }), amounts: S.vendors.map(function () { return ''; })}); render(); var ins = T.querySelectorAll('input[data-f="particular"]'); ins[ins.length - 1].focus(); };
  document.getElementById('cgAddVendor').onclick = function () { S.vendors.push(blankV()); S.items.forEach(function (it) { it.rates.push(''); if (it.amounts) it.amounts.push(''); }); render(); };
  document.getElementById('cgPasteBtn').onclick = function () { var p = document.getElementById('cgPaste'); p.style.display = p.style.display === 'none' ? 'block' : 'none'; };
  document.getElementById('cgPasteGo').onclick = function () {
    var lines = document.getElementById('cgPasteTxt').value.split(/\r?\n/).filter(function (l) { return l.trim() !== ''; });
    if (S.items.length === 1 && !S.items[0].particular) S.items = [];
    lines.forEach(function (l) {
      var c = l.split('\t'), rates = S.vendors.map(function (v, vi) { return (c[3 + vi] || '').trim(); });
      S.items.push({particular: (c[0] || '').trim(), qty: (c[1] || '1').trim(), unit: (c[2] || '').trim(), rates: rates, amounts: S.vendors.map(function () { return ''; })});
    });
    document.getElementById('cgPasteTxt').value = ''; document.getElementById('cgPaste').style.display = 'none'; render();
  };
  document.getElementById('cgForm').addEventListener('submit', function () { document.getElementById('cgPayload').value = JSON.stringify(S); });
  render();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
