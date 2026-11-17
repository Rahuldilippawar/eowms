<?php
// 🖥️ Computer Lab — प्रत्येक Machine ची स्थिती एका नजरेत, Lab Assistant ची रोजची तपासणी, एकाच वेळी अनेक Machines जोडणं
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-lab-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$labId = (int)($_GET['id'] ?? $_POST['lab_id'] ?? 0);

$labStmt = $pdo->prepare("SELECT l.*, u.name AS assistant_name FROM it_labs l LEFT JOIN users u ON u.id = l.assistant_id WHERE l.id = ? AND l.organization_id = ?");
$labStmt->execute([$labId, $orgId]);
$lab = $labStmt->fetch();
if (!$lab) { $_SESSION['flash'] = 'Lab सापडली नाही.'; header('Location: labs.php'); exit; }

$canCheck = itCanCheckLab($pdo, $user, $labId);
$canView = $canCheck || isItDeptStaff($pdo, $user) || hasPermission('manage_it') || isManagementOrAbove();
if (!$canView) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">या Lab ची माहिती पाहण्याची परवानगी नाही.</div>'); }
$canBulk = itCanBulkAdd();
$ready = itLabChecksReady($pdo);
$isAjax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

$machineSql = "SELECT a.*, ? AS lab_name FROM it_assets a WHERE a.lab_id = ? AND a.organization_id = ? AND a.asset_type IN ('computer','cpu') AND a.status <> 'scrapped'";
$loadAsset = function ($assetId) use ($pdo, $labId, $orgId, $lab, $machineSql) {
    $st = $pdo->prepare($machineSql . " AND a.id = ?");
    $st->execute([$lab['lab_name'], $labId, $orgId, (int)$assetId]);
    return $st->fetch();
};

// 📜 एका Machine चा इतिहास (Sheet मध्ये)
if (isset($_GET['history']) && $ready) {
    header('Content-Type: application/json; charset=utf-8');
    $st = $pdo->prepare("SELECT c.check_date, c.power, c.screen, c.cpu, c.keyboard, c.mouse, c.internet, c.remarks, u.name FROM it_machine_checks c
        LEFT JOIN users u ON u.id = c.checked_by WHERE c.asset_id = ? AND c.lab_id = ? ORDER BY c.check_date DESC LIMIT 10");
    $st->execute([(int)$_GET['history'], $labId]);
    $tk = $pdo->prepare("SELECT id, issue_description, status, created_at FROM it_repair_tickets WHERE asset_id = ? AND lab_id = ? ORDER BY id DESC LIMIT 3");
    $tk->execute([(int)$_GET['history'], $labId]);
    echo json_encode(['checks' => $st->fetchAll(), 'tickets' => $tk->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $back = 'lab.php?id=' . $labId;
    if (!$ready) { $_SESSION['flash'] = '⚠️ Database Update बाकी आहे — System Update → Database Update चालवा.'; header('Location: ' . $back); exit; }

    if ($action === 'check') {
        $asset = $canCheck ? $loadAsset($_POST['asset_id'] ?? 0) : null;
        $r = $asset ? itSaveMachineCheck($pdo, $orgId, $asset, $_POST, $user['id'], !empty($_POST['open_ticket']))
                    : ['ok' => false, 'msg' => $canCheck ? 'Machine सापडली नाही' : 'परवानगी नाही'];
        if ($isAjax) {
            header('Content-Type: application/json; charset=utf-8');
            if ($asset && $r['ok']) { $r['asset'] = $loadAsset($asset['id']); $r['by'] = $user['name']; }
            echo json_encode($r, JSON_UNESCAPED_UNICODE); exit;
        }
        $_SESSION['flash'] = $r['ok'] ? '✓ ' . ($asset['workstation_no'] ?: $asset['asset_tag']) . ' — तपासणी जतन' : '⚠️ ' . $r['msg'];
        header('Location: ' . $back); exit;
    }
    if ($action === 'all_ok' && $canCheck) {
        // आज न तपासलेल्या, बिघाड-नोंद नसलेल्या सगळ्या Machines — सगळं ठीक, चालू, Internet चालू
        $st = $pdo->prepare($machineSql . " AND (a.last_check_at IS NULL OR DATE(a.last_check_at) < CURDATE())");
        $st->execute([$lab['lab_name'], $labId, $orgId]);
        $n = 0;
        foreach ($st->fetchAll() as $a) {
            if (itMachineHealth($a) === 'fault') continue; // आधीचा बिघाड — स्वतः तपासून नोंदवावा
            itSaveMachineCheck($pdo, $orgId, $a, ['power' => 'on', 'internet' => 'ok'], $user['id']);
            $n++;
        }
        logActivity('IT Lab — सगळे ठीक नोंदवले', 'it_lab', $labId, $lab['lab_name'] . ' · ' . $n);
        $_SESSION['flash'] = "✓ $n Machines ठीक म्हणून नोंदवल्या (आधी बिघाड असलेल्या वगळल्या — त्या स्वतंत्र तपासा).";
        header('Location: ' . $back); exit;
    }
    if ($action === 'bulk_add' && $canBulk) {
        try {
            [$added, $skipped] = itBulkAddMachines($pdo, $orgId, $labId, $_POST, $user['id']);
            logActivity('IT Lab — अनेक Machines जोडल्या', 'it_lab', $labId, $lab['lab_name'] . ' · ' . $added);
            $_SESSION['flash'] = "✓ $added Machines जोडल्या" . ($skipped ? " · $skipped आधीच होत्या (वगळल्या)" : '') . '.';
        } catch (Throwable $e) {
            error_log('it bulk add: ' . $e->getMessage());
            $_SESSION['flash'] = '⚠️ Machines जोडता आल्या नाहीत — पुन्हा प्रयत्न करा.';
        }
        header('Location: ' . $back); exit;
    }
    header('Location: ' . $back); exit;
}

$pageTitle = $lab['lab_name'] . ' — Computer Lab';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$machines = [];
if ($ready) {
    $st = $pdo->prepare($machineSql . " ORDER BY (a.workstation_no IS NULL), LENGTH(a.workstation_no), a.workstation_no, a.asset_tag");
    $st->execute([$lab['lab_name'], $labId, $orgId]);
    $machines = $st->fetchAll();
}
$others = $pdo->prepare("SELECT asset_type, COUNT(*) n, SUM(power_status = 'on') on_n, SUM(status IN ('faulty','under_repair')) bad FROM it_assets WHERE lab_id = ? AND asset_type NOT IN ('computer','cpu') AND status <> 'scrapped' GROUP BY asset_type");
$others->execute([$labId]);
$others = $others->fetchAll();
$openTickets = [];
$tk = $pdo->prepare("SELECT asset_id, COUNT(*) n FROM it_repair_tickets WHERE lab_id = ? AND status IN ('pending','in_progress') GROUP BY asset_id");
$tk->execute([$labId]);
$openTickets = $tk->fetchAll(PDO::FETCH_KEY_PAIR);

// 📅 मागील १४ दिवस — प्रत्येक Machine चा दिवसागणिक सारांश
$days = []; for ($i = 13; $i >= 0; $i--) { $days[] = date('Y-m-d', strtotime("-$i day")); }
$hist = [];
if ($ready && $machines) {
    $st = $pdo->prepare("SELECT asset_id, check_date, power, screen, cpu, keyboard, mouse, internet FROM it_machine_checks WHERE lab_id = ? AND check_date >= ?");
    $st->execute([$labId, $days[0]]);
    foreach ($st->fetchAll() as $c) {
        $bad = in_array('fault', [$c['screen'], $c['cpu'], $c['keyboard'], $c['mouse']], true) || $c['internet'] === 'down';
        $hist[$c['asset_id']][$c['check_date']] = $bad ? 'fault' : ($c['internet'] === 'slow' ? 'warn' : ($c['power'] === 'off' ? 'off' : 'ok'));
    }
}
$today = date('Y-m-d');
$tileData = function ($a) use ($openTickets, $today) {
    return [
        'id' => (int)$a['id'], 'ws' => $a['workstation_no'] ?: $a['asset_tag'], 'tag' => $a['asset_tag'],
        'brand' => trim(($a['brand'] ?? '') . ' ' . ($a['model'] ?? '')), 'power' => $a['power_status'] ?: 'off',
        'screen' => $a['screen_status'], 'cpu' => $a['cpu_status'], 'keyboard' => $a['keyboard_status'], 'mouse' => $a['mouse_status'],
        'internet' => $a['internet_status'], 'health' => itMachineHealth($a), 'today' => !empty($a['last_check_at']) && substr($a['last_check_at'], 0, 10) === $today,
        'last' => $a['last_check_at'], 'tickets' => (int)($openTickets[$a['id']] ?? 0), 'status' => $a['status'],
    ];
};
$typeLabel = ['monitor' => '🖥️ Monitor', 'keyboard' => '⌨️ Keyboard', 'mouse' => '🖱️ Mouse', 'ups' => '🔋 UPS', 'smartboard' => '📺 Smart Board', 'projector' => '📽️ Projector', 'printer' => '🖨️ Printer', 'other' => '📦 इतर'];
?>
<style>
  .lb-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
  .lb-sub{font-size:12px;color:var(--text-dim);margin-top:3px;}
  .lb-act{display:flex;gap:6px;flex-wrap:wrap;}
  .lb-act a,.lb-act button{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;white-space:nowrap;}
  .lb-act .pri{background:var(--rail);border-color:var(--rail);color:#fff;}
  .lb-k{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:12px;}
  .lb-k > div{background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:10px 12px;min-width:0;}
  .lb-k b{display:block;font-size:20px;line-height:1.15;font-weight:800;}
  .lb-k span{font-size:11px;color:var(--text-dim);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .lb-k .pb{height:4px;background:var(--canvas);border-radius:4px;margin-top:6px;overflow:hidden;}
  .lb-k .pb i{display:block;height:100%;background:#16A34A;transition:width .3s;}
  .lb-bulk{border:1px dashed var(--rail);border-radius:12px;margin-bottom:12px;background:var(--surface);}
  .lb-bulk > summary{list-style:none;cursor:pointer;padding:11px 14px;font-weight:700;font-size:13px;color:var(--rail);}
  .lb-bulk > summary::-webkit-details-marker{display:none;}
  .lb-bulk form{padding:4px 14px 14px;}
  .lb-prev{font-size:12px;color:var(--text-dim);margin-top:8px;}
  .lb-prev b{color:var(--ink);font-family:'JetBrains Mono',monospace;}
  .lb-tools{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:10px;}
  .lb-tools input{flex:1 1 180px;min-width:0;font-size:13px !important;padding:8px 12px !important;}
  .lb-f{display:flex;gap:5px;overflow-x:auto;scrollbar-width:none;}
  .lb-f button{flex:0 0 auto;border:1px solid var(--line);background:var(--surface);border-radius:999px;font-size:12px;font-weight:600;padding:6px 11px;color:var(--text-dim);}
  .lb-f button.on{background:var(--ink);color:#fff;border-color:var(--ink);}
  .lb-f button em{font-style:normal;opacity:.7;margin-left:3px;}
  .lb-allok{border:0;background:#16A34A;color:#fff;border-radius:10px;font-size:12.5px;font-weight:700;padding:8px 14px;white-space:nowrap;}
  .lb-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:10px;margin-bottom:16px;}
  .mt{position:relative;text-align:left;border:1px solid var(--line);border-left:4px solid #CBD5E1;background:var(--surface);border-radius:12px;padding:10px 10px 9px;cursor:pointer;transition:transform .12s, box-shadow .12s;}
  .mt:hover{transform:translateY(-1px);box-shadow:0 6px 16px -10px rgba(0,0,0,.35);}
  .mt.h-ok{border-left-color:#16A34A;} .mt.h-warn{border-left-color:#F59E0B;} .mt.h-fault{border-left-color:#DC2626;background:#FFF7F7;}
  .mt .ws{font-weight:800;font-size:14px;font-family:'JetBrains Mono',monospace;display:flex;align-items:center;gap:6px;}
  .mt .pw{width:9px;height:9px;border-radius:50%;background:#CBD5E1;flex:0 0 9px;}
  .mt .pw.on{background:#22C55E;box-shadow:0 0 0 3px rgba(34,197,94,.18);}
  .mt .br{font-size:10.5px;color:var(--text-dim);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;min-height:13px;}
  .mt .pr{display:flex;gap:4px;margin-top:7px;}
  .mt .pr span{flex:1;text-align:center;font-size:13px;border-radius:6px;padding:3px 0;background:var(--canvas);filter:grayscale(1);opacity:.55;}
  .mt .pr span.ok{background:#DCFCE7;filter:none;opacity:1;}
  .mt .pr span.fault{background:#FEE2E2;filter:none;opacity:1;box-shadow:inset 0 0 0 1px #FCA5A5;}
  .mt .pr span.slow{background:#FEF3C7;filter:none;opacity:1;}
  .mt .pr span.down{background:#FEE2E2;filter:none;opacity:1;box-shadow:inset 0 0 0 1px #FCA5A5;}
  .mt .ft{display:flex;justify-content:space-between;align-items:center;margin-top:7px;font-size:10.5px;color:var(--text-dim);}
  .mt .ft .td{color:#16A34A;font-weight:700;}
  .mt .ft .pend{color:#B45309;font-weight:600;}
  .mt .tk{position:absolute;top:6px;right:7px;font-size:10px;font-weight:700;color:#B91C1C;background:#FEE2E2;border-radius:999px;padding:1px 6px;}
  .mt.hide{display:none;}
  .lb-oth{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
  .lb-oth span{font-size:12px;background:var(--surface);border:1px solid var(--line);border-radius:999px;padding:5px 11px;}
  .lb-hist{border:1px solid var(--line);border-radius:12px;background:var(--surface);}
  .lb-hist > summary{list-style:none;cursor:pointer;padding:12px 14px;font-weight:700;font-size:13px;}
  .lb-hist > summary::-webkit-details-marker{display:none;}
  .lb-hwrap{overflow-x:auto;padding:0 14px 12px;}
  .lb-ht{border-collapse:separate;border-spacing:3px;font-size:10.5px;}
  .lb-ht th{font-weight:600;color:var(--text-dim);text-align:center;padding:0 2px;white-space:nowrap;}
  .lb-ht td.n{font-family:'JetBrains Mono',monospace;font-weight:700;padding-right:6px;white-space:nowrap;}
  .lb-ht td.c{width:20px;height:18px;border-radius:4px;background:var(--canvas);}
  .lb-ht td.ok{background:#4ADE80;} .lb-ht td.warn{background:#FBBF24;} .lb-ht td.fault{background:#F87171;} .lb-ht td.off{background:#94A3B8;}
  .lb-lg{display:flex;gap:10px;flex-wrap:wrap;font-size:11px;color:var(--text-dim);padding:0 14px 12px;}
  .lb-lg i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:4px;vertical-align:-1px;}
  /* तपासणी Sheet */
  #mcSheet .modal-content{border-radius:18px;}
  .mc-row{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px dashed var(--line);}
  .mc-row:last-of-type{border-bottom:0;}
  .mc-row .l{flex:1;font-weight:600;font-size:13.5px;}
  .mc-seg{display:flex;background:var(--canvas);border-radius:10px;padding:3px;gap:3px;}
  .mc-seg label{margin:0;}
  .mc-seg input{position:absolute;opacity:0;pointer-events:none;}
  .mc-seg span{display:block;padding:7px 12px;border-radius:8px;font-size:12.5px;font-weight:700;color:var(--text-dim);cursor:pointer;white-space:nowrap;}
  .mc-seg input:checked + span{background:var(--surface);box-shadow:0 1px 3px rgba(0,0,0,.15);}
  .mc-seg input[value=ok]:checked + span,.mc-seg input[value=on]:checked + span{color:#15803D;}
  .mc-seg input[value=fault]:checked + span,.mc-seg input[value=down]:checked + span{color:#B91C1C;}
  .mc-seg input[value=slow]:checked + span{color:#B45309;}
  .mc-seg input[value=off]:checked + span{color:#475569;}
  .mc-seg input:disabled + span{cursor:default;}
  .mc-hist{font-size:11.5px;color:var(--text-dim);max-height:150px;overflow-y:auto;margin-top:8px;}
  .mc-hist div{padding:4px 0;border-bottom:1px solid var(--line);}
  @media (max-width:991px){ .lb-k{grid-template-columns:repeat(3,1fr);} }
  @media (max-width:767px){
    body.modal-open .mb-nav{transform:translateY(110%);} /* तपासणी Sheet ची बटणं खालच्या Menu खाली झाकू नयेत */
    .lb-act{width:100%;flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;}
    .lb-k{gap:6px;} .lb-k > div{padding:8px 10px;} .lb-k b{font-size:17px;}
    .lb-grid{grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:8px;}
    .mt{padding:8px 8px 7px;} .mt .ws{font-size:13px;} .mt .pr span{font-size:12px;}
    #mcSheet .modal-dialog{margin:0;position:fixed;bottom:0;left:0;right:0;max-width:none;}
    #mcSheet .modal-content{border-radius:18px 18px 0 0;max-height:88vh;overflow-y:auto;}
    #mcSheet.fade .modal-dialog{transform:translateY(100%);}
    #mcSheet.show .modal-dialog{transform:none;}
    .mc-seg span{padding:7px 10px;}
  }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?> · IT विभाग</div>
<div class="lb-head">
  <div>
    <div class="page-title mb-0">🖥️ <?= h($lab['lab_name']) ?></div>
    <div class="lb-sub">
      <?= $lab['location'] ? '📍 ' . h($lab['location']) . ' · ' : '' ?>
      👤 <?= $lab['assistant_name'] ? h($lab['assistant_name']) : 'Assistant नेमलेला नाही' ?>
      <?= $lab['capacity'] ? ' · क्षमता ' . (int)$lab['capacity'] : '' ?>
    </div>
  </div>
  <div class="lb-act">
    <a href="assets.php?lab_id=<?= $labId ?>">📋 सर्व Assets</a>
    <a href="smartboard-report.php">📺 Smart Board</a>
    <a href="labs.php">← Labs</a>
  </div>
</div>

<?php if (!$ready): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ Machine तपासणीसाठी Database Update बाकी आहे — Super Admin ने <b>System Update → Database Update</b> चालवावं.</div>
<?php endif; ?>

<?php if ($ready): ?>
<div class="lb-k" id="lbK">
  <div><b data-k="n"><?= count($machines) ?></b><span>🖥️ Machines</span></div>
  <div><b data-k="ok" style="color:#16A34A;">0</b><span>✅ सगळं ठीक</span></div>
  <div><b data-k="fault" style="color:#DC2626;">0</b><span>⚠️ बिघाड</span></div>
  <div><b data-k="on" style="color:#2563EB;">0</b><span>⚡ चालू</span></div>
  <div><b data-k="net" style="color:#B45309;">0</b><span>🌐 Internet अडचण</span></div>
  <div><b data-k="today">0</b><span>📋 आज तपासणी</span><div class="pb"><i data-k="todaybar" style="width:0"></i></div></div>
</div>

<?php if ($canBulk): ?>
<details class="lb-bulk" <?= !$machines ? 'open' : '' ?>>
  <summary>➕ एकाच वेळी अनेक Machines जोडा</summary>
  <form method="POST" id="bulkForm">
    <input type="hidden" name="action" value="bulk_add"><input type="hidden" name="lab_id" value="<?= $labId ?>">
    <div class="row g-2">
      <div class="col-6 col-md-2"><label class="form-label-custom">किती Machines</label><input type="number" name="count" min="1" max="200" value="<?= max(1, (int)$lab['capacity'] - count($machines)) ?: 20 ?>" class="form-control-custom" required></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">नावाची सुरुवात</label><input type="text" name="prefix" value="PC-" maxlength="20" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">पहिला क्रमांक</label><input type="number" name="start" min="0" value="<?= count($machines) + 1 ?>" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">अंक (01 / 001)</label><select name="pad" class="form-select-custom"><option value="2">01</option><option value="3">001</option><option value="1">1</option></select></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">प्रकार</label><select name="asset_type" class="form-select-custom"><option value="computer">💻 Computer</option><option value="cpu">🖥️ CPU</option></select></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">Brand</label><input type="text" name="brand" class="form-control-custom" placeholder="उदा. Dell"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">Model</label><input type="text" name="model" class="form-control-custom" placeholder="उदा. OptiPlex 3000"></div>
      <div class="col-6 col-md-3"><label class="form-label-custom">OS / License</label><input type="text" name="os_license" class="form-control-custom" placeholder="उदा. Windows 11"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">खरेदी दिनांक</label><input type="date" name="purchase_date" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">Warranty पर्यंत</label><input type="date" name="warranty_expiry" class="form-control-custom"></div>
      <div class="col-6 col-md-2"><label class="form-label-custom">प्रत्येकी किंमत ₹</label><input type="number" name="purchase_cost" min="0" step="0.01" class="form-control-custom"></div>
    </div>
    <div class="lb-prev" id="bulkPrev"></div>
    <button type="submit" class="btn mt-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;font-weight:700;padding:8px 18px;">✓ Machines जोडा</button>
  </form>
</details>
<?php endif; ?>

<?php if ($machines): ?>
<div class="lb-tools">
  <input type="search" id="lbQ" class="form-control-custom" placeholder="🔍 PC क्रमांक / Tag शोधा…" autocomplete="off">
  <div class="lb-f" id="lbF">
    <button type="button" data-f="" class="on">सर्व<em data-c=""></em></button>
    <button type="button" data-f="pending">📋 तपासणी बाकी<em data-c="pending"></em></button>
    <button type="button" data-f="fault">⚠️ बिघाड<em data-c="fault"></em></button>
    <button type="button" data-f="off">⭘ बंद<em data-c="off"></em></button>
    <button type="button" data-f="net">🌐 Internet<em data-c="net"></em></button>
  </div>
  <?php if ($canCheck): ?>
    <form method="POST" onsubmit="return confirm('आज न तपासलेल्या सगळ्या Machines — Screen, CPU, Keyboard, Mouse ठीक, चालू व Internet चालू — म्हणून नोंदवायच्या? (आधी बिघाड असलेल्या वगळल्या जातील)');">
      <input type="hidden" name="action" value="all_ok"><input type="hidden" name="lab_id" value="<?= $labId ?>">
      <button type="submit" class="lb-allok" id="lbAllOk">✓ बाकी सगळे ठीक</button>
    </form>
  <?php endif; ?>
</div>

<div class="lb-grid" id="lbGrid">
  <?php foreach ($machines as $a): $d = $tileData($a); ?>
    <button type="button" class="mt h-<?= h($d['health']) ?>" data-m='<?= h(json_encode($d, JSON_UNESCAPED_UNICODE)) ?>'></button>
  <?php endforeach; ?>
</div>
<?php elseif ($ready): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">🖥️ या Lab मध्ये अजून Computer / CPU नाही.<?= $canBulk ? ' वरच्या "एकाच वेळी अनेक Machines जोडा" मधून जोडा.' : ' Super Admin / IT विभागाकडून जोडून घ्या.' ?></div>
<?php endif; ?>

<?php if ($others): ?>
  <div class="lb-oth">
    <?php foreach ($others as $o): ?>
      <span><?= $typeLabel[$o['asset_type']] ?? h($o['asset_type']) ?> · <?= (int)$o['n'] ?><?= (int)$o['on_n'] ? ' · ⚡' . (int)$o['on_n'] : '' ?><?= (int)$o['bad'] ? ' · <b style="color:#DC2626">⚠️' . (int)$o['bad'] . '</b>' : '' ?></span>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if ($machines): ?>
<details class="lb-hist" id="lbHist">
  <summary>📅 मागील १४ दिवसांचा इतिहास</summary>
  <div class="lb-lg"><span><i style="background:#4ADE80"></i>ठीक</span><span><i style="background:#FBBF24"></i>Internet हळू</span><span><i style="background:#F87171"></i>बिघाड</span><span><i style="background:#94A3B8"></i>बंद</span><span><i style="background:var(--canvas);border:1px solid var(--line)"></i>तपासणी नाही</span></div>
  <div class="lb-hwrap">
    <table class="lb-ht">
      <thead><tr><th></th><?php foreach ($days as $dd): ?><th title="<?= h($dd) ?>"><?= date('d', strtotime($dd)) ?><br><?= mb_substr(['र', 'सो', 'मं', 'बु', 'गु', 'शु', 'श'][(int)date('w', strtotime($dd))], 0, 2) ?></th><?php endforeach; ?></tr></thead>
      <tbody>
        <?php foreach ($machines as $a): ?>
          <tr><td class="n"><?= h($a['workstation_no'] ?: $a['asset_tag']) ?></td>
            <?php foreach ($days as $dd): $s = $hist[$a['id']][$dd] ?? ''; ?><td class="c <?= $s ?>" title="<?= h($dd . ($s ? ' · ' . $s : ' · तपासणी नाही')) ?>"></td><?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
<?php endif; ?>
<?php endif; ?>

<!-- 🩺 तपासणी Sheet (एकच, Tile वरून भरतो) -->
<div class="modal fade" id="mcSheet" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form method="POST" id="mcForm">
      <input type="hidden" name="action" value="check"><input type="hidden" name="lab_id" value="<?= $labId ?>"><input type="hidden" name="asset_id" value="">
      <div class="modal-body" style="padding:18px 20px;">
        <div class="d-flex justify-content-between align-items-start mb-2">
          <div><div style="font-weight:800;font-size:17px;font-family:'JetBrains Mono',monospace;" data-mc="ws"></div><div style="font-size:11.5px;color:var(--text-dim);" data-mc="sub"></div></div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="बंद"></button>
        </div>
        <div class="mc-row"><div class="l">⚡ Power</div>
          <div class="mc-seg"><label><input type="radio" name="power" value="on"><span>🟢 चालू</span></label><label><input type="radio" name="power" value="off"><span>⭘ बंद</span></label></div></div>
        <?php foreach (IT_LAB_PARTS as $k => [$ic, $nm]): ?>
          <div class="mc-row"><div class="l"><?= $ic ?> <?= $nm ?></div>
            <div class="mc-seg"><label><input type="radio" name="<?= $k ?>" value="ok"><span>✓ ठीक</span></label><label><input type="radio" name="<?= $k ?>" value="fault"><span>✕ खराब</span></label></div></div>
        <?php endforeach; ?>
        <div class="mc-row"><div class="l">🌐 Internet</div>
          <div class="mc-seg"><label><input type="radio" name="internet" value="ok"><span>चालू</span></label><label><input type="radio" name="internet" value="slow"><span>हळू</span></label><label><input type="radio" name="internet" value="down"><span>बंद</span></label></div></div>
        <input type="text" name="remarks" maxlength="255" class="form-control-custom mt-2" placeholder="टिप्पणी (ऐच्छिक) — उदा. Keyboard ची Enter Key अडकते">
        <label class="d-flex align-items-center gap-2 mt-2" style="font-size:12.5px;" id="mcTicketWrap"><input type="checkbox" name="open_ticket" value="1"> 🛠️ बिघाड असल्यास IT दुरुस्ती Ticket उघडा</label>
        <div class="mc-hist" data-mc="hist"></div>
      </div>
      <?php if ($canCheck && $ready): ?>
      <div class="modal-footer" style="border-top:1px solid var(--line);padding:10px 20px;">
        <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;" data-mc="allok">✓ सगळं ठीक</button>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:700;padding:8px 20px;">जतन करा</button>
      </div>
      <?php endif; ?>
    </form>
  </div></div>
</div>

<script>
(function () {
  var CAN = <?= $canCheck && $ready ? 'true' : 'false' ?>, LAB = <?= $labId ?>;
  var grid = document.getElementById('lbGrid');
  var PARTS = [['screen', '🖥️'], ['cpu', '💻'], ['keyboard', '⌨️'], ['mouse', '🖱️']];
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function ago(t) { if (!t) return 'कधीच नाही'; var d = (Date.now() - new Date(t.replace(' ', 'T')).getTime()) / 60000; if (d < 2) return 'आत्ताच'; if (d < 60) return Math.round(d) + ' मि.'; if (d < 1440) return Math.round(d / 60) + ' तास'; return Math.round(d / 1440) + ' दिवस'; }
  function render(btn) {
    var m = JSON.parse(btn.getAttribute('data-m'));
    btn.className = 'mt h-' + m.health;
    var pr = PARTS.map(function (p) { return '<span class="' + (m[p[0]] || '') + '" title="' + p[0] + '">' + p[1] + '</span>'; }).join('')
      + '<span class="' + (m.internet || '') + '" title="internet">🌐</span>';
    btn.innerHTML = (m.tickets ? '<span class="tk">🛠️' + m.tickets + '</span>' : '')
      + '<div class="ws"><span class="pw ' + (m.power === 'on' ? 'on' : '') + '"></span>' + esc(m.ws) + '</div>'
      + '<div class="br">' + esc(m.brand || m.tag) + '</div><div class="pr">' + pr + '</div>'
      + '<div class="ft">' + (m.today ? '<span class="td">✓ आज</span>' : '<span class="pend">तपासणी बाकी</span>') + '<span>' + ago(m.last) + '</span></div>';
    btn.setAttribute('aria-label', m.ws);
  }
  var tiles = grid ? Array.prototype.slice.call(grid.querySelectorAll('.mt')) : [];
  tiles.forEach(render);

  // 📊 आकडे व Filter संख्या — Tiles वरून (जतन केल्यावर लगेच अद्ययावत)
  function stats() {
    var c = { n: 0, ok: 0, fault: 0, on: 0, net: 0, today: 0, pending: 0, off: 0 };
    tiles.forEach(function (t) { var m = JSON.parse(t.getAttribute('data-m')); c.n++;
      if (m.health === 'ok') c.ok++; if (m.health === 'fault') c.fault++; if (m.power === 'on') c.on++; else c.off++;
      if (m.internet === 'down' || m.internet === 'slow') c.net++; if (m.today) c.today++; else c.pending++; });
    var K = document.getElementById('lbK'); if (!K) return;
    ['n', 'ok', 'fault', 'on', 'net'].forEach(function (k) { K.querySelector('[data-k="' + k + '"]').textContent = c[k]; });
    K.querySelector('[data-k="today"]').textContent = c.today + '/' + c.n;
    K.querySelector('[data-k="todaybar"]').style.width = (c.n ? Math.round(c.today * 100 / c.n) : 0) + '%';
    var cnt = { '': c.n, pending: c.pending, fault: c.fault, off: c.off, net: c.net };
    document.querySelectorAll('#lbF em').forEach(function (e) { e.textContent = cnt[e.getAttribute('data-c')]; });
    var ao = document.getElementById('lbAllOk'); if (ao) { ao.disabled = !c.pending; ao.textContent = '✓ बाकी सगळे ठीक (' + c.pending + ')'; }
  }
  stats();

  var q = document.getElementById('lbQ'), F = document.getElementById('lbF'), fv = '';
  function filter() {
    var term = q ? q.value.trim().toLowerCase() : '';
    tiles.forEach(function (t) { var m = JSON.parse(t.getAttribute('data-m'));
      var ok = (!term || (m.ws + ' ' + m.tag + ' ' + m.brand).toLowerCase().indexOf(term) !== -1)
        && (!fv || (fv === 'pending' && !m.today) || (fv === 'fault' && m.health === 'fault') || (fv === 'off' && m.power !== 'on') || (fv === 'net' && (m.internet === 'down' || m.internet === 'slow')));
      t.classList.toggle('hide', !ok); });
  }
  if (q) q.addEventListener('input', filter);
  if (F) F.addEventListener('click', function (e) { var b = e.target.closest('button'); if (!b) return; fv = b.getAttribute('data-f');
    F.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); }); filter(); });

  // 🩺 तपासणी Sheet
  var sheetEl = document.getElementById('mcSheet'), form = document.getElementById('mcForm'), cur = null, sheet = null;
  function setRadio(n, v) { var r = form.querySelector('input[name="' + n + '"][value="' + v + '"]'); if (r) r.checked = true; }
  function anyFault() { return PARTS.some(function (p) { var r = form.querySelector('input[name="' + p[0] + '"]:checked'); return r && r.value === 'fault'; }) || (form.querySelector('input[name="internet"]:checked') || {}).value === 'down'; }
  function syncTicket() { var cb = form.querySelector('[name="open_ticket"]'); var f = anyFault(); document.getElementById('mcTicketWrap').style.display = f ? '' : 'none'; if (!f) cb.checked = false; }
  form.addEventListener('change', function (e) { if (e.target.type === 'radio') { if (anyFault()) form.querySelector('[name="open_ticket"]').checked = true; syncTicket(); } });
  if (grid) grid.addEventListener('click', function (e) {
    var t = e.target.closest('.mt'); if (!t) return; cur = t; var m = JSON.parse(t.getAttribute('data-m'));
    form.asset_id.value = m.id;
    sheetEl.querySelector('[data-mc="ws"]').textContent = m.ws;
    sheetEl.querySelector('[data-mc="sub"]').textContent = (m.brand ? m.brand + ' · ' : '') + m.tag + ' · शेवटची तपासणी: ' + ago(m.last);
    // आजची नोंद नसेल तर — आधीची स्थिती, नसल्यास "ठीक" गृहीत
    setRadio('power', m.power === 'on' || !m.last ? 'on' : 'off');
    PARTS.forEach(function (p) { setRadio(p[0], m[p[0]] || 'ok'); });
    setRadio('internet', m.internet || 'ok');
    form.remarks.value = ''; form.querySelector('[name="open_ticket"]').checked = false; syncTicket();
    form.querySelectorAll('input').forEach(function (i) { if (i.type !== 'hidden') i.disabled = !CAN; });
    var hb = sheetEl.querySelector('[data-mc="hist"]'); hb.textContent = '…';
    fetch('lab.php?id=' + LAB + '&history=' + m.id, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      var h = (d.tickets || []).map(function (k) { return '<div>🛠️ <b>' + (k.status === 'resolved' ? 'सोडवलं' : 'उघडं') + '</b> · ' + esc(k.issue_description) + '</div>'; }).join('');
      h += (d.checks || []).map(function (c) { var bad = PARTS.filter(function (p) { return c[p[0]] === 'fault'; }).map(function (p) { return p[1]; }).join(' ');
        return '<div>' + esc(c.check_date) + ' · ' + (c.power === 'on' ? '🟢' : '⭘') + ' ' + (bad ? '✕ ' + bad : '✓') + (c.internet !== 'ok' ? ' · 🌐 ' + (c.internet === 'slow' ? 'हळू' : 'बंद') : '') + (c.remarks ? ' · ' + esc(c.remarks) : '') + ' <span style="opacity:.7">— ' + esc(c.name || '') + '</span></div>'; }).join('');
      hb.innerHTML = h ? '<div style="font-weight:700;margin-bottom:2px;">📜 इतिहास</div>' + h : '<div>अजून तपासणी नोंद नाही.</div>';
    }).catch(function () { hb.textContent = ''; });
    sheet = sheet || new bootstrap.Modal(sheetEl); sheet.show();
  });
  var allBtn = sheetEl.querySelector('[data-mc="allok"]');
  if (allBtn) allBtn.addEventListener('click', function () { setRadio('power', 'on'); PARTS.forEach(function (p) { setRadio(p[0], 'ok'); }); setRadio('internet', 'ok'); syncTicket(); });
  form.addEventListener('submit', function (e) {
    if (!cur || !window.fetch) return; e.preventDefault();
    var btn = form.querySelector('[type=submit]'); btn.disabled = true;
    fetch('lab.php?id=' + LAB, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch' } })
      .then(function (r) { return r.json(); }).then(function (d) {
        btn.disabled = false;
        if (!d.ok) { alert(d.msg || 'जतन झालं नाही'); return; }
        var a = d.asset, m = JSON.parse(cur.getAttribute('data-m'));
        m.power = a.power_status; m.screen = a.screen_status; m.cpu = a.cpu_status; m.keyboard = a.keyboard_status; m.mouse = a.mouse_status;
        m.internet = a.internet_status; m.last = a.last_check_at; m.today = true; m.status = a.status;
        var bad = [m.screen, m.cpu, m.keyboard, m.mouse].indexOf('fault') !== -1 || m.internet === 'down' || a.status === 'faulty' || a.status === 'under_repair';
        m.health = bad ? 'fault' : (m.internet === 'slow' ? 'warn' : 'ok');
        if (d.ticket && !m.tickets) m.tickets = 1;
        cur.setAttribute('data-m', JSON.stringify(m)); render(cur); stats(); filter();
        sheet.hide();
        // पुढची तपासणी बाकी असलेली Machine — पटापट तपासणीसाठी
        var next = tiles.filter(function (t) { return !JSON.parse(t.getAttribute('data-m')).today && !t.classList.contains('hide'); })[0];
        if (next) next.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
      }).catch(function () { btn.disabled = false; form.submit(); });
  });

  // ➕ अनेक Machines — नावांचं पूर्वावलोकन
  var bf = document.getElementById('bulkForm'), bp = document.getElementById('bulkPrev');
  function prev() { if (!bf) return; var n = Math.max(1, Math.min(200, +bf.count.value || 1)), s = +bf.start.value || 0, p = +bf.pad.value || 2, x = bf.prefix.value;
    function nm(i) { return x + String(s + i).padStart(p, '0'); }
    bp.innerHTML = n + ' Machines: <b>' + esc(nm(0)) + '</b>' + (n > 1 ? ', <b>' + esc(nm(1)) + '</b>' + (n > 2 ? ' … <b>' + esc(nm(n - 1)) + '</b>' : '') : ''); }
  if (bf) { bf.addEventListener('input', prev); prev(); }
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
