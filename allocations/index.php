<?php
// Issue / Return Register — कायमचं Allocation आणि अल्प-मुदतीचं उसनं (Loan/Checkout) एकाच ठिकाणी
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'asset_allocation_types', '../');
requireMigratedTable($pdo, 'asset_items', '../');
$user = currentUser();
$orgId = activeOrgId();
// जुन्या "Asset Checkout" ची परवानगी असलेल्यांनाही इथे व्यवस्थापन करता येतं
$canManage = hasPermission('manage_asset_allocations') || hasPermission('manage_checkout') || isManagementOrAbove();

/** हा ID याच संस्थेचा आहे का (Table + id) */
function allocBelongsToOrg($pdo, $table, $id, $orgId, $extra = '') {
    if (!$id) return false;
    $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE id = ? AND organization_id = ? $extra");
    $st->execute([$id, $orgId]);
    return (bool)$st->fetchColumn();
}

$redirectView = preg_replace('/[^a-z]/', '', $_GET['view'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';
    $error = null;

    if ($action === 'add_item') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $location = trim($_POST['default_location'] ?? '') ?: null;
        $campusId = (int)($_POST['campus_id'] ?? 0);
        if (!allocBelongsToOrg($pdo, 'asset_allocation_types', $typeId, $orgId, 'AND is_active = 1') || $name === '') {
            $error = 'Type व नाव भरा.';
        } elseif ($campusId && !allocBelongsToOrg($pdo, 'campuses', $campusId, $orgId)) {
            $error = 'चुकीचं College/School.';
        } else {
            $pdo->prepare("INSERT INTO asset_items (organization_id, type_id, campus_id, name, default_location, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $typeId, $campusId ?: null, $name, $location, $user['id']]);
            $_SESSION['flash'] = 'नवीन वस्तू जोडली गेली.';
        }
        $redirectView = 'items';
    } elseif ($action === 'deactivate_item') {
        // History जपण्यासाठी वस्तू Delete करत नाही — फक्त निष्क्रिय
        $itemId = (int)($_POST['item_id'] ?? 0);
        $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM asset_allocations WHERE item_id = ? AND organization_id = ? AND unallocated_date IS NULL");
        $activeStmt->execute([$itemId, $orgId]);
        if ((int)$activeStmt->fetchColumn() > 0) {
            $error = 'ही वस्तू सध्या कोणाकडे तरी आहे — आधी परत घ्या.';
        } else {
            $pdo->prepare("UPDATE asset_items SET is_active = 0 WHERE id = ? AND organization_id = ?")->execute([$itemId, $orgId]);
            $_SESSION['flash'] = 'वस्तू निष्क्रिय केली (History कायम आहे).';
        }
        $redirectView = 'items';
    } elseif ($action === 'allocate') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $campusId = (int)($_POST['campus_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $itemId = (int)($_POST['item_id'] ?? 0);
        $assetLabel = trim($_POST['asset_label'] ?? '');
        $purpose = trim($_POST['purpose'] ?? '') ?: null;
        $allocatedDate = ($_POST['allocated_date'] ?? '') ?: date('Y-m-d');
        if (!strtotime($allocatedDate)) { $allocatedDate = date('Y-m-d'); }
        $dueAt = ($_POST['due_at'] ?? '') !== '' && strtotime($_POST['due_at']) ? date('Y-m-d H:i:s', strtotime($_POST['due_at'])) : null;

        $typeStmt = $pdo->prepare("SELECT * FROM asset_allocation_types WHERE id = ? AND organization_id = ? AND is_active = 1");
        $typeStmt->execute([$typeId, $orgId]);
        $type = $typeStmt->fetch();

        if (!$type) {
            $error = 'योग्य Type निवडा.';
        } elseif (!allocBelongsToOrg($pdo, 'users', $userId, $orgId, 'AND is_active = 1')) {
            $error = 'योग्य कर्मचारी निवडा.';
        } elseif (!$type['is_loan'] && !$campusId) {
            $error = 'College/School निवडा.';
        } elseif ($campusId && !allocBelongsToOrg($pdo, 'campuses', $campusId, $orgId)) {
            $error = 'चुकीचं College/School.';
        } elseif (!$itemId && $assetLabel === '') {
            $error = 'वस्तू निवडा किंवा तिचं नाव लिहा.';
        }

        // या Type च्या Fields नुसार, Custom Data — Required Field रिकामं असेल तर नाकारतो
        $customData = [];
        if (!$error) {
            $fieldsStmt = $pdo->prepare("SELECT * FROM asset_allocation_fields WHERE type_id = ? ORDER BY sort_order");
            $fieldsStmt->execute([$typeId]);
            foreach ($fieldsStmt->fetchAll() as $tf) {
                $val = trim($_POST['field_' . $tf['field_key']] ?? '');
                if ($tf['is_required'] && $val === '') { $error = "'{$tf['field_label']}' भरणं आवश्यक आहे."; break; }
                $customData[$tf['field_key']] = $val;
            }
        }

        if (!$error) {
            $pdo->beginTransaction();
            try {
                if ($itemId) {
                    $itemStmt = $pdo->prepare("SELECT * FROM asset_items WHERE id = ? AND organization_id = ? AND type_id = ? AND is_active = 1 FOR UPDATE");
                    $itemStmt->execute([$itemId, $orgId, $typeId]);
                    $item = $itemStmt->fetch();
                    if (!$item) { throw new RuntimeException('ही वस्तू या Type मध्ये सापडली नाही.'); }
                } else {
                    // नवीन नाव — त्याच Type मध्ये त्याच नावाची वस्तू असेल तर तीच वापरतो, नाहीतर नवीन
                    $itemStmt = $pdo->prepare("SELECT * FROM asset_items WHERE organization_id = ? AND type_id = ? AND name = ? AND is_active = 1 LIMIT 1 FOR UPDATE");
                    $itemStmt->execute([$orgId, $typeId, $assetLabel]);
                    $item = $itemStmt->fetch();
                    if (!$item) {
                        $pdo->prepare("INSERT INTO asset_items (organization_id, type_id, campus_id, name, created_by) VALUES (?, ?, ?, ?, ?)")
                            ->execute([$orgId, $typeId, $campusId ?: null, $assetLabel, $user['id']]);
                        $item = ['id' => (int)$pdo->lastInsertId(), 'name' => $assetLabel];
                    }
                }
                // एकच वस्तू एकाच वेळी दोघांना देता येत नाही
                $busyStmt = $pdo->prepare("SELECT u.name FROM asset_allocations aa JOIN users u ON u.id = aa.user_id WHERE aa.item_id = ? AND aa.unallocated_date IS NULL LIMIT 1");
                $busyStmt->execute([$item['id']]);
                $busyWith = $busyStmt->fetchColumn();
                if ($busyWith !== false) { throw new RuntimeException("'{$item['name']}' सध्या $busyWith यांच्याकडे आहे — आधी परत घ्या किंवा Transfer करा."); }

                $allocatedAt = $allocatedDate === date('Y-m-d') ? date('Y-m-d H:i:s') : $allocatedDate . ' 00:00:00';
                $pdo->prepare("
                    INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, item_id, asset_label, custom_data, purpose, allocated_date, allocated_at, due_at, allocated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$orgId, $typeId, $campusId ?: null, $userId, $item['id'], $item['name'], json_encode($customData, JSON_UNESCAPED_UNICODE), $purpose, $allocatedDate, $allocatedAt, $dueAt, $user['id']]);
                $newId = $pdo->lastInsertId();
                $pdo->commit();
                notifyUser($userId, $orgId, $type['is_loan'] ? '🔑 वस्तू तुमच्या नावावर Issue झाली' : '📦 नवीन Allocation', $item['name'] . ($dueAt ? ' — परत करायची मुदत: ' . date('d M, h:i A', strtotime($dueAt)) : ''), 'allocations/index.php');
                logActivity($type['is_loan'] ? 'वस्तू Issue (उसनं) केली' : 'Asset Allocate केलं', 'asset_allocations', $newId, $item['name']);
                $_SESSION['flash'] = 'Issue/Allocation झालं.';
            } catch (RuntimeException $e) {
                $pdo->rollBack();
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'transfer') {
        // सध्याचं (चालू) Allocation संपवून, त्याच वस्तूचं नवीन Allocation नवीन
        // व्यक्तीला सुरू करतो — History दोन्ही Records मध्ये राहते
        $oldAllocId = (int)($_POST['old_allocation_id'] ?? 0);
        $newUserId = (int)($_POST['new_user_id'] ?? 0);
        $reason = trim($_POST['unallocated_reason'] ?? '');

        if (!allocBelongsToOrg($pdo, 'users', $newUserId, $orgId, 'AND is_active = 1')) {
            $error = 'योग्य कर्मचारी निवडा.';
        } else {
            $pdo->beginTransaction();
            $oldStmt = $pdo->prepare("SELECT * FROM asset_allocations WHERE id = ? AND organization_id = ? AND unallocated_date IS NULL FOR UPDATE");
            $oldStmt->execute([$oldAllocId, $orgId]);
            $old = $oldStmt->fetch();
            if (!$old) {
                $pdo->rollBack();
                $error = 'फक्त चालू (Active) Allocation च Transfer करता येतं.';
            } else {
                $pdo->prepare("UPDATE asset_allocations SET unallocated_date = CURDATE(), returned_at = NOW(), returned_to = ?, unallocated_reason = ? WHERE id = ?")
                    ->execute([$user['id'], $reason ?: 'Transfer', $oldAllocId]);
                $pdo->prepare("
                    INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, item_id, asset_label, custom_data, purpose, allocated_date, allocated_at, due_at, allocated_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), NOW(), ?, ?)
                ")->execute([$orgId, $old['type_id'], $old['campus_id'], $newUserId, $old['item_id'], $old['asset_label'], $old['custom_data'], $old['purpose'], $old['due_at'], $user['id']]);
                $newId = $pdo->lastInsertId();
                $pdo->commit();
                notifyUser($newUserId, $orgId, '📦 वस्तू तुम्हाला Transfer झाली', $old['asset_label'] ?: '', 'allocations/index.php');
                logActivity('Asset Transfer केलं', 'asset_allocations', $newId, $old['asset_label']);
                $_SESSION['flash'] = 'Transfer झालं.';
            }
        }
    } elseif ($action === 'end_allocation') {
        // परत आलं / Allocation संपवलं — कोणी, केव्हा परत घेतलं ते नोंदवतो
        $allocId = (int)($_POST['allocation_id'] ?? 0);
        $reason = trim($_POST['unallocated_reason'] ?? '');
        $upd = $pdo->prepare("UPDATE asset_allocations SET unallocated_date = CURDATE(), returned_at = NOW(), returned_to = ?, unallocated_reason = ? WHERE id = ? AND organization_id = ? AND unallocated_date IS NULL");
        $upd->execute([$user['id'], $reason ?: null, $allocId, $orgId]);
        if ($upd->rowCount()) { logActivity('वस्तू परत घेतली', 'asset_allocations', $allocId); }
        $_SESSION['flash'] = 'परत जमा झालं / Allocation संपवलं.';
    }
    if ($error) { $_SESSION['form_error'] = $error; }
    header('Location: index.php' . ($redirectView ? '?view=' . $redirectView : ''));
    exit;
}

$pageTitle = 'Issue / Return Register';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$view = in_array($_GET['view'] ?? '', ['current', 'loans', 'overdue', 'history', 'items'], true) ? $_GET['view'] : 'current';
$filterTypeId = (int)($_GET['type_id'] ?? 0);

$typesStmt = $pdo->prepare("SELECT * FROM asset_allocation_types WHERE organization_id = ? AND is_active = 1 ORDER BY is_loan, name");
$typesStmt->execute([$orgId]);
$types = $typesStmt->fetchAll();
$typesById = array_column($types, null, 'id');

$fieldsByType = [];
foreach ($types as $t) {
    $fStmt = $pdo->prepare("SELECT * FROM asset_allocation_fields WHERE type_id = ? ORDER BY sort_order");
    $fStmt->execute([$t['id']]);
    $fieldsByType[$t['id']] = $fStmt->fetchAll();
}

// वस्तू + सध्या कोणाकडे आहे
$itemsStmt = $pdo->prepare("
    SELECT ai.*, at.name AS type_name, at.is_loan, c.name AS campus_name,
      (SELECT aa.id FROM asset_allocations aa WHERE aa.item_id = ai.id AND aa.unallocated_date IS NULL LIMIT 1) AS active_alloc_id,
      (SELECT u.name FROM asset_allocations aa JOIN users u ON u.id = aa.user_id WHERE aa.item_id = ai.id AND aa.unallocated_date IS NULL LIMIT 1) AS holder_name
    FROM asset_items ai JOIN asset_allocation_types at ON at.id = ai.type_id LEFT JOIN campuses c ON c.id = ai.campus_id
    WHERE ai.organization_id = ? AND ai.is_active = 1 AND at.is_active = 1" . ($filterTypeId ? " AND ai.type_id = " . $filterTypeId : "") . "
    ORDER BY at.name, ai.name
");
$itemsStmt->execute([$orgId]);
$items = $itemsStmt->fetchAll();

$sql = "
    SELECT aa.*, at.name AS type_name, at.icon AS type_icon, at.is_loan, c.name AS campus_name, u.name AS user_name, u.designation,
      ib.name AS issued_by_name, rt.name AS returned_to_name
    FROM asset_allocations aa
    JOIN asset_allocation_types at ON at.id = aa.type_id
    LEFT JOIN campuses c ON c.id = aa.campus_id
    JOIN users u ON u.id = aa.user_id
    LEFT JOIN users ib ON ib.id = aa.allocated_by
    LEFT JOIN users rt ON rt.id = aa.returned_to
    WHERE aa.organization_id = ?
";
$params = [$orgId];
if ($view === 'current') { $sql .= " AND aa.unallocated_date IS NULL"; }
if ($view === 'loans') { $sql .= " AND aa.unallocated_date IS NULL AND (at.is_loan = 1 OR aa.due_at IS NOT NULL)"; }
if ($view === 'overdue') { $sql .= " AND aa.unallocated_date IS NULL AND aa.due_at IS NOT NULL AND aa.due_at < NOW()"; }
if ($filterTypeId) { $sql .= " AND aa.type_id = ?"; $params[] = $filterTypeId; }
$sql .= $view === 'overdue' ? " ORDER BY aa.due_at" : " ORDER BY COALESCE(aa.allocated_at, aa.allocated_date) DESC, aa.id DESC";
$allocations = [];
if ($view !== 'items') {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $allocations = $stmt->fetchAll();
}

$kpiStmt = $pdo->prepare("
    SELECT SUM(aa.unallocated_date IS NULL) AS active_count,
      SUM(aa.unallocated_date IS NULL AND (at.is_loan = 1 OR aa.due_at IS NOT NULL)) AS loan_count,
      SUM(aa.unallocated_date IS NULL AND aa.due_at IS NOT NULL AND aa.due_at < NOW()) AS overdue_count
    FROM asset_allocations aa JOIN asset_allocation_types at ON at.id = aa.type_id WHERE aa.organization_id = ?
");
$kpiStmt->execute([$orgId]);
$kpi = $kpiStmt->fetch();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();

$itemsForJs = array_map(fn($i) => ['id' => (int)$i['id'], 'type_id' => (int)$i['type_id'], 'name' => $i['name'], 'busy' => (bool)$i['active_alloc_id'], 'holder' => $i['holder_name'], 'campus_id' => $i['campus_id']], $items);
$tabStyle = fn($v) => 'background:' . ($view === $v ? 'var(--rail)' : '#fff') . ';color:' . ($view === $v ? '#fff' : 'inherit') . ';border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;';
$typeQs = $filterTypeId ? '&type_id=' . $filterTypeId : '';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0">📦 Issue / Return Register</div>
  <div class="d-flex gap-2 flex-wrap">
    <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📊 Report</a>
    <?php if ($canManage): ?>
      <a href="export.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📥 CSV Export</a>
      <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📤 वस्तू Import</a>
    <?php endif; ?>
    <?php if (hasRole('super_admin')): ?><a href="types.php" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:12.5px;padding:8px 16px;">⚙️ Types Manage करा</a><?php endif; ?>
    <?php if ($canManage && !empty($types)): ?>
      <button type="button" class="btn" style="border:1px solid var(--rail);color:var(--rail);border-radius:9px;font-size:12.5px;padding:8px 16px;" data-bs-toggle="modal" data-bs-target="#addItemModal">+ नवीन वस्तू</button>
      <button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#allocateModal" onclick="prepAllocate(0, 0)">+ Issue / Allocate करा</button>
    <?php endif; ?>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">कोणाला काय दिलंय — कायमचं Allocation (PC, Xerox) आणि अल्प-मुदतीचं उसनं (Projector, चावी, Camera) — Current + Full History</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if (empty($types)): ?>
  <div class="panel"><div class="empty-state"><div class="icon"><i class="fa-solid fa-box"></i></div><div class="title">अजून कुठलाही Asset-Type तयार केलेला नाही</div><div class="sub">आधी "Types Manage करा" वर जाऊन Xerox/PC (कायमचं) किंवा Projector/चावी (उसनं) सारखे Type तयार करा</div></div></div>
<?php else: ?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण वस्तू</div><div class="val"><?= count($items) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🟢 सध्या दिलेल्या</div><div class="val" style="color:var(--rail);"><?= (int)$kpi['active_count'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔑 उसनं बाहेर</div><div class="val" style="color:var(--amber);"><?= (int)$kpi['loan_count'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">⚠️ मुदत संपलेल्या</div><div class="val" style="color:<?= (int)$kpi['overdue_count'] > 0 ? 'var(--rose)' : 'var(--rail)' ?>;"><?= (int)$kpi['overdue_count'] ?></div></div></div>
</div>

<div class="d-flex gap-2 mb-4 flex-wrap">
  <a href="?view=current<?= $typeQs ?>" class="btn" style="<?= $tabStyle('current') ?>">🟢 सध्याचे</a>
  <a href="?view=loans<?= $typeQs ?>" class="btn" style="<?= $tabStyle('loans') ?>">🔑 उसनं (Loans)</a>
  <a href="?view=overdue<?= $typeQs ?>" class="btn" style="<?= $tabStyle('overdue') ?>">⚠️ मुदत संपलेले</a>
  <a href="?view=history<?= $typeQs ?>" class="btn" style="<?= $tabStyle('history') ?>">📜 संपूर्ण History</a>
  <a href="?view=items<?= $typeQs ?>" class="btn" style="<?= $tabStyle('items') ?>">📦 वस्तू यादी</a>
  <select onchange="window.location = '?view=<?= $view ?>&type_id=' + this.value" class="form-select-custom" style="width:auto;margin-left:auto;">
    <option value="">-- सगळे Types --</option>
    <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>" <?= $filterTypeId === (int)$t['id'] ? 'selected' : '' ?>><?= $t['is_loan'] ? '🔑 ' : '' ?><?= h($t['name']) ?></option><?php endforeach; ?>
  </select>
</div>

<?php if ($view === 'items'): ?>
<div class="panel">
  <table class="rtable">
    <thead><tr><th>वस्तू</th><th>Type</th><th>ठिकाण</th><th>College/School</th><th>स्थिती</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($items)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही वस्तू जोडलेली नाही</td></tr><?php endif; ?>
      <?php foreach ($items as $it): ?>
        <tr>
          <td style="font-weight:600;"><?= h($it['name']) ?></td>
          <td><?= $it['is_loan'] ? '🔑 ' : '' ?><?= h($it['type_name']) ?></td>
          <td><?= h($it['default_location'] ?: '—') ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($it['campus_name'] ?: '—') ?></td>
          <td><?php if ($it['active_alloc_id']): ?><span class="status-chip pending">🔴 <?= h($it['holder_name']) ?> यांच्याकडे</span><?php else: ?><span class="status-chip done">🟢 उपलब्ध</span><?php endif; ?></td>
          <?php if ($canManage): ?>
            <td class="d-flex gap-2">
              <?php if (!$it['active_alloc_id']): ?>
                <button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:6px;font-size:11px;padding:5px 10px;" data-bs-toggle="modal" data-bs-target="#allocateModal" onclick="prepAllocate(<?= (int)$it['type_id'] ?>, <?= (int)$it['id'] ?>)">Issue करा</button>
                <form method="POST" onsubmit="return confirm('ही वस्तू निष्क्रिय करायची? (History कायम राहील)')"><input type="hidden" name="action" value="deactivate_item"><input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                  <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;" title="निष्क्रिय करा">✕</button>
                </form>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php else: ?>
<div class="panel">
  <div style="overflow-x:auto;">
  <table class="rtable">
    <thead><tr><th>Type</th><th>वस्तू</th><th>कोणाकडे</th><th>College/School</th><th>दिलं</th><th>मुदत</th><?php if ($view === 'history'): ?><th>परत/संपलं</th><?php endif; ?><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($allocations)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($allocations as $a):
        $customData = json_decode($a['custom_data'] ?? '{}', true) ?: [];
        $isOverdue = !$a['unallocated_date'] && $a['due_at'] && strtotime($a['due_at']) < time();
      ?>
        <tr style="<?= $isOverdue ? 'background:var(--rose-soft, #F7E2E3);' : '' ?>">
          <td><i class="<?= h($a['type_icon']) ?> me-1" style="color:var(--rail);"></i><?= h($a['type_name']) ?></td>
          <td>
            <div style="font-weight:600;"><?= h($a['asset_label'] ?: '—') ?></div>
            <?php if (!empty($customData)): ?>
              <div style="font-size:10.5px;color:var(--text-dim);"><?= h(implode(' · ', array_filter(array_map(fn($v) => is_string($v) && $v !== '' ? $v : null, $customData)))) ?></div>
            <?php endif; ?>
            <?php if ($a['purpose']): ?><div style="font-size:10.5px;color:var(--text-dim);">कारण: <?= h($a['purpose']) ?></div><?php endif; ?>
          </td>
          <td><?= h($a['user_name']) ?><div style="font-size:10.5px;color:var(--text-dim);"><?= h($a['designation'] ?: '') ?></div></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($a['campus_name'] ?: '—') ?></td>
          <td class="font-mono" style="font-size:12px;"><?= $a['allocated_at'] ? date('d M Y, h:i A', strtotime($a['allocated_at'])) : date('d M Y', strtotime($a['allocated_date'])) ?><?php if ($a['issued_by_name']): ?><div style="font-size:10px;color:var(--text-dim);">दिलं: <?= h($a['issued_by_name']) ?></div><?php endif; ?></td>
          <td class="font-mono" style="font-size:12px;"><?= $a['due_at'] ? date('d M, h:i A', strtotime($a['due_at'])) : '—' ?><?= $isOverdue ? ' ⚠️' : '' ?></td>
          <?php if ($view === 'history'): ?>
            <td class="font-mono" style="font-size:12px;">
              <?php if ($a['unallocated_date']): ?>
                <?= $a['returned_at'] ? date('d M Y, h:i A', strtotime($a['returned_at'])) : date('d M Y', strtotime($a['unallocated_date'])) ?>
                <?php if ($a['returned_to_name']): ?><div style="font-size:10px;color:var(--text-dim);">घेतलं: <?= h($a['returned_to_name']) ?></div><?php endif; ?>
                <?php if ($a['unallocated_reason']): ?><div style="font-size:10px;color:var(--text-dim);"><?= h($a['unallocated_reason']) ?></div><?php endif; ?>
              <?php else: ?><span style="color:var(--rail);font-weight:600;">अजूनही आहे</span><?php endif; ?>
            </td>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <td>
              <?php if (!$a['unallocated_date']): ?>
                <div class="d-flex gap-2">
                  <button type="button" class="btn btn-sm" style="border:1px solid var(--amber);color:var(--amber);border-radius:6px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#transferModal" onclick="document.getElementById('trOldId').value='<?= $a['id'] ?>';document.getElementById('trLabel').textContent=<?= h(json_encode($a['asset_label'] ?: $a['type_name'], JSON_UNESCAPED_UNICODE)) ?>;">Transfer</button>
                  <form method="POST" onsubmit="return confirm('वस्तू परत आली / हे Allocation संपवायचं?')"><input type="hidden" name="action" value="end_allocation"><input type="hidden" name="allocation_id" value="<?= $a['id'] ?>">
                    <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:6px;font-size:10.5px;padding:4px 10px;">✅ परत आलं</button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="modal fade" id="addItemModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_item">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन वस्तू जोडा</div>
        <label class="form-label-custom">Type</label>
        <select name="type_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= $t['is_loan'] ? '🔑 ' : '' ?><?= h($t['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">नाव / ओळख (उदा. "Projector #2")</label>
        <input type="text" name="name" class="form-control-custom mb-2" required>
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

<div class="modal fade" id="allocateModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" action="index.php?view=<?= h($view) ?>">
      <input type="hidden" name="action" value="allocate">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Issue / Allocate करा</div>
        <label class="form-label-custom">Type</label>
        <select name="type_id" id="allocTypeSelect" class="form-select-custom mb-2" required onchange="showTypeFields(this.value)">
          <option value="">-- निवडा --</option>
          <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= $t['is_loan'] ? '🔑 ' : '' ?><?= h($t['name']) ?><?= $t['is_loan'] ? ' (उसनं)' : '' ?></option><?php endforeach; ?>
        </select>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label-custom">वस्तू (यादीतून)</label>
            <select name="item_id" id="allocItemSelect" class="form-select-custom"><option value="">-- आधी Type निवडा --</option></select>
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">किंवा नवीन वस्तूचं नाव (उदा. "Xerox #2")</label>
            <input type="text" name="asset_label" id="allocLabel" class="form-control-custom">
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label-custom">कोणाला देत आहात</label>
            <select name="user_id" class="form-select-custom" required>
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">College/School <span id="campusOptionalNote" style="color:var(--text-dim);font-size:11px;"></span></label>
            <select name="campus_id" id="allocCampusSelect" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-4"><label class="form-label-custom">दिनांक</label><input type="date" name="allocated_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-4"><label class="form-label-custom">परत करायची मुदत <span id="dueNote" style="color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label><input type="datetime-local" name="due_at" id="allocDueAt" class="form-control-custom"></div>
          <div class="col-md-4"><label class="form-label-custom">कारण (ऐच्छिक)</label><input type="text" name="purpose" class="form-control-custom"></div>
        </div>

        <div id="dynamicFieldsContainer"></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Issue करा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" action="index.php?view=<?= h($view) ?>">
      <input type="hidden" name="action" value="transfer">
      <input type="hidden" name="old_allocation_id" id="trOldId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Transfer करा — <span id="trLabel"></span></div>
        <label class="form-label-custom">नवीन कोणाला देत आहात</label>
        <select name="new_user_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">कारण (ऐच्छिक)</label>
        <input type="text" name="unallocated_reason" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Transfer करा</button>
      </div>
    </form>
  </div></div>
</div>

<script>
const fieldsByType = <?= json_encode($fieldsByType, JSON_UNESCAPED_UNICODE) ?>;
const allocItems = <?= json_encode($itemsForJs, JSON_UNESCAPED_UNICODE) ?>;
const loanTypes = <?= json_encode(array_values(array_map(fn($t) => (int)$t['id'], array_filter($types, fn($t) => $t['is_loan'])))) ?>;
function escHtml(s) { const d = document.createElement('div'); d.textContent = s == null ? '' : String(s); return d.innerHTML; }
function prepAllocate(typeId, itemId) {
  document.getElementById('allocTypeSelect').value = typeId || '';
  showTypeFields(typeId || '');
  if (itemId) { document.getElementById('allocItemSelect').value = itemId; }
}
function showTypeFields(typeId) {
  typeId = parseInt(typeId) || 0;
  const isLoan = loanTypes.indexOf(typeId) !== -1;
  document.getElementById('dueNote').textContent = isLoan ? '(उसनं — मुदत भरा)' : '(ऐच्छिक)';
  document.getElementById('campusOptionalNote').textContent = isLoan ? '(ऐच्छिक)' : '*';
  document.getElementById('allocCampusSelect').required = !isLoan && typeId > 0;
  const itemSel = document.getElementById('allocItemSelect');
  let opts = '<option value="">-- यादीतून निवडा --</option>';
  allocItems.filter(function (i) { return i.type_id === typeId; }).forEach(function (i) {
    opts += '<option value="' + i.id + '"' + (i.busy ? ' disabled' : '') + '>' + escHtml(i.name) + (i.busy ? ' — ' + escHtml(i.holder) + ' यांच्याकडे' : '') + '</option>';
  });
  itemSel.innerHTML = opts;

  const container = document.getElementById('dynamicFieldsContainer');
  const fields = fieldsByType[typeId] || [];
  if (!fields.length) { container.innerHTML = ''; return; }
  let html = '<div style="font-weight:700;font-size:13px;margin-bottom:10px;">या Type साठी अतिरिक्त माहिती</div><div class="row g-2">';
  fields.forEach(function (f) {
    html += '<div class="col-md-6"><label class="form-label-custom">' + escHtml(f.field_label) + (f.is_required == 1 ? ' *' : '') + '</label>';
    const name = 'field_' + escHtml(f.field_key);
    const req = f.is_required == 1 ? 'required' : '';
    if (f.field_type === 'dropdown') {
      html += '<select name="' + name + '" class="form-select-custom" ' + req + '><option value="">-- निवडा --</option>';
      (f.field_options || '').split(',').forEach(function (opt) { opt = opt.trim(); if (opt) html += '<option value="' + escHtml(opt) + '">' + escHtml(opt) + '</option>'; });
      html += '</select>';
    } else if (f.field_type === 'yesno') {
      html += '<select name="' + name + '" class="form-select-custom" ' + req + '><option value="">-- निवडा --</option><option value="Yes">Yes</option><option value="No">No</option></select>';
    } else if (f.field_type === 'date') {
      html += '<input type="date" name="' + name + '" class="form-control-custom" ' + req + '>';
    } else if (f.field_type === 'number') {
      html += '<input type="number" name="' + name + '" class="form-control-custom" ' + req + '>';
    } else {
      html += '<input type="text" name="' + name + '" class="form-control-custom" ' + req + '>';
    }
    html += '</div>';
  });
  html += '</div>';
  container.innerHTML = html;
}
</script>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
