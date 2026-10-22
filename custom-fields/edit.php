<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['field_label'] ?? '');
    $type = $_POST['field_type'] ?? 'text';
    $options = trim($_POST['field_options'] ?? '') ?: null;
    $isRequired = isset($_POST['is_required']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    $isActive = (int)($_POST['is_active'] ?? 1);

    $stmt = $pdo->prepare("UPDATE custom_fields SET field_label=?, field_type=?, field_options=?, is_required=?, sort_order=?, is_active=? WHERE id=? AND organization_id=?");
    $stmt->execute([$label, $type, $options, $isRequired, $sortOrder, $isActive, $id, $orgId]);

    $_SESSION['flash'] = t('s566');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$field = $stmt->fetch();
if (!$field) { die(t('s567')); }

$pageTitle = t('s568');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h($field['field_label']) ?> <?= h(t('s11')) ?></div>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $field['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s116')) ?></label>
        <input type="text" name="field_label" class="form-control-custom" value="<?= h($field['field_label']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s427')) ?></label>
        <input type="text" class="form-control-custom" value="<?= h($field['field_key']) ?>" disabled>
      </div>

      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s17')) ?></label>
        <select name="field_type" class="form-select-custom" required onchange="document.getElementById('optionsWrap').style.display = this.value==='select' ? 'block' : 'none';">
          <?php foreach (['text'=>'साधा मजकूर','textarea'=>'मोठा मजकूर','number'=>'संख्या','date'=>'दिनांक','select'=>'ड्रॉपडाउन','checkbox'=>'होय/नाही'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= $field['field_type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s114')) ?></label>
        <input type="number" name="sort_order" class="form-control-custom" value="<?= $field['sort_order'] ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s115')) ?></label>
        <select name="is_required" class="form-select-custom">
          <option value="0" <?= !$field['is_required'] ? 'selected' : '' ?>><?= h(t('s41')) ?></option>
          <option value="1" <?= $field['is_required'] ? 'selected' : '' ?>><?= h(t('s40')) ?></option>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $field['is_active'] ? 'selected' : '' ?>><?= h(t('s4')) ?></option>
          <option value="0" <?= !$field['is_active'] ? 'selected' : '' ?>><?= h(t('s5')) ?></option>
        </select>
      </div>

      <div class="col-12" id="optionsWrap" style="<?= $field['field_type'] === 'select' ? '' : 'display:none;' ?>">
        <label class="form-label-custom"><?= h(t('s117')) ?></label>
        <input type="text" name="field_options" class="form-control-custom" value="<?= h($field['field_options']) ?>">
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
