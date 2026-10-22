<?php
$pageTitle = 'पदे व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$categoryLabels = [
    'super_admin' => 'Super Admin (सर्वोच्च)',
    'management' => 'Management (President/GM स्तर — संपूर्ण संस्था दिसते)',
    'hod' => 'HOD (विभागप्रमुख — फक्त स्वतःचा विभाग)',
    'staff' => 'Staff (कर्मचारी — फक्त स्वतःचा विभाग)',
];
$categoryChip = ['super_admin' => 'overdue', 'management' => 'pending', 'hod' => 'done', 'staff' => 'done'];

$roles = $pdo->query("SELECT * FROM role_definitions ORDER BY FIELD(category,'super_admin','management','hod','staff'), label")->fetchAll();

// Count how many users currently hold each role
$usageCounts = [];
foreach ($pdo->query("SELECT role, COUNT(*) c FROM users GROUP BY role")->fetchAll() as $row) {
    $usageCounts[$row['role']] = $row['c'];
}
?>

<div class="page-eyebrow">Super Admin</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s328')) ?></div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन पद जोडा
  </a>
</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">
  प्रत्येक पद एका access category शी जोडलेलं आहे — तेच ठरवतं की त्या पदावरील व्यक्तीला किती data दिसेल.
  नवीन पद (उदा. Director, Trustee, Coordinator) कधीही जोडा — योग्य category निवडून permission आपोआप मिळेल.
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th><?= h(t('s329')) ?></th><th>Role Key</th><th>Access Category</th><th><?= h(t('s330')) ?></th><th></th></tr></thead>
    <tbody>
      <?php foreach ($roles as $r): ?>
        <tr>
          <td style="font-weight:500;">
            <?= h($r['label_local'] ?: $r['label']) ?> <span style="color:var(--text-dim);font-size:12px;">(<?= h($r['label']) ?>)</span>
            <?php if (!empty($r['restricted_mode'])): ?>
              <span title="Single-Purpose Access Mode चालू आहे" style="background:#FBEBD6;color:#C97F1D;font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;margin-left:6px;">🎯 Restricted</span>
            <?php endif; ?>
          </td>
          <td class="font-mono" style="font-size:12px;"><?= h($r['role_key']) ?></td>
          <td><span class="status-chip <?= $categoryChip[$r['category']] ?>"><?= h($categoryLabels[$r['category']]) ?></span></td>
          <td class="font-mono"><?= $usageCounts[$r['role_key']] ?? 0 ?></td>
          <td>
            <a href="edit.php?role_key=<?= urlencode($r['role_key']) ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;">
              <?php if ($r['is_system']): ?><i class="fa-solid fa-lock me-1"></i>Permissions पहा/बदला<?php else: ?><i class="fa-solid fa-pen me-1"></i>Edit<?php endif; ?>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
