<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $nameLocal = trim($_POST['name_local'] ?? '') ?: null;
    if ($name) {
        $pdo->prepare("INSERT INTO letter_categories (organization_id, name, name_local) VALUES (?, ?, ?)")->execute([$orgId, $name, $nameLocal]);
        $_SESSION['flash'] = 'पत्र प्रकार जोडला गेला.';
        header('Location: categories.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM letter_categories WHERE organization_id = ? ORDER BY name_local");
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll();

$pageTitle = 'पत्र प्रकार';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">पत्र प्रकार (Letter Categories)</div>
  <a href="seed-defaults.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;">
    <i class="fa-solid fa-wand-magic-sparkles me-1"></i>मानक प्रकार व Workflows आपोआप तयार करा
  </a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;">पत्र/फाईल तयार करताना कोणता प्रकार निवडायचा — उदा. सर्वसाधारण पत्रव्यवहार, रजा अर्ज, तक्रार, परवानगी अर्ज.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन प्रकार जोडा</div></div>
  <form method="POST" style="padding:20px;">
    <div class="row g-2">
      <div class="col-md-5"><input type="text" name="name" class="form-control-custom" placeholder="नाव (English)" required></div>
      <div class="col-md-5"><input type="text" name="name_local" class="form-control-custom" placeholder="नाव (मराठी)"></div>
      <div class="col-md-2"><button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">जोडा</button></div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th></tr></thead>
    <tbody>
      <?php if (empty($categories)): ?><tr><td style="text-align:center;color:var(--text-dim);padding:20px;">कोणताही प्रकार अजून जोडलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($categories as $c): ?>
        <tr><td style="font-weight:500;"><?= h($c['name_local'] ?: $c['name']) ?> <span style="color:var(--text-dim);font-size:12px;">(<?= h($c['name']) ?>)</span></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
