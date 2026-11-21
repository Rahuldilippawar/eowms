<?php
// 🧾 वर्क ऑर्डर — नवीन / बदल / याच वरून नवीन. पुढचा सलग W.O. क्र. आपोआप (जतन करताना), पुरवठादाराचा पत्ता लक्षात,
// नग × दर = रक्कम, GST %, Round off, शब्दांत रक्कम, अटी (YES / NO), Payment, सह्या — विभागानुसार आपोआप.
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/work-order.php';
if (!woCanCreate()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">वर्क ऑर्डर बनवण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!woReady($pdo)) { header('Location: index.php'); exit; }
$SET = woSettings($pdo, $orgId);
$canAll = woCanAll();
$ST = woStatuses();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$row = $id ? woLoad($pdo, $orgId, $id) : null;
if ($id && (!$row || !woCanModify($row))) { header('Location: index.php'); exit; }
$copy = (!$row && !empty($_GET['copy'])) ? woLoad($pdo, $orgId, (int)$_GET['copy']) : null;
if ($copy && !woCanSee($copy)) $copy = null;

// विभाग — केंद्रीय: सगळे; विभाग: फक्त स्वतःचे
$st = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local, ''), name) nm, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY nm"); $st->execute([$orgId]);
$allDepts = []; foreach ($st->fetchAll() as $d) $allDepts[(int)$d['id']] = $d;
$depts = $canAll ? $allDepts : array_intersect_key($allDepts, array_flip(woMyDepts()));

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_wo') {
    $t = fn($k, $m = 300) => mb_substr(trim(str_replace("\r", '', (string)($_POST[$k] ?? ''))), 0, $m);
    $num = fn($v) => ($v = trim(str_replace([',', '₹', ' '], '', (string)$v))) === '' || !is_numeric($v) ? null : round((float)$v, 2);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['wo_date'] ?? '')) ? $_POST['wo_date'] : date('Y-m-d');
    $did = (int)($_POST['department_id'] ?? 0);
    if (!isset($depts[$did])) $did = $canAll ? 0 : (int)(array_key_first($depts) ?? 0);
    $items = [];
    foreach ((array)($_POST['it_part'] ?? []) as $i => $p) {
        $p = mb_substr(trim(str_replace("\r", '', (string)$p)), 0, 2000);
        $q = mb_substr(trim((string)($_POST['it_qty'][$i] ?? '')), 0, 60); $u = mb_substr(trim((string)($_POST['it_unit'][$i] ?? '')), 0, 40);
        $r = $num($_POST['it_rate'][$i] ?? ''); $a = $num($_POST['it_amt'][$i] ?? '');
        if ($a === null && $r !== null && ($qn = woQtyNum($q)) !== null) $a = round($qn * $r, 2);
        if ($p === '' && $a === null) continue;
        $items[] = [$p, $q, $u, $r, $a];
    }
    $sub = round(array_sum(array_map(fn($x) => (float)$x[4], $items)), 2);
    $gstPct = (float)($num($_POST['gst_pct'] ?? 0) ?? 0);
    $gst = $num($_POST['gst_amount'] ?? '') ?? round($sub * $gstPct / 100, 2);
    $ro = !empty($_POST['round']) ? round(round($sub + $gst) - ($sub + $gst), 2) : 0.0;
    $deptName = $t('department_name', 150) ?: ($did ? ($allDepts[$did]['name'] ?? '') : '');
    $terms = [];
    foreach ((array)($_POST['tm_text'] ?? []) as $i => $tx) { $tx = mb_substr(trim(str_replace("\r", '', (string)$tx)), 0, 1500); if ($tx === '') continue; $fl = (string)($_POST['tm_flag'][$i] ?? ''); $terms[] = [$tx, in_array($fl, ['YES', 'NO'], true) ? $fl : '']; }
    $signs = array_values(array_filter(array_map(fn($x) => mb_substr(trim((string)$x), 0, 120), (array)($_POST['sg'] ?? [])), 'strlen'));
    $pay = array_values(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 200), preg_split('/\R/u', (string)($_POST['payment'] ?? ''))), 'strlen'));
    $vendor = $t('vendor_name', 200);
    $d = ['fy' => $row['fy'] ?? purchaseFy($date), 'wo_date' => $date, 'department_id' => $did ?: null, 'department_name' => $deptName ?: null,
        'vendor_name' => $vendor, 'vendor_address' => $t('vendor_address', 500) ?: null, 'vendor_phone' => $t('vendor_phone', 100) ?: null, 'vendor_email' => $t('vendor_email', 150) ?: null, 'vendor_gstin' => $t('vendor_gstin', 30) ?: null,
        'attn' => $t('attn', 200) ?: ($vendor !== '' ? 'M/s ' . $vendor : null), 'subject' => $t('subject', 500),
        'labels' => json_encode(['qty' => $t('l_qty', 30) ?: 'Qty', 'unit' => $t('l_unit', 30), 'rate' => $t('l_rate', 30) ?: 'Rate'], JSON_UNESCAPED_UNICODE),
        'subtotal' => $sub, 'gst_pct' => $gstPct, 'gst_amount' => $gst, 'round_off' => $ro, 'grand_total' => round($sub + $gst + $ro, 2),
        'purpose' => $t('purpose', 1000) ?: null, 'taxes_note' => $t('taxes_note') ?: null, 'execution_note' => $t('execution_note') ?: null, 'completion_note' => $t('completion_note') ?: null,
        'terms_json' => json_encode($terms, JSON_UNESCAPED_UNICODE), 'payment_json' => json_encode($pay, JSON_UNESCAPED_UNICODE), 'signs_json' => json_encode($signs, JSON_UNESCAPED_UNICODE),
        'status' => in_array($_POST['status'] ?? '', ['draft', 'issued'], true) ? $_POST['status'] : 'issued'];
    if ($row && !in_array($row['status'], ['draft', 'issued'], true)) $d['status'] = $row['status'];
    if ($vendor === '') $error = 'पुरवठादार / ठेकेदाराचं नाव लिहा.';
    elseif ($d['subject'] === '') $error = 'वर्क ऑर्डरचा विषय (Ref) लिहा.';
    elseif (!$items) $error = 'किमान एक काम / वस्तू लिहा.';
    elseif (!$canAll && !$did) $error = 'विभाग निवडा.';
    if (!$error) {
        $newId = woSave($pdo, $orgId, (int)$user['id'], $d, $items, $row ? $id : 0);
        $w = woLoad($pdo, $orgId, $newId);
        woLog($pdo, $orgId, $newId, $row ? 'edited' : 'created', ($row ? 'बदल' : 'नवीन') . ' — ₹' . woMoney($w['grand_total']), $user['id']);
        logActivity($row ? 'वर्क ऑर्डर बदलली' : 'वर्क ऑर्डर बनवली', 'work_order', $newId, woFullNo($w) . ' — ' . $vendor . ' — ₹' . woMoney($w['grand_total']));
        if ($vendor !== '') { require_once __DIR__ . '/../../includes/purchase-compare.php'; try { purchaseSuppliersSync($pdo, $orgId, [$vendor], (int)$user['id']); } catch (Throwable $e) {} }
        $_SESSION['flash'] = '✓ वर्क ऑर्डर ' . woFullNo($w) . ' जतन झाली' . ($row ? '.' : ' — सलग क्रमांक ' . $w['wo_no'] . '.');
        header('Location: print.php?id=' . $newId); exit;
    }
}

// ---------- फॉर्मचे मूल्य ----------
$src = $row ?: $copy;
$F = $src ?: ['wo_date' => date('Y-m-d'), 'department_id' => (int)(array_key_first($depts) ?? 0), 'department_name' => '', 'vendor_name' => '', 'vendor_address' => '', 'vendor_phone' => '', 'vendor_email' => '', 'vendor_gstin' => '',
    'attn' => '', 'subject' => '', 'labels' => '', 'gst_pct' => 18, 'gst_amount' => '', 'round_off' => 0, 'purpose' => '', 'taxes_note' => 'The supply price quoted above relates of products Without GST.',
    'execution_note' => 'Immediately after receiving the work order.', 'completion_note' => 'To Complete the work immediately same day from the date of work order.', 'terms_json' => '', 'payment_json' => '', 'signs_json' => '', 'status' => 'issued', '_items' => []];
if ($copy) { $F['wo_date'] = date('Y-m-d'); $F['status'] = 'issued'; }
$items = array_map(fn($i) => [$i['particular'], (string)$i['qty'], (string)$i['unit'], $i['rate'], $i['amount']], $F['_items'] ?? []);
$terms = json_decode((string)$F['terms_json'], true) ?: $SET['terms'];
$pay = json_decode((string)$F['payment_json'], true); if (!is_array($pay)) $pay = $SET['payment'];
$signs = json_decode((string)$F['signs_json'], true) ?: [];
$L = woLabels($F);
if (!$src) $L = ['qty' => 'Qty', 'unit' => 'Unit', 'rate' => 'Rate'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error) {
    foreach (['wo_date', 'department_id', 'department_name', 'vendor_name', 'vendor_address', 'vendor_phone', 'vendor_email', 'vendor_gstin', 'attn', 'subject', 'gst_pct', 'purpose', 'taxes_note', 'execution_note', 'completion_note', 'status'] as $k) $F[$k] = $_POST[$k] ?? $F[$k];
    $items = []; foreach ((array)($_POST['it_part'] ?? []) as $i => $p) $items[] = [$p, $_POST['it_qty'][$i] ?? '', $_POST['it_unit'][$i] ?? '', $_POST['it_rate'][$i] ?? '', $_POST['it_amt'][$i] ?? ''];
    $terms = []; foreach ((array)($_POST['tm_text'] ?? []) as $i => $tx) $terms[] = [$tx, $_POST['tm_flag'][$i] ?? ''];
    $signs = (array)($_POST['sg'] ?? []); $L = ['qty' => $_POST['l_qty'] ?? 'Qty', 'unit' => $_POST['l_unit'] ?? '', 'rate' => $_POST['l_rate'] ?? 'Rate'];
}
if (!$items) $items = [['', '', '', '', '']];
$curFy = purchaseFy($F['wo_date']);
$nextNo = woNextNum($pdo, $orgId, $curFy);

// पुरवठादार — आधीच्या वर्क ऑर्डरमधील पत्ता / फोन / Email / GSTIN लक्षात
$mem = [];
$st = $pdo->prepare("SELECT vendor_name, vendor_address, vendor_phone, vendor_email, vendor_gstin FROM work_orders WHERE organization_id = ? ORDER BY id DESC LIMIT 400"); $st->execute([$orgId]);
foreach ($st->fetchAll() as $v) if (!isset($mem[$v['vendor_name']])) $mem[$v['vendor_name']] = [$v['vendor_address'], $v['vendor_phone'], $v['vendor_email'], $v['vendor_gstin']];
$sup = [];
try { require_once __DIR__ . '/../../includes/purchase-compare.php'; $sup = purchaseSupplierNames($pdo, $orgId); } catch (Throwable $e) {}
$vendors = array_values(array_unique(array_merge(array_keys($mem), $sup)));
$deptSign = []; foreach ($allDepts as $did => $d) $deptSign[$did] = woDefaultSigns($d['name']);

$pageTitle = $row ? 'वर्क ऑर्डर ' . woFullNo($row) : 'नवीन वर्क ऑर्डर';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .wf-top{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
  .wf-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:16px;align-items:start;}
  .wf-card{border:1px solid var(--line);border-radius:16px;background:var(--surface);padding:16px 18px;margin-bottom:14px;box-shadow:0 1px 2px rgba(16,24,40,.04);}
  .wf-h{display:flex;align-items:center;gap:10px;margin:0 0 12px;} .wf-h b{font-size:14.5px;font-weight:800;} .wf-h small{font-size:12px;color:var(--text-dim);margin-left:auto;}
  .wf-step{width:28px;height:28px;border-radius:50%;display:grid;place-items:center;font-size:13px;font-weight:800;color:#fff;background:linear-gradient(135deg,#0D9488,#0F766E);flex:0 0 auto;}
  .wf-hint{font-size:11.5px;color:var(--text-dim);margin-top:4px;}
  .wf-mem{display:none;font-size:11px;font-weight:700;color:#166534;background:#DCFCE7;border-radius:99px;padding:2px 8px;margin-left:6px;}
  /* वस्तूंचा तक्ता */
  .wf-itw{border:1px solid var(--line);border-radius:12px;overflow:hidden;}
  .wf-it{width:100%;border-collapse:collapse;font-size:13px;}
  .wf-it th{background:color-mix(in srgb,#0D9488 9%,var(--surface));color:#0F766E;font-size:11.5px;font-weight:800;text-align:left;padding:8px 6px;border-bottom:1px solid var(--line);}
  .wf-it td{padding:4px;border-bottom:1px solid var(--line);vertical-align:top;} .wf-it tr:last-child td{border-bottom:0;}
  .wf-it tbody tr:nth-child(even) td{background:color-mix(in srgb,var(--canvas) 60%,transparent);}
  .wf-it input,.wf-it textarea{width:100%;border:1px solid transparent;border-radius:8px;padding:7px 8px;font:inherit;background:transparent;color:var(--text);}
  .wf-it input:focus,.wf-it textarea:focus{border-color:#0D9488;background:var(--surface);outline:0;box-shadow:0 0 0 3px color-mix(in srgb,#0D9488 15%,transparent);}
  .wf-it textarea{min-height:36px;resize:vertical;} .wf-it .n{text-align:right;} .wf-it .sr{width:34px;text-align:center;color:var(--text-dim);font-weight:700;padding-top:12px;}
  .wf-it input[data-auto="1"]{color:#0F766E;font-weight:700;} .wf-it .x{border:0;background:none;color:#DC2626;font-size:15px;opacity:.55;padding-top:9px;} .wf-it tr:hover .x{opacity:1;}
  .wf-add{display:flex;gap:8px;align-items:center;padding:8px;background:var(--canvas);border-top:1px solid var(--line);}
  .wf-add button{border:1px dashed #0D9488;color:#0F766E;background:var(--surface);border-radius:9px;padding:5px 12px;font-size:12.5px;font-weight:700;}
  .wf-presets{display:flex;gap:6px;flex-wrap:wrap;} .wf-presets button{font-size:12px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:99px;padding:4px 11px;font-weight:600;}
  .wf-presets button.on{background:#0F766E;border-color:#0F766E;color:#fff;}
  /* अटी */
  .wf-terms > summary{list-style:none;cursor:pointer;display:flex;align-items:center;gap:10px;} .wf-terms > summary::-webkit-details-marker{display:none;}
  .wf-terms > summary .chev{margin-left:auto;transition:transform .2s;color:var(--text-dim);} .wf-terms[open] > summary .chev{transform:rotate(90deg);}
  .wf-pill{font-size:11px;font-weight:800;border-radius:99px;padding:2px 8px;} .p-yes{background:#DCFCE7;color:#166534;} .p-no{background:#FEE2E2;color:#991B1B;} .p-na{background:var(--canvas);color:var(--text-dim);}
  .wf-tm{display:grid;grid-template-columns:30px 1fr auto 26px;gap:8px;align-items:start;padding:6px 0;border-bottom:1px dashed var(--line);font-size:12.5px;}
  .wf-tm b{color:var(--text-dim);padding-top:7px;} .wf-tm textarea{width:100%;border:1px solid var(--line);border-radius:8px;padding:6px 8px;font:inherit;font-size:12.5px;min-height:34px;background:var(--surface);color:var(--text);resize:vertical;}
  .wf-tm select{border:1px solid var(--line);border-radius:99px;padding:4px 8px;font-size:11.5px;font-weight:800;background:var(--surface);color:var(--text);}
  .wf-tm select.v-YES{background:#DCFCE7;color:#166534;border-color:#86EFAC;} .wf-tm select.v-NO{background:#FEE2E2;color:#991B1B;border-color:#FCA5A5;}
  .wf-tm .x{border:0;background:none;color:#DC2626;opacity:.55;padding-top:6px;} .wf-tm:hover .x{opacity:1;}
  /* उजवीकडचा सारांश */
  .wf-sum{position:sticky;top:76px;border-radius:18px;overflow:hidden;border:1px solid var(--line);background:var(--surface);box-shadow:0 10px 30px -14px rgba(15,118,110,.45);}
  .wf-sum-h{background:linear-gradient(135deg,#0F766E,#0D9488 60%,#14B8A6);color:#fff;padding:16px 18px;}
  .wf-sum-h small{display:block;font-size:11.5px;opacity:.85;font-weight:600;} .wf-sum-h .no{font-size:24px;font-weight:900;letter-spacing:.3px;} .wf-sum-h .sub{font-size:12px;opacity:.9;margin-top:4px;}
  .wf-sum-b{padding:14px 18px;font-size:13px;}
  .wf-row{display:flex;justify-content:space-between;align-items:center;padding:5px 0;} .wf-row b{font-variant-numeric:tabular-nums;}
  .wf-row select{border:1px solid var(--line);border-radius:8px;padding:2px 6px;font-size:12.5px;background:var(--surface);color:var(--text);}
  .wf-grand{display:flex;justify-content:space-between;align-items:baseline;border-top:2px solid var(--line);margin-top:6px;padding-top:10px;} .wf-grand span{font-weight:800;} .wf-grand b{font-size:24px;color:#0F766E;font-weight:900;}
  .wf-words{font-size:11.5px;color:var(--text-dim);margin-top:4px;line-height:1.45;}
  .wf-meta{font-size:12px;color:var(--text-dim);border-top:1px dashed var(--line);margin-top:10px;padding-top:10px;line-height:1.7;} .wf-meta b{color:var(--text);}
  .wf-save{display:block;width:100%;border:0;border-radius:12px;padding:11px;font-weight:800;font-size:14px;color:#fff;background:linear-gradient(90deg,#0F766E,#0D9488);margin-top:12px;box-shadow:0 6px 16px -8px rgba(13,148,136,.8);}
  .wf-cancel{display:block;text-align:center;font-size:12.5px;margin-top:8px;color:var(--text-dim);}
  .wf-mbar{display:none;}
  @media (max-width:1100px){ .wf-grid{grid-template-columns:1fr;} .wf-sum{position:static;} }
  @media (max-width:760px){
    .wf-card{padding:14px;} .wf-it thead{display:none;} .wf-it tr{display:grid;grid-template-columns:repeat(4,1fr);gap:2px;padding:6px 4px;position:relative;} .wf-it td{border:0;padding:1px;} .wf-it .sr{display:none;} .wf-it td.part{grid-column:1/-1;padding-right:28px;} .wf-it td.xx{position:absolute;right:2px;top:6px;}
    .wf-it tbody tr{border-bottom:1px solid var(--line);} .wf-tm{grid-template-columns:22px 1fr auto 22px;}
    .wf-mbar{display:flex;position:fixed;left:0;right:0;bottom:62px;z-index:30;background:var(--surface);border-top:1px solid var(--line);padding:8px 14px;align-items:center;gap:10px;box-shadow:0 -6px 18px -10px rgba(0,0,0,.25);}
    .wf-mbar div{flex:1;font-size:11px;color:var(--text-dim);} .wf-mbar b{display:block;font-size:17px;color:#0F766E;} .wf-mbar button{border:0;border-radius:10px;padding:9px 16px;font-weight:800;color:#fff;background:#0D9488;}
  }
</style>

<div class="wf-top">
  <div><div class="page-eyebrow"><a href="index.php" style="color:inherit;">🧾 वर्क ऑर्डर</a></div>
    <div class="page-title mb-0"><?= $row ? '✎ वर्क ऑर्डर ' . h(woFullNo($row)) : ($copy ? '📄 याच वरून नवीन वर्क ऑर्डर' : '＋ नवीन वर्क ऑर्डर') ?></div></div>
</div>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;">⚠️ <?= h($error) ?></div><?php endif; ?>
<?php if ($copy): ?><div class="alert alert-info" style="border-radius:10px;font-size:13px;">📄 W.O. <?= h(woFullNo($copy)) ?> वरून नवीन — नवीन दिनांक व पुढचा सलग क्रमांक मिळेल; मजकूर तपासा.</div><?php endif; ?>

<form method="POST" id="wf" class="wf-grid" data-no-smart-filter>
  <div>
  <input type="hidden" name="action" value="save_wo"><input type="hidden" name="id" value="<?= (int)$id ?>">

  <div class="wf-card"><div class="wf-h"><span class="wf-step">1</span><b>विभाग व दिनांक</b></div>
    <div class="row g-2">
      <div class="col-md-5"><label class="form-label-custom">विभाग <?= $canAll ? '' : '*' ?></label><select name="department_id" id="wfDept" class="form-select-custom"><?php if ($canAll): ?><option value="0">— विभाग नाही / खाली लिहा —</option><?php endif; ?><?php foreach ($depts as $did => $d): ?><option value="<?= $did ?>" <?= (int)$F['department_id'] === $did ? 'selected' : '' ?>><?= h($d['nm']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><label class="form-label-custom">विभागाचं नाव (Work Order वर)</label><input name="department_name" class="form-control-custom" value="<?= h($F['department_name']) ?>" maxlength="150" placeholder="उदा. Civil Dept."></div>
      <div class="col-md-3"><label class="form-label-custom">दिनांक</label><input type="date" name="wo_date" class="form-control-custom" value="<?= h($F['wo_date']) ?>"></div>
    </div>
  </div>

  <div class="wf-card"><div class="wf-h"><span class="wf-step">2</span><b>पुरवठादार / ठेकेदार (To)</b><span class="wf-mem" id="wfMem">✓ आधीचा पत्ता भरला</span></div>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">नाव *</label><input name="vendor_name" id="wfVendor" class="form-control-custom" value="<?= h($F['vendor_name']) ?>" maxlength="200" list="wfVendors" required autocomplete="off" placeholder="टाईप करा — आधीचे पत्ते आपोआप"></div>
      <div class="col-md-6"><label class="form-label-custom">Kind Attn.</label><input name="attn" class="form-control-custom" value="<?= h($F['attn']) ?>" maxlength="200" placeholder="रिकामं ठेवलं तर M/s + नाव"></div>
      <div class="col-12"><label class="form-label-custom">पत्ता</label><input name="vendor_address" class="form-control-custom" value="<?= h($F['vendor_address']) ?>" maxlength="500"></div>
      <div class="col-md-4 col-6"><label class="form-label-custom">📞 Phone</label><input name="vendor_phone" class="form-control-custom" value="<?= h($F['vendor_phone']) ?>" maxlength="100"></div>
      <div class="col-md-4 col-6"><label class="form-label-custom">✉️ E-mail</label><input name="vendor_email" class="form-control-custom" value="<?= h($F['vendor_email']) ?>" maxlength="150"></div>
      <div class="col-md-4"><label class="form-label-custom">GSTIN</label><input name="vendor_gstin" class="form-control-custom" value="<?= h($F['vendor_gstin'] ?? '') ?>" maxlength="30"></div>
    </div>
  </div>

  <div class="wf-card"><div class="wf-h"><span class="wf-step">3</span><b>विषय (Ref) व काम</b></div>
    <label class="form-label-custom">Ref / विषय *</label><input name="subject" id="wfSub" class="form-control-custom" value="<?= h($F['subject']) ?>" maxlength="500" required placeholder="उदा. C Wing Roofing Sheet Maintenance Labour Charges">
    <div class="d-flex gap-3 flex-wrap align-items-end mt-3 mb-2">
      <div><label class="form-label-custom">रकाने — नमुना</label><div class="wf-presets" id="wfPresets"><?php foreach (woLabelPresets() as $k => $p): ?><button type="button" data-l='<?= h(json_encode(array_slice($p, 0, 3), JSON_UNESCAPED_UNICODE)) ?>' title="<?= h(implode(' / ', array_slice($p, 0, 3))) ?>"><?= h($p[3]) ?></button><?php endforeach; ?></div></div>
      <div class="d-flex gap-2" style="font-size:12.5px;">
        <div><label class="form-label-custom">नग रकाना</label><input name="l_qty" class="form-control-custom" style="width:100px;" value="<?= h($L['qty']) ?>"></div>
        <div><label class="form-label-custom">Unit रकाना</label><input name="l_unit" class="form-control-custom" style="width:100px;" value="<?= h($L['unit']) ?>" placeholder="(नको तर रिकामं)"></div>
        <div><label class="form-label-custom">दर रकाना</label><input name="l_rate" class="form-control-custom" style="width:120px;" value="<?= h($L['rate']) ?>"></div>
      </div>
    </div>
    <div class="wf-itw">
      <table class="wf-it"><thead><tr><th class="sr">#</th><th style="width:46%;">Particular / काम</th><th>नग</th><th>Unit</th><th>दर ₹</th><th>रक्कम ₹</th><th style="width:30px;"></th></tr></thead><tbody id="wfItems">
        <?php foreach ($items as $k => $it): ?><tr><td class="sr"><?= $k + 1 ?></td><td class="part"><textarea name="it_part[]" rows="1" placeholder="काम / वस्तू"><?= h($it[0]) ?></textarea></td><td><input name="it_qty[]" value="<?= h($it[1]) ?>" class="n" placeholder="नग"></td><td><input name="it_unit[]" value="<?= h($it[2]) ?>" placeholder="Unit"></td><td><input name="it_rate[]" value="<?= h($it[3] === null ? '' : rtrim(rtrim((string)$it[3], '0'), '.')) ?>" class="n" inputmode="decimal" placeholder="दर"></td><td><input name="it_amt[]" value="<?= h($it[4] === null ? '' : $it[4]) ?>" class="n" inputmode="decimal" placeholder="रक्कम"></td><td class="xx"><button type="button" class="x" title="काढा">✕</button></td></tr><?php endforeach; ?>
      </tbody></table>
      <div class="wf-add"><button type="button" id="wfAdd">＋ ओळ जोडा</button><span class="wf-hint" style="margin:0;">रक्कम रिकामी → नग × दर आपोआप · मथळ्याच्या ओळीसाठी फक्त Particular</span></div>
    </div>
    <label class="form-label-custom mt-3">Purpose</label><input name="purpose" id="wfPur" class="form-control-custom" value="<?= h($F['purpose']) ?>" maxlength="1000" placeholder="रिकामं ठेवलं तर विषयच">
  </div>

  <div class="wf-card"><div class="wf-h"><span class="wf-step">4</span><b>कालावधी व कर</b></div>
    <div class="row g-2">
      <div class="col-md-6"><label class="form-label-custom">Date of Execution (Work start)</label><input name="execution_note" class="form-control-custom" value="<?= h($F['execution_note']) ?>" maxlength="300" list="wfExec"></div>
      <div class="col-md-6"><label class="form-label-custom">Target Date of Completion</label><input name="completion_note" class="form-control-custom" value="<?= h($F['completion_note']) ?>" maxlength="300" list="wfComp"></div>
      <div class="col-12"><label class="form-label-custom">Taxes</label><input name="taxes_note" class="form-control-custom" value="<?= h($F['taxes_note']) ?>" maxlength="300"></div>
    </div>
  </div>

  <details class="wf-card wf-terms"><summary><span class="wf-step">5</span><b style="font-size:14.5px;">अटी व शर्ती</b> <span class="wf-pill p-na" id="wfTmN"><?= count($terms) ?></span> <span class="wf-pill p-yes" id="wfTmY">YES</span> <span class="wf-pill p-no" id="wfTmNo">NO</span><span class="chev">▶</span></summary>
    <div class="mt-3" id="wfTerms"><?php foreach ($terms as $i => $tm): ?><div class="wf-tm"><b><?= $i + 1 ?>)</b><textarea name="tm_text[]" rows="1"><?= h($tm[0]) ?></textarea><select name="tm_flag[]"><option value="">—</option><option value="YES" <?= ($tm[1] ?? '') === 'YES' ? 'selected' : '' ?>>YES</option><option value="NO" <?= ($tm[1] ?? '') === 'NO' ? 'selected' : '' ?>>NO</option></select><button type="button" class="x" title="काढा">✕</button></div><?php endforeach; ?></div>
    <div class="d-flex gap-2 flex-wrap mt-2"><button type="button" id="wfTmAdd" class="btn btn-sm" style="border:1px dashed #0D9488;color:#0F766E;border-radius:8px;font-weight:700;">＋ अट जोडा</button><button type="button" id="wfTmReset" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">↺ नेहमीच्या अटी</button></div>
    <label class="form-label-custom mt-3">🏦 Payment — बँक तपशील ओळी (Payment Terms अटीखाली छापल्या जातात)</label><textarea name="payment" class="form-control-custom" rows="4" style="font-size:12.5px;"><?= h(implode("\n", $pay)) ?></textarea>
  </details>

  <div class="wf-card"><div class="wf-h"><span class="wf-step">6</span><b>सह्या</b><small>सहीखाली: <?= h($SET['sign_org']) ?></small></div>
    <div class="row g-2" id="wfSigns"><?php foreach (array_pad($signs ?: woDefaultSigns($F['department_name'] ?: ($allDepts[(int)$F['department_id']]['name'] ?? '')), 3, '') as $i => $s): ?><div class="col-md-4"><input name="sg[]" class="form-control-custom" value="<?= h($s) ?>" placeholder="सही <?= $i + 1 ?>"></div><?php endforeach; ?></div>
  </div>
  </div>

  <aside class="wf-sum">
    <div class="wf-sum-h"><small><?= $row ? 'W.O. क्रमांक' : 'W.O. क्रमांक (जतन करताना आपोआप)' ?></small><div class="no"><?= h($row ? woFullNo($row) : woFmtNo($nextNo) . '/' . $curFy) ?></div>
      <div class="sub"><?= $row ? '' : '🔢 संस्थेचा सलग क्रम — कोणत्याही विभागाने बनवली तरी पुढचाच' ?></div></div>
    <div class="wf-sum-b">
      <div class="wf-row"><span>TOTAL</span><b id="wfSubT">0.00</b></div>
      <div class="wf-row"><span>GST <select name="gst_pct" id="wfGst"><?php foreach ([0, 5, 12, 18, 28] as $g): ?><option value="<?= $g ?>" <?= (float)$F['gst_pct'] == $g ? 'selected' : '' ?>><?= $g ?>%</option><?php endforeach; ?></select></span><b id="wfGstT">0.00</b></div>
      <div class="wf-row"><label style="font-weight:600;margin:0;"><input type="checkbox" name="round" id="wfRound" value="1" <?= (float)($F['round_off'] ?? 0) != 0 || !$src ? 'checked' : '' ?>> Round Off</label><b id="wfRoT">0.00</b></div>
      <div class="wf-grand"><span>Grand Total</span><b>₹<span id="wfGrand">0.00</span></b></div>
      <div class="wf-words" id="wfWords"></div>
      <div class="wf-row mt-2"><span>स्थिती</span><select name="status"><option value="issued" <?= $F['status'] === 'issued' ? 'selected' : '' ?>><?= h($ST['issued'][0]) ?></option><option value="draft" <?= $F['status'] === 'draft' ? 'selected' : '' ?>><?= h($ST['draft'][0]) ?></option></select></div>
      <div class="wf-meta">👷 <b id="wfMV"><?= h($F['vendor_name'] ?: '—') ?></b><br>🧾 <span id="wfMN">0</span> ओळी · 📜 <span id="wfMT"><?= count($terms) ?></span> अटी</div>
      <button type="submit" class="wf-save">✓ जतन करा<?= $row ? '' : ' — W.O. क्रमांक मिळवा' ?></button>
      <a href="index.php" class="wf-cancel">रद्द</a>
    </div>
  </aside>
  <div class="wf-mbar"><div>Grand Total<b>₹<span id="wfGrand2">0.00</span></b></div><button type="submit">✓ जतन करा</button></div>
</form>

<datalist id="wfVendors"><?php foreach ($vendors as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist>
<datalist id="wfExec"><option value="Immediately after receiving the work order."><option value="Within 2 days after receiving the work order."></datalist>
<datalist id="wfComp"><option value="To Complete the work immediately same day from the date of work order."><option value="To Complete the work immediately within 5 To 10 Days from the date of work order."><option value="To Complete the work immediately within 30 day from the date of work order."></datalist>
<script>
(function () {
  var f = document.getElementById('wf'), tb = document.getElementById('wfItems');
  var MEM = <?= json_encode($mem, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, SG = <?= json_encode($deptSign, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>, TERMS = <?= json_encode($SET['terms'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
  var num = function (v) { v = String(v || '').replace(/[,₹\s]/g, ''); return v === '' || isNaN(v) ? null : parseFloat(v); };
  var qn = function (v) { var m = String(v || '').match(/^\s*([\d.]+)/); return m ? parseFloat(m[1]) : null; };
  var fmt = function (n) { n = Math.round(n * 100) / 100; var s = n.toFixed(2).split('.'), i = s[0], l3 = i.slice(-3), r = i.slice(0, -3); if (r) l3 = r.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + l3; return l3 + '.' + s[1]; };
  var O = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'], T = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
  var two = function (x) { return x < 20 ? O[x] : (T[Math.floor(x / 10)] + ' ' + O[x % 10]).trim(); };
  var three = function (x) { return ((x >= 100 ? O[Math.floor(x / 100)] + ' Hundred ' : '') + two(x % 100)).trim(); };
  var words = function (a) { var r = Math.floor(a + 1e-9), p = Math.round((a - r) * 100), parts = [], cr = Math.floor(r / 1e7); r %= 1e7;
    if (cr) parts.push(two(cr) + ' Crore'); [[1e5, 'Lakh'], [1e3, 'Thousand']].forEach(function (d) { var x = Math.floor(r / d[0]); r %= d[0]; if (x) parts.push(two(x) + ' ' + d[1]); }); if (r) parts.push(three(r));
    return (parts.length ? parts.join(' ') + ' Rupees' : 'Zero Rupees') + (p ? ' and ' + two(p) + ' Paise' : '') + ' Only'; };
  function calc() {
    var sub = 0;
    tb.querySelectorAll('tr').forEach(function (tr) { var q = tr.querySelector('[name="it_qty[]"]'), r = tr.querySelector('[name="it_rate[]"]'), a = tr.querySelector('[name="it_amt[]"]');
      if (a.dataset.auto !== '0') { var qv = qn(q.value), rv = num(r.value); a.value = qv !== null && rv !== null ? (Math.round(qv * rv * 100) / 100) : (a.dataset.auto === '1' ? '' : a.value); if (qv !== null && rv !== null) a.dataset.auto = '1'; }
      sub += num(a.value) || 0; });
    var g = Math.round(sub * (parseFloat(f.gst_pct.value) || 0)) / 100, tot = sub + g, ro = f.round.checked ? Math.round(tot) - tot : 0;
    document.getElementById('wfSubT').textContent = fmt(sub); document.getElementById('wfGstT').textContent = fmt(g); document.getElementById('wfRoT').textContent = fmt(ro);
    document.getElementById('wfGrand').textContent = fmt(tot + ro); document.getElementById('wfGrand2').textContent = fmt(tot + ro); document.getElementById('wfWords').textContent = 'In Words: ' + words(tot + ro);
    var n = 0; tb.querySelectorAll('tr').forEach(function (tr, i) { tr.querySelector('.sr').textContent = i + 1; if (tr.querySelector('[name="it_part[]"]').value.trim()) n++; });
    document.getElementById('wfMN').textContent = n; document.getElementById('wfMV').textContent = f.vendor_name.value.trim() || '—';
    var y = 0, no = 0; document.querySelectorAll('#wfTerms select').forEach(function (s) { s.className = s.value ? 'v-' + s.value : ''; if (s.value === 'YES') y++; if (s.value === 'NO') no++; });
    document.getElementById('wfTmY').textContent = 'YES ' + y; document.getElementById('wfTmNo').textContent = 'NO ' + no; document.getElementById('wfMT').textContent = document.querySelectorAll('#wfTerms .wf-tm').length;
  }
  function bindRow(tr) {
    tr.querySelector('.x').onclick = function () { if (tb.children.length > 1) tr.remove(); else tr.querySelectorAll('input,textarea').forEach(function (i) { i.value = ''; }); calc(); };
    var a = tr.querySelector('[name="it_amt[]"]'); if (a.value !== '' && !(qn(tr.querySelector('[name="it_qty[]"]').value) !== null && num(tr.querySelector('[name="it_rate[]"]').value) !== null)) a.dataset.auto = '0';
    a.addEventListener('input', function () { a.dataset.auto = a.value === '' ? '' : '0'; });
  }
  tb.querySelectorAll('tr').forEach(bindRow);
  document.getElementById('wfAdd').onclick = function () { var r = tb.lastElementChild.cloneNode(true); delete r.querySelector('[name="it_amt[]"]').dataset.auto; r.querySelectorAll('input,textarea').forEach(function (i) { i.value = ''; delete i.dataset.auto; }); tb.appendChild(r); bindRow(r); r.querySelector('textarea').focus(); };
  f.addEventListener('input', calc); f.addEventListener('change', calc); calc();
  // पुरवठादार — आधीचा पत्ता
  document.getElementById('wfVendor').addEventListener('change', function () { var m = MEM[this.value]; document.getElementById('wfMem').style.display = m ? 'inline-block' : 'none'; if (!m) return; ['vendor_address', 'vendor_phone', 'vendor_email', 'vendor_gstin'].forEach(function (k, i) { if (!f[k].value.trim() && m[i]) f[k].value = m[i]; }); if (!f.attn.value.trim()) f.attn.value = 'M/s ' + this.value; });
  // विभाग → सह्या
  document.getElementById('wfDept').addEventListener('change', function () { var s = SG[this.value]; if (!s) return; var ins = document.querySelectorAll('#wfSigns input'); if (!ins[0].value.trim() || /\/ HOD$/.test(ins[0].value)) ins[0].value = s[0]; });
  // रकाने नमुना
  document.querySelectorAll('#wfPresets button').forEach(function (b) { b.addEventListener('click', function () { var l = JSON.parse(b.dataset.l); f.l_qty.value = l[0]; f.l_unit.value = l[1]; f.l_rate.value = l[2];
    document.querySelectorAll('#wfPresets button').forEach(function (x) { x.classList.toggle('on', x === b); });
    tb.querySelectorAll('[name="it_unit[]"]').forEach(function (u) { if (!u.value && !/Unit/.test(l[1])) u.value = l[1]; }); }); });
  // अटी
  var tmBox = document.getElementById('wfTerms');
  function renum() { tmBox.querySelectorAll('.wf-tm').forEach(function (r, i) { r.querySelector('b').textContent = (i + 1) + ')'; r.querySelector('.x').onclick = function () { r.remove(); renum(); }; }); document.getElementById('wfTmN').textContent = tmBox.children.length + ' अटी'; calc(); }
  function addTerm(t, fl) { var d = document.createElement('div'); d.className = 'wf-tm'; d.innerHTML = '<b></b><textarea name="tm_text[]" rows="1"></textarea><select name="tm_flag[]"><option value="">—</option><option value="YES">YES</option><option value="NO">NO</option></select><button type="button" class="x" title="काढा">✕</button>'; d.querySelector('textarea').value = t || ''; d.querySelector('select').value = fl || ''; tmBox.appendChild(d); renum(); return d; }
  document.getElementById('wfTmAdd').onclick = function () { addTerm('', '').querySelector('textarea').focus(); };
  document.getElementById('wfTmReset').onclick = function () { if (!confirm('अटी नेहमीच्या नमुन्याने बदलायच्या?')) return; tmBox.innerHTML = ''; TERMS.forEach(function (t) { addTerm(t[0], t[1]); }); };
  renum();
  // Purpose — विषयच
  document.getElementById('wfSub').addEventListener('change', function () { var p = document.getElementById('wfPur'); if (!p.value.trim() || p.dataset.auto === '1') { p.value = this.value; p.dataset.auto = '1'; } });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
