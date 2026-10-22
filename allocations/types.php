<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

function makeFieldKey($label) {
    $key = preg_replace('/[^a-zA-Z0-9]+/', '_', trim($label));
    return strtolower(trim($key, '_')) ?: 'field';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_type') {
        $name = trim($_POST['name'] ?? '');
        $icon = trim($_POST['icon'] ?? '') ?: 'fa-solid fa-box';
        if ($name) {
            $pdo->prepare("INSERT INTO asset_allocation_types (organization_id, name, icon, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$orgId, $name, $icon, $user['id']]);
            $_SESSION['flash'] = "'$name' Type तयार झाला — आता त्यात Fields जोडा.";
            header('Location: types.php?id=' . $pdo->lastInsertId());
            exit;
        }
    } elseif ($action === 'add_field') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $label = trim($_POST['field_label'] ?? '');
        $fieldType = in_array($_POST['field_type'] ?? '', ['text','number','dropdown','date','yesno'], true) ? $_POST['field_type'] : 'text';
        $options = trim($_POST['field_options'] ?? '');
        $isRequired = isset($_POST['is_required']) ? 1 : 0;
        if ($typeId && $label) {
            $maxOrderStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM asset_allocation_fields WHERE type_id = ?");
            $maxOrderStmt->execute([$typeId]);
            $nextOrder = $maxOrderStmt->fetch()['next_order'];
            $fieldKey = makeFieldKey($label);
            $pdo->prepare("INSERT INTO asset_allocation_fields (type_id, field_label, field_key, field_type, field_options, is_required, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$typeId, $label, $fieldKey, $fieldType, $fieldType === 'dropdown' ? $options : null, $isRequired, $nextOrder]);
            $_SESSION['flash'] = 'Field जोडला गेला.';
        }
        header('Location: types.php?id=' . $typeId);
        exit;
    } elseif ($action === 'delete_field') {
        $fieldId = (int)($_POST['field_id'] ?? 0);
        $typeId = (int)($_POST['type_id'] ?? 0);
        $pdo->prepare("DELETE FROM asset_allocation_fields WHERE id = ?")->execute([$fieldId]);
        header('Location: types.php?id=' . $typeId);
        exit;
    } elseif ($action === 'delete_type') {
        $typeId = (int)($_POST['type_id'] ?? 0);
        $pdo->prepare("DELETE FROM asset_allocation_types WHERE id = ? AND organization_id = ?")->execute([$typeId, $orgId]);
        $_SESSION['flash'] = 'Type काढला गेला.';
        header('Location: types.php');
        exit;
    }
    header('Location: types.php');
    exit;
}

$pageTitle = 'Asset Allocation Types';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$typesStmt = $pdo->prepare("SELECT * FROM asset_allocation_types WHERE organization_id = ? ORDER BY name");
$typesStmt->execute([$orgId]);
$types = $typesStmt->fetchAll();

$selectedTypeId = (int)($_GET['id'] ?? ($types[0]['id'] ?? 0));
$selectedType = null;
$fields = [];
foreach ($types as $t) { if ((int)$t['id'] === $selectedTypeId) { $selectedType = $t; break; } }
if ($selectedType) {
    $fieldsStmt = $pdo->prepare("SELECT * FROM asset_allocation_fields WHERE type_id = ? ORDER BY sort_order");
    $fieldsStmt->execute([$selectedTypeId]);
    $fields = $fieldsStmt->fetchAll();
}

$fieldTypeLabels = ['text' => '📝 Text', 'number' => '🔢 Number', 'dropdown' => '📋 Dropdown', 'date' => '📅 Date', 'yesno' => '✅ Yes/No'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">⚙️ Asset Allocation Types व्यवस्थापन</div>
  <div class="d-flex gap-2">
    <button type="button" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 16px;" data-bs-toggle="modal" data-bs-target="#addTypeModal">+ नवीन Type</button>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;">Allocations बघा →</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:24px;">उदा. "Xerox Machine" Type तयार करा, मग त्यासाठी "Model No.", "Toner Level %" सारखे Fields जोडा</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">सगळे Types</div></div>
      <?php foreach ($types as $t): ?>
        <a href="?id=<?= $t['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;padding:12px 18px;text-decoration:none;color:inherit;border-bottom:1px solid var(--line);<?= $selectedTypeId === (int)$t['id'] ? 'background:var(--rail-soft);' : '' ?>">
          <span><i class="<?= h($t['icon']) ?> me-2" style="color:var(--rail);"></i><?= h($t['name']) ?></span>
        </a>
      <?php endforeach; ?>
      <?php if (empty($types)): ?><p style="font-size:12px;color:var(--text-dim);padding:14px 18px;">अजून कुठलाही Type नाही</p><?php endif; ?>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$selectedType): ?>
      <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">डावीकडून एक Type निवडा, किंवा नवीन तयार करा.</div>
    <?php else: ?>
      <div class="panel">
        <div class="panel-head">
          <div class="title"><i class="<?= h($selectedType['icon']) ?> me-2"></i><?= h($selectedType['name']) ?> — Fields</div>
          <form method="POST" onsubmit="return confirm('हा संपूर्ण Type (आणि त्यातले सगळे Allocations) काढायचे?')">
            <input type="hidden" name="action" value="delete_type"><input type="hidden" name="type_id" value="<?= $selectedType['id'] ?>">
            <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11px;">🗑️ Type काढा</button>
          </form>
        </div>
        <table class="rtable">
          <thead><tr><th>Field</th><th>प्रकार</th><th>Required</th><th></th></tr></thead>
          <tbody>
            <?php if (empty($fields)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:20px;">अजून कुठलंही Field नाही</td></tr><?php endif; ?>
            <?php foreach ($fields as $f): ?>
              <tr>
                <td style="font-weight:600;"><?= h($f['field_label']) ?><?php if ($f['field_type'] === 'dropdown'): ?><div style="font-size:10.5px;color:var(--text-dim);">Options: <?= h($f['field_options']) ?></div><?php endif; ?></td>
                <td><?= $fieldTypeLabels[$f['field_type']] ?></td>
                <td><?= $f['is_required'] ? '✅' : '—' ?></td>
                <td><form method="POST"><input type="hidden" name="action" value="delete_field"><input type="hidden" name="field_id" value="<?= $f['id'] ?>"><input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
                  <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;">✕</button>
                </form></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div style="padding:16px 20px;border-top:1px solid var(--line);">
          <form method="POST">
            <input type="hidden" name="action" value="add_field">
            <input type="hidden" name="type_id" value="<?= $selectedTypeId ?>">
            <div style="font-weight:700;font-size:13px;margin-bottom:10px;">+ नवीन Field जोडा</div>
            <div class="row g-2">
              <div class="col-md-4"><input type="text" name="field_label" class="form-control-custom" placeholder="उदा. Model No." required></div>
              <div class="col-md-3">
                <select name="field_type" class="form-select-custom" id="fieldTypeSelect" onchange="document.getElementById('optionsRow').style.display = this.value === 'dropdown' ? 'block' : 'none';">
                  <?php foreach ($fieldTypeLabels as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="col-md-3">
                <label style="font-size:11.5px;display:flex;align-items:center;gap:6px;margin-top:8px;"><input type="checkbox" name="is_required" style="width:14px;height:14px;"> Required?</label>
              </div>
              <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:8px;">जोडा</button></div>
              <div class="col-12" id="optionsRow" style="display:none;">
                <input type="text" name="field_options" class="form-control-custom" placeholder="Options — Comma ने वेगळे करा (उदा. Admin,Teaching,Exam)">
              </div>
            </div>
          </form>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal fade" id="addTypeModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_type">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन Asset-Type तयार करा</div>
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom mb-2" placeholder="उदा. Xerox Machine, Computer/PC" required>
        <label class="form-label-custom">Icon (FontAwesome Class, ऐच्छिक)</label>
        <input type="text" name="icon" class="form-control-custom" placeholder="fa-solid fa-print">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">तयार करा</button>
      </div>
    </form>
  </div></div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
