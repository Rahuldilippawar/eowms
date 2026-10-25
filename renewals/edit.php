<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/renewal-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$item = renewalFetchAccessibleItem($pdo, $id);
if (!$item) {
    $_SESSION['form_error'] = 'ही नोंद सापडली नाही किंवा तुम्हाला ती बदलण्याची परवानगी नाही.';
    header('Location: index.php');
    exit;
}
$source = renewalSourceInfo($item);
$visibleDeptIds = getVisibleDepartmentIds('view_org_wide_dashboards');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $expiryDate = $_POST['expiry_date'] ?? '';
    $issuedDate = ($_POST['issued_date'] ?? '') ?: null;
    $renewalCost = ($_POST['renewal_cost'] ?? '') !== '' ? (float)$_POST['renewal_cost'] : null;
    $regulatoryBody = trim($_POST['regulatory_body'] ?? '') ?: null;
    $referenceNo = trim($_POST['reference_no'] ?? '') ?: null;
    $vendorName = trim($_POST['vendor_name'] ?? '') ?: null;
    $vendorContact = trim($_POST['vendor_contact'] ?? '') ?: null;
    $responsibleUserId = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;

    // दुसऱ्या Module मधून आलेल्या नोंदीचं नाव/प्रकार/विभाग तिथूनच ठरतो — इथे बदलता येत नाही
    $itemType = $source ? $item['item_type'] : trim($_POST['item_type'] ?? '');
    $itemName = $source ? $item['item_name'] : trim($_POST['item_name'] ?? '');
    $departmentId = $source ? (int)$item['department_id'] : (int)($_POST['department_id'] ?? 0);

    $error = null;
    if (!$itemType || !$itemName || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiryDate)) {
        $error = t('s428');
    }
    $department = null;
    if (!$error) {
        $deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
        $deptStmt->execute([$departmentId, $orgId]);
        $department = $deptStmt->fetch();
        if (!$department || ($visibleDeptIds !== null && !in_array($departmentId, $visibleDeptIds, true))) {
            $error = 'निवडलेला विभाग अवैध आहे.';
        }
    }
    if (!$error && $responsibleUserId) {
        $ru = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ?");
        $ru->execute([$responsibleUserId, $orgId]);
        if (!$ru->fetch()) { $responsibleUserId = null; }
    }
    if ($error) {
        $_SESSION['form_error'] = $error;
        header('Location: edit.php?id=' . $id);
        exit;
    }

    $pdo->prepare("
        UPDATE renewal_items SET campus_id = ?, department_id = ?, responsible_user_id = ?, item_type = ?, regulatory_body = ?, item_name = ?,
          reference_no = ?, issued_date = ?, expiry_date = ?, renewal_cost = ?, vendor_name = ?, vendor_contact = ?
        WHERE id = ? AND organization_id = ?
    ")->execute([$department['campus_id'], $departmentId, $responsibleUserId, $itemType, $regulatoryBody, $itemName,
        $referenceNo, $issuedDate, $expiryDate, $renewalCost, $vendorName, $vendorContact, $id, $orgId]);

    if ($expiryDate !== $item['expiry_date']) {
        renewalWriteBackSource($pdo, $item, $expiryDate);
    }

    $changes = [];
    foreach (['item_name' => $itemName, 'item_type' => $itemType, 'expiry_date' => $expiryDate, 'department_id' => $departmentId, 'reference_no' => $referenceNo, 'vendor_name' => $vendorName] as $k => $v) {
        if ((string)$item[$k] !== (string)$v) { $changes[] = "$k: " . ($item[$k] ?? '—') . ' → ' . ($v ?? '—'); }
    }
    logActivity('नूतनीकरण नोंद संपादित केली', 'renewal_item', $id, $itemName . ($changes ? ' (' . implode('; ', $changes) . ')' : ''));
    $_SESSION['flash'] = 'नूतनीकरण नोंद अद्ययावत केली.';
    header('Location: index.php');
    exit;
}

$deptSql = "SELECT * FROM departments WHERE organization_id = ? AND is_active = 1";
$deptParams = [$orgId];
if ($visibleDeptIds !== null) {
    $deptSql .= empty($visibleDeptIds) ? " AND 0" : " AND id IN (" . implode(',', array_fill(0, count($visibleDeptIds), '?')) . ")";
    $deptParams = array_merge($deptParams, $visibleDeptIds);
}
$deptStmt = $pdo->prepare($deptSql . " ORDER BY name_local");
$deptStmt->execute($deptParams);
$departments = $deptStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$staffPool = $staffStmt->fetchAll();

$attachmentsByCycle = renewalAttachmentsByCycle($pdo, $id);

$pageTitle = 'नूतनीकरण नोंद संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$regBodies = ['CBSE','State Board','AICTE','DTE Maharashtra','MSBTE','University','UGC','NAAC','RTE','RTO/Transport','Other Government','Not Applicable'];
?>

<div class="page-eyebrow"><?= h($item['dept_name'] ?: $item['dept_name_en']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">✏️ <?= h($item['item_name']) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>नूतनीकरण यादी</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if ($source): ?>
  <div class="alert alert-info" style="border-radius:10px;font-size:12.5px;">
    🔗 ही नोंद <strong><?= h($source['label']) ?></strong> Module मधून आपोआप तयार झाली आहे — नाव, प्रकार व विभाग तिथूनच बदलता येतात. मुदत-दिनांक बदलल्यास तो <?= h($source['label']) ?> मध्येही अद्ययावत होईल.
  </div>
<?php endif; ?>

<div class="panel mb-3">
  <form method="POST" action="edit.php" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s1')) ?></label>
        <select name="department_id" class="form-select-custom" <?= $source ? 'disabled' : '' ?> required>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= (int)$dept['id'] === (int)$item['department_id'] ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s172')) ?></label>
        <input type="text" name="item_type" class="form-control-custom" value="<?= h($item['item_type']) ?>" <?= $source ? 'readonly' : '' ?> required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">Regulatory Body</label>
        <select name="regulatory_body" class="form-select-custom">
          <option value="">-- लागू नाही / निवडा --</option>
          <?php foreach ($regBodies as $rb): ?><option value="<?= h($rb) ?>" <?= $item['regulatory_body'] === $rb ? 'selected' : '' ?>><?= h($rb) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s173')) ?></label>
        <input type="text" name="item_name" class="form-control-custom" value="<?= h($item['item_name']) ?>" <?= $source ? 'readonly' : '' ?> required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s174')) ?></label>
        <input type="text" name="reference_no" class="form-control-custom" value="<?= h($item['reference_no']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s175')) ?></label>
        <input type="date" name="issued_date" class="form-control-custom" value="<?= h($item['issued_date']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s30')) ?></label>
        <input type="date" name="expiry_date" class="form-control-custom" value="<?= h($item['expiry_date']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s70')) ?></label>
        <input type="number" step="0.01" name="renewal_cost" class="form-control-custom" value="<?= h($item['renewal_cost']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s176')) ?></label>
        <input type="text" name="vendor_name" class="form-control-custom" value="<?= h($item['vendor_name']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">Vendor संपर्क (फोन/ईमेल)</label>
        <input type="text" name="vendor_contact" class="form-control-custom" value="<?= h($item['vendor_contact']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">जबाबदार व्यक्ती</label>
        <select name="responsible_user_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($staffPool as $s): ?><option value="<?= $s['id'] ?>" <?= (int)$s['id'] === (int)$item['responsible_user_id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">जतन करा</button>
      </div>
    </div>
  </form>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📎 प्रमाणपत्रे / दस्तऐवज</div><div class="sub">प्रत्येक नूतनीकरण-Cycle (मुदत-दिनांक) नुसार — PDF किंवा प्रतिमा</div></div>
  <div style="padding:16px 20px;">
    <form method="POST" action="attachment.php" enctype="multipart/form-data" class="row g-2 align-items-end mb-3">
      <input type="hidden" name="action" value="upload">
      <input type="hidden" name="item_id" value="<?= $id ?>">
      <input type="hidden" name="back" value="edit">
      <div class="col-md-3">
        <label class="form-label-custom">कोणत्या Cycle चं (मुदत-दिनांक)</label>
        <input type="date" name="cycle_expiry_date" class="form-control-custom" value="<?= h($item['expiry_date']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">शीर्षक (ऐच्छिक)</label>
        <input type="text" name="title" class="form-control-custom" placeholder="उदा. विमा पॉलिसी">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">File (PDF/JPG/PNG, कमाल 10 MB)</label>
        <input type="file" name="file" class="form-control-custom" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
      </div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;">Upload</button></div>
    </form>
    <?php if (empty($attachmentsByCycle)): ?>
      <div style="font-size:12.5px;color:var(--text-dim);">अजून कोणतंही प्रमाणपत्र जोडलेलं नाही.</div>
    <?php endif; ?>
    <?php foreach ($attachmentsByCycle as $cycle => $files): ?>
      <div style="font-size:12px;font-weight:700;margin:10px 0 4px;">Cycle: <?= $cycle ? date('d M Y', strtotime($cycle)) : '—' ?></div>
      <?php foreach ($files as $a): ?>
        <div class="d-flex align-items-center gap-2" style="font-size:12.5px;padding:4px 0;">
          <a href="attachment.php?id=<?= $a['id'] ?>" style="color:var(--rail);"><i class="fa-solid fa-paperclip me-1"></i><?= h($a['title'] ?: $a['original_filename']) ?></a>
          <span style="color:var(--text-dim);font-size:11px;"><?= h($a['uploaded_by_name'] ?? '') ?> · <?= date('d M Y', strtotime($a['uploaded_at'])) ?></span>
          <form method="POST" action="attachment.php" onsubmit="return confirm('ही File काढायची?');" style="display:inline;">
            <input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>"><input type="hidden" name="back" value="edit">
            <button type="submit" class="btn btn-sm" style="font-size:11px;color:var(--rose);padding:0 6px;"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!hasCategory('staff')): ?>
<div class="panel" style="border-color:var(--rose);">
  <div style="padding:16px 20px;display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
    <div style="font-size:12.5px;color:var(--text-dim);">ही नोंद निष्क्रिय केल्यास यादी, Dashboard व Reminders मधून वगळली जाईल (इतिहास व प्रमाणपत्रे जतन राहतील).</div>
    <form method="POST" action="delete.php" onsubmit="return confirm('ही नोंद निष्क्रिय करायची?');">
      <input type="hidden" name="id" value="<?= $id ?>">
      <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;"><i class="fa-solid fa-ban me-1"></i>निष्क्रिय करा</button>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
