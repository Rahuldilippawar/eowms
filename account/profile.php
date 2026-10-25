<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_password') {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();

    if (!$row || !password_verify($currentPassword, $row['password'])) {
        $error = t('s502');
    } elseif (strlen($newPassword) < 6) {
        $error = t('s503');
    } elseif ($newPassword !== $confirmPassword) {
        $error = t('s504');
    } else {
        $update = $pdo->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?");
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), $user['id']]);
        $_SESSION['user']['must_change_password'] = 0;
        $success = t('s505');
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove_webauthn') {
    $pdo->prepare("DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?")->execute([(int)($_POST['webauthn_id'] ?? 0), $user['id']]);
    $success = 'डिव्हाइस काढलं गेलं.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_photo') {
    if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt, true)) {
            $error = 'फक्त jpg, jpeg, png, webp फाईल्स चालतात.';
        } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
            $error = 'फोटो 3 MB पेक्षा मोठा नसावा.';
        } else {
            $photoDir = __DIR__ . '/../assets/uploads/profile-photos';
            if (!is_dir($photoDir)) { mkdir($photoDir, 0755, true); }
            $safeFilename = 'user-' . $user['id'] . '-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['photo']['tmp_name'], $photoDir . '/' . $safeFilename)) {
                // जुना फोटो असल्यास काढून टाका (जागा वाया जाऊ नये)
                $oldStmt = $pdo->prepare("SELECT photo_path FROM users WHERE id = ?");
                $oldStmt->execute([$user['id']]);
                $oldPhoto = $oldStmt->fetch()['photo_path'] ?? null;
                if ($oldPhoto && strpos($oldPhoto, 'profile-photos/') !== false) {
                    $oldFullPath = __DIR__ . '/../' . $oldPhoto;
                    if (is_file($oldFullPath)) { @unlink($oldFullPath); }
                }
                $newPhotoPath = 'assets/uploads/profile-photos/' . $safeFilename;
                $pdo->prepare("UPDATE users SET photo_path = ? WHERE id = ?")->execute([$newPhotoPath, $user['id']]);
                // Session मधली प्रतही अद्ययावत — नवीन फोटो लगेच सगळीकडे दिसावा
                $_SESSION['user']['photo_path'] = $newPhotoPath;
                $user['photo_path'] = $newPhotoPath;
                logActivity('स्वतःचा Profile फोटो बदलला');
                $success = 'फोटो यशस्वीरित्या अपडेट झाला.';
            } else {
                $error = 'फोटो Upload होऊ शकला नाही — पुन्हा प्रयत्न करा.';
            }
        }
    }
}

// Reload full profile (mobile, joining_date, employee_code etc.) + department/campus/org names
$stmt = $pdo->prepare("
    SELECT u.*, d.name_local AS dept_name, d.name AS dept_name_en, c.name AS campus_name, o.name AS org_name
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN campuses c ON c.id = u.campus_id
    LEFT JOIN organizations o ON o.id = u.organization_id
    WHERE u.id = ?
");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();

// Safety net: if the session's user no longer matches a real row (e.g. database
// was re-imported/reset while this session was still active), force a clean re-login
// instead of showing broken warnings.
if (!$profile) {
    session_destroy();
    header('Location: ../login.php?msg=session_expired');
    exit;
}

$roleLabels = [
    'super_admin' => 'Super Admin', 'president' => 'President', 'executive_president' => 'Executive President',
    'general_manager' => 'General Manager', 'hod' => 'HOD (विभागप्रमुख)', 'staff' => 'Staff',
];

$pageTitle = t('s507');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(t('s239')) ?></div>
<div class="page-title"><?= h(t('s240')) ?></div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($success) ?></div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">📷 Profile फोटो</div></div>
  <div style="padding:20px 24px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
    <?php if (!empty($user['photo_path'])): ?>
      <img src="<?= $rootPath ?><?= h($user['photo_path']) ?>?v=<?= time() ?>" style="width:84px;height:84px;border-radius:50%;object-fit:cover;border:2px solid var(--line);">
    <?php else: ?>
      <div style="width:84px;height:84px;border-radius:50%;background:var(--rail);color:#fff;display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;font-family:'Space Grotesk';"><?= h(mb_substr($user['name'], 0, 2)) ?></div>
    <?php endif; ?>
    <form method="POST" enctype="multipart/form-data" style="flex:1;min-width:220px;">
      <input type="hidden" name="action" value="update_photo">
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp" class="form-control-custom mb-2">
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 18px;font-weight:600;">फोटो अपडेट करा</button>
      <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">jpg/png/webp — जास्तीत जास्त 3 MB</div>
    </form>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title"><?= h(t('s241')) ?></div><div class="sub"><?= h(t('s242')) ?></div></div>
      <div style="padding:22px 24px;">
        <div class="d-flex align-items-center gap-3 mb-4">
          <div style="width:56px;height:56px;border-radius:12px;background:var(--ink-soft);color:#fff;font-family:'Space Grotesk';font-weight:700;font-size:20px;display:flex;align-items:center;justify-content:center;">
            <?= h(mb_substr($profile['name'], 0, 2)) ?>
          </div>
          <div>
            <div style="font-family:'Space Grotesk';font-weight:700;font-size:17px;color:var(--ink);"><?= h($profile['name']) ?></div>
            <div style="font-size:12.5px;color:var(--text-dim);"><?= h($roleLabels[$profile['role']] ?? $profile['role']) ?></div>
          </div>
        </div>

        <table class="rtable" style="font-size:13px;">
          <tbody>
            <tr><td style="color:var(--text-dim);width:40%;"><?= h(t('s91')) ?></td><td class="font-mono"><?= h($profile['employee_code'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);">Email</td><td class="font-mono"><?= h($profile['email']) ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s92')) ?></td><td><?= h($profile['mobile'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s45')) ?></td><td><?= h($profile['designation'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s1')) ?></td><td><?= h($profile['dept_name'] ?? $profile['dept_name_en'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s8')) ?></td><td><?= h($profile['campus_name'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s243')) ?></td><td><?= h($profile['org_name'] ?? '—') ?></td></tr>
            <tr><td style="color:var(--text-dim);"><?= h(t('s244')) ?></td><td><?= !empty($profile['joining_date']) ? date('d M Y', strtotime($profile['joining_date'])) : '—' ?></td></tr>
            <tr>
              <td style="color:var(--text-dim);"><?= h(t('s2')) ?></td>
              <td><?php if ($profile['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
            </tr>
          </tbody>
        </table>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:10px;"><?= h(t('s245')) ?></div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title"><?= h(t('s246')) ?></div><div class="sub"><?= h(t('s247')) ?></div></div>
      <form method="POST" style="padding:22px 24px;max-width:420px;">
        <input type="hidden" name="action" value="change_password">
        <label class="form-label-custom"><?= h(t('s248')) ?></label>
        <input type="password" name="current_password" class="form-control-custom mb-3" required>

        <label class="form-label-custom"><?= h(t('s49')) ?></label>
        <input type="password" name="new_password" class="form-control-custom mb-3" minlength="6" required>

        <label class="form-label-custom"><?= h(t('s249')) ?></label>
        <input type="password" name="confirm_password" class="form-control-custom mb-3" minlength="6" required>

        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-weight:600;padding:9px 22px;font-size:13.5px;"><?= h(t('s250')) ?></button>
      </form>
    </div>

    <div class="panel mt-3">
      <div class="panel-head"><div class="title">👆 Fingerprint / Face ID Login</div><div class="sub">पासवर्ड न टाकता, फक्त बोटाच्या ठशाने/चेहऱ्याने Login</div></div>
      <div style="padding:20px 24px;">
        <?php
          $wcStmt = $pdo->prepare("SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC");
          $wcStmt->execute([$user['id']]);
          $webauthnDevices = $wcStmt->fetchAll();
        ?>
        <?php if (empty($webauthnDevices)): ?>
          <p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">अजून कुठलंही डिव्हाइस नोंदवलेलं नाही. तुमच्या फोन/लॅपटॉपचा Fingerprint किंवा Face ID सेट असेल तर खालून सुरू करा.</p>
        <?php else: ?>
          <?php foreach ($webauthnDevices as $d): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);">
              <div>
                <div style="font-size:13px;font-weight:600;">📱 <?= h($d['device_label']) ?></div>
                <div style="font-size:11px;color:var(--text-dim);">नोंदवलं: <?= date('d M Y', strtotime($d['created_at'])) ?><?= $d['last_used_at'] ? ' · शेवटचा वापर: ' . date('d M Y', strtotime($d['last_used_at'])) : '' ?></div>
              </div>
              <form method="POST" onsubmit="return confirm('हे डिव्हाइस काढायचं? — तिथून Fingerprint Login बंद होईल.');">
                <input type="hidden" name="action" value="remove_webauthn">
                <input type="hidden" name="webauthn_id" value="<?= $d['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;cursor:pointer;">काढा</button>
              </form>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
        <button type="button" onclick="eowmsRegisterFingerprint('<?= $rootPath ?>')" class="btn mt-3" style="border:1.5px solid var(--rail);color:var(--rail);background:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">
          + हे डिव्हाइस नोंदवा
        </button>
      </div>
    </div>
  </div>
</div>

<script src="<?= $rootPath ?>assets/js/webauthn.js"></script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
