<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'trust';
    // फक्त ENUM मधलेच प्रकार स्वीकारा
    if (!in_array($type, ['trust', 'school', 'hospital', 'corporate'], true)) { $type = 'trust'; }
    $email = trim($_POST['contact_email'] ?? '') ?: null;
    $phone = trim($_POST['contact_phone'] ?? '') ?: null;
    $isActive = (int)($_POST['is_active'] ?? 1) === 0 ? 0 : 1;
    $parentOrgId = ($_POST['parent_organization_id'] ?? '') !== '' ? (int)$_POST['parent_organization_id'] : null;

    // सुरक्षितता: स्वतःचीच (Login असलेली) संस्था निष्क्रिय केली तर Super Admin स्वतःच बाहेर अडकतो
    if ($isActive === 0 && $id === (int)currentUser()['organization_id']) {
        $isActive = 1;
        $_SESSION['form_error'] = 'तुम्ही ज्या संस्थेतून Login आहात ती निष्क्रिय करता येणार नाही — बाकी बदल जतन केले.';
    }

    // सुरक्षितता: स्वतःलाच parent बनवणे किंवा कितीही पायऱ्यांचं वर्तुळाकार नातं टाळा —
    // निवडलेल्या Parent पासून वरपर्यंत संपूर्ण साखळी तपासतो
    if ($parentOrgId === $id) {
        $parentOrgId = null;
        $_SESSION['form_error'] = 'एखादी संस्था स्वतःचीच Parent असू शकत नाही — हे बदल वगळले.';
    } elseif ($parentOrgId) {
        $checkStmt = $pdo->prepare("SELECT parent_organization_id FROM organizations WHERE id = ?");
        $cursor = $parentOrgId;
        $seen = [];
        while ($cursor && !isset($seen[$cursor])) {
            $seen[$cursor] = true;
            $checkStmt->execute([$cursor]);
            $cursor = (int)($checkStmt->fetch()['parent_organization_id'] ?? 0);
            if ($cursor === $id) {
                $parentOrgId = null;
                $_SESSION['form_error'] = 'निवडलेली संस्था आधीच या संस्थेची उप-शाखा आहे — वर्तुळाकार नातं तयार होऊ शकत नाही.';
                break;
            }
        }
    }

    // Logo upload — optional, keeps existing logo if none re-uploaded
    $logoPath = null;
    if (!empty($_FILES['logo']['name']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowed, true)) {
            $dir = __DIR__ . '/../assets/uploads/logos';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $filename = 'org-' . $id . '-' . time() . '.' . $ext;
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $dir . '/' . $filename)) {
                $logoPath = 'assets/uploads/logos/' . $filename;
            }
        }
    }

    // Welcome Music upload — ऐच्छिक, नवीन टाकली नाही तर जुनीच राहते
    $audioPath = null;
    if (!empty($_FILES['welcome_audio']['name']) && $_FILES['welcome_audio']['error'] === UPLOAD_ERR_OK) {
        $allowedAudio = ['mp3', 'wav', 'ogg', 'm4a'];
        $audioExt = strtolower(pathinfo($_FILES['welcome_audio']['name'], PATHINFO_EXTENSION));
        if (in_array($audioExt, $allowedAudio, true)) {
            $audioDir = __DIR__ . '/../assets/uploads/welcome-audio';
            if (!is_dir($audioDir)) { mkdir($audioDir, 0755, true); }
            $audioFilename = 'org-' . $id . '-' . time() . '.' . $audioExt;
            if (move_uploaded_file($_FILES['welcome_audio']['tmp_name'], $audioDir . '/' . $audioFilename)) {
                $audioPath = 'assets/uploads/welcome-audio/' . $audioFilename;
            }
        } else {
            $_SESSION['form_error'] = 'Music साठी फक्त mp3, wav, ogg, m4a फाईल्स चालतात.';
        }
    }

    $sql = "UPDATE organizations SET name=?, type=?, contact_email=?, contact_phone=?, is_active=?, parent_organization_id=?";
    $params = [$name, $type, $email, $phone, $isActive, $parentOrgId];
    if ($logoPath) { $sql .= ", logo_path=?"; $params[] = $logoPath; }
    if ($audioPath) { $sql .= ", welcome_audio_path=?"; $params[] = $audioPath; }
    $sql .= " WHERE id=?";
    $params[] = $id;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $_SESSION['flash'] = t('s542');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$stmt->execute([$id]);
$org = $stmt->fetch();
if (!$org) { die(t('s440')); }

$otherOrgsStmt = $pdo->prepare("SELECT id, name FROM organizations WHERE id != ? AND is_active = 1 ORDER BY name");
$otherOrgsStmt->execute([$id]);
$otherOrgs = $otherOrgsStmt->fetchAll();

$pageTitle = t('s543');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title"><?= h($org['name']) ?> <?= h(t('s11')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $org['id'] ?>">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s352')) ?></label>
        <div class="d-flex align-items-center gap-3">
          <?php if (!empty($org['logo_path'])): ?>
            <img src="../<?= h($org['logo_path']) ?>" alt="Logo" style="width:64px;height:64px;object-fit:contain;border:1px solid var(--line);border-radius:10px;padding:6px;background:#fff;">
          <?php else: ?>
            <div style="width:64px;height:64px;border-radius:10px;background:var(--ink);color:#fff;display:flex;align-items:center;justify-content:center;font-family:'Space Grotesk';font-weight:700;font-size:18px;"><?= h(mb_substr($org['name'], 0, 2)) ?></div>
          <?php endif; ?>
          <div class="flex-grow-1">
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" class="form-control-custom">
            <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;"><?= h(t('s353')) ?></div>
          </div>
        </div>
      </div>

      <div class="col-12">
        <label class="form-label-custom">🔔 Welcome Chime (स्वागत-संगीत)</label>
        <div class="d-flex align-items-center gap-3">
          <?php if (!empty($org['welcome_audio_path'])): ?>
            <audio controls style="height:36px;max-width:220px;">
              <source src="../<?= h($org['welcome_audio_path']) ?>">
            </audio>
          <?php else: ?>
            <div style="font-size:12px;color:var(--text-dim);white-space:nowrap;">सध्या डिफॉल्ट (Bell) आवाज वापरला जातोय</div>
          <?php endif; ?>
          <div class="flex-grow-1">
            <input type="file" name="welcome_audio" accept="audio/mpeg,audio/wav,audio/ogg,audio/mp4,.mp3,.wav,.ogg,.m4a" class="form-control-custom">
            <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">Login केल्यावर वाजणारं संगीत — mp3/wav/ogg/m4a — अपलोड करताच लगेच वापरात येईल, कुठलाही Code बदलावा लागणार नाही.</div>
          </div>
        </div>
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s111')) ?></label>
        <input type="text" name="name" class="form-control-custom" value="<?= h($org['name']) ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s17')) ?></label>
        <select name="type" class="form-select-custom" required>
          <?php foreach (['trust','school','hospital','corporate'] as $t): ?>
            <option value="<?= $t ?>" <?= $org['type'] === $t ? 'selected' : '' ?>><?= ucfirst($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s112')) ?></label>
        <input type="email" name="contact_email" class="form-control-custom" value="<?= h($org['contact_email']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s113')) ?></label>
        <input type="text" name="contact_phone" class="form-control-custom" value="<?= h($org['contact_phone']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $org['is_active'] ? 'selected' : '' ?>><?= h(t('s4')) ?></option>
          <option value="0" <?= !$org['is_active'] ? 'selected' : '' ?>><?= h(t('s5')) ?></option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">मुख्य Trust (Parent Organization) <span style="font-weight:400;color:var(--text-dim);font-size:11px;">— ऐच्छिक</span></label>
        <select name="parent_organization_id" class="form-select-custom">
          <option value="">-- स्वतंत्र संस्था (कोणाचीही शाखा नाही) --</option>
          <?php foreach ($otherOrgs as $o): ?>
            <option value="<?= $o['id'] ?>" <?= (int)($org['parent_organization_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>><?= h($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">हे निवडलं की — या संस्थेला व निवडलेल्या संस्थेला एकमेकांचा पत्र व्यवहार (विभाग-निहाय) करता येईल — जसं एकाच संस्थेत होतं तसंच.</div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
