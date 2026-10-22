<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'checkout_items', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_checkout') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_item') {
        $name = trim($_POST['name'] ?? '');
        $category = trim($_POST['category'] ?? '') ?: null;
        $location = trim($_POST['default_location'] ?? '') ?: null;
        $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
        if ($name) {
            $pdo->prepare("INSERT INTO checkout_items (organization_id, campus_id, name, category, default_location, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $name, $category, $location, $user['id']]);
            $_SESSION['flash'] = 'नवीन वस्तू जोडली गेली.';
        }
    } elseif ($action === 'checkout') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $borrowedBy = (int)($_POST['borrowed_by'] ?? 0);
        $purpose = trim($_POST['purpose'] ?? '');
        $expectedReturn = ($_POST['expected_return_at'] ?? '') ?: null;
        $ownStmt = $pdo->prepare("SELECT (SELECT COUNT(*) FROM checkout_items WHERE id = ? AND organization_id = ?) + (SELECT COUNT(*) FROM users WHERE id = ? AND organization_id = ?) AS c");
        $ownStmt->execute([$itemId, $orgId, $borrowedBy, $orgId]);
        if ($itemId && $borrowedBy && (int)$ownStmt->fetch()['c'] === 2) {
            $pdo->prepare("INSERT INTO checkout_logs (item_id, borrowed_by, issued_by, purpose, checkout_at, expected_return_at) VALUES (?, ?, ?, ?, NOW(), ?)")
                ->execute([$itemId, $borrowedBy, $user['id'], $purpose ?: null, $expectedReturn]);
            $newLogId = $pdo->lastInsertId();
            $itemNameStmt = $pdo->prepare("SELECT name FROM checkout_items WHERE id = ?");
            $itemNameStmt->execute([$itemId]);
            $itemName = $itemNameStmt->fetch()['name'] ?? '';
            notifyUser($borrowedBy, $orgId, '🔑 वस्तू तुमच्या नावावर Issue झाली', $itemName, 'checkout/index.php');
            logActivity('Checkout केलं', 'checkout_logs', $newLogId, $itemName);
            $_SESSION['flash'] = 'Checkout झालं.';
        }
    } elseif ($action === 'return') {
        $logId = (int)($_POST['log_id'] ?? 0);
        $pdo->prepare("UPDATE checkout_logs l JOIN checkout_items i ON i.id = l.item_id SET l.actual_return_at = NOW(), l.returned_to = ? WHERE l.id = ? AND i.organization_id = ? AND l.actual_return_at IS NULL")->execute([$user['id'], $logId, $orgId]);
        $_SESSION['flash'] = 'परत जमा झालं.';
    } elseif ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $pdo->prepare("DELETE FROM checkout_items WHERE id = ? AND organization_id = ?")->execute([$itemId, $orgId]);
        $_SESSION['flash'] = 'वस्तू काढली गेली.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'Asset Checkout / Borrowing';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$itemsStmt = $pdo->prepare("
    SELECT ci.*, c.name AS campus_name,
      (SELECT cl.id FROM checkout_logs cl WHERE cl.item_id = ci.id AND cl.actual_return_at IS NULL ORDER BY cl.checkout_at DESC LIMIT 1) AS active_log_id
    FROM checkout_items ci LEFT JOIN campuses c ON c.id = ci.campus_id
    WHERE ci.organization_id = ? AND ci.is_active = 1 ORDER BY ci.name
");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();

$currentlyOutStmt = $pdo->prepare("
    SELECT cl.*, ci.name AS item_name, u.name AS borrower_name
    FROM checkout_logs cl JOIN checkout_items ci ON ci.id = cl.item_id JOIN users u ON u.id = cl.borrowed_by
    WHERE ci.organization_id = ? AND cl.actual_return_at IS NULL ORDER BY cl.checkout_at DESC
");
$currentlyOutStmt->execute([$orgId]);
$currentlyOut = $currentlyOutStmt->fetchAll();
$overdueCount = count(array_filter($currentlyOut, fn($l) => $l['expected_return_at'] && strtotime($l['expected_return_at']) < time()));

$staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔑 Asset Checkout / Borrowing</div>
  <div class="d-flex gap-2">
    <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📊 Report</a>
    <?php if ($canManage): ?>
      <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📥 Bulk Import</a>
      <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addItemModal">+ नवीन वस्तू</button>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Projector, चावी, Camera — कोणी घेतली, कधी परत करणार — साधी Log</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण वस्तू</div><div class="val"><?= count($items) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 सध्या बाहेर</div><div class="val" style="color:var(--amber);"><?= count($currentlyOut) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ मुदत संपलेल्या</div><div class="val" style="color:<?= $overdueCount > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $overdueCount ?></div></div></div>
</div>

<div class="panel mb-4">
  <div class="panel-head"><div class="title">🔴 सध्या ज्यांच्याकडे आहे</div></div>
  <table class="rtable">
    <thead><tr><th>वस्तू</th><th>कोणाकडे</th><th>कारण</th><th>घेतल्याची वेळ</th><th>परत करायची मुदत</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($currentlyOut)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">सध्या कुठलीही वस्तू कोणाकडे नाही</td></tr><?php endif; ?>
      <?php foreach ($currentlyOut as $log):
        $isOverdue = $log['expected_return_at'] && strtotime($log['expected_return_at']) < time();
      ?>
        <tr style="<?= $isOverdue ? 'background:var(--rose-soft, #F7E2E3);' : '' ?>">
          <td style="font-weight:600;"><?= h($log['item_name']) ?></td>
          <td><?= h($log['borrower_name']) ?></td>
          <td><?= h($log['purpose'] ?: '—') ?></td>
          <td class="font-mono"><?= date('d M, h:i A', strtotime($log['checkout_at'])) ?></td>
          <td class="font-mono"><?= $log['expected_return_at'] ? date('d M, h:i A', strtotime($log['expected_return_at'])) : '—' ?><?= $isOverdue ? ' ⚠️' : '' ?></td>
          <?php if ($canManage): ?>
            <td><form method="POST"><input type="hidden" name="action" value="return"><input type="hidden" name="log_id" value="<?= $log['id'] ?>">
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:6px;font-size:11px;padding:5px 12px;">✅ परत आलं</button>
            </form></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📦 सगळ्या वस्तू</div></div>
  <table class="rtable">
    <thead><tr><th>वस्तू</th><th>प्रकार</th><th>ठिकाण</th><th>College/School</th><th>स्थिती</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($items)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही वस्तू जोडलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($items as $item): ?>
        <tr>
          <td style="font-weight:600;"><?= h($item['name']) ?></td>
          <td><?= h($item['category'] ?: '—') ?></td>
          <td><?= h($item['default_location'] ?: '—') ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($item['campus_name'] ?: '—') ?></td>
          <td>
            <?php if ($item['active_log_id']): ?><span class="status-chip pending">🔴 बाहेर आहे</span>
            <?php else: ?><span class="status-chip done">🟢 उपलब्ध</span><?php endif; ?>
          </td>
          <?php if ($canManage): ?>
            <td class="d-flex gap-2">
              <?php if (!$item['active_log_id']): ?>
                <button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:6px;font-size:11px;padding:5px 10px;" data-bs-toggle="modal" data-bs-target="#checkoutModal" onclick="document.getElementById('coItemId').value='<?= $item['id'] ?>';document.getElementById('coItemName').textContent='<?= h($item['name']) ?>';">Issue करा</button>
              <?php endif; ?>
              <form method="POST" onsubmit="return confirm('काढायचं?')"><input type="hidden" name="action" value="delete_item"><input type="hidden" name="item_id" value="<?= $item['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;">✕</button>
              </form>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="addItemModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_item">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन वस्तू जोडा</div>
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
        <label class="form-label-custom">प्रकार</label>
        <input type="text" name="category" class="form-control-custom mb-2" placeholder="उदा. Projector, चावी">
        <label class="form-label-custom">कुठे ठेवतात (ऐच्छिक)</label>
        <input type="text" name="default_location" class="form-control-custom mb-2">
        <label class="form-label-custom">College/School (ऐच्छिक)</label>
        <select name="campus_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="checkoutModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="checkout">
      <input type="hidden" name="item_id" id="coItemId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Issue करा — <span id="coItemName"></span></div>
        <label class="form-label-custom">कोणाला देत आहात</label>
        <select name="borrowed_by" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">कारण</label>
        <input type="text" name="purpose" class="form-control-custom mb-2">
        <label class="form-label-custom">परत करायची मुदत (ऐच्छिक)</label>
        <input type="datetime-local" name="expected_return_at" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Issue करा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
