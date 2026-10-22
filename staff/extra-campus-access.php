<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $selectedCampuses = array_map('intval', $_POST['campus_ids'] ?? []);

    $targetStmt = $pdo->prepare("SELECT id, campus_id FROM users WHERE id = ? AND organization_id = ?");
    $targetStmt->execute([$targetUserId, $orgId]);
    $target = $targetStmt->fetch();

    if ($target) {
        // स्वतःचा मूळ Campus वगळून बाकीचे — Extra Access म्हणून Save करतो
        $selectedCampuses = array_filter($selectedCampuses, fn($c) => $c !== (int)$target['campus_id']);
        $pdo->prepare("DELETE FROM user_extra_campus_access WHERE user_id = ?")->execute([$targetUserId]);
        $ins = $pdo->prepare("INSERT INTO user_extra_campus_access (user_id, campus_id, granted_by) VALUES (?, ?, ?)");
        foreach ($selectedCampuses as $cId) { $ins->execute([$targetUserId, $cId, $user['id']]); }
        logActivity('Extra Campus Access बदललं', 'user', $targetUserId, count($selectedCampuses) . ' Campus');
        $_SESSION['flash'] = 'बदल जतन झाले.';
    }
    header('Location: extra-campus-access.php?user_id=' . $targetUserId);
    exit;
}

$pageTitle = 'Extra Campus Access';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$staffStmt = $pdo->prepare("
    SELECT u.*, c.name AS campus_name,
      (SELECT COUNT(*) FROM user_extra_campus_access WHERE user_id = u.id) AS extra_count
    FROM users u LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.name
");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();

$selectedUserId = (int)($_GET['user_id'] ?? 0);
$selectedUser = null;
$grantedCampusIds = [];
if ($selectedUserId) {
    $suStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND organization_id = ?");
    $suStmt->execute([$selectedUserId, $orgId]);
    $selectedUser = $suStmt->fetch();
    if ($selectedUser) {
        $geStmt = $pdo->prepare("SELECT campus_id FROM user_extra_campus_access WHERE user_id = ?");
        $geStmt->execute([$selectedUserId]);
        $grantedCampusIds = $geStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

$campusesStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$allCampuses = $campusesStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏫 Extra Campus Access</div>
  <a href="extra-access.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;">🔓 Extra Department Access →</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">एखाद्या व्यक्तीला — स्वतःच्या College/School व्यतिरिक्त, इतर College/School चंही Staff Profile/Track Record बघण्याची परवानगी द्या ("अनेक कॉलेज" Tier). <strong>संपूर्ण संस्था</strong> बघायची असल्यास — त्याऐवजी Roles Module मधून "संपूर्ण संस्थेतल्या सगळ्यांचे Staff Profile" Permission द्या.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel">
      <div class="panel-head"><div class="title">व्यक्ती निवडा</div></div>
      <div style="max-height:520px;overflow-y:auto;">
        <?php foreach ($allStaff as $s): ?>
          <a href="?user_id=<?= $s['id'] ?>" style="display:flex;justify-content:space-between;align-items:center;padding:10px 18px;text-decoration:none;color:inherit;border-bottom:1px solid var(--line);<?= $selectedUserId === (int)$s['id'] ? 'background:var(--rail-soft);' : '' ?>">
            <div>
              <div style="font-size:13px;font-weight:600;"><?= h($s['name']) ?></div>
              <div style="font-size:10.5px;color:var(--text-dim);"><?= h($s['campus_name'] ?: '—') ?></div>
            </div>
            <?php if ($s['extra_count'] > 0): ?><span class="status-chip done" style="font-size:10px;">+<?= $s['extra_count'] ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-8">
    <?php if (!$selectedUser): ?>
      <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);">डावीकडून एक व्यक्ती निवडा.</div>
    <?php else: ?>
      <div class="panel">
        <div class="panel-head"><div class="title"><?= h($selectedUser['name']) ?> — कोणते Extra College/School बघता यावेत?</div></div>
        <form method="POST" style="padding:20px 24px;">
          <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
          <div style="max-height:400px;overflow-y:auto;background:var(--canvas);border-radius:10px;padding:14px 16px;">
            <?php foreach ($allCampuses as $c): if ((int)$c['id'] === (int)$selectedUser['campus_id']) continue; ?>
              <label style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13px;">
                <input type="checkbox" name="campus_ids[]" value="<?= $c['id'] ?>" style="width:16px;height:16px;" <?= in_array($c['id'], $grantedCampusIds) ? 'checked' : '' ?>>
                <?= h($c['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">💡 यांचा स्वतःचा Campus आधीच दिसतोच — तो इथे वेगळा दाखवायची गरज नाही. निवडलेल्या Campus मधले <strong>सगळे विभाग</strong> आपोआप दिसतील.</div>
          <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">जतन करा</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
