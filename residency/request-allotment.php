<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $buildingId = ($_POST['preferred_building_id'] ?? '') !== '' ? (int)$_POST['preferred_building_id'] : null;
    $familySize = (int)($_POST['family_size'] ?? 1);
    $reason = trim($_POST['reason'] ?? '');

    if ($reason) {
        $pdo->prepare("INSERT INTO residency_allotment_requests (organization_id, requested_by, preferred_building_id, family_size, reason) VALUES (?, ?, ?, ?, ?)")
            ->execute([$orgId, $user['id'], $buildingId, $familySize, $reason]);
        logActivity('निवास वाटप-विनंती केली', 'residency_allotment_request', $pdo->lastInsertId());
        $_SESSION['flash'] = 'तुमची विनंती नोंदवली गेली — HOD/Management च्या मंजुरीची वाट बघा.';
        header('Location: my-requests.php');
        exit;
    } else {
        $_SESSION['form_error'] = 'कृपया कारण लिहा.';
    }
}

$pageTitle = 'निवास-विनंती';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$buildingStmt = $pdo->prepare("SELECT * FROM buildings WHERE organization_id = ? ORDER BY name");
$buildingStmt->execute([$orgId]);
$buildings = $buildingStmt->fetchAll();

// Building-निहाय, कोणती Room किती भरलेली/रिकामी आहे — जेणेकरून विनंती
// करण्याआधीच Staff ला अंदाज येईल.
$roomsStmt = $pdo->prepare("
    SELECT ml.id, ml.name AS room_name, ml.capacity, b.name AS building_name,
      (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND status = 'active') AS occupied
    FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ml.organization_id = ? AND ml.is_active = 1
    ORDER BY b.name, ml.name
");
$roomsStmt->execute([$orgId]);
$allRoomsForDisplay = $roomsStmt->fetchAll();
$roomsByBuilding = [];
foreach ($allRoomsForDisplay as $r) { $roomsByBuilding[$r['building_name'] ?: 'Trust-स्तरीय'][] = $r; }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏠 निवासासाठी विनंती करा</div>
  <a href="my-requests.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list-check me-1"></i>माझ्या विनंत्या</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">🔍 कोणती Room कुठे रिकामी आहे</div><div class="sub">Building-निहाय, प्रत्येक Room ची सध्याची स्थिती</div></div>
  <div style="padding:14px 20px;">
    <?php if (empty($roomsByBuilding)): ?><p style="font-size:12.5px;color:var(--text-dim);">अजून कुठलीही Room तयार केलेली नाही.</p><?php endif; ?>
    <?php foreach ($roomsByBuilding as $bName => $bRooms): ?>
      <div style="font-size:12px;font-weight:700;color:var(--text-dim);text-transform:uppercase;margin:10px 0 6px;"><?= h($bName) ?></div>
      <div class="row g-2 mb-2">
        <?php foreach ($bRooms as $r):
          $vacant = $r['capacity'] !== null ? max(0, $r['capacity'] - $r['occupied']) : null;
          $isFullyBooked = $r['capacity'] !== null && $r['occupied'] >= $r['capacity'];
        ?>
          <div class="col-6 col-md-3">
            <div style="border:1px solid var(--line);border-radius:9px;padding:10px 12px;<?= $isFullyBooked ? 'background:var(--rose-soft);' : ($r['occupied'] == 0 ? 'background:var(--rail-soft);' : '') ?>">
              <div style="font-weight:700;font-size:12.5px;"><?= h($r['room_name']) ?></div>
              <?php if ($r['capacity'] !== null): ?>
                <div style="font-size:11px;color:var(--text-dim);"><?= $r['occupied'] ?>/<?= $r['capacity'] ?> भरलेली</div>
                <div style="font-size:11px;font-weight:600;color:<?= $isFullyBooked ? 'var(--rose)' : 'var(--rail)' ?>;"><?= $isFullyBooked ? '🔴 पूर्ण भरलेली' : "🟢 $vacant जागा रिकाम्या" ?></div>
              <?php else: ?>
                <div style="font-size:11px;color:var(--text-dim);">क्षमता अजून ठरवलेली नाही</div>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="panel" style="max-width:600px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">पसंतीची Building <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <select name="preferred_building_id" class="form-select-custom mb-3">
      <option value="">-- काही फरक नाही --</option>
      <?php foreach ($buildings as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?>
    </select>

    <label class="form-label-custom">कुटुंबाचा आकार (तुमच्यासकट)</label>
    <input type="number" name="family_size" class="form-control-custom mb-3" value="1" min="1">

    <label class="form-label-custom">कारण/तपशील</label>
    <textarea name="reason" class="form-control-custom mb-3" rows="4" placeholder="उदा. सध्या भाड्याने राहतो, निवास हवं आहे" required></textarea>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">विनंती पाठवा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
