<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/it-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$assetId = (int)($_GET['asset_id'] ?? $_POST['asset_id'] ?? 0);

$assetStmt = $pdo->prepare("SELECT a.*, l.lab_name FROM it_assets a JOIN it_labs l ON l.id = a.lab_id WHERE a.id = ? AND a.organization_id = ?");
$assetStmt->execute([$assetId, $orgId]);
$asset = $assetStmt->fetch();
if (!$asset) { die('<div style="font-family:sans-serif;padding:40px;">Asset सापडलं नाही.</div>'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $issueDescription = trim($_POST['issue_description'] ?? '');
    if ($issueDescription) {
        $pdo->prepare("INSERT INTO it_repair_tickets (organization_id, asset_id, lab_id, issue_description, reported_by) VALUES (?, ?, ?, ?, ?)")
            ->execute([$orgId, $assetId, $asset['lab_id'], $issueDescription, $user['id']]);
        $ticketId = $pdo->lastInsertId();
        $pdo->prepare("UPDATE it_assets SET status = 'faulty' WHERE id = ? AND status = 'working'")->execute([$assetId]);

        // IT विभागाच्या HOD ला Notification
        $itDeptStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE organization_id = ? AND code = 'IT'");
        $itDeptStmt->execute([$orgId]);
        $itHod = $itDeptStmt->fetch()['hod_id'] ?? null;
        if ($itHod) {
            notifyUser($itHod, $orgId, 'नवीन IT तक्रार', "{$asset['asset_tag']} ({$asset['lab_name']}) — $issueDescription", 'it/general-requests-manage.php');
        }

        logActivity('IT तक्रार नोंदवली', 'it_repair_ticket', $ticketId, $asset['asset_tag']);
        $_SESSION['flash'] = 'तक्रार यशस्वीरित्या नोंदवली गेली — IT विभाग लवकरच त्यावर काम करेल.';
        header('Location: report-issue.php?asset_id=' . $assetId . '&done=1');
        exit;
    }
}

$pageTitle = 'तक्रार नोंदवा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($asset['lab_name']) ?></div>
<div class="page-title mb-1">🔧 <?= itAssetTypeLabel($asset['asset_type'] ?? '') ?> — <?= h($asset['asset_tag']) ?> — तक्रार नोंदवा</div>

<?php if (isset($_GET['done'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;">✅ तक्रार यशस्वीरित्या नोंदवली गेली — IT विभाग लवकरच त्यावर काम करेल.</div>
<?php endif; ?>

<div class="panel" style="max-width:560px;">
  <form method="POST" style="padding:22px 24px;">
    <input type="hidden" name="asset_id" value="<?= $assetId ?>">
    <label class="form-label-custom">काय समस्या आहे?</label>
    <textarea name="issue_description" class="form-control-custom" rows="4" placeholder="उदा. Monitor वर काहीच दिसत नाही / सुरूच होत नाही" required></textarea>
    <button type="submit" class="btn w-100 mt-3" style="background:var(--amber);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">तक्रार पाठवा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
