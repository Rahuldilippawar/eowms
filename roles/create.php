<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? '');
    $labelLocal = trim($_POST['label_local'] ?? '') ?: null;
    $category = $_POST['category'] ?? 'staff';
    $roleKey = trim($_POST['role_key'] ?? '');

    // Auto-generate a safe role_key from the English label if not provided
    if (!$roleKey) {
        $roleKey = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_'));
    }

    if (!$label || !$roleKey) {
        $_SESSION['form_error'] = t('s439');
        header('Location: create.php');
        exit;
    }

    $check = $pdo->prepare("SELECT role_key FROM role_definitions WHERE role_key = ?");
    $check->execute([$roleKey]);
    if ($check->fetch()) {
        $_SESSION['form_error'] = "'$roleKey' हा role_key आधीच अस्तित्वात आहे — वेगळे नाव वापरा.";
        header('Location: create.php');
        exit;
    }

    $insert = $pdo->prepare("INSERT INTO role_definitions (role_key, label, label_local, category, is_system) VALUES (?, ?, ?, ?, 0)");
    $insert->execute([$roleKey, $label, $labelLocal, $category]);

    $_SESSION['flash'] = "'$label' हे नवीन पद जोडले गेले.";
    header('Location: index.php');
    exit;
}

$pageTitle = t('s531');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title"><?= h(t('s331')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <div class="panel-head"><div class="title"><?= h(t('s107')) ?></div><div class="sub"><?= h(t('s332')) ?></div></div>
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s108')) ?></label>
        <input type="text" name="label" class="form-control-custom" placeholder="उदा. Director, Coordinator" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s109')) ?></label>
        <input type="text" name="label_local" class="form-control-custom" placeholder="उदा. संचालक">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s333')) ?></label>
        <input type="text" name="role_key" class="form-control-custom" placeholder="उदा. director">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">Access Category</label>
        <select name="category" class="form-select-custom" required>
          <option value="management"><?= h(t('s334')) ?></option>
          <option value="hod"><?= h(t('s335')) ?></option>
          <option value="staff" selected><?= h(t('s336')) ?></option>
        </select>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;"><?= h(t('s337')) ?></div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s338')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
