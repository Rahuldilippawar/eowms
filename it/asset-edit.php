<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$assetId = (int)($_GET['id'] ?? 0);
$assetStmt = $pdo->prepare("SELECT a.*, l.lab_name FROM it_assets a JOIN it_labs l ON l.id = a.lab_id WHERE a.id = ? AND a.organization_id = ?");
$assetStmt->execute([$assetId, $orgId]);
$asset = $assetStmt->fetch();
if (!$asset) { die('Asset सापडलं नाही.'); }

$canEdit = canManageLabAssets($pdo, $user, $asset['lab_id']);
if (!$canEdit) { http_response_code(403); die('तुम्हाला हे Edit करण्याची परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $assetType = $_POST['asset_type'] ?? 'computer';
    $workstationNo = trim($_POST['workstation_no'] ?? '');
    $brand = trim($_POST['brand'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $serialNo = trim($_POST['serial_no'] ?? '');
    $purchaseDate = ($_POST['purchase_date'] ?? '') ?: null;
    $purchaseCost = ($_POST['purchase_cost'] ?? '') !== '' ? (float)$_POST['purchase_cost'] : null;
    $warrantyExpiry = ($_POST['warranty_expiry'] ?? '') ?: null;
    $osLicense = trim($_POST['os_license'] ?? '');
    $licenseExpiry = ($_POST['license_expiry'] ?? '') ?: null;
    $remarks = trim($_POST['remarks'] ?? '');

    // प्रकार व तारखा — फक्त वैध मूल्यं
    $validDate = fn($d) => $d === null || (bool)DateTime::createFromFormat('!Y-m-d', $d);
    if (!in_array($assetType, ['computer', 'monitor', 'keyboard', 'mouse', 'cpu', 'ups', 'smartboard', 'projector', 'printer', 'other'], true)
        || !$validDate($purchaseDate) || !$validDate($warrantyExpiry) || !$validDate($licenseExpiry)) {
        $_SESSION['flash'] = 'अवैध प्रकार किंवा तारीख — बदल जतन झाले नाहीत.';
        header('Location: asset-edit.php?id=' . $assetId);
        exit;
    }

    $pdo->prepare("
        UPDATE it_assets SET asset_type = ?, workstation_no = ?, brand = ?, model = ?, serial_no = ?, purchase_date = ?, purchase_cost = ?, warranty_expiry = ?, os_license = ?, license_expiry = ?, remarks = ?
        WHERE id = ? AND organization_id = ?
    ")->execute([$assetType, $workstationNo ?: null, $brand, $model, $serialNo, $purchaseDate, $purchaseCost, $warrantyExpiry, $osLicense, $licenseExpiry, $remarks, $assetId, $orgId]);

    // Warranty तारीख बदलली/काढली — नूतनीकरण नोंदही अद्ययावत
    syncItAssetWarranty($pdo, $assetId, $orgId, $asset['lab_id'], $asset['asset_tag'], $assetType, $warrantyExpiry);

    logActivity('IT Asset Edit केलं', 'it_asset', $assetId, $asset['asset_tag']);
    $_SESSION['flash'] = "'{$asset['asset_tag']}' Update झालं.";
    header('Location: asset-view.php?id=' . $assetId);
    exit;
}

$pageTitle = 'Edit — ' . $asset['asset_tag'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($asset['lab_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">✏️ Edit — <?= h($asset['asset_tag']) ?></div>
  <a href="asset-view.php?id=<?= $assetId ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>

<div class="panel" style="max-width:760px;">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">प्रकार</label>
        <select name="asset_type" class="form-select-custom">
          <?php $types = ['computer' => '💻 Computer', 'monitor' => '🖥️ Monitor', 'keyboard' => '⌨️ Keyboard', 'mouse' => '🖱️ Mouse', 'cpu' => '🖥️ CPU', 'ups' => '🔋 UPS', 'smartboard' => '📺 Smart Board', 'projector' => '📽️ Projector', 'printer' => '🖨️ Printer', 'other' => '📦 इतर']; ?>
          <?php foreach ($types as $val => $lbl): ?><option value="<?= $val ?>" <?= $asset['asset_type'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><label class="form-label-custom">PC Setup क्र.</label><input type="text" name="workstation_no" class="form-control-custom" value="<?= h($asset['workstation_no']) ?>"></div>
      <div class="col-md-3"><label class="form-label-custom">Brand</label><input type="text" name="brand" class="form-control-custom" value="<?= h($asset['brand']) ?>"></div>
      <div class="col-md-3"><label class="form-label-custom">Model</label><input type="text" name="model" class="form-control-custom" value="<?= h($asset['model']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">Serial No.</label><input type="text" name="serial_no" class="form-control-custom" value="<?= h($asset['serial_no']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">खरेदी दिनांक</label><input type="date" name="purchase_date" class="form-control-custom" value="<?= h($asset['purchase_date']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">किंमत (₹)</label><input type="number" step="0.01" name="purchase_cost" class="form-control-custom" value="<?= h($asset['purchase_cost']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">Warranty Expiry</label><input type="date" name="warranty_expiry" class="form-control-custom" value="<?= h($asset['warranty_expiry']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">OS/Software License</label><input type="text" name="os_license" class="form-control-custom" value="<?= h($asset['os_license']) ?>"></div>
      <div class="col-md-4"><label class="form-label-custom">License Expiry</label><input type="date" name="license_expiry" class="form-control-custom" value="<?= h($asset['license_expiry']) ?>"></div>
      <div class="col-12"><label class="form-label-custom">टिप्पणी</label><textarea name="remarks" class="form-control-custom" rows="2"><?= h($asset['remarks']) ?></textarea></div>
    </div>
    <div style="font-size:11px;color:var(--text-dim);margin-top:10px;">💡 Asset Tag (<?= h($asset['asset_tag']) ?>) आणि Power/Condition Status — इथून बदलता येत नाहीत (ते वेगळ्या, Quick-Action Buttons ने बदला).</div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 22px;font-weight:600;">जतन करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
