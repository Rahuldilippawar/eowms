<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $type = $_POST['type'] ?? 'trust';
    // फक्त ENUM मधलेच प्रकार स्वीकारा
    if (!in_array($type, ['trust', 'school', 'hospital', 'corporate'], true)) { $type = 'trust'; }
    $email = trim($_POST['contact_email'] ?? '') ?: null;
    $phone = trim($_POST['contact_phone'] ?? '') ?: null;
    $parentOrgId = ($_POST['parent_organization_id'] ?? '') !== '' ? (int)$_POST['parent_organization_id'] : null;

    if (!$name || !$slug) {
        $_SESSION['form_error'] = t('s428');
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO organizations (name, slug, type, parent_organization_id, contact_email, contact_phone) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$name, $slug, $type, $parentOrgId, $email, $phone]);
            $_SESSION['flash'] = t('s539');
            header('Location: index.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['form_error'] = "'$slug' हा Slug आधीच वापरात आहे — कृपया वेगळा Slug द्या.";
        }
    }
}

$pageTitle = t('s540');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$existingOrgsStmt = $pdo->query("SELECT id, name FROM organizations WHERE is_active = 1 ORDER BY name");
$existingOrgs = $existingOrgsStmt->fetchAll();
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title"><?= h(t('s349')) ?></div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s111')) ?></label>
        <input type="text" name="name" class="form-control-custom" placeholder="उदा. Shivaji Education Trust" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s350')) ?></label>
        <input type="text" name="slug" class="form-control-custom" placeholder="shivaji-education-trust" required>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s17')) ?></label>
        <select name="type" class="form-select-custom" required>
          <option value="trust">Trust</option>
          <option value="school">School</option>
          <option value="hospital">Hospital</option>
          <option value="corporate">Corporate</option>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s112')) ?></label>
        <input type="email" name="contact_email" class="form-control-custom">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s113')) ?></label>
        <input type="text" name="contact_phone" class="form-control-custom">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">मुख्य Trust (Parent Organization) <span style="font-weight:400;color:var(--text-dim);font-size:11px;">— ऐच्छिक</span></label>
        <select name="parent_organization_id" class="form-select-custom">
          <option value="">-- स्वतंत्र संस्था (कोणाचीही शाखा नाही) --</option>
          <?php foreach ($existingOrgs as $o): ?>
            <option value="<?= $o['id'] ?>"><?= h($o['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">निवडलं तर या नवीन संस्थेला व निवडलेल्या Trust ला विभाग-निहाय पत्रव्यवहार करता येईल.</div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s351')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
