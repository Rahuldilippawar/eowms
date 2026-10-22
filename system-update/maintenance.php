<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'enable') {
        $message = trim($_POST['maintenance_message'] ?? '');
        $pdo->prepare("UPDATE organizations SET maintenance_mode = 1, maintenance_message = ?, maintenance_started_at = NOW() WHERE id = ?")
            ->execute([$message ?: null, $orgId]);
        logActivity('Site Maintenance Mode चालू केलं', 'organization', $orgId, $message);
        $_SESSION['flash'] = '🛠️ Site आता Maintenance Mode मध्ये आहे — तुमच्याशिवाय बाकी सगळ्यांना सूचना-पान दिसेल.';
    } elseif ($action === 'disable') {
        $pdo->prepare("UPDATE organizations SET maintenance_mode = 0, maintenance_message = NULL, maintenance_started_at = NULL WHERE id = ?")->execute([$orgId]);
        logActivity('Site Maintenance Mode बंद केलं', 'organization', $orgId);
        $_SESSION['flash'] = '✅ Site पुन्हा सगळ्यांसाठी सुरू झाली आहे.';
    }
    header('Location: maintenance.php');
    exit;
}

$pageTitle = 'Site Maintenance Mode';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgStmt = $pdo->prepare("SELECT * FROM organizations WHERE id = ?");
$orgStmt->execute([$orgId]);
$org = $orgStmt->fetch();
$isOn = (int)($org['maintenance_mode'] ?? 0) === 1;
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🛠️ Site Maintenance Mode</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Site बंद केल्यास — तुमच्याशिवाय (Super Admin) बाकी सगळ्यांना "Maintenance चालू आहे" असं सूचना-पान दिसेल, नवीन कोणालाही Login करता येणार नाही.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel" style="max-width:560px;">
  <div style="padding:24px;">
    <?php if ($isOn): ?>
      <div style="background:var(--rose-soft, #F7E2E3);border-radius:12px;padding:16px 18px;margin-bottom:18px;">
        <div style="font-weight:700;color:var(--rose);font-size:14px;"><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--rose);margin-right:8px;"></span>सध्या Maintenance Mode — चालू आहे</div>
        <div style="font-size:12px;color:var(--text-dim);margin-top:6px;">सुरू झाल्यापासून: <?= $org['maintenance_started_at'] ? date('d M Y, h:i A', strtotime($org['maintenance_started_at'])) : '—' ?></div>
        <?php if ($org['maintenance_message']): ?><div style="font-size:12.5px;margin-top:8px;">"<?= h($org['maintenance_message']) ?>"</div><?php endif; ?>
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="disable">
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:12px;font-weight:600;">✅ Site पुन्हा सुरू करा</button>
      </form>
    <?php else: ?>
      <div style="background:var(--rail-soft, #DCEFEC);border-radius:12px;padding:14px 18px;margin-bottom:18px;font-size:13px;color:var(--rail);font-weight:600;">🟢 सध्या Site सुरळीत सुरू आहे</div>
      <form method="POST">
        <input type="hidden" name="action" value="enable">
        <label class="form-label-custom">सूचना-संदेश (ऐच्छिक — रिकामं ठेवल्यास Default संदेश दिसेल)</label>
        <textarea name="maintenance_message" class="form-control-custom mb-3" rows="3" placeholder="उदा. System मध्ये नवीन Feature जोडलं जात आहे — साधारण 30 मिनिटांत पूर्ण होईल."></textarea>
        <button type="submit" class="btn w-100" style="background:var(--rose);color:#fff;border-radius:9px;font-size:14px;padding:12px;font-weight:600;" onclick="return confirm('संपूर्ण Site बंद करायची? — तुमच्याशिवाय बाकी सगळ्यांना Maintenance-पान दिसेल.')">🛠️ Site बंद करा (Maintenance Mode)</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="panel mt-3" style="max-width:560px;">
  <div style="padding:18px 24px;font-size:12px;color:var(--text-dim);">
    💡 <strong>टीप:</strong> Maintenance Mode चालू असतानाही — तुम्ही (Super Admin) System मध्ये सामान्यपणे काम करू शकता. बदल पूर्ण झाल्यावर "Site पुन्हा सुरू करा" दाबायला विसरू नका.
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
