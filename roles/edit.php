<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

$roleKey = $_GET['role_key'] ?? $_POST['role_key'] ?? '';

$stmt = $pdo->prepare("SELECT * FROM role_definitions WHERE role_key = ?");
$stmt->execute([$roleKey]);
$role = $stmt->fetch();

if (!$role) { die(t('s535')); }

// System roles (super_admin/hod/staff) can't be renamed or have their base
// category changed, but their granular PERMISSIONS can still be tuned.
$isLocked = (bool)$role['is_system'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isLocked) {
        $label = trim($_POST['label'] ?? '');
        $labelLocal = trim($_POST['label_local'] ?? '') ?: null;
        $category = $_POST['category'] ?? 'staff';

        if (!$label) {
            $_SESSION['form_error'] = t('s439');
            header('Location: edit.php?role_key=' . urlencode($roleKey));
            exit;
        }

        $update = $pdo->prepare("UPDATE role_definitions SET label=?, label_local=?, category=? WHERE role_key=?");
        $update->execute([$label, $labelLocal, $category, $roleKey]);
    }

    // Save granular permissions regardless of lock status
    $selectedPerms = $_POST['permissions'] ?? [];
    $pdo->prepare("DELETE FROM role_permissions WHERE role_key = ?")->execute([$roleKey]);
    if (!empty($selectedPerms)) {
        $insert = $pdo->prepare("INSERT INTO role_permissions (role_key, permission_key) VALUES (?, ?)");
        foreach ($selectedPerms as $permKey) {
            $insert->execute([$roleKey, $permKey]);
        }
    }

    // 🎯 Single-Purpose Access Mode — super_admin Role वर हे कधीच लावता
    // येणार नाही (Safety — चुकून स्वतःचाच Access मर्यादित होऊ नये).
    if ($roleKey !== 'super_admin') {
        $restrictedMode = isset($_POST['restricted_mode']) ? 1 : 0;
        $restrictedModulesSelected = $_POST['restricted_modules'] ?? [];
        $restrictedModulesStr = $restrictedMode && !empty($restrictedModulesSelected) ? implode(',', $restrictedModulesSelected) : null;
        if ($restrictedMode && !$restrictedModulesStr) { $restrictedMode = 0; } // Module निवडलंच नसेल तर Restricted-Mode निरर्थक
        $pdo->prepare("UPDATE role_definitions SET restricted_mode = ?, restricted_modules = ? WHERE role_key = ?")
            ->execute([$restrictedMode, $restrictedModulesStr, $roleKey]);
    }

    $_SESSION['flash'] = "'{$role['label_local']}' अद्ययावत केले.";
    header('Location: index.php');
    exit;
}

$allPermissions = $pdo->query("SELECT * FROM permissions ORDER BY sort_order")->fetchAll();
$grantedStmt = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ?");
$grantedStmt->execute([$roleKey]);
$grantedPerms = $grantedStmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = t('s536');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title"><?= h($role['label_local'] ?: $role['label']) ?> <?= h(t('s11')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<form method="POST">
<input type="hidden" name="role_key" value="<?= h($role['role_key']) ?>">

<div class="panel mb-3">
  <div class="panel-head"><div class="title"><?= h(t('s107')) ?></div><?php if ($isLocked): ?><div class="sub"><?= h(t('s339')) ?></div><?php endif; ?></div>
  <div style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s108')) ?></label>
        <input type="text" name="label" class="form-control-custom" value="<?= h($role['label']) ?>" <?= $isLocked ? 'disabled' : 'required' ?>>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s109')) ?></label>
        <input type="text" name="label_local" class="form-control-custom" value="<?= h($role['label_local']) ?>" <?= $isLocked ? 'disabled' : '' ?>>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s340')) ?></label>
        <input type="text" class="form-control-custom" value="<?= h($role['role_key']) ?>" disabled>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s341')) ?></label>
        <select name="category" class="form-select-custom" <?= $isLocked ? 'disabled' : 'required' ?>>
          <option value="management" <?= $role['category'] === 'management' ? 'selected' : '' ?>>Management</option>
          <option value="hod" <?= $role['category'] === 'hod' ? 'selected' : '' ?>>HOD</option>
          <option value="staff" <?= $role['category'] === 'staff' ? 'selected' : '' ?>>Staff</option>
        </select>
      </div>
    </div>
  </div>
</div>

<?php if ($roleKey !== 'super_admin'): ?>
<?php $moduleRegistryForRole = getModuleRegistry(); $currentRestrictedModules = !empty($role['restricted_modules']) ? explode(',', $role['restricted_modules']) : []; ?>
<div class="panel mb-3" style="border:1px solid #F0D9B5;">
  <div class="panel-head" style="background:#FBEBD6;">
    <div class="title">🎯 Single-Purpose Access Mode</div>
    <div class="sub">फक्त काही Narrow-Purpose Roles साठीच वापरा (उदा. "Lab Assistant")</div>
  </div>
  <div style="padding:18px 24px;">
    <div style="background:#F7E2E3;border-radius:9px;padding:12px 16px;font-size:12px;color:#8A1F2B;margin-bottom:16px;">
      ⚠️ <strong>सावधान:</strong> हे चालू केल्यास — या Role च्या व्यक्तीला Login केल्यावर **फक्त खाली निवडलेले Module** दिसतील — Command Center, इतर काहीही नाही (URL टाकूनही जाता येणार नाही). <strong>दैनंदिन कामाच्या नोंदी, नूतनीकरण, पत्रव्यवहार सारख्या Normal, रोजच्या-वापरातल्या Role साठी हे कधीच चालू करू नका</strong> — फक्त एकाच, ठराविक कामासाठी नेमलेल्या व्यक्तीसाठी (उदा. Lab Assistant, Security Guard) वापरा.
    </div>
    <label style="display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;margin-bottom:14px;">
      <input type="checkbox" name="restricted_mode" id="restrictedModeToggle" value="1" <?= $role['restricted_mode'] ? 'checked' : '' ?> style="width:17px;height:17px;" onchange="document.getElementById('restrictedModulesPicker').style.display = this.checked ? 'block' : 'none';">
      हा Role — Single-Purpose (Restricted) ठेवा
    </label>
    <div id="restrictedModulesPicker" style="display:<?= $role['restricted_mode'] ? 'block' : 'none' ?>;background:var(--canvas);border-radius:10px;padding:14px 16px;">
      <div style="font-size:11.5px;color:var(--text-dim);margin-bottom:10px;">कोणकोणते Module दिसावेत (एकापेक्षा जास्त निवडता येतील):</div>
      <div class="row g-2">
        <?php foreach ($moduleRegistryForRole as $modKey => $modInfo): ?>
          <div class="col-md-4">
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;">
              <input type="checkbox" name="restricted_modules[]" value="<?= h($modKey) ?>" <?= in_array($modKey, $currentRestrictedModules, true) ? 'checked' : '' ?> style="width:15px;height:15px;">
              <i class="<?= h($modInfo['icon']) ?>" style="color:<?= h($modInfo['color']) ?>;width:16px;"></i> <?= h($modInfo['label']) ?>
            </label>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div class="panel-head">
    <div class="title"><?= h(t('s342')) ?></div>
    <div class="sub"><?= h(t('s343')) ?></div>
  </div>
  <div style="padding:18px 24px;">
    <div class="row g-2">
      <?php foreach ($allPermissions as $perm): ?>
        <div class="col-md-6">
          <label style="display:flex;align-items:center;gap:10px;font-size:13px;background:var(--canvas);padding:10px 14px;border-radius:9px;margin-bottom:6px;">
            <input type="checkbox" name="permissions[]" value="<?= h($perm['permission_key']) ?>" <?= in_array($perm['permission_key'], $grantedPerms) ? 'checked' : '' ?>>
            <span>
              <?= h($perm['label_local'] ?: $perm['label']) ?>
              <div style="font-size:10.5px;color:var(--text-dim);"><?= h($perm['label']) ?></div>
            </span>
          </label>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-3">
      <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
    </div>
  </div>
</div>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
