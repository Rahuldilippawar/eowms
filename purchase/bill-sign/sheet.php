<?php
// ✍️ बिल परवानगी पत्रक — नवीन / बदल: Sheet No. आपोआप, बिलं (उप-ओळींसह), टिप्पणी क्र. आपोआप पुढचा, पार्टी आठवण, थेट एकूण व अक्षरी रक्कम
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/bill-sign.php';
if (!bsCan()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बिल परवानगी पत्रक बनवण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!bsReady($pdo)) { header('Location: index.php'); exit; }
$SET = bsSettings($pdo, $orgId);
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$s = $id ? bsLoad($pdo, $orgId, $id) : null;
if ($id && (!$s || !in_array($s['status'], ['draft', 'submitted'], true))) { $_SESSION['flash'] = '⚠️ हे पत्रक आता बदलता येत नाही (परवानगी / लेखा विभाग झाले).'; header('Location: ' . ($s ? 'view.php?id=' . $id : 'index.php')); exit; }
$carry = !$s ? bsLoad($pdo, $orgId, (int)($_GET['carry'] ?? $_POST['carry'] ?? 0)) : null;
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_sheet') {
    $date = (string)($_POST['sheet_date'] ?? '');
    $no = trim((string)($_POST['sheet_no'] ?? ''));
    $items = []; $P = fn($k) => (array)($_POST[$k] ?? []);
    $n = count($P('r_party'));
    for ($i = 0; $i < $n; $i++) {
        $g = fn($k, $len = 250) => mb_substr(trim((string)($P($k)[$i] ?? '')), 0, $len);
        $amt = str_replace([',', '₹', ' '], '', (string)($P('r_amt')[$i] ?? ''));
        $row = [(int)($P('r_sub')[$i] ?? 0), $g('r_type', 20), $g('r_tip', 40), $g('r_party'), $g('r_part'), $g('r_paid', 120), $g('r_billno', 120), $g('r_billdt', 60), is_numeric($amt) ? (float)$amt : '', $g('r_inw', 60), $g('r_mat', 60), $g('r_ret', 60), $g('r_rem'), $g('r_st', 10) ?: 'pending'];
        if ($row[3] === '' && $row[4] === '' && $row[6] === '' && $row[8] === '') continue;
        $items[] = $row;
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $err = 'पत्रकाचा दिनांक निवडा.';
    elseif ($no !== '' && !ctype_digit($no)) $err = 'Sheet No. फक्त आकडे.';
    elseif (!$items) $err = 'किमान एक बिल भरा (पार्टी / तपशील / रक्कम).';
    else {
        $fy = purchaseFy($date);
        $d = ['fy' => $fy, 'sheet_no' => $no === '' ? null : (int)$no, 'sheet_date' => $date, 'note' => mb_substr(trim((string)($_POST['note'] ?? '')), 0, 500) ?: null,
            'submitted_to' => mb_substr(trim((string)($_POST['submitted_to'] ?? '')), 0, 150) ?: null];
        if ($no !== '') {
            $st = $pdo->prepare("SELECT id FROM bill_sign_sheets WHERE organization_id = ? AND fy = ? AND sheet_no = ? AND id <> ?"); $st->execute([$orgId, $fy, (int)$no, $id]);
            if ($st->fetchColumn()) $err = 'Sheet No. ' . (int)$no . ' (आ.व. ' . $fy . ') आधीच आहे — दुसरा क्रमांक द्या किंवा रिकामा ठेवा (आपोआप पुढचा).';
        }
        if (!$err) {
            if (!$s) { $d['status'] = 'draft'; if ($no === '') unset($d['sheet_no']); }
            elseif ($no === '') $d['sheet_no'] = (int)$s['sheet_no'];
            $newId = bsSave($pdo, $orgId, (int)$user['id'], $d, $items, $s ? (int)$s['id'] : 0);
            $ns = bsLoad($pdo, $orgId, $newId);
            bsLog($pdo, $orgId, $newId, $s ? 'edited' : 'created', ($s ? 'बदल' : 'नवीन पत्रक') . ' — ' . count(bsGroups($ns['_items'])) . ' बिलं, ' . purchaseInr($ns['total_amount']), (int)$user['id']);
            if ($carry) {
                $ids = array_map('intval', (array)($_POST['carry_ids'] ?? []));
                if ($ids) {
                    $pdo->prepare("UPDATE bill_sign_items SET decision_note = ? WHERE sheet_id = ? AND organization_id = ? AND id IN (" . implode(',', $ids) . ")")->execute(['↪ Sheet ' . bsSheetLabel($ns) . ' मध्ये पुढे पाठवले', $carry['id'], $orgId]);
                    bsLog($pdo, $orgId, $carry['id'], 'carried', '↪ थांबलेली बिलं Sheet No. ' . bsSheetLabel($ns) . ' मध्ये', (int)$user['id']);
                }
            }
            logActivity('बिल परवानगी पत्रक — ' . ($s ? 'बदल' : 'नवीन'), 'bill_sign_sheet', $newId, 'Sheet ' . bsSheetLabel($ns) . ' · ' . purchaseInr($ns['total_amount']));
            if (($_POST['go'] ?? '') === 'submit' && $ns['status'] === 'draft') { bsSetStatus($pdo, $orgId, $ns, 'submit', (int)$user['id'], (string)($d['submitted_to'] ?? '')); $_SESSION['flash'] = '✓ Sheet No. ' . bsSheetLabel($ns) . ' जतन होऊन सहीसाठी जमा झाले.'; }
            else $_SESSION['flash'] = '✓ Sheet No. ' . bsSheetLabel($ns) . ' जतन झाले.';
            header('Location: view.php?id=' . $newId); exit;
        }
    }
}

// ---------- फॉर्मचा data ----------
$curFy = purchaseFy();
$rows = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $P = fn($k) => (array)($_POST[$k] ?? []);
    foreach ($P('r_party') as $i => $_) $rows[] = ['is_sub' => (int)($P('r_sub')[$i] ?? 0), 'doc_type' => $P('r_type')[$i] ?? '', 'tippani_no' => $P('r_tip')[$i] ?? '', 'party' => $P('r_party')[$i] ?? '', 'particular' => $P('r_part')[$i] ?? '', 'paid_by' => $P('r_paid')[$i] ?? '',
        'bill_no' => $P('r_billno')[$i] ?? '', 'bill_date' => $P('r_billdt')[$i] ?? '', 'amount' => $P('r_amt')[$i] ?? '', 'inward_date' => $P('r_inw')[$i] ?? '', 'material_date' => $P('r_mat')[$i] ?? '', 'return_date' => $P('r_ret')[$i] ?? '', 'remark' => $P('r_rem')[$i] ?? '', 'status' => $P('r_st')[$i] ?? 'pending'];
} elseif ($s) $rows = $s['_items'];
elseif ($carry) {
    foreach (bsGroups($carry['_items']) as $g) if (in_array(bsGroupStatus($g), ['hold', 'rejected'], true) && strpos((string)$g[0]['decision_note'], '↪') !== 0) foreach ($g as $r) { $r['status'] = 'pending'; $r['return_date'] = ''; $r['_carry'] = $r['id']; $rows[] = $r; }
}
$fv = [
    'sheet_no' => $_POST['sheet_no'] ?? ($s ? ((int)$s['sheet_no'] ?: '') : ''),
    'sheet_date' => $_POST['sheet_date'] ?? ($s['sheet_date'] ?? date('Y-m-d')),
    'note' => $_POST['note'] ?? ($s['note'] ?? ($carry ? 'Sheet No. ' . bsSheetLabel($carry) . ' मधील थांबलेली बिलं' : '')),
    'submitted_to' => $_POST['submitted_to'] ?? ($s['submitted_to'] ?? $SET['submit_to']),
];
$nextNo = bsNextNo($pdo, $orgId, $curFy);
$nextTip = bsNextTippani($pdo, $orgId);
// पार्टी आठवण — शेवटचा तपशील, बिल भरणारा, प्रकार
$mem = [];
$st = $pdo->prepare("SELECT party, particular, paid_by, doc_type FROM bill_sign_items WHERE organization_id = ? AND party IS NOT NULL AND is_sub = 0 ORDER BY id DESC LIMIT 2000"); $st->execute([$orgId]);
foreach ($st->fetchAll() as $r) { $k = mb_strtolower(trim($r['party'])); if (!isset($mem[$k])) $mem[$k] = ['n' => trim($r['party']), 'p' => (string)$r['particular'], 'b' => (string)$r['paid_by'], 't' => (string)$r['doc_type']]; }
$st = $pdo->prepare("SELECT DISTINCT paid_by FROM bill_sign_items WHERE organization_id = ? AND paid_by IS NOT NULL ORDER BY paid_by LIMIT 300"); $st->execute([$orgId]); $payers = $st->fetchAll(PDO::FETCH_COLUMN);
$st = $pdo->prepare("SELECT DISTINCT particular FROM bill_sign_items WHERE organization_id = ? AND particular IS NOT NULL ORDER BY particular LIMIT 400"); $st->execute([$orgId]); $parts = $st->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = $s ? 'Sheet No. ' . bsSheetLabel($s) . ' — बदल' : 'नवीन बिल परवानगी पत्रक';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
$rowHtml = function ($r = []) {
    $v = fn($k) => h((string)($r[$k] ?? ''));
    $amt = isset($r['amount']) && $r['amount'] !== '' && $r['amount'] !== null ? rtrim(rtrim(number_format((float)$r['amount'], 2, '.', ''), '0'), '.') : '';
    ob_start(); ?>
  <div class="bf-row<?= !empty($r['is_sub']) ? ' sub' : '' ?>">
    <input type="hidden" name="r_sub[]" value="<?= !empty($r['is_sub']) ? 1 : 0 ?>"><input type="hidden" name="r_st[]" value="<?= $v('status') ?: 'pending' ?>"><input type="hidden" name="r_ret[]" value="<?= $v('return_date') ?>">
    <?php if (!empty($r['_carry'])): ?><input type="hidden" name="carry_ids[]" value="<?= (int)$r['_carry'] ?>"><?php endif; ?>
    <div class="bf-sr"><b></b><small class="bf-sl">उप-ओळ</small></div>
    <div class="bf-grid">
      <label class="c-type"><span>PO / Bill</span><input name="r_type[]" list="bfTypes" value="<?= $v('doc_type') ?>" placeholder="Bill"></label>
      <label class="c-tip"><span>टिप्पणी क्र.</span><input name="r_tip[]" value="<?= $v('tippani_no') ?>" inputmode="numeric"></label>
      <label class="c-party"><span>पार्टीचे नाव</span><input name="r_party[]" list="bfParties" value="<?= $v('party') ?>" autocomplete="off"></label>
      <label class="c-part"><span>तपशील (Particular)</span><input name="r_part[]" list="bfParts" value="<?= $v('particular') ?>" autocomplete="off"></label>
      <label class="c-paid"><span>बिल भरणारा</span><input name="r_paid[]" list="bfPayers" value="<?= $v('paid_by') ?>" autocomplete="off"></label>
      <label class="c-bno"><span>बिल क्र.</span><input name="r_billno[]" value="<?= $v('bill_no') ?>"></label>
      <label class="c-bdt"><span>बिल दिनांक</span><input name="r_billdt[]" class="bf-dt" value="<?= $v('bill_date') ?>" placeholder="dd.mm.yyyy"></label>
      <label class="c-amt"><span>रक्कम ₹</span><input name="r_amt[]" class="bf-amt" value="<?= h($amt) ?>" inputmode="decimal"></label>
      <label class="c-inw"><span>बिल आवक दि.</span><input name="r_inw[]" class="bf-dt" value="<?= $v('inward_date') ?>" placeholder="dd.mm.yyyy"></label>
      <label class="c-mat"><span>माल मिळाला दि.</span><input name="r_mat[]" class="bf-dt" value="<?= $v('material_date') ?>" placeholder="dd.mm.yyyy"></label>
      <label class="c-rem"><span>शेरा</span><input name="r_rem[]" value="<?= $v('remark') ?>"></label>
    </div>
    <div class="bf-acts"><button type="button" class="bf-addsub" title="याच बिलाखाली आणखी बिल क्र. / रक्कम">＋ उप-ओळ</button><button type="button" class="bf-up" title="वर">↑</button><button type="button" class="bf-del" title="काढा">✕</button></div>
  </div>
<?php return ob_get_clean(); };
?>
<style>
  .bf-head{display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:12px;} .bf-head h1{font-size:20px;font-weight:900;margin:0;}
  .bf-card{border:1px solid var(--line);border-radius:16px;background:var(--surface);padding:14px 16px;margin-bottom:12px;}
  .bf-top{display:grid;grid-template-columns:140px 170px minmax(0,1fr) minmax(0,1fr);gap:10px;}
  .bf-top label span,.bf-grid label span{display:block;font-size:11px;font-weight:800;color:var(--text-dim);margin-bottom:3px;letter-spacing:.2px;}
  .bf-top input,.bf-grid input{width:100%;border:1px solid var(--line);border-radius:9px;padding:7px 9px;font:inherit;font-size:13px;background:var(--canvas);color:var(--text);min-width:0;}
  .bf-top input:focus,.bf-grid input:focus{outline:2px solid #818CF8;background:var(--surface);}
  .bf-no input{font-weight:900;font-size:16px;color:#4338CA;}
  .bf-row{display:grid;grid-template-columns:44px minmax(0,1fr) auto;gap:10px;align-items:start;border:1px solid var(--line);border-left:4px solid #4338CA;border-radius:12px;padding:10px;margin-bottom:8px;background:var(--surface);}
  .bf-row.sub{margin-left:44px;border-left-color:#A5B4FC;border-style:dashed;background:color-mix(in srgb,#4338CA 3%,var(--surface));}
  .bf-sr{text-align:center;padding-top:16px;} .bf-sr b{display:inline-grid;place-items:center;width:32px;height:32px;border-radius:50%;background:#4338CA;color:#fff;font-weight:900;font-size:14px;} .bf-sl{display:none;font-size:10px;font-weight:800;color:#6366F1;}
  .bf-row.sub .bf-sr b{display:none;} .bf-row.sub .bf-sl{display:block;padding-top:4px;}
  .bf-grid{display:grid;grid-template-columns:90px 90px minmax(0,2fr) minmax(0,1.6fr) minmax(0,1.2fr) minmax(0,1fr);gap:7px;}
  .bf-grid .c-bdt{grid-column:span 2;}
  .bf-grid .c-amt input{font-weight:800;text-align:right;}
  .bf-acts{display:flex;flex-direction:column;gap:4px;padding-top:16px;} .bf-acts button{border:1px solid var(--line);background:var(--surface);border-radius:8px;font-size:11.5px;font-weight:700;padding:4px 8px;white-space:nowrap;color:var(--text);} .bf-acts .bf-del{color:#B91C1C;}
  .bf-row.sub .bf-addsub{visibility:hidden;}
  .bf-add{display:flex;gap:8px;flex-wrap:wrap;margin:4px 0 2px;} .bf-add button{border:2px dashed #818CF8;background:color-mix(in srgb,#4338CA 5%,var(--surface));color:#4338CA;border-radius:11px;padding:9px 16px;font-weight:800;font-size:13px;}
  .bf-sum{position:sticky;bottom:0;z-index:20;display:flex;gap:14px;align-items:center;justify-content:space-between;flex-wrap:wrap;border:1px solid var(--line);border-radius:16px;background:var(--surface);padding:12px 16px;box-shadow:0 -10px 30px -20px rgba(15,23,42,.5);margin-top:12px;}
  .bf-sum .tot{font-size:22px;font-weight:900;color:#4338CA;font-variant-numeric:tabular-nums;} .bf-sum small{display:block;font-size:11.5px;color:var(--text-dim);font-weight:600;}
  .bf-sum .btns{display:flex;gap:8px;flex-wrap:wrap;} .bf-sum button{border-radius:11px;font-weight:800;padding:10px 18px;font-size:14px;border:1px solid var(--line);background:var(--surface);color:var(--text);} .bf-sum .go{background:#F59E0B;border-color:#F59E0B;color:#fff;} .bf-sum .sv{background:#4338CA;border-color:#4338CA;color:#fff;}
  .bf-hint{font-size:12px;color:var(--text-dim);margin:2px 2px 10px;}
  @media (max-width:1100px){ .bf-grid{grid-template-columns:repeat(4,minmax(0,1fr));} .bf-grid .c-party,.bf-grid .c-part{grid-column:span 2;} .bf-grid .c-bdt{grid-column:auto;} }
  @media (max-width:760px){ .bf-top{grid-template-columns:1fr 1fr;} .bf-top .w{grid-column:1/-1;} .bf-row{grid-template-columns:minmax(0,1fr);padding:10px;} .bf-row.sub{margin-left:16px;} .bf-sr{padding-top:0;text-align:left;} .bf-acts{flex-direction:row;padding-top:0;}
    .bf-grid{grid-template-columns:1fr 1fr;} .bf-grid .c-bdt{grid-column:auto;} .bf-grid .c-party,.bf-grid .c-part{grid-column:1/-1;} .bf-sum{bottom:58px;} .bf-sum .btns{width:100%;} .bf-sum .btns button{flex:1;} }
</style>

<div class="bf-head">
  <h1>✍️ <?= $s ? 'Sheet No. ' . h(bsSheetLabel($s)) . ' — बदल' : 'नवीन बिल परवानगी पत्रक' ?></h1>
  <a href="<?= $s ? 'view.php?id=' . (int)$s['id'] : 'index.php' ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;">← परत</a>
</div>
<?php if ($err): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ <?= h($err) ?></div><?php endif; ?>
<?php if ($carry): ?><div class="alert alert-info" style="border-radius:10px;font-size:13px;">↪ Sheet No. <?= h(bsSheetLabel($carry)) ?> मधील थांबलेली / नाकारलेली <?= count(array_filter($rows, fn($r) => empty($r['is_sub']))) ?> बिलं या नवीन पत्रकात घेतली आहेत — हवे ते बदला, नवीन जोडा.</div><?php endif; ?>

<form method="POST" id="bfForm" data-no-smart-filter autocomplete="off">
  <input type="hidden" name="action" value="save_sheet"><input type="hidden" name="id" value="<?= $s ? (int)$s['id'] : 0 ?>"><?php if ($carry): ?><input type="hidden" name="carry" value="<?= (int)$carry['id'] ?>"><?php endif; ?><input type="hidden" name="go" id="bfGo" value="">
  <div class="bf-card">
    <div class="bf-top">
      <label class="bf-no"><span>🔢 Sheet No.</span><input name="sheet_no" value="<?= h((string)$fv['sheet_no']) ?>" placeholder="<?= (int)$nextNo ?> (आपोआप)" inputmode="numeric"></label>
      <label><span>📅 दिनांक</span><input type="date" name="sheet_date" value="<?= h($fv['sheet_date']) ?>" required></label>
      <label><span>✍️ सहीसाठी कोणाकडे</span><input name="submitted_to" value="<?= h($fv['submitted_to']) ?>"></label>
      <label class="w"><span>📝 टीप (ऐच्छिक)</span><input name="note" value="<?= h($fv['note']) ?>" placeholder="उदा. भोजनालय महिना अखेर बिलं"></label>
    </div>
  </div>
  <div class="bf-hint">💡 टिप्पणी क्र. आपोआप पुढचा येतो · पार्टी निवडली की मागचा तपशील / बिल भरणारा आपोआप · एकाच पार्टीची अनेक बिलं असतील तर "＋ उप-ओळ" · दिनांक 19112026 असे टाइप केले तरी 19.11.2026 होते.</div>
  <div id="bfRows"><?php foreach ($rows ?: [[]] as $r) echo $rowHtml($r); ?></div>
  <div class="bf-add"><button type="button" id="bfAdd">＋ नवीन बिल</button><button type="button" id="bfAdd5">＋ 5 बिलं</button></div>

  <div class="bf-sum">
    <div><div class="tot" id="bfTot">₹0</div><small id="bfCnt">0 बिलं</small><small id="bfWords" style="max-width:520px;"></small></div>
    <div class="btns">
      <button type="submit" class="sv">✓ जतन करा</button>
      <?php if (!$s || $s['status'] === 'draft'): ?><button type="submit" class="go" onclick="document.getElementById('bfGo').value='submit';">📤 जतन करून सहीसाठी जमा</button><?php endif; ?>
    </div>
  </div>
</form>
<template id="bfTpl"><?= $rowHtml([]) ?></template>
<datalist id="bfTypes"><?php foreach (bsDocTypes() as $t): ?><option value="<?= h($t) ?>"><?php endforeach; ?></datalist>
<datalist id="bfParties"><?php foreach ($mem as $m): ?><option value="<?= h($m['n']) ?>"><?php endforeach; ?></datalist>
<datalist id="bfPayers"><?php foreach ($payers as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
<datalist id="bfParts"><?php foreach ($parts as $p): ?><option value="<?= h($p) ?>"><?php endforeach; ?></datalist>
<script>
(function () {
  const MEM = <?= json_encode($mem, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, NEXT_TIP = <?= json_encode((string)$nextTip) ?>, TODAY = <?= json_encode(date('d.m.Y')) ?>;
  const box = document.getElementById('bfRows'), tpl = document.getElementById('bfTpl');
  const rows = () => [...box.querySelectorAll('.bf-row')];
  const q = (r, n) => r.querySelector('[name="' + n + '[]"]');
  function words(n) {
    n = Math.round(n * 100) / 100; const r = Math.floor(n), p = Math.round((n - r) * 100);
    const o = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'], t = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    const two = x => x < 20 ? o[x] : (t[Math.floor(x / 10)] + ' ' + o[x % 10]).trim(), three = x => ((x >= 100 ? o[Math.floor(x / 100)] + ' Hundred ' : '') + two(x % 100)).trim();
    const w = x => { const a = []; const cr = Math.floor(x / 1e7); x %= 1e7; if (cr) a.push((cr > 99 ? w(cr) : two(cr)) + ' Crore'); [[1e5, 'Lakh'], [1e3, 'Thousand']].forEach(([d, l]) => { const y = Math.floor(x / d); x %= d; if (y) a.push(two(y) + ' ' + l); }); if (x) a.push(three(x)); return a.join(' '); };
    return (r ? w(r) + ' Rupees' : 'Zero Rupees') + (p ? ' and ' + two(p) + ' Paise' : '') + ' Only';
  }
  const inr = n => '₹' + n.toLocaleString('en-IN', { maximumFractionDigits: 2 });
  function refresh() {
    let sr = 0, tot = 0;
    rows().forEach((r, i) => {
      const sub = q(r, 'r_sub').value === '1' && i > 0; if (!sub) { sr++; if (q(r, 'r_sub').value === '1') { q(r, 'r_sub').value = '0'; r.classList.remove('sub'); } }
      r.querySelector('.bf-sr b').textContent = sr;
      const a = parseFloat((q(r, 'r_amt').value || '').replace(/[,₹\s]/g, '')); if (!isNaN(a)) tot += a;
    });
    document.getElementById('bfTot').textContent = inr(tot);
    document.getElementById('bfCnt').textContent = sr + ' बिलं · ' + rows().length + ' ओळी';
    document.getElementById('bfWords').textContent = tot ? '(In Words: ' + words(tot) + ')' : '';
  }
  function nextTip() {
    let mx = 0; rows().forEach(r => { const v = q(r, 'r_tip').value.trim(); if (/^\d+$/.test(v)) mx = Math.max(mx, +v); });
    return mx ? String(mx + 1) : NEXT_TIP;
  }
  function add(after, sub) {
    const n = tpl.content.firstElementChild.cloneNode(true);
    if (sub) { n.classList.add('sub'); q(n, 'r_sub').value = '1'; const src = after; ['r_party', 'r_part', 'r_paid', 'r_type', 'r_inw', 'r_mat'].forEach(k => q(n, k).value = q(src, k).value); }
    else { q(n, 'r_tip').value = nextTip(); q(n, 'r_inw').value = TODAY; const last = rows().at(-1); if (last) q(n, 'r_type').value = q(last, 'r_type').value; }
    if (after) { let a = after; if (sub) { while (a.nextElementSibling && a.nextElementSibling.classList.contains('sub')) a = a.nextElementSibling; } a.after(n); } else box.appendChild(n);
    refresh(); return n;
  }
  document.getElementById('bfAdd').onclick = () => { const n = add(null, false); q(n, 'r_party').focus(); };
  document.getElementById('bfAdd5').onclick = () => { for (let i = 0; i < 5; i++) add(null, false); };
  box.addEventListener('click', e => {
    const r = e.target.closest('.bf-row'); if (!r) return;
    if (e.target.closest('.bf-addsub')) { const n = add(r, true); q(n, 'r_billno').focus(); }
    if (e.target.closest('.bf-del')) { if (rows().length > 1) { if (!r.classList.contains('sub')) { let x = r.nextElementSibling; while (x && x.classList.contains('sub')) { const y = x.nextElementSibling; x.remove(); x = y; } } r.remove(); } else r.querySelectorAll('input:not([type=hidden])').forEach(i => i.value = ''); refresh(); }
    if (e.target.closest('.bf-up')) { const p = r.previousElementSibling; if (p) { p.before(r); refresh(); } }
  });
  box.addEventListener('input', e => { if (e.target.matches('.bf-amt')) refresh(); });
  box.addEventListener('change', e => {
    const t = e.target, r = t.closest('.bf-row');
    if (t.name === 'r_party[]') { const m = MEM[t.value.trim().toLowerCase()]; if (m) { if (m.n) t.value = m.n; if (!q(r, 'r_part').value) q(r, 'r_part').value = m.p; if (!q(r, 'r_paid').value) q(r, 'r_paid').value = m.b; if (!q(r, 'r_type').value && m.t) q(r, 'r_type').value = m.t; } }
    if (t.matches('.bf-dt')) { const v = t.value.trim(); let m;
      if ((m = v.match(/^(\d{2})(\d{2})(\d{4})$/))) t.value = m[1] + '.' + m[2] + '.' + m[3];
      else if ((m = v.match(/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{2,4})$/))) t.value = m[1].padStart(2, '0') + '.' + m[2].padStart(2, '0') + '.' + (m[3].length === 2 ? '20' + m[3] : m[3]); }
    if (t.matches('.bf-amt')) { const a = parseFloat(t.value.replace(/[,₹\s]/g, '')); if (!isNaN(a)) t.value = String(Math.round(a * 100) / 100); refresh(); }
  });
  // पहिली रिकामी ओळ — टिप्पणी क्र. व आवक दिनांक भरून
  rows().forEach(r => { if (!q(r, 'r_party').value && !q(r, 'r_tip').value && !q(r, 'r_amt').value) { q(r, 'r_tip').value = nextTip(); if (!q(r, 'r_inw').value) q(r, 'r_inw').value = TODAY; } });
  refresh();
})();
</script>
<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
