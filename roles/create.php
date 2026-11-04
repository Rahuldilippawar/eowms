<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

// 📋 नक्कल — ?copy=role_key : त्या पदाचे Permissions (व Restricted Mode) नवीन पदाला
$copyFrom = null;
$copyKey = (string)($_GET['copy'] ?? $_POST['copy_from'] ?? '');
if ($copyKey !== '' && $copyKey !== 'super_admin') {
    $st = $pdo->prepare("SELECT * FROM role_definitions WHERE role_key = ?");
    $st->execute([$copyKey]);
    $copyFrom = $st->fetch() ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $label = trim($_POST['label'] ?? '');
    $labelLocal = trim($_POST['label_local'] ?? '') ?: null;
    $category = $_POST['category'] ?? 'staff';
    if (!in_array($category, ['management', 'hod', 'staff'], true)) { $category = 'staff'; }
    $roleKey = strtolower(trim($_POST['role_key'] ?? ''));

    // Auto-generate a safe role_key from the English label if not provided
    if (!$roleKey) {
        $roleKey = substr(strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $label), '_')), 0, 50);
    }

    if ($roleKey !== '' && !preg_match('/^[a-z0-9_]{2,50}$/', $roleKey)) {
        $_SESSION['form_error'] = 'Role Key मध्ये फक्त इंग्रजी लहान अक्षरे, अंक व _ चालतात (उदा. lab_assistant).';
        header('Location: create.php' . ($copyFrom ? '?copy=' . urlencode($copyFrom['role_key']) : ''));
        exit;
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
    if ($copyFrom) {
        $pdo->prepare("INSERT INTO role_permissions (role_key, permission_key) SELECT ?, permission_key FROM role_permissions WHERE role_key = ?")
            ->execute([$roleKey, $copyFrom['role_key']]);
        $pdo->prepare("UPDATE role_definitions SET restricted_mode = ?, restricted_modules = ? WHERE role_key = ?")
            ->execute([(int)$copyFrom['restricted_mode'], $copyFrom['restricted_modules'], $roleKey]);
    }
    logActivity('नवीन पद जोडले', 'role', null, $roleKey . ' — ' . $label . ($copyFrom ? ' (नक्कल: ' . $copyFrom['role_key'] . ')' : ''));

    $_SESSION['flash'] = "'$label' हे नवीन पद जोडले गेले" . ($copyFrom ? " — '" . ($copyFrom['label_local'] ?: $copyFrom['label']) . "' चे Permissions घेतले. आता तपासा/बदला." : '.');
    if ($copyFrom) { header('Location: edit.php?role_key=' . urlencode($roleKey)); exit; }
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
    <?php if ($copyFrom): $cpCount = $pdo->prepare("SELECT COUNT(*) FROM role_permissions WHERE role_key = ?"); $cpCount->execute([$copyFrom['role_key']]); ?>
      <input type="hidden" name="copy_from" value="<?= h($copyFrom['role_key']) ?>">
      <div style="background:color-mix(in srgb,var(--rail) 8%,transparent);border:1px dashed var(--rail);border-radius:10px;padding:10px 14px;font-size:12.5px;margin-bottom:16px;">
        📋 <b><?= h($copyFrom['label_local'] ?: $copyFrom['label']) ?></b> या पदाची नक्कल — त्याचे <b><?= (int)$cpCount->fetchColumn() ?></b> Permissions<?= $copyFrom['restricted_mode'] ? ' व 🎯 Restricted Mode' : '' ?> नवीन पदाला मिळतील. जतन केल्यावर लगेच बदलता येतील.
      </div>
    <?php endif; ?>
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s108')) ?></label>
        <input type="text" name="label" id="rcLabel" class="form-control-custom" placeholder="उदा. Director, Coordinator" required value="<?= $copyFrom ? h($copyFrom['label'] . ' (Copy)') : '' ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s109')) ?></label>
        <input type="text" name="label_local" class="form-control-custom" placeholder="उदा. संचालक" value="<?= $copyFrom && $copyFrom['label_local'] ? h($copyFrom['label_local'] . ' (नक्कल)') : '' ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s333')) ?></label>
        <input type="text" name="role_key" id="rcKey" class="form-control-custom" placeholder="उदा. director" pattern="[a-z0-9_]{2,50}" title="फक्त इंग्रजी लहान अक्षरे, अंक व _">
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;">रिकामं ठेवल्यास आपोआप: <b class="font-mono" id="rcKeyPreview">—</b> <span id="rcKeyTaken" style="color:var(--rose);font-weight:600;" hidden>· हे आधीच आहे!</span></div>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">Access Category</label>
        <select name="category" class="form-select-custom" required>
          <option value="management" <?= $copyFrom && $copyFrom['category'] === 'management' ? 'selected' : '' ?>><?= h(t('s334')) ?></option>
          <option value="hod" <?= $copyFrom && $copyFrom['category'] === 'hod' ? 'selected' : '' ?>><?= h(t('s335')) ?></option>
          <option value="staff" <?= !$copyFrom || $copyFrom['category'] === 'staff' ? 'selected' : '' ?>><?= h(t('s336')) ?></option>
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

<script>
(function () {
  var TAKEN = <?= json_encode($pdo->query("SELECT role_key FROM role_definitions")->fetchAll(PDO::FETCH_COLUMN)) ?>;
  var label = document.getElementById('rcLabel'), key = document.getElementById('rcKey'), pv = document.getElementById('rcKeyPreview'), tk = document.getElementById('rcKeyTaken');
  function upd() {
    var k = (key.value.trim() || label.value.replace(/[^a-zA-Z0-9]+/g, '_').replace(/^_+|_+$/g, '')).toLowerCase();
    pv.textContent = k || '—';
    tk.hidden = !k || TAKEN.indexOf(k) === -1;
  }
  label.addEventListener('input', upd); key.addEventListener('input', upd); upd();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
