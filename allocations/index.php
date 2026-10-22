<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'asset_allocation_types', '../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_asset_allocations') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'allocate') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $campusId = (int)($_POST['campus_id'] ?? 0);
        $userId = (int)($_POST['user_id'] ?? 0);
        $assetLabel = trim($_POST['asset_label'] ?? '');
        $allocatedDate = ($_POST['allocated_date'] ?? '') ?: date('Y-m-d');

        if ($typeId && $campusId && $userId) {
            // या Type च्या Fields नुसार, Custom Data गोळा करतो
            $fieldsStmt = $pdo->prepare("SELECT * FROM asset_allocation_fields WHERE type_id = ?");
            $fieldsStmt->execute([$typeId]);
            $typeFields = $fieldsStmt->fetchAll();
            $customData = [];
            foreach ($typeFields as $tf) {
                $val = trim($_POST['field_' . $tf['field_key']] ?? '');
                if ($tf['is_required'] && $val === '') { continue; } // Validation — पुढे सुधारता येईल
                $customData[$tf['field_key']] = $val;
            }

            $pdo->prepare("
                INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, asset_label, custom_data, allocated_date, allocated_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ")->execute([$orgId, $typeId, $campusId, $userId, $assetLabel ?: null, json_encode($customData, JSON_UNESCAPED_UNICODE), $allocatedDate, $user['id']]);
            $newId = $pdo->lastInsertId();
            notifyUser($userId, $orgId, '📦 नवीन Allocation', $assetLabel ?: 'तुम्हाला नवीन वस्तू Allot झाली', 'allocations/index.php');
            logActivity('Asset Allocate केलं', 'asset_allocations', $newId, $assetLabel);
            $_SESSION['flash'] = 'Allocation झालं.';
        }
    } elseif ($action === 'transfer') {
        // सध्याचं Allocation संपवून, त्याच वस्तूचं नवीन Allocation नवीन
        // व्यक्तीला सुरू करतो — History दोन्ही Records मध्ये राहते
        $oldAllocId = (int)($_POST['old_allocation_id'] ?? 0);
        $newUserId = (int)($_POST['new_user_id'] ?? 0);
        $reason = trim($_POST['unallocated_reason'] ?? '');

        $oldStmt = $pdo->prepare("SELECT * FROM asset_allocations WHERE id = ? AND organization_id = ?");
        $oldStmt->execute([$oldAllocId, $orgId]);
        $old = $oldStmt->fetch();

        if ($old && $newUserId) {
            $pdo->prepare("UPDATE asset_allocations SET unallocated_date = CURDATE(), unallocated_reason = ? WHERE id = ?")->execute([$reason ?: null, $oldAllocId]);
            $pdo->prepare("
                INSERT INTO asset_allocations (organization_id, type_id, campus_id, user_id, asset_label, custom_data, allocated_date, allocated_by)
                VALUES (?, ?, ?, ?, ?, ?, CURDATE(), ?)
            ")->execute([$orgId, $old['type_id'], $old['campus_id'], $newUserId, $old['asset_label'], $old['custom_data'], $user['id']]);
            $newId = $pdo->lastInsertId();
            notifyUser($newUserId, $orgId, '📦 वस्तू तुम्हाला Transfer झाली', $old['asset_label'] ?: '', 'allocations/index.php');
            logActivity('Asset Transfer केलं', 'asset_allocations', $newId, $old['asset_label']);
            $_SESSION['flash'] = 'Transfer झालं.';
        }
    } elseif ($action === 'end_allocation') {
        $allocId = (int)($_POST['allocation_id'] ?? 0);
        $reason = trim($_POST['unallocated_reason'] ?? '');
        $pdo->prepare("UPDATE asset_allocations SET unallocated_date = CURDATE(), unallocated_reason = ? WHERE id = ? AND organization_id = ?")
            ->execute([$reason ?: null, $allocId, $orgId]);
        $_SESSION['flash'] = 'Allocation संपवलं गेलं.';
    }
    header('Location: index.php' . (!empty($_GET['type_id']) ? '?type_id=' . (int)$_GET['type_id'] : ''));
    exit;
}

$pageTitle = 'Asset Allocation Registry';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$view = $_GET['view'] ?? 'current';
$filterTypeId = (int)($_GET['type_id'] ?? 0);

$typesStmt = $pdo->prepare("SELECT * FROM asset_allocation_types WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$typesStmt->execute([$orgId]);
$types = $typesStmt->fetchAll();

$fieldsByType = [];
foreach ($types as $t) {
    $fStmt = $pdo->prepare("SELECT * FROM asset_allocation_fields WHERE type_id = ? ORDER BY sort_order");
    $fStmt->execute([$t['id']]);
    $fieldsByType[$t['id']] = $fStmt->fetchAll();
}

$sql = "
    SELECT aa.*, at.name AS type_name, at.icon AS type_icon, c.name AS campus_name, u.name AS user_name, u.designation
    FROM asset_allocations aa
    JOIN asset_allocation_types at ON at.id = aa.type_id
    JOIN campuses c ON c.id = aa.campus_id
    JOIN users u ON u.id = aa.user_id
    WHERE aa.organization_id = ?
";
$params = [$orgId];
if ($view === 'current') { $sql .= " AND aa.unallocated_date IS NULL"; }
if ($filterTypeId) { $sql .= " AND aa.type_id = ?"; $params[] = $filterTypeId; }
$sql .= " ORDER BY aa.allocated_date DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$allocations = $stmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📦 Asset Allocation Registry</div>
  <div class="d-flex gap-2">
    <a href="report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;">📊 Report</a>
    <?php if (hasRole('super_admin')): ?><a href="types.php" class="btn" style="border:1px solid var(--amber);color:var(--amber);border-radius:9px;font-size:12.5px;padding:8px 16px;">⚙️ Types Manage करा</a><?php endif; ?>
    <?php if ($canManage): ?><button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#allocateModal">+ नवीन Allocate करा</button><?php endif; ?>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">कोणाला काय Allot केलंय — Current + Full History</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (empty($types)): ?>
  <div class="panel"><div class="empty-state"><div class="icon"><i class="fa-solid fa-box"></i></div><div class="title">अजून कुठलाही Asset-Type तयार केलेला नाही</div><div class="sub">आधी "Types Manage करा" वर जाऊन Xerox/PC सारखे Type तयार करा</div></div></div>
<?php else: ?>

<div class="d-flex gap-2 mb-4 flex-wrap">
  <a href="?view=current<?= $filterTypeId ? '&type_id=' . $filterTypeId : '' ?>" class="btn" style="background:<?= $view === 'current' ? 'var(--rail)' : '#fff' ?>;color:<?= $view === 'current' ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">🟢 सध्याचे</a>
  <a href="?view=history<?= $filterTypeId ? '&type_id=' . $filterTypeId : '' ?>" class="btn" style="background:<?= $view === 'history' ? 'var(--rail)' : '#fff' ?>;color:<?= $view === 'history' ? '#fff' : 'inherit' ?>;border:1px solid var(--line);border-radius:9px;font-size:12px;padding:7px 14px;">📜 संपूर्ण History</a>
  <select onchange="window.location = '?view=<?= $view ?>&type_id=' + this.value" class="form-select-custom" style="width:auto;margin-left:auto;">
    <option value="">-- सगळे Types --</option>
    <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>" <?= $filterTypeId === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['name']) ?></option><?php endforeach; ?>
  </select>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Type</th><th>वस्तू</th><th>कोणाकडे</th><th>College/School</th><th>दिनांक</th><?php if ($view === 'history'): ?><th>परत/संपलं</th><?php endif; ?><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($allocations)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">कुठलीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($allocations as $a):
        $customData = json_decode($a['custom_data'] ?? '{}', true) ?: [];
      ?>
        <tr>
          <td><i class="<?= h($a['type_icon']) ?> me-1" style="color:var(--rail);"></i><?= h($a['type_name']) ?></td>
          <td>
            <div style="font-weight:600;"><?= h($a['asset_label'] ?: '—') ?></div>
            <?php if (!empty($customData)): ?>
              <div style="font-size:10.5px;color:var(--text-dim);"><?= implode(' · ', array_filter(array_map(fn($v) => is_string($v) && $v !== '' ? $v : null, $customData))) ?></div>
            <?php endif; ?>
          </td>
          <td><?= h($a['user_name']) ?><div style="font-size:10.5px;color:var(--text-dim);"><?= h($a['designation'] ?: '') ?></div></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($a['campus_name']) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($a['allocated_date'])) ?></td>
          <?php if ($view === 'history'): ?>
            <td class="font-mono"><?= $a['unallocated_date'] ? date('d M Y', strtotime($a['unallocated_date'])) : '<span style="color:var(--rail);font-weight:600;">अजूनही आहे</span>' ?></td>
          <?php endif; ?>
          <?php if ($canManage): ?>
            <td>
              <?php if (!$a['unallocated_date']): ?>
                <div class="d-flex gap-2">
                  <button type="button" class="btn btn-sm" style="border:1px solid var(--amber);color:var(--amber);border-radius:6px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#transferModal" onclick="document.getElementById('trOldId').value='<?= $a['id'] ?>';document.getElementById('trLabel').textContent='<?= h($a['asset_label'] ?: $a['type_name']) ?>';">Transfer</button>
                  <form method="POST" onsubmit="return confirm('हे Allocation संपवायचं?')"><input type="hidden" name="action" value="end_allocation"><input type="hidden" name="allocation_id" value="<?= $a['id'] ?>">
                    <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">संपवा</button>
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

<?php if ($canManage): ?>
<div class="modal fade" id="allocateModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="allocate">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Allocation</div>
        <label class="form-label-custom">Type</label>
        <select name="type_id" id="allocTypeSelect" class="form-select-custom mb-2" required onchange="showTypeFields(this.value)">
          <option value="">-- निवडा --</option>
          <?php foreach ($types as $t): ?><option value="<?= $t['id'] ?>"><?= h($t['name']) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label-custom">वस्तूचं Identify (उदा. "Xerox #2")</label>
        <input type="text" name="asset_label" class="form-control-custom mb-2">
        <div class="row g-2 mb-2">
          <div class="col-md-6">
            <label class="form-label-custom">कोणाला Allot करत आहात</label>
            <select name="user_id" class="form-select-custom" required>
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">College/School</label>
            <select name="campus_id" class="form-select-custom" required>
              <option value="">-- निवडा --</option>
              <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="allocated_date" class="form-control-custom mb-3" value="<?= date('Y-m-d') ?>">

        <div id="dynamicFieldsContainer"></div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">Allocate करा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="transferModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
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
function showTypeFields(typeId) {
  const container = document.getElementById('dynamicFieldsContainer');
  const fields = fieldsByType[typeId] || [];
  if (!fields.length) { container.innerHTML = ''; return; }
  let html = '<div style="font-weight:700;font-size:13px;margin-bottom:10px;">या Type साठी अतिरिक्त माहिती</div><div class="row g-2">';
  fields.forEach(function (f) {
    html += '<div class="col-md-6"><label class="form-label-custom">' + f.field_label + (f.is_required == 1 ? ' *' : '') + '</label>';
    const name = 'field_' + f.field_key;
    const req = f.is_required == 1 ? 'required' : '';
    if (f.field_type === 'dropdown') {
      html += '<select name="' + name + '" class="form-select-custom" ' + req + '><option value="">-- निवडा --</option>';
      (f.field_options || '').split(',').forEach(function (opt) { opt = opt.trim(); if (opt) html += '<option value="' + opt + '">' + opt + '</option>'; });
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
