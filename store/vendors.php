<?php
// 🏪 भांडार — Vendor (पुरवठादार) यादी, खरेदीचा एकूण आकडा
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/store-helpers.php';
$user = currentUser();
requireStoreAccess($pdo, $user);
$orgId = activeOrgId();
$ready = storeStockReady($pdo);

if ($ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';
    $vid = (int)($_POST['vendor_id'] ?? 0);
    $f = [];
    foreach (['name' => 200, 'contact_person' => 150, 'mobile' => 20, 'gstin' => 20, 'address' => 255, 'supplies' => 255] as $k => $len) {
        $f[$k] = mb_substr(trim($_POST[$k] ?? ''), 0, $len) ?: null;
    }
    if ($f['mobile'] && !preg_match('/^[0-9+\- ]{6,20}$/', $f['mobile'])) $f['mobile'] = null;
    if ($f['gstin']) $f['gstin'] = strtoupper($f['gstin']);
    if ($action === 'toggle' && $vid) {
        $pdo->prepare("UPDATE store_vendors SET is_active = 1 - is_active WHERE id = ? AND organization_id = ?")->execute([$vid, $orgId]);
        $_SESSION['flash'] = 'Vendor ची स्थिती बदलली.';
    } elseif (!$f['name']) {
        $_SESSION['flash'] = 'Vendor चं नाव आवश्यक आहे.';
    } elseif ($action === 'update' && $vid) {
        $pdo->prepare("UPDATE store_vendors SET name = ?, contact_person = ?, mobile = ?, gstin = ?, address = ?, supplies = ? WHERE id = ? AND organization_id = ?")
            ->execute([$f['name'], $f['contact_person'], $f['mobile'], $f['gstin'], $f['address'], $f['supplies'], $vid, $orgId]);
        logActivity('Vendor बदलला', 'store_vendor', $vid, $f['name']);
        $_SESSION['flash'] = "'{$f['name']}' अद्ययावत झाला.";
    } else {
        $pdo->prepare("INSERT INTO store_vendors (organization_id, name, contact_person, mobile, gstin, address, supplies, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $f['name'], $f['contact_person'], $f['mobile'], $f['gstin'], $f['address'], $f['supplies'], $user['id']]);
        logActivity('Vendor जोडला', 'store_vendor', (int)$pdo->lastInsertId(), $f['name']);
        $_SESSION['flash'] = "'{$f['name']}' Vendor यादीत जोडला.";
    }
    header('Location: vendors.php');
    exit;
}

$vendors = [];
if ($ready) {
    $q = $pdo->prepare("SELECT v.*,
            (SELECT COUNT(DISTINCT CONCAT(m.move_date, '|', COALESCE(m.bill_no, m.id))) FROM store_stock_moves m WHERE m.vendor_id = v.id) bills,
            (SELECT COALESCE(SUM(m.quantity * m.rate), 0) FROM store_stock_moves m WHERE m.vendor_id = v.id AND m.rate IS NOT NULL) total,
            (SELECT COALESCE(SUM(m.quantity * m.rate), 0) FROM store_stock_moves m WHERE m.vendor_id = v.id AND m.rate IS NOT NULL AND m.move_date >= DATE_FORMAT(CURDATE(), '%Y-04-01') - INTERVAL (MONTH(CURDATE()) < 4) YEAR) fy,
            (SELECT MAX(m.move_date) FROM store_stock_moves m WHERE m.vendor_id = v.id) last_buy
        FROM store_vendors v WHERE v.organization_id = ? ORDER BY v.is_active DESC, v.name");
    $q->execute([$orgId]);
    $vendors = $q->fetchAll();
}

$pageTitle = 'Vendor यादी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">🏪 Vendor (पुरवठादार)</div>
<?= storeNav('vendors') ?>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;">Stock साठी Database अद्ययावत करायचा आहे — Super Admin ने <b>System Update → Database Update</b> चालवा.</div><?php endif; ?>
<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Vendor</div></div>
  <form method="POST" style="padding:16px 22px;">
    <div class="row g-2">
      <div class="col-md-3"><label class="form-label-custom">नाव *</label><input name="name" class="form-control-custom" required maxlength="200"></div>
      <div class="col-md-2"><label class="form-label-custom">संपर्क व्यक्ती</label><input name="contact_person" class="form-control-custom" maxlength="150"></div>
      <div class="col-md-2"><label class="form-label-custom">Mobile</label><input name="mobile" class="form-control-custom" inputmode="tel" maxlength="20"></div>
      <div class="col-md-2"><label class="form-label-custom">GSTIN</label><input name="gstin" class="form-control-custom" maxlength="20" style="text-transform:uppercase;"></div>
      <div class="col-md-3"><label class="form-label-custom">काय पुरवतो</label><input name="supplies" class="form-control-custom" maxlength="255" placeholder="इलेक्ट्रिकल, स्टेशनरी…"></div>
      <div class="col-md-9"><label class="form-label-custom">पत्ता</label><input name="address" class="form-control-custom" maxlength="255"></div>
      <div class="col-md-3 d-flex align-items-end"><button class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;">+ जोडा</button></div>
    </div>
  </form>
</div>

<?php if (count($vendors) > 6): ?><input type="search" id="vQ" class="form-control-custom mb-2" placeholder="🔍 Vendor शोधा…" style="max-width:280px;"><?php endif; ?>
<div class="panel">
  <div class="table-responsive">
  <table class="rtable" id="vTable">
    <thead><tr><th>Vendor</th><th>संपर्क</th><th>काय पुरवतो</th><th class="text-end">बिलं</th><th class="text-end">या आर्थिक वर्षात</th><th class="text-end">एकूण खरेदी</th><th>शेवटची खरेदी</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($vendors as $v): ?>
      <tr data-q="<?= h(mb_strtolower($v['name'] . ' ' . $v['supplies'] . ' ' . $v['contact_person'])) ?>" style="<?= $v['is_active'] ? '' : 'opacity:.55;' ?>">
        <td style="font-weight:600;"><?= h($v['name']) ?><?= $v['gstin'] ? '<div class="font-mono" style="font-size:10.5px;color:var(--text-dim);font-weight:400;">' . h($v['gstin']) . '</div>' : '' ?></td>
        <td style="font-size:12.5px;"><?= h($v['contact_person']) ?><?php if ($v['mobile']): ?><div><a href="tel:<?= h($v['mobile']) ?>"><?= h($v['mobile']) ?></a></div><?php endif; ?></td>
        <td style="font-size:12.5px;"><?= h($v['supplies']) ?></td>
        <td class="text-end font-mono"><?= (int)$v['bills'] ?: '—' ?></td>
        <td class="text-end font-mono"><?= $v['fy'] > 0 ? '₹' . number_format($v['fy']) : '—' ?></td>
        <td class="text-end font-mono" style="font-weight:700;"><?= $v['total'] > 0 ? '₹' . number_format($v['total']) : '—' ?></td>
        <td class="font-mono" style="font-size:12px;"><?= $v['last_buy'] ? h(date('d M Y', strtotime($v['last_buy']))) : '—' ?></td>
        <td style="white-space:nowrap;">
          <a href="ledger.php?vendor=<?= $v['id'] ?>&from=2000-01-01" class="st-a" title="खरेदी नोंदी">📒</a>
          <button type="button" class="st-a" title="Edit" onclick="document.getElementById('vE<?= $v['id'] ?>').classList.toggle('d-none')">✎</button>
          <form method="POST" style="display:inline;"><input type="hidden" name="action" value="toggle"><input type="hidden" name="vendor_id" value="<?= $v['id'] ?>"><button class="st-a" title="<?= $v['is_active'] ? 'निष्क्रिय करा' : 'सक्रिय करा' ?>"><?= $v['is_active'] ? '⏸' : '▶' ?></button></form>
        </td>
      </tr>
      <tr id="vE<?= $v['id'] ?>" class="d-none"><td colspan="8">
        <form method="POST" class="row g-2"><input type="hidden" name="action" value="update"><input type="hidden" name="vendor_id" value="<?= $v['id'] ?>">
          <div class="col-md-3"><input name="name" class="form-control-custom" value="<?= h($v['name']) ?>" required></div>
          <div class="col-md-2"><input name="contact_person" class="form-control-custom" value="<?= h($v['contact_person']) ?>" placeholder="संपर्क व्यक्ती"></div>
          <div class="col-md-2"><input name="mobile" class="form-control-custom" value="<?= h($v['mobile']) ?>" placeholder="Mobile"></div>
          <div class="col-md-2"><input name="gstin" class="form-control-custom" value="<?= h($v['gstin']) ?>" placeholder="GSTIN"></div>
          <div class="col-md-3"><input name="supplies" class="form-control-custom" value="<?= h($v['supplies']) ?>" placeholder="काय पुरवतो"></div>
          <div class="col-md-9"><input name="address" class="form-control-custom" value="<?= h($v['address']) ?>" placeholder="पत्ता"></div>
          <div class="col-md-3"><button class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;">जतन करा</button></div>
        </form>
      </td></tr>
    <?php endforeach; ?>
    <?php if (!$vendors): ?><tr><td colspan="8" class="text-center" style="color:var(--text-dim);padding:26px;">अजून Vendor नाहीत</td></tr><?php endif; ?>
    </tbody>
  </table>
  </div>
</div>
<?= storeStyles() ?>
<script>
(function () { var q = document.getElementById('vQ'); if (!q) return;
  q.oninput = function () { var s = q.value.trim().toLowerCase(); document.querySelectorAll('#vTable tbody tr[data-q]').forEach(function (tr) { var ok = !s || tr.dataset.q.indexOf(s) !== -1; tr.style.display = ok ? '' : 'none'; if (!ok) tr.nextElementSibling.classList.add('d-none'); }); };
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
