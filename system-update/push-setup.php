<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();
require_once __DIR__ . '/../includes/webpush/WebPush.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    $keys = WebPush::generateVapidKeys();
    $pdo->prepare("UPDATE organizations SET vapid_public_key = ?, vapid_private_key = ? WHERE id = ?")
        ->execute([$keys['publicKey'], $keys['privateKey'], $orgId]);
    logActivity('Push Notification Keys तयार केल्या', 'organization', $orgId);
    $_SESSION['flash'] = 'नवीन Push Keys तयार झाल्या — आता Push Notification सुरू होतील. (टीप: आधीच्या सगळ्या Subscriptions आता निरुपयोगी होतील — प्रत्येकाला पुन्हा एकदा "Allow" करावं लागेल.)';
    try { $pdo->prepare("DELETE FROM push_subscriptions WHERE user_id IN (SELECT id FROM users WHERE organization_id = ?)")->execute([$orgId]); } catch (PDOException $e) {}
    header('Location: push-setup.php');
    exit;
}

$pageTitle = 'Push Notification Setup';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgStmt = $pdo->prepare("SELECT vapid_public_key, vapid_private_key FROM organizations WHERE id = ?");
$orgStmt->execute([$orgId]);
$org = $orgStmt->fetch();
$isConfigured = !empty($org['vapid_public_key']) && !empty($org['vapid_private_key']);

$subCountStmt = $pdo->prepare("SELECT COUNT(*) c FROM push_subscriptions WHERE user_id IN (SELECT id FROM users WHERE organization_id = ?)");
try { $subCountStmt->execute([$orgId]); $subCount = $subCountStmt->fetch()['c']; } catch (PDOException $e) { $subCount = 0; }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">📲 Push Notification Setup</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">App बंद असतानाही Staff ला थेट Mobile वर सूचना पाठवण्यासाठी — हे एकदाच Setup करावं लागतं.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">स्थिती</div><div class="val" style="font-size:16px;color:<?= $isConfigured ? 'var(--rail)' : 'var(--amber)' ?>;"><?= $isConfigured ? '✅ Active' : '⏳ Setup बाकी' ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">Subscribe केलेले Devices</div><div class="val"><?= $subCount ?></div></div></div>
</div>

<div class="panel" style="max-width:600px;">
  <div class="panel-head"><div class="title"><?= $isConfigured ? 'Keys पुन्हा तयार करा' : 'Push Keys तयार करा' ?></div></div>
  <div style="padding:22px 24px;">
    <?php if ($isConfigured): ?>
      <p style="font-size:13px;color:var(--text-dim);">✅ Push Notification आधीच Active आहे. Keys पुन्हा तयार केल्यास — सध्याचे सगळे Subscribers पुन्हा "Allow" करावे लागतील.</p>
    <?php else: ?>
      <p style="font-size:13px;color:var(--text-dim);">एकदा "तयार करा" दाबा — त्यानंतर App मध्ये सगळ्यांना Notification-Permission विचारलं जाईल.</p>
    <?php endif; ?>
    <form method="POST" onsubmit="return confirm('<?= $isConfigured ? 'नवीन Keys तयार केल्यास — सगळे जुने Subscribers निरुपयोगी होतील. पुढे जायचं?' : 'Push Keys तयार करायच्या?' ?>');">
      <input type="hidden" name="action" value="generate">
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px 22px;font-weight:600;">
        <?= $isConfigured ? '🔄 नवीन Keys तयार करा' : '⚡ Push Notification सुरू करा' ?>
      </button>
    </form>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
