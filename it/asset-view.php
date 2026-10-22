<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
requireItAccess($pdo, $user);
$orgId = activeOrgId();

$assetId = (int)($_GET['id'] ?? 0);

$assetStmt = $pdo->prepare("SELECT a.*, l.lab_name FROM it_assets a JOIN it_labs l ON l.id = a.lab_id WHERE a.id = ? AND a.organization_id = ?");
$assetStmt->execute([$assetId, $orgId]);
$asset = $assetStmt->fetch();
if (!$asset) { die('Asset सापडलं नाही.'); }

$canEdit = canManageLabAssets($pdo, $user, $asset['lab_id']);

// याच PC Setup चे इतर भाग (उदा. याच Monitor बरोबरचे CPU/Keyboard/Mouse)
$siblingAssets = [];
if (!empty($asset['workstation_no'])) {
    $sibStmt = $pdo->prepare("SELECT * FROM it_assets WHERE lab_id = ? AND workstation_no = ? AND id != ? ORDER BY asset_type");
    $sibStmt->execute([$asset['lab_id'], $asset['workstation_no'], $assetId]);
    $siblingAssets = $sibStmt->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status' && $canEdit) {
        $newStatus = $_POST['status'] ?? 'working';
        $pdo->prepare("UPDATE it_assets SET status = ? WHERE id = ?")->execute([$newStatus, $assetId]);
        logActivity('IT Asset स्थिती बदलली', 'it_asset', $assetId, $newStatus);
        $_SESSION['flash'] = 'स्थिती अद्ययावत झाली.';
    } elseif ($action === 'toggle_power' && $canEdit) {
        $newPower = $_POST['power_status'] ?? 'off';
        $pdo->prepare("UPDATE it_assets SET power_status = ?, power_status_updated_at = NOW(), power_status_updated_by = ? WHERE id = ?")
            ->execute([$newPower, $user['id'], $assetId]);
        $_SESSION['flash'] = 'Power Status बदललं.';
    } elseif ($action === 'delete' && $canEdit) {
        $pdo->prepare("DELETE FROM it_assets WHERE id = ? AND organization_id = ?")->execute([$assetId, $orgId]);
        logActivity('IT Asset काढलं', 'it_asset', $assetId, $asset['asset_tag']);
        header('Location: assets.php?lab_id=' . $asset['lab_id']);
        exit;
    } elseif ($action === 'transfer' && (hasPermission('manage_it') || isManagementOrAbove())) {
        $toLabId = (int)($_POST['to_lab_id'] ?? 0);
        if ($toLabId && $toLabId !== (int)$asset['lab_id']) {
            $pdo->prepare("INSERT INTO it_asset_transfers (asset_id, from_lab_id, to_lab_id, transferred_by, remarks) VALUES (?, ?, ?, ?, ?)")
                ->execute([$assetId, $asset['lab_id'], $toLabId, $user['id'], trim($_POST['remarks'] ?? '')]);
            $pdo->prepare("UPDATE it_assets SET lab_id = ? WHERE id = ?")->execute([$toLabId, $assetId]);
            logActivity('IT Asset Transfer झालं', 'it_asset', $assetId, $asset['asset_tag']);
            $_SESSION['flash'] = 'Asset दुसऱ्या Lab मध्ये हलवलं गेलं.';
        }
    }
    header('Location: asset-view.php?id=' . $assetId);
    exit;
}

$pageTitle = $asset['asset_tag'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$ticketsStmt = $pdo->prepare("
    SELECT t.*, u.name AS reported_by_name, r.name AS resolved_by_name
    FROM it_repair_tickets t LEFT JOIN users u ON u.id = t.reported_by LEFT JOIN users r ON r.id = t.resolved_by
    WHERE t.asset_id = ? ORDER BY t.created_at DESC
");
$ticketsStmt->execute([$assetId]);
$tickets = $ticketsStmt->fetchAll();

// वारंवार-समस्या Auto-Warning — गेल्या 90 दिवसांत 3+ वेळा तक्रार आली का
$recentIssueCount = 0;
foreach ($tickets as $t) {
    if (strtotime($t['created_at']) >= strtotime('-90 days')) { $recentIssueCount++; }
}

$transferHistStmt = $pdo->prepare("
    SELECT tr.*, fl.lab_name AS from_lab_name, tl.lab_name AS to_lab_name, u.name AS transferred_by_name
    FROM it_asset_transfers tr LEFT JOIN it_labs fl ON fl.id = tr.from_lab_id
    JOIN it_labs tl ON tl.id = tr.to_lab_id LEFT JOIN users u ON u.id = tr.transferred_by
    WHERE tr.asset_id = ? ORDER BY tr.created_at DESC
");
$transferHistStmt->execute([$assetId]);
$transfers = $transferHistStmt->fetchAll();

$labsStmt = $pdo->prepare("SELECT * FROM it_labs WHERE organization_id = ? AND is_active = 1 AND id != ? ORDER BY lab_name");
$labsStmt->execute([$orgId, $asset['lab_id']]);
$otherLabs = $labsStmt->fetchAll();

$verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/report-issue.php?asset_id=' . $assetId;
$qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&margin=1&data=' . urlencode($verifyUrl);

$statusLabels = ['working' => '✅ चालू', 'faulty' => '🔴 बिघडलेलं', 'under_repair' => '🟡 दुरुस्तीत', 'scrapped' => '⚫ बाद केलेलं'];
$statusChips = ['working' => 'done', 'faulty' => 'overdue', 'under_repair' => 'pending', 'scrapped' => 'overdue'];
?>

<div class="page-eyebrow"><?= h($asset['lab_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= itAssetTypeLabel($asset['asset_type']) ?> — <?= h($asset['asset_tag']) ?></div>
  <a href="assets.php?lab_id=<?= $asset['lab_id'] ?>" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Lab कडे परत</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($recentIssueCount >= 3): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ या Asset ने गेल्या 90 दिवसांत <?= $recentIssueCount ?> वेळा तक्रार नोंदवली गेली आहे — कायमस्वरूपी बदलण्याचा विचार करा.</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">तपशील</div></div>
      <div style="padding:16px 20px;font-size:13px;line-height:2;">
        <?php if (!empty($asset['workstation_no'])): ?><strong>PC Setup क्र.:</strong> <span style="color:var(--rail);font-weight:600;"><?= h($asset['workstation_no']) ?></span><br><?php endif; ?>
        <strong>Brand/Model:</strong> <?= h($asset['brand']) ?> <?= h($asset['model']) ?><br>
        <strong>Serial No.:</strong> <?= h($asset['serial_no']) ?: '—' ?><br>
        <strong>खरेदी दिनांक:</strong> <?= $asset['purchase_date'] ? date('d M Y', strtotime($asset['purchase_date'])) : '—' ?><?= $asset['purchase_cost'] ? ' — ₹' . number_format($asset['purchase_cost'], 0) : '' ?><br>
        <strong>Warranty:</strong> <?= $asset['warranty_expiry'] ? date('d M Y', strtotime($asset['warranty_expiry'])) : '—' ?><br>
        <?php if ($asset['os_license']): ?><strong>License:</strong> <?= h($asset['os_license']) ?> <?= $asset['license_expiry'] ? '(' . date('d M Y', strtotime($asset['license_expiry'])) . ' पर्यंत)' : '' ?><br><?php endif; ?>
        <strong>सध्याची स्थिती:</strong> <?= $statusLabels[$asset['status']] ?><br>
        <strong>⚡ Power:</strong> <span style="font-weight:700;color:<?= $asset['power_status'] === 'on' ? 'var(--rail)' : 'var(--rose)' ?>;"><?= $asset['power_status'] === 'on' ? '🟢 चालू (ON)' : '🔴 बंद (OFF)' ?></span>
        <?php if ($asset['power_status_updated_at']): ?><span style="font-size:11px;color:var(--text-dim);"> — <?= date('d M, h:i A', strtotime($asset['power_status_updated_at'])) ?> रोजी बदललं</span><?php endif; ?>
      </div>
    </div>

    <?php if (!empty($siblingAssets)): ?>
    <div class="panel mb-3" style="border-color:var(--rail);">
      <div class="panel-head"><div class="title">🖥️ याच PC Setup (<?= h($asset['workstation_no']) ?>) चे इतर भाग</div></div>
      <div style="padding:10px 20px;">
        <?php foreach ($siblingAssets as $sib): ?>
          <a href="asset-view.php?id=<?= $sib['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;">
            <span style="font-size:12.5px;"><?= itAssetTypeLabel($sib['asset_type']) ?> — <span class="font-mono" style="font-weight:600;"><?= h($sib['asset_tag']) ?></span> <?= h($sib['brand']) ?></span>
            <span class="status-chip <?= $statusChips[$sib['status']] ?>" style="font-size:10.5px;"><?= $statusLabels[$sib['status']] ?></span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">🔧 दुरुस्ती इतिहास</div></div>
      <div style="padding:12px 20px;">
        <?php if (empty($tickets)): ?><p style="font-size:12.5px;color:var(--text-dim);">कधीही तक्रार नोंदवलेली नाही ✅</p><?php endif; ?>
        <?php foreach ($tickets as $t): ?>
          <div style="padding:10px 0;border-bottom:1px solid var(--line);">
            <div style="font-size:13px;"><?= h($t['issue_description']) ?></div>
            <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">
              <?= h($t['reported_by_name']) ?> यांनी <?= date('d M Y', strtotime($t['created_at'])) ?> रोजी नोंदवलं —
              <span class="status-chip <?= $t['status'] === 'resolved' ? 'done' : 'pending' ?>"><?= $t['status'] === 'resolved' ? '✅ पूर्ण' : ($t['status'] === 'in_progress' ? '🔧 सुरू आहे' : '⏳ प्रलंबित') ?></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if (!empty($transfers)): ?>
    <div class="panel">
      <div class="panel-head"><div class="title">📦 Transfer इतिहास</div></div>
      <div style="padding:12px 20px;">
        <?php foreach ($transfers as $tr): ?>
          <div style="font-size:12.5px;padding:8px 0;border-bottom:1px solid var(--line);">
            <?= h($tr['from_lab_name'] ?? 'नवीन') ?> → <?= h($tr['to_lab_name']) ?> — <?= date('d M Y', strtotime($tr['created_at'])) ?> (<?= h($tr['transferred_by_name']) ?>)
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-4">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📱 QR कोड</div></div>
      <div style="padding:18px;text-align:center;">
        <img src="<?= h($qrSrc) ?>" style="width:140px;height:140px;">
        <div style="font-size:11px;color:var(--text-dim);margin-top:8px;">स्कॅन करून थेट तक्रार नोंदवा</div>
      </div>
    </div>

    <a href="report-issue.php?asset_id=<?= $assetId ?>" class="btn w-100 mb-3" style="background:var(--amber);color:#fff;border-radius:9px;font-size:13px;padding:10px;font-weight:600;text-align:center;text-decoration:none;display:block;">🔧 तक्रार नोंदवा</a>

    <?php if ($canEdit): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">⚡ Power Status</div></div>
      <div style="padding:16px 20px;">
        <form method="POST">
          <input type="hidden" name="action" value="toggle_power">
          <input type="hidden" name="power_status" value="<?= $asset['power_status'] === 'on' ? 'off' : 'on' ?>">
          <button type="submit" class="btn w-100" style="background:<?= $asset['power_status'] === 'on' ? '#F7E2E3' : '#DCEFEC' ?>;color:<?= $asset['power_status'] === 'on' ? 'var(--rose)' : 'var(--rail)' ?>;border-radius:9px;font-size:13px;padding:10px;font-weight:700;border:none;">
            <?= $asset['power_status'] === 'on' ? '🔴 बंद करा (Turn OFF)' : '🟢 चालू करा (Turn ON)' ?>
          </button>
        </form>
      </div>
    </div>

    <div class="panel mb-3">
      <div style="padding:14px 20px;display:flex;gap:10px;">
        <a href="asset-edit.php?id=<?= $assetId ?>" class="btn w-100" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:12.5px;padding:9px;text-align:center;text-decoration:none;">✏️ Edit करा</a>
        <form method="POST" onsubmit="return confirm('हे Asset कायमचं काढायचं? — याचा दुरुस्ती/Transfer इतिहासही जाईल.')" style="width:100%;">
          <input type="hidden" name="action" value="delete">
          <button type="submit" class="btn w-100" style="border:1px solid var(--rose);color:var(--rose);border-radius:9px;font-size:12.5px;padding:9px;background:none;">🗑️ काढा</button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">स्थिती बदला</div></div>
      <form method="POST" style="padding:16px 20px;">
        <input type="hidden" name="action" value="update_status">
        <select name="status" class="form-select-custom mb-2">
          <?php foreach ($statusLabels as $k => $lbl): ?><option value="<?= $k ?>" <?= $asset['status'] === $k ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px;">Update करा</button>
      </form>
    </div>
    <?php endif; ?>

    <?php if ((hasPermission('manage_it') || isManagementOrAbove()) && !empty($otherLabs)): ?>
    <div class="panel">
      <div class="panel-head"><div class="title">📦 दुसऱ्या Lab मध्ये हलवा</div></div>
      <form method="POST" style="padding:16px 20px;">
        <input type="hidden" name="action" value="transfer">
        <select name="to_lab_id" class="form-select-custom mb-2">
          <?php foreach ($otherLabs as $ol): ?><option value="<?= $ol['id'] ?>"><?= h($ol['lab_name']) ?></option><?php endforeach; ?>
        </select>
        <input type="text" name="remarks" class="form-control-custom mb-2" placeholder="कारण (ऐच्छिक)">
        <button type="submit" class="btn w-100" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px;">Transfer करा</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
