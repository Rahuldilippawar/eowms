<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_maintenance_settings', '../');
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_dept_id'])) {
    $deptId = (int)$_POST['toggle_dept_id'];
    $pdo->prepare("UPDATE departments SET has_issue_register = 1 - has_issue_register WHERE id = ? AND organization_id = ?")->execute([$deptId, $orgId]);
    $_SESSION['flash'] = 'बदल जतन झाला.';
    header('Location: settings.php');
    exit;
}

$pageTitle = 'देखभाल नोंदवही — विभाग सेटिंग्ज';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">⚙️ देखभाल नोंदवही — विभाग सेटिंग्ज</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">कोणत्या विभागांना "देखभाल नोंदवही" tab दिसेल ते ठरवा — उदा. देखभाल, प्लंबिंग, इलेक्ट्रिशियन, अग्निशमन, वर्कशॉप</div>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>परत जा</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>विभाग</th><th>Code</th><th>देखभाल नोंदवही</th></tr></thead>
    <tbody>
      <?php foreach ($departments as $d): ?>
        <tr>
          <td style="font-weight:500;"><i class="<?= h($d['icon']) ?> me-2" style="color:var(--rail);"></i><?= h($d['name_local'] ?: $d['name']) ?></td>
          <td class="font-mono"><?= h($d['code']) ?></td>
          <td>
            <form method="POST">
              <input type="hidden" name="toggle_dept_id" value="<?= $d['id'] ?>">
              <button type="submit" class="status-chip <?= $d['has_issue_register'] ? 'done' : 'overdue' ?>" style="border:none;cursor:pointer;">
                <?= $d['has_issue_register'] ? '✓ सक्रिय' : '✕ निष्क्रिय' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
