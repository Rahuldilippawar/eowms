<?php
// 📒 भांडार नोंदवही — सगळ्या आवक/जावक नोंदी; एका वस्तूची निवड केल्यास धावती शिल्लक (Stock Card)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();
$types = storeMoveTypes();

// चुकीची नोंद काढणे (फक्त हाताने केलेली — कामातून आलेली नाही; 7 दिवसांच्या आत)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && storeStockReady($pdo)) {
    $mid = (int)($_POST['move_id'] ?? 0);
    $m = $pdo->prepare("SELECT m.*, si.item_name FROM store_stock_moves m JOIN store_items si ON si.id = m.item_id WHERE m.id = ? AND m.organization_id = ?");
    $m->execute([$mid, $orgId]);
    $mv = $m->fetch();
    if (!$mv || $mv['move_type'] === 'work') {
        $_SESSION['flash'] = 'ही नोंद काढता येत नाही (कामातून आलेली नोंद कामाच्या नोंदीसोबतच बदलते).';
    } elseif (strtotime($mv['created_at']) < strtotime('-7 days') && !hasRole('super_admin')) {
        $_SESSION['flash'] = '7 दिवसांपेक्षा जुनी नोंद काढता येत नाही — "दुरुस्ती" नोंद करा.';
    } else {
        $after = (float)$pdo->query("SELECT COALESCE(SUM(quantity),0) FROM store_stock_moves WHERE item_id = " . (int)$mv['item_id'])->fetchColumn() - (float)$mv['quantity'];
        if ($after < -0.0001) {
            $_SESSION['flash'] = "ही आवक काढली तर '{$mv['item_name']}' ची शिल्लक उणे होईल — आधी त्यानंतरच्या जावक नोंदी तपासा.";
        } else {
            $pdo->prepare("DELETE FROM store_stock_moves WHERE id = ? AND organization_id = ?")->execute([$mid, $orgId]);
            logActivity('भांडार नोंद काढली', 'store_stock', $mid, $mv['item_name'] . ' ' . storeQty($mv['quantity']));
            $_SESSION['flash'] = 'नोंद काढली.';
        }
    }
    header('Location: ledger.php?' . http_build_query(array_diff_key($_GET, ['x' => 1])));
    exit;
}

$itemId = (int)($_GET['item'] ?? 0);
$type = $_GET['type'] ?? '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01', strtotime('-2 months'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$vendorId = (int)($_GET['vendor'] ?? 0);
$deptId = (int)($_GET['dept'] ?? 0);

$itemsStmt = $pdo->prepare("SELECT id, item_name, unit, category FROM store_items WHERE organization_id = ? ORDER BY category IS NULL OR category = '', category, item_name");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();
$vendors = $pdo->prepare("SELECT id, name FROM store_vendors WHERE organization_id = ? ORDER BY name");
$depts = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$moves = [];
$opening = 0.0;
$curItem = null;
if (storeStockReady($pdo)) {
    $vendors->execute([$orgId]); $vendors = $vendors->fetchAll();
    $depts->execute([$orgId]); $depts = $depts->fetchAll();
    $where = "m.organization_id = ? AND m.move_date BETWEEN ? AND ?";
    $p = [$orgId, $from, $to];
    if ($itemId) { $where .= " AND m.item_id = ?"; $p[] = $itemId; }
    if (isset($types[$type])) { $where .= " AND m.move_type = ?"; $p[] = $type; }
    if ($vendorId) { $where .= " AND m.vendor_id = ?"; $p[] = $vendorId; }
    if ($deptId) { $where .= " AND m.department_id = ?"; $p[] = $deptId; }
    $q = $pdo->prepare("SELECT m.*, si.item_name, si.unit, v.name AS vendor_name, d.name_local AS dept_name, u.name AS by_name, wr.work_code
        FROM store_stock_moves m JOIN store_items si ON si.id = m.item_id
        LEFT JOIN store_vendors v ON v.id = m.vendor_id LEFT JOIN departments d ON d.id = m.department_id
        LEFT JOIN users u ON u.id = m.created_by LEFT JOIN work_records wr ON wr.id = m.work_record_id
        WHERE $where ORDER BY m.move_date, m.id LIMIT 3000");
    $q->execute($p);
    $moves = $q->fetchAll();
    if ($itemId) {
        foreach ($items as $i) if ((int)$i['id'] === $itemId) $curItem = $i;
        $o = $pdo->prepare("SELECT COALESCE(SUM(quantity),0) FROM store_stock_moves WHERE organization_id = ? AND item_id = ? AND move_date < ?");
        $o->execute([$orgId, $itemId, $from]);
        $opening = (float)$o->fetchColumn();
    }
} else { $vendors = []; $depts = []; }

$cls = fn($t, $q) => $t === 'adjust' ? 'adj' : ($q >= 0 ? 'in' : 'outm');
$party = fn($m) => $m['vendor_name'] ? '🏪 ' . $m['vendor_name'] . ($m['bill_no'] ? ' · बिल ' . $m['bill_no'] : '') : ($m['dept_name'] || $m['issued_to'] ? '🏢 ' . trim(($m['dept_name'] ?? '') . ($m['issued_to'] ? ' · ' . $m['issued_to'] : ''), ' ·') : ($m['work_code'] ? '🛠️ ' . $m['work_code'] : ''));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="store-ledger-' . $from . '-to-' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['दिनांक', 'वस्तू', 'प्रकार', 'आवक', 'जावक', 'एकक', 'दर (₹)', 'रक्कम (₹)', 'कोणाकडून / कोणाला', 'नोंद', 'नोंद करणारे']);
    foreach ($moves as $m) {
        $qq = (float)$m['quantity'];
        fputcsv($out, [$m['move_date'], $m['item_name'], strip_tags($types[$m['move_type']][0] ?? $m['move_type']), $qq > 0 ? storeQty($qq) : '', $qq < 0 ? storeQty(-$qq) : '', $m['unit'], $m['rate'] !== null ? storeQty($m['rate']) : '', $m['rate'] !== null ? round(abs($qq) * $m['rate'], 2) : '', $party($m), $m['notes'], $m['by_name']]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'भांडार नोंदवही';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$sumIn = 0; $sumOut = 0; $sumAmt = 0;
foreach ($moves as $m) { $qq = (float)$m['quantity']; if ($m['move_type'] !== 'adjust') { if ($qq > 0) $sumIn += $qq; else $sumOut -= $qq; } if ($m['move_type'] === 'purchase' && $m['rate'] !== null) $sumAmt += $qq * $m['rate']; }
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-0">📒 भांडार नोंदवही<?= $curItem ? ' — ' . h($curItem['item_name']) : '' ?></div>
  <div class="d-flex gap-2 no-print">
    <a href="?<?= h(http_build_query(array_merge($_GET, ['export' => 'csv']))) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">📊 Excel</a>
    <button type="button" onclick="window.print()" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">🖨️ Print</button>
  </div>
</div>
<?= storeNav('ledger') ?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-info" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>

<form method="GET" class="panel no-print" style="padding:14px 18px;margin-bottom:14px;">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label-custom">वस्तू</label><select name="item" class="form-select-custom" data-smart="1"><option value="">सर्व वस्तू</option>
      <?php $cat = null; foreach ($items as $i): $c = trim((string)$i['category']) ?: 'इतर'; if ($c !== $cat): if ($cat !== null) echo '</optgroup>'; $cat = $c; ?><optgroup label="<?= h($c) ?>"><?php endif; ?>
        <option value="<?= $i['id'] ?>" <?= $itemId === (int)$i['id'] ? 'selected' : '' ?>><?= h($i['item_name']) ?></option>
      <?php endforeach; if ($cat !== null) echo '</optgroup>'; ?></select></div>
    <div class="col-md-2"><label class="form-label-custom">प्रकार</label><select name="type" class="form-select-custom"><option value="">सर्व</option><?php foreach ($types as $k => [$l]): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label-custom">Vendor</label><select name="vendor" class="form-select-custom"><option value="">सर्व</option><?php foreach ($vendors as $v): ?><option value="<?= $v['id'] ?>" <?= $vendorId === (int)$v['id'] ? 'selected' : '' ?>><?= h($v['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><label class="form-label-custom">विभाग</label><select name="dept" class="form-select-custom"><option value="">सर्व</option><?php foreach ($depts as $d): ?><option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1"><label class="form-label-custom">पासून</label><input type="date" name="from" value="<?= h($from) ?>" class="form-control-custom"></div>
    <div class="col-md-1"><label class="form-label-custom">पर्यंत</label><input type="date" name="to" value="<?= h($to) ?>" class="form-control-custom"></div>
    <div class="col-md-1"><button class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;">पहा</button></div>
  </div>
</form>

<div class="hi-totals mb-3">
  <div><span>नोंदी</span><b><?= count($moves) ?></b><small><?= h(date('d M', strtotime($from))) ?> – <?= h(date('d M Y', strtotime($to))) ?></small></div>
  <div><span>एकूण आवक</span><b class="c-ok"><?= storeQty($sumIn) ?></b><small><?= $curItem ? h($curItem['unit']) : 'सर्व वस्तूंचं प्रमाण' ?></small></div>
  <div><span>एकूण जावक</span><b class="c-amber"><?= storeQty($sumOut) ?></b><small>वाटप + कामात वापर</small></div>
  <div><span>खरेदी रक्कम</span><b>₹<?= number_format($sumAmt) ?></b><small>दर नोंदवलेल्या खरेदी</small></div>
</div>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><?php if (!$curItem): ?><th>वस्तू</th><?php endif; ?><th>प्रकार</th><th class="text-end">आवक</th><th class="text-end">जावक</th><?php if ($curItem): ?><th class="text-end">शिल्लक</th><?php endif; ?><th class="text-end">दर</th><th>कोणाकडून / कोणाला</th><th>नोंद</th><th class="no-print"></th></tr></thead>
    <tbody>
      <?php if ($curItem): ?><tr style="background:var(--surface-2,#F8FAFC);"><td colspan="4" style="font-weight:600;">सुरुवातीची शिल्लक (<?= h(date('d M Y', strtotime($from))) ?> पूर्वी)</td><td class="text-end font-mono" style="font-weight:700;"><?= storeQty($opening) ?></td><td colspan="4"></td></tr><?php endif; ?>
      <?php $run = $opening; foreach ($moves as $m): $qq = (float)$m['quantity']; $run += $qq; ?>
        <tr>
          <td class="font-mono" style="white-space:nowrap;"><?= h(date('d M Y', strtotime($m['move_date']))) ?></td>
          <?php if (!$curItem): ?><td style="font-weight:600;"><a href="?item=<?= $m['item_id'] ?>&from=<?= h($from) ?>&to=<?= h($to) ?>" style="color:inherit;"><?= h($m['item_name']) ?></a></td><?php endif; ?>
          <td><span class="st-b <?= $cls($m['move_type'], $qq) ?>"><?= h($types[$m['move_type']][0] ?? $m['move_type']) ?></span></td>
          <td class="text-end font-mono" style="color:#15803D;"><?= $qq > 0 ? storeQty($qq) . ' ' . h($m['unit']) : '' ?></td>
          <td class="text-end font-mono" style="color:#C2410C;"><?= $qq < 0 ? storeQty(-$qq) . ' ' . h($m['unit']) : '' ?></td>
          <?php if ($curItem): ?><td class="text-end font-mono" style="font-weight:700;"><?= storeQty($run) ?></td><?php endif; ?>
          <td class="text-end font-mono"><?= $m['rate'] !== null ? '₹' . storeQty($m['rate']) : '' ?></td>
          <td style="font-size:12.5px;"><?= h($party($m)) ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($m['notes']) ?><div style="font-size:10.5px;"><?= h($m['by_name']) ?></div></td>
          <td class="no-print"><?php if ($m['move_type'] !== 'work' && (strtotime($m['created_at']) >= strtotime('-7 days') || hasRole('super_admin'))): ?>
            <form method="POST" onsubmit="return confirm('ही नोंद काढायची?')" style="display:inline;"><input type="hidden" name="action" value="delete"><input type="hidden" name="move_id" value="<?= $m['id'] ?>"><button class="btn btn-sm" style="color:var(--rose);" title="काढा">✕</button></form>
          <?php endif; ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$moves): ?><tr><td colspan="10" class="text-center" style="color:var(--text-dim);padding:28px;">या कालावधीत नोंदी नाहीत — <a href="move.php">नवीन आवक / जावक</a></td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?= storeStyles() ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
