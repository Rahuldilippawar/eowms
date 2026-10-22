<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$canManage = hasRole('super_admin') || $userDeptCode === 'RES' || hasPermission('manage_residency_all_depts');
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $occupantId = (int)($_POST['occupant_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    if ($occupantId && $userId) {
        $pdo->prepare("UPDATE residency_occupants SET user_id = ? WHERE id = ? AND organization_id = ?")->execute([$userId, $occupantId, $orgId]);
        logActivity('निवासी नोंद Staff शी Link केली', 'residency_occupants', $occupantId);
        $_SESSION['flash'] = 'Link झालं.';
    }
    header('Location: link-unlinked.php');
    exit;
}

$pageTitle = 'Unlinked निवासी नोंदी जोडा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$unlinkedStmt = $pdo->prepare("
    SELECT ro.id, ro.name, ml.name AS room_name, b.name AS building_name
    FROM residency_occupants ro JOIN maintenance_locations ml ON ml.id = ro.location_id LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ro.organization_id = ? AND ro.occupant_type = 'staff' AND ro.status = 'active' AND ro.user_id IS NULL
    ORDER BY ro.name
");
$unlinkedStmt->execute([$orgId]);
$unlinked = $unlinkedStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name, email, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔗 Unlinked निवासी नोंदी जोडा</div>
  <a href="staff-directory.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>Staff Directory</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">या नोंदी जुन्या Import मधून आल्या असू शकतात, अजून बरोबर Staff Account शी जोडलेल्या नाहीत. प्रत्येकीला बरोबर व्यक्ती निवडून Link करा — यामुळे Email/Mobile आपोआप दिसू लागेल, आणि Duplicate Data राहणार नाही.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <?php if (empty($unlinked)): ?>
    <div style="padding:30px;text-align:center;color:var(--rail);font-weight:600;">✅ सगळ्या निवासी नोंदी आधीच Link झालेल्या आहेत!</div>
  <?php endif; ?>
  <?php foreach ($unlinked as $u): ?>
    <form method="POST" style="display:flex;gap:12px;align-items:center;padding:14px 20px;border-bottom:1px solid var(--line);">
      <input type="hidden" name="occupant_id" value="<?= $u['id'] ?>">
      <div style="flex:1;">
        <div style="font-weight:600;font-size:13.5px;"><?= h($u['name']) ?></div>
        <div style="font-size:11px;color:var(--text-dim);"><?= h($u['building_name'] ?: '') ?><?= $u['building_name'] && $u['room_name'] ? ' — ' : '' ?><?= h($u['room_name'] ?: '') ?></div>
      </div>
      <select name="user_id" class="form-select-custom" style="width:280px;" required>
        <option value="">-- कोणती Staff Account? --</option>
        <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?> (<?= h($s['designation'] ?: $s['email']) ?>)</option><?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:7px 16px;">Link करा</button>
    </form>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
