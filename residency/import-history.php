<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_residency_all_depts', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rollback') {
    $batchId = (int)($_POST['batch_id'] ?? 0);
    $batchStmt = $pdo->prepare("SELECT * FROM residency_import_batches WHERE id = ? AND organization_id = ? AND status = 'completed'");
    $batchStmt->execute([$batchId, $orgId]);
    $batch = $batchStmt->fetch();

    if ($batch) {
        $pdo->beginTransaction();
        try {
            // FK-Safe क्रमाने काढतो — आधी Occupants, मग Rooms, मग Buildings
            $pdo->prepare("DELETE FROM residency_occupants WHERE import_batch_id = ?")->execute([$batchId]);
            $pdo->prepare("DELETE FROM maintenance_locations WHERE import_batch_id = ?")->execute([$batchId]);
            $pdo->prepare("DELETE FROM buildings WHERE import_batch_id = ?")->execute([$batchId]);
            $pdo->prepare("UPDATE residency_import_batches SET status = 'rolled_back' WHERE id = ?")->execute([$batchId]);
            $pdo->commit();
            logActivity('Residency Import Rollback केला', 'residency_import_batch', $batchId);
            $_SESSION['flash'] = 'हा संपूर्ण Import मागे घेतला गेला (Rollback यशस्वी).';
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['form_error'] = 'Rollback करताना त्रुटी आली.';
        }
    }
    header('Location: import-history.php');
    exit;
}

$pageTitle = 'Import इतिहास';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("
    SELECT ib.*, u.name AS imported_by_name
    FROM residency_import_batches ib LEFT JOIN users u ON u.id = ib.imported_by
    WHERE ib.organization_id = ? ORDER BY ib.imported_at DESC
");
$stmt->execute([$orgId]);
$batches = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🕐 Residency Import इतिहास</div>
  <a href="import.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Import</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>File</th><th>Buildings</th><th>Rooms</th><th>निवासी</th><th>कोणी केलं</th><th>दिनांक</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($batches)): ?><tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही Import झालेला नाही</td></tr><?php endif; ?>
      <?php foreach ($batches as $b): ?>
        <tr>
          <td class="font-mono" style="font-size:11.5px;"><?= h($b['filename'] ?: '—') ?></td>
          <td class="font-mono">+<?= $b['buildings_created'] ?></td>
          <td class="font-mono">+<?= $b['rooms_created'] ?></td>
          <td class="font-mono">+<?= $b['occupants_created'] ?></td>
          <td><?= h($b['imported_by_name']) ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($b['imported_at'])) ?></td>
          <td><?php if ($b['status'] === 'completed'): ?><span class="status-chip done">✅ Active</span><?php else: ?><span class="status-chip overdue">↩️ Rolled Back</span><?php endif; ?></td>
          <td>
            <?php if ($b['status'] === 'completed'): ?>
              <form method="POST" onsubmit="return confirm('हा संपूर्ण Import मागे घ्यायचा? यातून तयार झालेले सगळे Buildings/Rooms/निवासी कायमचे काढले जातील.');">
                <input type="hidden" name="action" value="rollback"><input type="hidden" name="batch_id" value="<?= $b['id'] ?>">
                <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11px;padding:4px 10px;">↩️ Rollback</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
