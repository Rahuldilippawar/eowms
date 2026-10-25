<?php
$pageTitle = 'रहिवासी विभाग';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$isMgmt = hasPermission('manage_residency_all_depts');

if (!$isMgmt && $userDeptCode !== 'RES') {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला रहिवासी विभागाची माहिती पाहण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}
$canManage = hasRole('super_admin') || $userDeptCode === 'RES' || $isMgmt;

$stmt = $pdo->prepare("
    SELECT ml.*, b.name AS building_name,
      (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND status = 'active') AS occupant_count
    FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ml.organization_id = ? AND ml.is_active = 1 AND ml.is_residential = 1
    ORDER BY b.name, ml.name
");
$stmt->execute([$orgId]);
$rooms = $stmt->fetchAll();

// निवासी नसलेल्या जागा (Office/Kitchen इ.) — फक्त व्यवस्थापकांना, निवासी म्हणून चिन्हांकित करण्यासाठी
$showNonRes = $canManage && !empty($_GET['all']);
$nonResRooms = [];
if ($showNonRes) {
    $nrStmt = $pdo->prepare("SELECT ml.id, ml.name, ml.location_code, b.name AS building_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.organization_id = ? AND ml.is_active = 1 AND ml.is_residential = 0 ORDER BY b.name, ml.name");
    $nrStmt->execute([$orgId]);
    $nonResRooms = $nrStmt->fetchAll();
}

$vacantCount = count(array_filter($rooms, fn($r) => $r['occupant_count'] == 0));
$occupiedCount = count($rooms) - $vacantCount;
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">🏠 रहिवासी विभाग — खोल्यांची स्थिती</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">कोणत्या खोलीत कोण राहतं, कोणती खोली रिकामी आहे — संपूर्ण नोंद</div>
  </div>
  <?php if (hasRole('super_admin') || hasPermission('manage_residency_all_depts')): ?>
    <div class="d-flex gap-2 flex-wrap" style="max-width:600px;justify-content:flex-end;">
      <a href="staff-directory.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 14px;font-weight:600;"><i class="fa-solid fa-address-book me-1"></i>📇 Staff Directory</a>
      <a href="building-layout.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-cubes me-1"></i>आराखडा</a>
      <a href="analytics.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-chart-pie me-1"></i>Analytics</a>
      <a href="manage-requests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-clipboard-list me-1"></i>विनंत्या</a>
      <a href="bills.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-money-bill me-1"></i>बिल</a>
      <a href="inspections.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-magnifying-glass me-1"></i>तपासणी</a>
      <a href="transfer.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-arrows-turn-right me-1"></i>खोली-बदल</a>
      <a href="guests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-user-group me-1"></i>पाहुणे</a>
      <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-file-import me-1"></i>Import</a>
      <a href="../maintenance/locations.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px 14px;"><i class="fa-solid fa-map-location-dot me-1"></i>इमारत/खोली</a>
    </div>
  <?php else: ?>
    <div class="d-flex gap-2">
      <?php if (hasCategory('hod') && $userDeptCode === 'RES'): ?>
        <a href="manage-requests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-clipboard-list me-1"></i>विनंत्या</a>
      <?php endif; ?>
      <a href="my-requests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-list-check me-1"></i>माझ्या विनंत्या</a>
      <a href="guests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-user-group me-1"></i>पाहुणे</a>
      <a href="building-layout.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-cubes me-1"></i>🏢 इमारत आराखडा</a>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण खोल्या</div><div class="val"><?= count($rooms) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🟢 रिकाम्या (Vacant)</div><div class="val" style="color:var(--rail);"><?= $vacantCount ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🔴 भरलेल्या (Occupied)</div><div class="val" style="color:var(--amber);"><?= $occupiedCount ?></div></div></div>
</div>

<?php if ($canManage): ?>
  <div class="mb-3" style="font-size:12.5px;">
    <?php if ($showNonRes): ?>
      <a href="index.php" style="color:var(--rail);font-weight:600;">← फक्त निवासी खोल्या दाखवा</a>
    <?php else: ?>
      <a href="index.php?all=1" style="color:var(--rail);font-weight:600;"><i class="fa-solid fa-building me-1"></i>निवासी नसलेल्या जागा (Office/Kitchen इ.) — निवासी म्हणून चिन्हांकित करा</a>
    <?php endif; ?>
  </div>
  <?php if ($showNonRes): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">निवासी नसलेल्या जागा (<?= count($nonResRooms) ?>)</div></div>
      <div style="padding:10px 20px;">
        <?php foreach ($nonResRooms as $nr): ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:12.5px;">
            <span><?= h($nr['name']) ?> <span style="color:var(--text-dim);"><?= h($nr['location_code']) ?><?= $nr['building_name'] ? ' · ' . h($nr['building_name']) : '' ?></span></span>
            <a href="room.php?id=<?= $nr['id'] ?>" style="color:var(--rail);font-weight:600;">उघडा →</a>
          </div>
        <?php endforeach; ?>
        <?php if (empty($nonResRooms)): ?><p style="font-size:12px;color:var(--text-dim);margin:6px 0;">सगळ्या जागा निवासी म्हणून चिन्हांकित आहेत.</p><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<div class="row g-3">
  <?php if (empty($rooms)): ?>
    <div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून कोणतीही खोली जोडलेली नाही<?php if (hasRole('super_admin')): ?> — <a href="../maintenance/locations.php">इथून जोडा</a><?php endif; ?></div></div>
  <?php endif; ?>
  <?php foreach ($rooms as $room): $vacant = $room['occupant_count'] == 0; ?>
    <div class="col-md-6 col-lg-4">
      <a href="room.php?id=<?= $room['id'] ?>" style="text-decoration:none;">
        <div class="panel" style="padding:18px 20px;<?= $vacant ? 'border:1.5px solid var(--rail);' : '' ?>">
          <div class="d-flex justify-content-between align-items-start">
            <div>
              <div style="font-weight:600;color:var(--ink);font-size:14.5px;"><?= h($room['name']) ?></div>
              <div style="font-size:11px;color:var(--text-dim);"><?= h($room['location_code']) ?><?= $room['building_name'] ? ' · ' . h($room['building_name']) : '' ?></div>
            </div>
            <span class="status-chip <?= $vacant ? 'done' : 'pending' ?>"><?= $vacant ? 'रिकामी' : 'भरलेली' ?></span>
          </div>
          <?php if (!$vacant): ?>
            <div style="font-size:12px;color:var(--text-dim);margin-top:10px;"><i class="fa-solid fa-users me-1"></i><?= $room['occupant_count'] ?> व्यक्ती राहतात</div>
          <?php endif; ?>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
