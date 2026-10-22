<?php
$pageTitle = 'सानुकूल फील्ड्स (Custom Fields)';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE organization_id = ? ORDER BY sort_order, id");
$stmt->execute([$orgId]);
$fields = $stmt->fetchAll();

$typeLabels = ['text' => 'साधा मजकूर', 'textarea' => 'मोठा मजकूर', 'number' => 'संख्या', 'date' => 'दिनांक', 'select' => 'ड्रॉपडाउन', 'checkbox' => 'होय/नाही'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s412')) ?></div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन फील्ड जोडा
  </a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">
  येथे जोडलेले फील्ड्स "दैनंदिन कामाचा अहवाल" entry form च्या शेवटी आपोआप दिसतील — प्रत्येक संस्थेचे नियम वेगळे असू शकतात म्हणून.
</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th><?= h(t('s114')) ?></th><th>Label</th><th>Field Key</th><th><?= h(t('s17')) ?></th><th><?= h(t('s115')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($fields)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s413')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($fields as $f): ?>
        <tr>
          <td class="font-mono"><?= $f['sort_order'] ?></td>
          <td style="font-weight:500;"><?= h($f['field_label']) ?></td>
          <td class="font-mono" style="font-size:12px;color:var(--text-dim);"><?= h($f['field_key']) ?></td>
          <td><?= h($typeLabels[$f['field_type']] ?? $f['field_type']) ?></td>
          <td><?= $f['is_required'] ? 'होय' : 'नाही' ?></td>
          <td><?php if ($f['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td><a href="edit.php?id=<?= $f['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
