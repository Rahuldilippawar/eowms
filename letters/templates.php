<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $subjectTemplate = trim($_POST['subject_template'] ?? '');
    $descTemplate = trim($_POST['description_template'] ?? '') ?: null;
    $categoryId = ($_POST['category_id'] ?? '') !== '' ? (int)$_POST['category_id'] : null;
    $workflowId = ($_POST['workflow_template_id'] ?? '') !== '' ? (int)$_POST['workflow_template_id'] : null;

    if ($name && $subjectTemplate) {
        $pdo->prepare("INSERT INTO letter_templates (organization_id, name, subject_template, description_template, category_id, workflow_template_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $name, $subjectTemplate, $descTemplate, $categoryId, $workflowId, $user['id']]);
        $_SESSION['flash'] = 'Template जोडला गेला.';
        header('Location: templates.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM letter_categories WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM workflow_templates WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$workflows = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT t.*, lc.name_local AS category_name FROM letter_templates t LEFT JOIN letter_categories lc ON lc.id = t.category_id WHERE t.organization_id = ? ORDER BY t.name");
$stmt->execute([$orgId]);
$templates = $stmt->fetchAll();

$pageTitle = 'पत्र Templates';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">पत्र Templates</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;">वारंवार लागणाऱ्या पत्रांसाठी (उदा. "रजा मंजुरी", "खरेदी परवानगी") आधीच भरलेला मसुदा — नवीन फाईल बनवताना निवडता येईल.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन Template जोडा</div></div>
  <form method="POST" style="padding:20px 24px;">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">Template चे नाव</label>
        <input type="text" name="name" class="form-control-custom" placeholder="उदा. रजा मंजुरी अर्ज" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">पत्र प्रकार (ऐच्छिक)</label>
        <select name="category_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name_local'] ?: $c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">Workflow (ऐच्छिक)</label>
        <select name="workflow_template_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($workflows as $w): ?><option value="<?= $w['id'] ?>"><?= h($w['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label-custom">Subject Template</label>
        <input type="text" name="subject_template" class="form-control-custom" placeholder="उदा. [नाव] यांचा रजा मंजुरी अर्ज" required>
      </div>
      <div class="col-12">
        <label class="form-label-custom">Description Template (ऐच्छिक)</label>
        <textarea name="description_template" class="form-control-custom" rows="3"></textarea>
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">जोडा</button>
      </div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>Subject</th><th>प्रकार</th></tr></thead>
    <tbody>
      <?php if (empty($templates)): ?><tr><td colspan="3" style="text-align:center;color:var(--text-dim);padding:20px;">कोणताही Template अजून जोडलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <tr><td style="font-weight:500;"><?= h($t['name']) ?></td><td><?= h($t['subject_template']) ?></td><td><?= h($t['category_name'] ?? '—') ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
