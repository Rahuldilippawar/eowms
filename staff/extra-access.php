<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $targetUserId = (int)($_POST['user_id'] ?? 0);
    $selectedDepts = array_map('intval', $_POST['department_ids'] ?? []);

    $targetStmt = $pdo->prepare("SELECT id, department_id FROM users WHERE id = ? AND organization_id = ?");
    $targetStmt->execute([$targetUserId, $orgId]);
    $target = $targetStmt->fetch();

    if ($target) {
        // स्वतःचा मूळ विभाग वगळून बाकीचे — Extra Access म्हणून Save करतो
        $selectedDepts = array_filter($selectedDepts, fn($d) => $d !== (int)$target['department_id']);
        $pdo->prepare("DELETE FROM user_extra_department_access WHERE user_id = ?")->execute([$targetUserId]);
        $ins = $pdo->prepare("INSERT INTO user_extra_department_access (user_id, department_id, granted_by) VALUES (?, ?, ?)");
        foreach ($selectedDepts as $dId) { $ins->execute([$targetUserId, $dId, $user['id']]); }
        logActivity('Extra Department Access बदललं', 'user', $targetUserId, count($selectedDepts) . ' विभाग');
        $_SESSION['flash'] = 'बदल जतन झाले.';
    }
    header('Location: extra-access.php?user_id=' . $targetUserId);
    exit;
}

$pageTitle = 'Extra Department Access';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$staffStmt = $pdo->prepare("
    SELECT u.*, d.name_local AS dept_name,
      (SELECT COUNT(*) FROM user_extra_department_access WHERE user_id = u.id) AS extra_count
    FROM users u LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 ORDER BY u.name
");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();

$selectedUserId = (int)($_GET['user_id'] ?? 0);
$selectedUser = null;
$grantedDeptIds = [];
if ($selectedUserId) {
    $suStmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND organization_id = ?");
    $suStmt->execute([$selectedUserId, $orgId]);
    $selectedUser = $suStmt->fetch();
    if ($selectedUser) {
        $geStmt = $pdo->prepare("SELECT department_id FROM user_extra_department_access WHERE user_id = ?");
        $geStmt->execute([$selectedUserId]);
        $grantedDeptIds = $geStmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🔓 Extra Department Access</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">एखाद्या व्यक्तीला — स्वतःच्या विभागाव्यतिरिक्त, इतर विभागांचंही Task/नूतनीकरण/कामाची विनंती/Tracker बघण्याची परवानगी द्या. <strong>पूर्ण Organization</strong> बघायची असल्यास — त्याऐवजी Roles Module मधून योग्य Org-wide Permission द्या.</p>

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
              <div style="font-size:10.5px;color:var(--text-dim);"><?= h($s['dept_name'] ?: '—') ?></div>
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
        <div class="panel-head"><div class="title"><?= h($selectedUser['name']) ?> — कोणते Extra विभाग बघता यावेत?</div></div>
        <form method="POST" style="padding:20px 24px;">
          <input type="hidden" name="user_id" value="<?= $selectedUser['id'] ?>">
          <div style="max-height:400px;overflow-y:auto;background:var(--canvas);border-radius:10px;padding:14px 16px;">
            <?php foreach ($departments as $d): if ((int)$d['id'] === (int)$selectedUser['department_id']) continue; ?>
              <label style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:13px;">
                <input type="checkbox" name="department_ids[]" value="<?= $d['id'] ?>" style="width:16px;height:16px;" <?= in_array($d['id'], $grantedDeptIds) ? 'checked' : '' ?>>
                <?= h($d['name_local'] ?: $d['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
          <div style="font-size:11px;color:var(--text-dim);margin-top:6px;">💡 यांचा स्वतःचा विभाग (<?= h($selectedUser['department_id'] ? '' : '—') ?>) आधीच दिसतोच — तो इथे वेगळा दाखवायची गरज नाही.</div>
          <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">जतन करा</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
