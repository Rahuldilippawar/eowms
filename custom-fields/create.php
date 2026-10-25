<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orgId = activeOrgId();
    $label = trim($_POST['field_label'] ?? '');
    $type = $_POST['field_type'] ?? 'text';
    // फक्त परवानगी असलेले Field-प्रकार
    if (!in_array($type, ['text', 'textarea', 'number', 'date', 'select', 'checkbox'], true)) { $type = 'text'; }
    $options = trim($_POST['field_options'] ?? '') ?: null;
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);

    // Auto-generate a safe field_key from the label (English/Marathi both handled generically)
    $key = trim($_POST['field_key'] ?? '');
    if ($key === '') {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $label));
        $key = trim($key, '_');
        if ($key === '') { $key = 'field_' . time(); }
    } else {
        $key = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '_', $key));
    }

    if (!$label) {
        $error = t('s562');
    } else {
        $check = $pdo->prepare("SELECT id FROM custom_fields WHERE organization_id = ? AND field_key = ?");
        $check->execute([$orgId, $key]);
        if ($check->fetch()) {
            $error = "'$key' हा field key आधीच वापरला गेला आहे — वेगळा key द्या.";
        } else {
            $insert = $pdo->prepare("
                INSERT INTO custom_fields (organization_id, field_key, field_label, field_type, field_options, is_required, sort_order, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $insert->execute([$orgId, $key, $label, $type, $options, $isRequired, $sortOrder]);
            $_SESSION['flash'] = "'$label' फील्ड जोडले गेले.";
            header('Location: index.php');
            exit;
        }
    }
}

$pageTitle = t('s563');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h(t('s414')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <div class="title"><?= h(t('s415')) ?></div>
    <div class="sub"><?= h(t('s416')) ?></div>
  </div>
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s116')) ?></label>
        <input type="text" name="field_label" class="form-control-custom" placeholder="उदा. वाहन क्रमांक" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s417')) ?></label>
        <input type="text" name="field_key" class="form-control-custom" placeholder="vehicle_number">
      </div>

      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s17')) ?></label>
        <select name="field_type" class="form-select-custom" required onchange="document.getElementById('optionsWrap').style.display = this.value==='select' ? 'block' : 'none';">
          <option value="text"><?= h(t('s418')) ?></option>
          <option value="textarea"><?= h(t('s419')) ?></option>
          <option value="number"><?= h(t('s420')) ?></option>
          <option value="date"><?= h(t('s421')) ?></option>
          <option value="select"><?= h(t('s422')) ?></option>
          <option value="checkbox"><?= h(t('s423')) ?></option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s424')) ?></label>
        <input type="number" name="sort_order" class="form-control-custom" value="0">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom"><?= h(t('s425')) ?></label>
        <select name="is_required" class="form-select-custom">
          <option value="0"><?= h(t('s41')) ?></option>
          <option value="1"><?= h(t('s40')) ?></option>
        </select>
      </div>

      <div class="col-12" id="optionsWrap" style="display:none;">
        <label class="form-label-custom"><?= h(t('s117')) ?></label>
        <input type="text" name="field_options" class="form-control-custom" placeholder="उदा. होय, नाही, अंशतः">
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s426')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
