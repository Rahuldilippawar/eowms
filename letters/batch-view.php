<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$batchRef = trim($_GET['ref'] ?? '');
if (!$batchRef) { die('Batch सापडला नाही.'); }

$stmt = $pdo->prepare("
    SELECT l.*, d.name_local AS dept_name, u.name AS holder_name
    FROM letters l JOIN departments d ON d.id = l.department_id LEFT JOIN users u ON u.id = l.current_holder_id
    WHERE l.organization_id = ? AND l.batch_ref = ? ORDER BY d.name_local
");
$stmt->execute([$orgId, $batchRef]);
$batchLetters = $stmt->fetchAll();
if (empty($batchLetters)) { die('या Batch मध्ये कुठलीही Files सापडली नाहीत, किंवा तुम्हाला बघण्याची परवानगी नाही.'); }

$pageTitle = 'परिपत्रक — सगळ्या Files';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$statusLabels = ['draft' => '📝 Draft', 'in_progress' => '🔵 सुरू आहे', 'returned' => '↩️ परत आलं', 'completed' => '✅ पूर्ण'];
$statusChips = ['draft' => 'pending', 'in_progress' => 'pending', 'returned' => 'pending', 'completed' => 'done'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📨 परिपत्रक — <?= h($batchLetters[0]['subject']) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व पत्रं</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Batch: <span class="font-mono"><?= h($batchRef) ?></span> — एकूण <?= count($batchLetters) ?> विभागांना पाठवलं</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>विभाग</th><th>File क्र.</th><th>सध्या कोणाकडे</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($batchLetters as $bl): ?>
        <tr>
          <td style="font-weight:600;"><?= h($bl['dept_name']) ?></td>
          <td class="font-mono"><?= h($bl['file_number']) ?></td>
          <td><?= h($bl['holder_name'] ?: '—') ?></td>
          <td><span class="status-chip <?= $statusChips[$bl['status']] ?>"><?= $statusLabels[$bl['status']] ?></span></td>
          <td><a href="view.php?id=<?= $bl['id'] ?>" style="font-size:11.5px;color:var(--rail);font-weight:600;">तपशील बघा</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
