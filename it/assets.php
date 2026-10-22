<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user); // कमीत कमी IT विभागाशी काहीतरी संबंध हवा (Assistant/IT/Management)
$orgId = activeOrgId();

$labId = (int)($_GET['lab_id'] ?? 0);

$labStmt = $pdo->prepare("SELECT * FROM it_labs WHERE id = ? AND organization_id = ?");
$labStmt->execute([$labId, $orgId]);
$lab = $labStmt->fetch();
if (!$lab) { die('Lab सापडली नाही.'); }

// फक्त याच Lab चा Assistant, किंवा IT/Management — Asset जोडू/बदलू शकतो
$canEdit = canManageLabAssets($pdo, $user, $labId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'add';

    if ($action === 'toggle_power') {
        if (!$canEdit) { http_response_code(403); die('परवानगी नाही.'); }
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $newPower = $_POST['power_status'] ?? 'off';
        $pdo->prepare("UPDATE it_assets SET power_status = ?, power_status_updated_at = NOW(), power_status_updated_by = ? WHERE id = ? AND lab_id = ?")
            ->execute([$newPower, $user['id'], $assetId, $labId]);
        header('Location: assets.php?lab_id=' . $labId);
        exit;
    } elseif ($action === 'delete') {
        if (!$canEdit) { http_response_code(403); die('परवानगी नाही.'); }
        $assetId = (int)($_POST['asset_id'] ?? 0);
        $tagStmt = $pdo->prepare("SELECT asset_tag FROM it_assets WHERE id = ? AND lab_id = ?");
        $tagStmt->execute([$assetId, $labId]);
        $tag = $tagStmt->fetch()['asset_tag'] ?? '';
        $pdo->prepare("DELETE FROM it_assets WHERE id = ? AND lab_id = ?")->execute([$assetId, $labId]);
        logActivity('IT Asset काढलं', 'it_asset', $assetId, $tag);
        $_SESSION['flash'] = "'$tag' काढलं गेलं.";
        header('Location: assets.php?lab_id=' . $labId);
        exit;
    }

    if (!$canEdit) {
        http_response_code(403);
        die('तुम्ही या Lab चे नेमलेले Assistant नाही — Asset जोडता येणार नाही.');
    }
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

    $assetTag = itGenerateAssetTag($pdo, $orgId, $assetType);
    $pdo->prepare("
        INSERT INTO it_assets (organization_id, lab_id, workstation_no, asset_tag, asset_type, brand, model, serial_no, purchase_date, purchase_cost, warranty_expiry, os_license, license_expiry, added_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ")->execute([$orgId, $labId, $workstationNo ?: null, $assetTag, $assetType, $brand, $model, $serialNo, $purchaseDate, $purchaseCost, $warrantyExpiry, $osLicense, $licenseExpiry, $user['id']]);
    $newAssetId = $pdo->lastInsertId();

    syncItAssetWarranty($pdo, $newAssetId, $orgId, $labId, $assetTag, $assetType, $warrantyExpiry);

    logActivity('IT Asset जोडलं', 'it_asset', $newAssetId, $assetTag);
    $_SESSION['flash'] = "'$assetTag' यशस्वीरित्या जोडलं गेलं.";
    header('Location: assets.php?lab_id=' . $labId);
    exit;
}

$pageTitle = $lab['lab_name'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$assetsStmt = $pdo->prepare("SELECT * FROM it_assets WHERE lab_id = ? ORDER BY (workstation_no IS NULL), workstation_no, asset_type, asset_tag");
$assetsStmt->execute([$labId]);
$assets = $assetsStmt->fetchAll();

$wnStmt = $pdo->prepare("SELECT DISTINCT workstation_no FROM it_assets WHERE lab_id = ? AND workstation_no IS NOT NULL AND workstation_no != '' ORDER BY workstation_no");
$wnStmt->execute([$labId]);
$workstationNumbers = array_column($wnStmt->fetchAll(), 'workstation_no');

$statusLabels = ['working' => '✅ चालू', 'faulty' => '🔴 बिघडलेलं', 'under_repair' => '🟡 दुरुस्तीत', 'scrapped' => '⚫ बाद केलेलं'];
$statusChips = ['working' => 'done', 'faulty' => 'overdue', 'under_repair' => 'pending', 'scrapped' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💻 <?= h($lab['lab_name']) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>IT विभाग</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h($lab['location']) ?><?php if (!$canEdit): ?> · <span style="color:var(--amber);">फक्त बघता येईल — Edit साठी या Lab चे नेमलेले Assistant असणे आवश्यक</span><?php endif; ?></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($canEdit): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Asset जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-2">
        <label class="form-label-custom">प्रकार</label>
        <select name="asset_type" class="form-select-custom">
          <option value="computer">💻 Computer</option><option value="monitor">🖥️ Monitor</option>
          <option value="keyboard">⌨️ Keyboard</option><option value="mouse">🖱️ Mouse</option>
          <option value="cpu">🖥️ CPU</option><option value="ups">🔋 UPS</option>
          <option value="smartboard">📺 Smart Board</option><option value="projector">📽️ Projector</option>
          <option value="printer">🖨️ Printer</option><option value="other">📦 इतर</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label-custom">PC Setup क्र. <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
        <input type="text" name="workstation_no" list="workstationList" class="form-control-custom" placeholder="उदा. PC-01">
        <datalist id="workstationList">
          <?php foreach ($workstationNumbers as $wn): ?><option value="<?= h($wn) ?>"><?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-2"><label class="form-label-custom">Brand</label><input type="text" name="brand" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">Model</label><input type="text" name="model" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">Serial No.</label><input type="text" name="serial_no" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">खरेदी दिनांक</label><input type="date" name="purchase_date" class="form-control-custom"></div>
      <div class="col-md-2"><label class="form-label-custom">किंमत (₹)</label><input type="number" step="0.01" name="purchase_cost" class="form-control-custom"></div>
      <div class="col-md-3"><label class="form-label-custom">Warranty Expiry</label><input type="date" name="warranty_expiry" class="form-control-custom"></div>
      <div class="col-md-3"><label class="form-label-custom">OS/Software License</label><input type="text" name="os_license" class="form-control-custom" placeholder="Windows 11 Pro"></div>
      <div class="col-md-3"><label class="form-label-custom">License Expiry</label><input type="date" name="license_expiry" class="form-control-custom"></div>
    </div>
    <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">💡 "PC Setup क्र." टाकल्यास — एका PC चे CPU, Monitor, Keyboard, Mouse असे सगळे भाग एकत्र (एकाच गटात) दिसतील.</div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Asset जोडा</button>
  </form>
</div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>PC Setup</th><th>Tag</th><th>प्रकार</th><th>Brand/Model</th><th>Serial No.</th><th>Warranty</th><th>स्थिती</th><th>⚡ Power</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($assets)): ?><tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतंही Asset नाही</td></tr><?php endif; ?>
      <?php $lastWn = null; $shadeToggle = false; ?>
      <?php foreach ($assets as $a):
        if ($a['workstation_no'] !== $lastWn) { $shadeToggle = !$shadeToggle; $lastWn = $a['workstation_no']; }
        $rowBg = ($a['workstation_no'] && $shadeToggle) ? 'background:var(--canvas);' : '';
      ?>
        <tr style="<?= $rowBg ?>">
          <td style="font-weight:600;color:var(--rail);"><?= $a['workstation_no'] ? h($a['workstation_no']) : '<span style="color:var(--text-dim);font-weight:400;">—</span>' ?></td>
          <td class="font-mono" style="font-weight:600;"><?= h($a['asset_tag']) ?></td>
          <td><?= itAssetTypeLabel($a['asset_type']) ?></td>
          <td><?= h($a['brand']) ?> <?= h($a['model']) ?></td>
          <td class="font-mono" style="font-size:11.5px;"><?= h($a['serial_no']) ?></td>
          <td><?= $a['warranty_expiry'] ? date('d M Y', strtotime($a['warranty_expiry'])) : '—' ?></td>
          <td><span class="status-chip <?= $statusChips[$a['status']] ?>"><?= $statusLabels[$a['status']] ?></span></td>
          <td>
            <?php if ($canEdit): ?>
              <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="toggle_power">
                <input type="hidden" name="asset_id" value="<?= $a['id'] ?>">
                <input type="hidden" name="power_status" value="<?= $a['power_status'] === 'on' ? 'off' : 'on' ?>">
                <button type="submit" style="border:none;background:<?= $a['power_status'] === 'on' ? '#DCEFEC' : '#F7E2E3' ?>;color:<?= $a['power_status'] === 'on' ? 'var(--rail)' : 'var(--rose)' ?>;border-radius:20px;font-size:11px;font-weight:700;padding:4px 12px;cursor:pointer;">
                  <?= $a['power_status'] === 'on' ? '🟢 ON' : '🔴 OFF' ?>
                </button>
              </form>
            <?php else: ?>
              <span class="status-chip <?= $a['power_status'] === 'on' ? 'done' : 'overdue' ?>"><?= $a['power_status'] === 'on' ? '🟢 ON' : '🔴 OFF' ?></span>
            <?php endif; ?>
          </td>
          <td class="d-flex gap-2">
            <a href="asset-view.php?id=<?= $a['id'] ?>" style="font-size:12px;color:var(--rail);font-weight:600;">बघा →</a>
            <?php if ($canEdit): ?>
              <a href="asset-edit.php?id=<?= $a['id'] ?>" style="font-size:12px;color:var(--amber);font-weight:600;">✏️ Edit</a>
              <form method="POST" onsubmit="return confirm('हे Asset कायमचं काढायचं?')" style="display:inline;">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="asset_id" value="<?= $a['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;font-weight:600;cursor:pointer;">🗑️ काढा</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
