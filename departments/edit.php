<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_departments', '../');
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $name = trim($_POST['name'] ?? '');
    $nameLocal = trim($_POST['name_local'] ?? '') ?: null;
    $icon = trim($_POST['icon'] ?? '') ?: 'fa-solid fa-building';
    $hodId = ($_POST['hod_id'] ?? '') !== '' ? (int)$_POST['hod_id'] : null;
    $isActive = (int)($_POST['is_active'] ?? 1);

    // कॅम्पस याच संस्थेचा असायलाच हवा
    if ($campusId) {
        $cChk = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
        $cChk->execute([$campusId, activeOrgId()]);
        if (!$cChk->fetch()) {
            $_SESSION['form_error'] = t('s428');
            header('Location: edit.php?id=' . $id);
            exit;
        }
    }
    $oldCampusStmt = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ? AND organization_id = ?");
    $oldCampusStmt->execute([$id, activeOrgId()]);
    $oldCampusId = $oldCampusStmt->fetchColumn();

    $stmt = $pdo->prepare("UPDATE departments SET campus_id=?, name=?, name_local=?, icon=?, hod_id=?, is_active=? WHERE id=? AND organization_id=?");
    try {
        $stmt->execute([$campusId, $name, $nameLocal, $icon, $hodId, $isActive, $id, activeOrgId()]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
        $_SESSION['form_error'] = 'निवडलेल्या कॅम्पसमध्ये याच कोडचा विभाग आधीच आहे — आधी कोड बदला किंवा दुसरा कॅम्पस निवडा.';
        header('Location: edit.php?id=' . $id);
        exit;
    }

    // विभाग दुसऱ्या कॅम्पसमध्ये हलवला — त्याच्या कर्मचाऱ्यांचा कॅम्पसही बदलतो
    // (Trust-स्तरावर हलवला तर कर्मचाऱ्यांचा आधीचाच कॅम्पस राहतो — users.campus_id रिकामा असू शकत नाही)
    if ($campusId && (int)$oldCampusId !== $campusId) {
        $pdo->prepare("UPDATE users SET campus_id = ? WHERE department_id = ? AND organization_id = ?")->execute([$campusId, $id, activeOrgId()]);
    }

    if ($stmt->rowCount() === 0) {
        // Either nothing actually changed, or (more likely) the active organization
        // changed in another tab between opening this form and submitting it —
        // tell the admin clearly instead of a false "saved" message.
        $checkStmt = $pdo->prepare("SELECT organization_id FROM departments WHERE id = ?");
        $checkStmt->execute([$id]);
        $actualDept = $checkStmt->fetch();
        if ($actualDept && (int)$actualDept['organization_id'] !== (int)activeOrgId()) {
            $_SESSION['form_error'] = t('s526');
            header('Location: edit.php?id=' . $id);
            exit;
        }
    }

    $_SESSION['flash'] = t('s527');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, activeOrgId()]);
$department = $stmt->fetch();
if (!$department) { die(t('s432')); }

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([activeOrgId()]);
$campuses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([activeOrgId()]);
$potentialHods = $stmt->fetchAll();

$pageTitle = t('s528');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title"><?= h($department['name_local'] ?: $department['name']) ?> <?= h(t('s11')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $department['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s8')) ?></label>
        <select name="campus_id" class="form-select-custom">
          <option value="" <?= empty($department['campus_id']) ? 'selected' : '' ?>>🏛️ थेट Trust अंतर्गत (कुठल्याही College/School खाली नाही)</option>
          <?php foreach ($campuses as $campus): ?>
            <option value="<?= $campus['id'] ?>" <?= (int)$department['campus_id'] === (int)$campus['id'] ? 'selected' : '' ?>><?= h($campus['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s103')) ?></label>
        <input type="text" class="form-control-custom" value="<?= h($department['code']) ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s104')) ?></label>
        <input type="text" name="name" class="form-control-custom" value="<?= h($department['name']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s105')) ?></label>
        <input type="text" name="name_local" class="form-control-custom" value="<?= h($department['name_local']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s106')) ?></label>
        <input type="text" name="icon" class="form-control-custom" value="<?= h($department['icon']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s27')) ?></label>
        <select name="hod_id" class="form-select-custom">
          <option value=""><?= h(t('s63')) ?></option>
          <?php foreach ($potentialHods as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)$department['hod_id'] === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $department['is_active'] ? 'selected' : '' ?>><?= h(t('s4')) ?></option>
          <option value="0" <?= !$department['is_active'] ? 'selected' : '' ?>><?= h(t('s5')) ?></option>
        </select>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
