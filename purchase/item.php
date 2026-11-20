<?php
// 🛒 एक खरेदी मागणी — नवीन / बदल / पाहणे; खरेदी पूर्ण, रद्द, पुन्हा बाकी; इतिहास
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/purchase.php';
if (!canViewPurchase()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">खरेदी विभाग पाहण्याची परवानगी नाही.</div>'); }
if (!purchaseReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$user = currentUser();
$canEdit = canPurchase();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

$row = null;
if ($id) {
    $st = $pdo->prepare("SELECT * FROM purchase_requests WHERE id = ? AND organization_id = ?");
    $st->execute([$id, $orgId]);
    $row = $st->fetch();
    if (!$row) { $_SESSION['flash'] = '⚠️ नोंद सापडली नाही.'; header('Location: index.php'); exit; }
} elseif (!$canEdit) { header('Location: index.php'); exit; }

$error = '';
$dt = fn($k) => (is_string($_POST[$k] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST[$k])) ? $_POST[$k] : null;
$tx = fn($k, $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'save_item') {
        $in = [
            'fy' => preg_match('/^\d{4}-\d{2}$/', (string)($_POST['fy'] ?? '')) ? $_POST['fy'] : purchaseFy($dt('demand_date')),
            'sr_no' => (int)($_POST['sr_no'] ?? 0) ?: null,
            'department' => $tx('department', 150), 'demand_no' => $tx('demand_no', 60), 'demand_date' => $dt('demand_date'),
            'approval_no' => $tx('approval_no', 60), 'approval_date' => $dt('approval_date'), 'inward_no' => $tx('inward_no', 60), 'inward_date' => $dt('inward_date'),
            'title' => $tx('title', 500), 'items' => trim(preg_replace("/\n{2,}/", "\n", str_replace("\r", '', (string)($_POST['items'] ?? '')))),
            'est_amount' => trim((string)($_POST['est_amount'] ?? '')) === '' ? null : round((float)str_replace([',', '₹', ' '], '', $_POST['est_amount']), 2),
            'pending_reason' => $tx('pending_reason', 255), 'remarks' => $tx('remarks', 500),
        ];
        if ($in['department'] === '') $error = 'विभाग भरा.';
        elseif (mb_strlen($in['title']) < 3) $error = 'तपशील (काय खरेदी करायचं) भरा.';
        // खरेदी आवक क्र. आपोआप — आवक दिनांक भरला / "आपोआप" निवडलं आणि क्र. रिकामा
        if (!$error && $in['inward_no'] === '' && ($in['inward_date'] || !empty($_POST['auto_inward']))) {
            if (!$in['inward_date']) $in['inward_date'] = date('Y-m-d');
            $in['inward_no'] = purchaseNextInward($pdo, $orgId, $in['inward_date'], $row ? $id : 0);
        }
        if (!$error) {
            $vals = [$in['fy'], $in['sr_no'], $in['department'], $in['demand_no'] ?: null, $in['demand_date'], $in['approval_no'] ?: null, $in['approval_date'], $in['inward_no'] ?: null, $in['inward_date'],
                $in['title'], $in['items'] ?: null, $in['est_amount'], $in['pending_reason'] ?: null, $in['remarks'] ?: null];
            if ($row) {
                $pdo->prepare("UPDATE purchase_requests SET fy=?, sr_no=?, department=?, demand_no=?, demand_date=?, approval_no=?, approval_date=?, inward_no=?, inward_date=?, title=?, items=?, est_amount=?, pending_reason=?, remarks=?, updated_by=?, updated_at=NOW() WHERE id=? AND organization_id=?")
                    ->execute(array_merge($vals, [$user['id'], $id, $orgId]));
                // काय बदललं ते इतिहासात
                $lbl = ['department' => 'विभाग', 'demand_no' => 'मागणी क्र.', 'demand_date' => 'मागणी दिनांक', 'approval_no' => 'मंजुरी क्र.', 'approval_date' => 'मंजुरी दिनांक', 'inward_no' => 'आवक क्र.', 'inward_date' => 'आवक दिनांक',
                    'title' => 'तपशील', 'items' => 'साहित्य', 'est_amount' => 'रक्कम', 'pending_reason' => 'प्रलंबित कारण', 'remarks' => 'शेरा', 'fy' => 'आर्थिक वर्ष', 'sr_no' => 'अ.क्र.'];
                $ch = [];
                foreach ($lbl as $k => $l) { $o = (string)($row[$k] ?? ''); $nv = (string)($in[$k] ?? ''); if ($k === 'est_amount') { $o = $o === '' ? '' : (string)(float)$o; $nv = $nv === '' ? '' : (string)(float)$nv; } if ($o !== $nv) $ch[] = $l; }
                if ($ch) purchaseLog($pdo, $orgId, $id, 'edited', implode(', ', $ch), $user['id']);
                logActivity('खरेदी मागणी बदलली', 'purchase_request', $id, $in['title']);
                $_SESSION['flash'] = '✓ जतन झालं' . ($ch ? ' (' . implode(', ', $ch) . ')' : '') . '.';
            } else {
                if (!$in['sr_no']) $vals[1] = purchaseNextSr($pdo, $orgId, $in['fy']);
                $pdo->prepare("INSERT INTO purchase_requests (fy, sr_no, department, demand_no, demand_date, approval_no, approval_date, inward_no, inward_date, title, items, est_amount, pending_reason, remarks, organization_id, status, created_by, created_at)
                    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,NOW())")->execute(array_merge($vals, [$orgId, $user['id']]));
                $id = (int)$pdo->lastInsertId();
                purchaseLog($pdo, $orgId, $id, 'created', null, $user['id']);
                logActivity('खरेदी मागणी नोंद', 'purchase_request', $id, $in['title']);
                $_SESSION['flash'] = '✓ नवीन खरेदी मागणी नोंदली — "खरेदी बाकी" यादीत.';
            }
            header('Location: item.php?id=' . $id); exit;
        }
        $row = array_merge($row ?: [], $in);
    }
    if ($row && $act === 'cancel_item') {
        $pdo->prepare("UPDATE purchase_requests SET status = 'cancelled', updated_by = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$user['id'], $id, $orgId]);
        purchaseLog($pdo, $orgId, $id, 'cancelled', $tx('note', 255) ?: null, $user['id']);
        logActivity('खरेदी मागणी रद्द', 'purchase_request', $id, $row['title']);
        $_SESSION['flash'] = '✕ मागणी रद्द केली.'; header('Location: item.php?id=' . $id); exit;
    }
    if ($row && $act === 'delete_item') {
        if (function_exists('recycleCapture')) recycleCapture($pdo, 'purchase_requests', $id, '🛒 ' . mb_strimwidth($row['title'], 0, 100, '…'), $orgId);
        $pdo->prepare("DELETE FROM purchase_request_events WHERE request_id = ? AND organization_id = ?")->execute([$id, $orgId]);
        $pdo->prepare("DELETE FROM purchase_requests WHERE id = ? AND organization_id = ?")->execute([$id, $orgId]);
        logActivity('खरेदी मागणी हटवली', 'purchase_request', $id, $row['title']);
        $_SESSION['flash'] = '🗑️ नोंद हटवली (Super Admin → Recycle Bin मधून परत आणता येईल).'; header('Location: index.php'); exit;
    }
}

$events = [];
if ($row && !empty($row['id'])) {
    $st = $pdo->prepare("SELECT e.*, u.name uname FROM purchase_request_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.request_id = ? AND e.organization_id = ? ORDER BY e.id DESC");
    $st->execute([$id, $orgId]);
    $events = $st->fetchAll();
}
$edit = $canEdit && (!$row || ($row['status'] ?? 'pending') !== 'cancelled' || true);
$v = fn($k, $d = '') => $row[$k] ?? $d;
$depts = purchaseDepartments($pdo, $orgId);
$fyNow = purchaseFy();
$fyList = array_unique(array_filter([$fyNow, purchaseFy(date('Y-m-d', strtotime('-1 year'))), $v('fy')]));
rsort($fyList);

$pageTitle = $row ? 'खरेदी मागणी' : 'नवीन खरेदी मागणी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
[$sk, $sl] = $row && !empty($row['id']) ? purchaseStage($row) : ['approve', ''];
$age = $row && !empty($row['id']) ? purchaseAgeDays($row) : null;
?>
<style>
  .pi-steps{display:flex;gap:0;margin:4px 0 14px;flex-wrap:wrap;}
  .pi-steps div{flex:1;min-width:120px;text-align:center;font-size:12px;padding:8px 6px;border-top:4px solid var(--line);color:var(--text-dim);font-weight:600;}
  .pi-steps div.on{border-color:#16A34A;color:#166534;} .pi-steps div.cur{border-color:#D97706;color:#92400E;}
  .pi-steps b{display:block;font-size:12.5px;}
  .pi-ev{display:flex;gap:10px;padding:8px 0;border-bottom:1px dashed var(--line);font-size:13px;} .pi-ev small{color:var(--text-dim);}
  .pi-sec{font-size:12px;font-weight:800;letter-spacing:.06em;color:#B45309;text-transform:uppercase;margin:6px 0 2px;}
</style>

<div class="page-eyebrow"><a href="index.php" style="color:inherit;text-decoration:none;">🛒 खरेदी विभाग</a></div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0"><?= $row && !empty($row['id']) ? h(mb_strimwidth($row['title'], 0, 90, '…')) : '＋ नवीन खरेदी मागणी' ?></div>
  <a href="index.php" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">← यादी</a>
</div>
<?php if ($error): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13.5px;">⚠️ <?= h($error) ?></div><?php endif; ?>

<?php if ($row && !empty($row['id'])):
  $step = ['approve' => 1, 'order' => 2, 'process' => 3, 'done' => 4, 'cancel' => 0][$sk]; ?>
  <div class="pi-steps">
    <?php foreach ([1 => ['📨 मागणी', $row['demand_no'] ? 'क्र. ' . $row['demand_no'] : ($row['demand_date'] ? '' : '—'), $row['demand_date']], 2 => ['✍️ मंजुरी', $row['approval_no'], $row['approval_date']], 3 => ['📥 आवक / आदेश', $row['inward_no'], $row['inward_date']], 4 => ['✅ खरेदी पूर्ण', $row['supplier'], $row['done_on']]] as $n => [$t, $no, $d]):
      $done = $sk === 'done' || ($n === 1) || ($n === 2 && $step >= 2) || ($n === 3 && $step >= 3); $cur = !$done && $n === $step + ($step >= 1 ? 1 : 0); ?>
      <div class="<?= $done ? 'on' : ($n === min(4, max(2, $step + 1)) && $sk !== 'cancel' ? 'cur' : '') ?>"><b><?= $t ?></b><?= h(trim(($no ?: '') . ($d ? ($no ? ' · ' : '') . purchaseDate($d) : ''))) ?: ($done ? '✓' : 'बाकी') ?></div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex gap-2 flex-wrap align-items-center mb-3" style="font-size:13px;">
    <span class="status-chip"><?= h($sl) ?></span>
    <?php if ($age !== null): ?><span style="color:var(--text-dim);"><?= $row['status'] === 'done' ? $age . ' दिवसांत पूर्ण' : 'मागणीपासून ' . $age . ' दिवस' ?></span><?php endif; ?>
    <?php if ($row['status'] === 'done'): ?><span>✅ <?= purchaseDate($row['done_on']) ?> · <?= h(purchaseInr($row['actual_amount'])) ?><?= $row['supplier'] ? ' · ' . h($row['supplier']) : '' ?><?= $row['bill_no'] ? ' · बिल ' . h($row['bill_no']) : '' ?></span><?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel">
      <?php if ($canEdit): ?>
      <form method="POST" style="padding:16px 20px;" data-no-smart-filter>
        <input type="hidden" name="action" value="save_item"><input type="hidden" name="id" value="<?= (int)$id ?>">
        <div class="pi-sec">मागणी</div>
        <div class="row g-2">
          <div class="col-md-3 col-6"><label class="form-label-custom">आर्थिक वर्ष</label><select name="fy" class="form-select-custom"><?php foreach ($fyList as $y): ?><option value="<?= h($y) ?>" <?= ($v('fy') ?: $fyNow) === $y ? 'selected' : '' ?>><?= h(purchaseFyLabel($y)) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-2 col-6"><label class="form-label-custom">अ.क्र.</label><input type="number" name="sr_no" class="form-control-custom" value="<?= h($v('sr_no')) ?>" min="1" placeholder="आपोआप"></div>
          <div class="col-md-7"><label class="form-label-custom">विभाग *</label><input type="text" name="department" class="form-control-custom" value="<?= h($v('department')) ?>" list="puDepts" maxlength="150" required></div>
          <div class="col-md-4 col-6"><label class="form-label-custom">मागणी क्रमांक</label><input type="text" name="demand_no" class="form-control-custom" value="<?= h($v('demand_no')) ?>" maxlength="60"></div>
          <div class="col-md-4 col-6"><label class="form-label-custom">मागणी दिनांक</label><input type="date" name="demand_date" class="form-control-custom" value="<?= h($v('demand_date')) ?>"></div>
        </div>
        <div class="pi-sec mt-3">काय खरेदी करायचं</div>
        <div class="row g-2">
          <div class="col-12"><label class="form-label-custom">तपशील *</label><input type="text" name="title" class="form-control-custom" value="<?= h($v('title')) ?>" maxlength="500" required placeholder="उदा. वसतिगृहासाठी आवश्यक साहित्य मिळणेबाबत"></div>
          <div class="col-md-8"><label class="form-label-custom">साहित्य यादी <span style="color:var(--text-dim);font-weight:400;">(प्रत्येक ओळीत एक — उदा. आरशे - 30 नग)</span></label><textarea name="items" class="form-control-custom" rows="5"><?= h($v('items')) ?></textarea></div>
          <div class="col-md-4"><label class="form-label-custom">अंदाजित रक्कम ₹</label><input type="number" name="est_amount" class="form-control-custom" value="<?= h($v('est_amount') !== null && $v('est_amount') !== '' ? (float)$v('est_amount') : '') ?>" min="0" step="0.01" inputmode="decimal"></div>
        </div>
        <div class="pi-sec mt-3">मंजुरी व खरेदी आवक</div>
        <div class="row g-2">
          <div class="col-md-3 col-6"><label class="form-label-custom">मंजुरी क्र.</label><input type="text" name="approval_no" class="form-control-custom" value="<?= h($v('approval_no')) ?>" maxlength="60"></div>
          <div class="col-md-3 col-6"><label class="form-label-custom">मंजुरी दिनांक</label><input type="date" name="approval_date" class="form-control-custom" value="<?= h($v('approval_date')) ?>"></div>
          <div class="col-md-3 col-6"><label class="form-label-custom">खरेदी आवक क्र.</label><input type="text" name="inward_no" id="puInwNo" class="form-control-custom" value="<?= h($v('inward_no')) ?>" maxlength="60" placeholder="आपोआप"><?php if ((string)$v('inward_no') === ''): ?><label class="d-flex align-items-center gap-1 mt-1" style="font-size:12px;white-space:nowrap;color:var(--text-dim);cursor:pointer;"><input type="checkbox" name="auto_inward" value="1" id="puInwAuto" onchange="var d=document.querySelector('[name=inward_date]');if(this.checked&&!d.value)d.value='<?= date('Y-m-d') ?>';document.getElementById('puInwNo').placeholder=this.checked?'<?= h(purchaseNextInward($pdo, $orgId, null, $row ? $id : 0)) ?> (आपोआप)':'आपोआप';"><span>⚡ आपोआप द्या (<b><?= h(purchaseNextInward($pdo, $orgId, null, $row ? $id : 0)) ?></b>)</span></label><?php endif; ?></div>
          <div class="col-md-3 col-6"><label class="form-label-custom">आवक दिनांक</label><input type="date" name="inward_date" class="form-control-custom" value="<?= h($v('inward_date')) ?>"></div>
          <div class="col-md-6"><label class="form-label-custom">खरेदी प्रलंबित राहण्याचे कारण</label><input type="text" name="pending_reason" class="form-control-custom" value="<?= h($v('pending_reason')) ?>" maxlength="255" list="puReasons"></div>
          <div class="col-md-6"><label class="form-label-custom">शेरा</label><input type="text" name="remarks" class="form-control-custom" value="<?= h($v('remarks')) ?>" maxlength="500" list="puRemarks"></div>
        </div>
        <div class="mt-3 d-flex gap-2 flex-wrap"><button type="submit" class="btn" style="background:#D97706;color:#fff;border-radius:10px;font-weight:700;">✓ जतन करा</button></div>
      </form>
      <datalist id="puDepts"><?php foreach ($depts as $d): ?><option value="<?= h($d) ?>"><?php endforeach; ?></datalist>
      <datalist id="puReasons"><option value="कोटेशन घेतले नसल्याने"><option value="कोटेशन घेतले"><option value="मंजुरी बाकी"><option value="पुरवठादाराकडून साहित्य येणे बाकी"><option value="आवश्यक त्या प्रमाणे उपलब्ध करून देण्यात येत आहे"></datalist>
      <datalist id="puRemarks"><option value="ऑर्डर दिलेली आहे"><option value="खरेदी आदेश तयार केला आहे"><option value="कोटेशन घेणे चालू आहे"></datalist>
      <?php else: ?>
      <div style="padding:16px 20px;font-size:13.5px;line-height:1.7;">
        <div><b>विभाग:</b> <?= h($v('department')) ?> · <b>आ.व.</b> <?= h(purchaseFyLabel($v('fy'))) ?> · <b>अ.क्र.</b> <?= (int)$v('sr_no') ?></div>
        <div><b>मागणी:</b> <?= h($v('demand_no') ?: '—') ?> <?= purchaseDate($v('demand_date')) ?> · <b>मंजुरी:</b> <?= h($v('approval_no') ?: '—') ?> <?= purchaseDate($v('approval_date')) ?> · <b>आवक:</b> <?= h($v('inward_no') ?: '—') ?> <?= purchaseDate($v('inward_date')) ?></div>
        <div class="mt-2"><b><?= h($v('title')) ?></b></div>
        <?php if ($v('items')): ?><ul class="mb-1"><?php foreach (array_filter(explode("\n", $v('items'))) as $it): ?><li><?= h($it) ?></li><?php endforeach; ?></ul><?php endif; ?>
        <div><b>अंदाजित रक्कम:</b> <?= h(purchaseInr($v('est_amount'))) ?></div>
        <?php if ($v('pending_reason')): ?><div><b>प्रलंबित कारण:</b> <?= h($v('pending_reason')) ?></div><?php endif; ?>
        <?php if ($v('remarks')): ?><div><b>शेरा:</b> <?= h($v('remarks')) ?></div><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-4">
    <?php if ($canEdit && $row && !empty($row['id'])): ?>
    <div class="panel mb-3" style="padding:14px 16px;">
      <?php if ($row['status'] === 'pending'): ?>
        <form method="POST" action="index.php" data-no-smart-filter>
          <input type="hidden" name="action" value="mark_done"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="item.php?id=<?= (int)$id ?>">
          <div style="font-weight:800;margin-bottom:6px;">✅ खरेदी पूर्ण</div>
          <div class="row g-2">
            <div class="col-6"><label class="form-label-custom">दिनांक</label><input type="date" name="done_on" class="form-control-custom" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
            <div class="col-6"><label class="form-label-custom">प्रत्यक्ष ₹</label><input type="number" name="actual_amount" class="form-control-custom" value="<?= $row['est_amount'] !== null ? (float)$row['est_amount'] : '' ?>" min="0" step="0.01"></div>
            <div class="col-6"><label class="form-label-custom">बिल क्र.</label><input type="text" name="bill_no" class="form-control-custom" maxlength="80"></div>
            <div class="col-6"><label class="form-label-custom">पुरवठादार</label><input type="text" name="supplier" class="form-control-custom" maxlength="150" list="piSup" autocomplete="off"></div>
            <div class="col-12"><input type="text" name="done_note" class="form-control-custom" maxlength="255" placeholder="शेरा"></div>
          </div>
          <button type="submit" class="btn btn-sm mt-2 w-100" style="background:#16A34A;color:#fff;border-radius:9px;font-weight:700;">✓ खरेदी पूर्ण करा</button>
        </form>
        <form method="POST" class="mt-2" onsubmit="return confirm('ही मागणी रद्द करायची? (खरेदी करायची नाही)');" data-no-smart-filter>
          <input type="hidden" name="action" value="cancel_item"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <div class="d-flex gap-2"><input type="text" name="note" class="form-control-custom" placeholder="रद्द करण्याचं कारण" maxlength="255"><button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;white-space:nowrap;">✕ रद्द</button></div>
        </form>
      <?php else: ?>
        <form method="POST" action="index.php" onsubmit="return confirm('पुन्हा खरेदी बाकी यादीत टाकायची?');" data-no-smart-filter>
          <input type="hidden" name="action" value="reopen_item"><input type="hidden" name="id" value="<?= (int)$id ?>"><input type="hidden" name="back" value="item.php?id=<?= (int)$id ?>">
          <div style="font-size:13px;margin-bottom:6px;"><?= $row['status'] === 'done' ? '✅ खरेदी पूर्ण झाली आहे.' : '✕ ही मागणी रद्द आहे.' ?></div>
          <button type="submit" class="btn btn-sm w-100" style="border:1px solid var(--line);border-radius:9px;">↺ पुन्हा खरेदी बाकी करा</button>
        </form>
      <?php endif; ?>
      <?php if (!(function_exists('recordLockActive') && recordLockActive())): ?>
        <form method="POST" class="mt-3 pt-2" style="border-top:1px dashed var(--line);" onsubmit="return confirm('ही नोंद कायमची हटवायची? (Recycle Bin मधून परत आणता येईल)');" data-no-smart-filter>
          <input type="hidden" name="action" value="delete_item"><input type="hidden" name="id" value="<?= (int)$id ?>">
          <button type="submit" class="btn btn-sm" style="color:#DC2626;border:0;padding:0;font-size:12.5px;">🗑️ चुकीची नोंद — हटवा</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php // 📊 या मागणीचे तुलनात्मक तक्ते
    if ($row && !empty($row['id'])) { require_once __DIR__ . '/../includes/purchase-compare.php'; }
    if ($row && !empty($row['id']) && purchaseCmpReady($pdo) && purchaseCmpCanView()):
      $cst = $pdo->prepare("SELECT id, cmp_no, fy, title, status FROM purchase_comparisons WHERE organization_id = ? AND request_id = ? ORDER BY id DESC"); $cst->execute([$orgId, (int)$row['id']]); $cmps = $cst->fetchAll(); ?>
    <div class="panel mb-3" style="padding:12px 16px;">
      <div style="font-weight:800;margin-bottom:6px;">📊 तुलनात्मक तक्ता</div>
      <?php foreach ($cmps as $cm): $cs = purchaseCmpStatuses()[$cm['status']] ?? ['', '#000', '#fff']; ?>
        <a href="compare/view.php?id=<?= (int)$cm['id'] ?>" style="display:block;font-size:13px;margin-bottom:4px;text-decoration:none;">#<?= (int)$cm['cmp_no'] ?>/<?= h(purchaseFyLabel($cm['fy'])) ?> — <?= h(mb_strimwidth($cm['title'], 0, 50, '…')) ?> <span style="font-size:11px;font-weight:700;color:<?= $cs[1] ?>;background:<?= $cs[2] ?>;border-radius:99px;padding:1px 7px;"><?= h($cs[0]) ?></span></a>
      <?php endforeach; ?>
      <?php if (purchaseCmpCanEdit()): ?><a href="compare/chart.php?request=<?= (int)$row['id'] ?>" class="btn btn-sm mt-1" style="background:#2563EB;color:#fff;border-radius:8px;font-weight:700;">＋ दरपत्रकांचा तुलनात्मक तक्ता बनवा</a><?php elseif (!$cmps): ?><div style="font-size:12.5px;color:var(--text-dim);">अजून तक्ता नाही.</div><?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($events): $el = purchaseEventLabels(); ?>
    <div class="panel" style="padding:12px 16px;">
      <div style="font-weight:800;margin-bottom:4px;">🕘 इतिहास</div>
      <?php foreach ($events as $e): ?>
        <div class="pi-ev"><div style="flex:1;"><b><?= h($el[$e['event']] ?? $e['event']) ?></b><?= $e['note'] ? '<div style="font-size:12.5px;">' . h($e['note']) . '</div>' : '' ?><small><?= h($e['uname'] ?? '') ?> · <?= date('d.m.Y h:i A', strtotime($e['created_at'])) ?></small></div></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php if (purchaseReady($pdo)) { require_once __DIR__ . '/../includes/purchase-compare.php'; ?><datalist id="piSup"><?php foreach (purchaseSupplierNames($pdo, $orgId) as $sn): ?><option value="<?= h($sn) ?>"><?php endforeach; ?></datalist><?php } ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
