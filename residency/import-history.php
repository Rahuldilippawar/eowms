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
            // FK-Safe क्रमाने काढतो — आधी Occupants, मग Rooms, मग Buildings.
            // Import नंतर जोडलेला Data (बिल, खोली-बदल, इतर रहिवासी, तपासणी, Maintenance Log इ.)
            // असलेल्या नोंदी काढत नाही — त्या तशाच ठेवतो (Cascade ने तो Data पुसला जाऊ नये).
            $pdo->prepare("
                DELETE FROM residency_occupants WHERE import_batch_id = ?
                  AND id NOT IN (SELECT occupant_id FROM residency_bill_shares)
                  AND id NOT IN (SELECT occupant_id FROM residency_room_transfers)
            ")->execute([$batchId]);
            $keptOccStmt = $pdo->prepare("SELECT COUNT(*) FROM residency_occupants WHERE import_batch_id = ?");
            $keptOccStmt->execute([$batchId]);
            $keptOccupants = (int)$keptOccStmt->fetchColumn();

            // maintenance_locations ला Reference करणाऱ्या सगळ्या Tables (FK वरून)
            $refCols = $pdo->query("
                SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
                WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = 'maintenance_locations'
            ")->fetchAll();
            $roomIdsStmt = $pdo->prepare("SELECT id FROM maintenance_locations WHERE import_batch_id = ?");
            $roomIdsStmt->execute([$batchId]);
            $keptRooms = 0;
            $delRoom = $pdo->prepare("DELETE FROM maintenance_locations WHERE id = ?");
            foreach ($roomIdsStmt->fetchAll(PDO::FETCH_COLUMN) as $roomId) {
                $inUse = false;
                foreach ($refCols as $rc) {
                    $chk = $pdo->prepare("SELECT 1 FROM `{$rc['TABLE_NAME']}` WHERE `{$rc['COLUMN_NAME']}` = ? LIMIT 1");
                    $chk->execute([$roomId]);
                    if ($chk->fetchColumn()) { $inUse = true; break; }
                }
                if ($inUse) { $keptRooms++; continue; }
                $delRoom->execute([$roomId]);
            }
            $pdo->prepare("DELETE FROM buildings WHERE import_batch_id = ? AND id NOT IN (SELECT building_id FROM maintenance_locations WHERE building_id IS NOT NULL)")->execute([$batchId]);
            $pdo->prepare("UPDATE residency_import_batches SET status = 'rolled_back' WHERE id = ?")->execute([$batchId]);
            $pdo->commit();
            logActivity('Residency Import Rollback केला', 'residency_import_batch', $batchId);
            if ($keptRooms || $keptOccupants) {
                $_SESSION['flash'] = "Import मागे घेतला — पण $keptRooms खोल्या व $keptOccupants रहिवासी नोंदी ठेवल्या, कारण Import नंतर त्यांच्याशी बिल/खोली-बदल/रहिवासी/तपासणी इ. नोंदी जोडल्या गेल्या आहेत.";
            } else {
                $_SESSION['flash'] = 'हा संपूर्ण Import मागे घेतला गेला (Rollback यशस्वी).';
            }
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
