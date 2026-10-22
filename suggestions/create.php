<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'improvement';
    $moduleName = trim($_POST['module_name'] ?? '');

    if ($title && $description) {
        $pdo->prepare("INSERT INTO software_suggestions (organization_id, title, description, category, module_name, submitted_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $title, $description, $category, $moduleName, $user['id']]);
        $newId = $pdo->lastInsertId();
        // स्वतःच्याच कल्पनेला आधीच 1 Vote — सोयीसाठी
        $pdo->prepare("INSERT INTO software_suggestion_votes (suggestion_id, user_id) VALUES (?, ?)")->execute([$newId, $user['id']]);

        logActivity('Software Suggestion मांडली', 'software_suggestion', $newId, $title);
        $_SESSION['flash'] = 'तुमची कल्पना यशस्वीरित्या नोंदवली गेली — धन्यवाद!';
        header('Location: index.php');
        exit;
    } else {
        $_SESSION['form_error'] = 'कृपया शीर्षक आणि तपशील भरा.';
    }
}

$pageTitle = 'नवीन कल्पना मांडा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">💡 नवीन कल्पना मांडा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व Suggestions</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:700px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">प्रकार</label>
    <div class="row g-2 mb-3">
      <?php $categories = ['new_feature' => ['✨', 'नवीन Feature हवं'], 'improvement' => ['🔧', 'सध्याचं Sudhar हवी'], 'bug' => ['🐛', 'काहीतरी चुकतंय'], 'other' => ['💭', 'इतर']]; ?>
      <?php foreach ($categories as $val => [$icon, $label]): ?>
        <div class="col-6 col-md-3">
          <label style="display:flex;align-items:center;gap:8px;border:1px solid var(--line);border-radius:9px;padding:10px 12px;cursor:pointer;font-size:12.5px;">
            <input type="radio" name="category" value="<?= $val ?>" <?= $val === 'improvement' ? 'checked' : '' ?> style="width:16px;height:16px;">
            <?= $icon ?> <?= $label ?>
          </label>
        </div>
      <?php endforeach; ?>
    </div>

    <label class="form-label-custom">शीर्षक (थोडक्यात)</label>
    <input type="text" name="title" class="form-control-custom mb-3" placeholder="उदा. पत्रांच्या यादीत Search Box हवा" required>

    <label class="form-label-custom">सविस्तर वर्णन — काय, का हवं आहे</label>
    <textarea name="description" class="form-control-custom mb-3" rows="5" required></textarea>

    <label class="form-label-custom">कुठला Module संबंधित आहे <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <input type="text" name="module_name" class="form-control-custom mb-3" placeholder="उदा. पत्र व्यवहार, नूतनीकरण, स्टाफ">

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">कल्पना मांडा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
