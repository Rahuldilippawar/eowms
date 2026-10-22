<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_departments', '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $name = trim($_POST['name'] ?? '');
    $nameLocal = trim($_POST['name_local'] ?? '') ?: null;
    $code = trim($_POST['code'] ?? '');
    $icon = trim($_POST['icon'] ?? '') ?: 'fa-solid fa-building';
    $hodId = ($_POST['hod_id'] ?? '') !== '' ? (int)$_POST['hod_id'] : null;

    if (!$name || !$code) {
        $_SESSION['form_error'] = t('s428');
    } else {
        $stmt = $pdo->prepare("INSERT INTO departments (organization_id, campus_id, name, name_local, code, icon, hod_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([activeOrgId(), $campusId, $name, $nameLocal, $code, $icon, $hodId]);
        $_SESSION['flash'] = t('s522');
        header('Location: index.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([activeOrgId()]);
$campuses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([activeOrgId()]);
$potentialHods = $stmt->fetchAll();

$pageTitle = t('s523');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title"><?= h(t('s325')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s8')) ?></label>
        <select name="campus_id" class="form-select-custom">
          <option value="">🏛️ थेट Trust अंतर्गत (कुठल्याही College/School खाली नाही)</option>
          <?php foreach ($campuses as $campus): ?>
            <option value="<?= $campus['id'] ?>"><?= h($campus['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s326')) ?></label>
        <input type="text" name="code" class="form-control-custom" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s104')) ?></label>
        <input type="text" name="name" class="form-control-custom" placeholder="उदा. Maintenance" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s105')) ?></label>
        <input type="text" name="name_local" class="form-control-custom" placeholder="उदा. देखभाल">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s106')) ?></label>
        <input type="text" name="icon" class="form-control-custom" placeholder="fa-solid fa-screwdriver-wrench">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s27')) ?></label>
        <select name="hod_id" class="form-select-custom">
          <option value=""><?= h(t('s327')) ?></option>
          <?php foreach ($potentialHods as $u): ?>
            <option value="<?= $u['id'] ?>"><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s38')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
