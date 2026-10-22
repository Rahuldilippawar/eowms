<?php
$pageTitle = 'कार्यप्रवाह टेम्प्लेट';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT wt.*, lc.name_local AS category_name,
      (SELECT COUNT(*) FROM workflow_steps ws WHERE ws.workflow_template_id = wt.id) AS step_count
    FROM workflow_templates wt
    LEFT JOIN letter_categories lc ON lc.id = wt.category_id
    WHERE wt.organization_id = ?
    ORDER BY wt.name
");
$stmt->execute([$orgId]);
$templates = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">कार्यप्रवाह टेम्प्लेट (Workflow Templates)</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">पत्र कोणत्या क्रमाने कोणाकडे जाईल ते इथे ठरवा — उदा. HOD → General Manager → President</div>
  </div>
  <a href="workflow-create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन टेम्प्लेट</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>नाव</th><th>पत्र प्रकार</th><th>पायऱ्या</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($templates)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">कोणताही टेम्प्लेट अजून तयार केलेला नाही</td></tr><?php endif; ?>
      <?php foreach ($templates as $t): ?>
        <tr>
          <td style="font-weight:500;"><?= h($t['name']) ?></td>
          <td><?= h($t['category_name'] ?? 'सर्व प्रकार') ?></td>
          <td class="font-mono"><?= $t['step_count'] ?></td>
          <td><?php if ($t['is_active']): ?><span class="status-chip done">सक्रिय</span><?php else: ?><span class="status-chip overdue">निष्क्रिय</span><?php endif; ?></td>
          <td><a href="workflow-edit.php?id=<?= $t['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
