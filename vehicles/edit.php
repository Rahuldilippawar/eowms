<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleNumber = trim($_POST['vehicle_number'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '') ?: null;
    $model = trim($_POST['model'] ?? '') ?: null;
    $dateOfRegistration = ($_POST['date_of_registration'] ?? '') ?: null;
    $chassisNo = trim($_POST['chassis_no'] ?? '') ?: null;
    $engineNo = trim($_POST['engine_no'] ?? '') ?: null;
    $vehicleClass = trim($_POST['vehicle_class'] ?? '') ?: null;
    $passengerCapacity = ($_POST['passenger_capacity'] ?? '') !== '' ? (int)$_POST['passenger_capacity'] : null;
    $driverId = ($_POST['driver_id'] ?? '') !== '' ? (int)$_POST['driver_id'] : null;
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $fuelType = $_POST['fuel_type'] ?? 'diesel';
    $currentOdometer = ($_POST['current_odometer'] ?? '') !== '' ? (float)$_POST['current_odometer'] : 0;
    $isActive = (int)($_POST['is_active'] ?? 1);

    $renewalDates = [];
    foreach (['permit', 'puc', 'insurance', 'fitness', 'road_tax', 'green_tax', 'mv_tax'] as $f) {
        $renewalDates[$f] = $_POST[$f . '_validity'] ?: null;
    }

    $update = $pdo->prepare("
        UPDATE vehicles SET vehicle_number=?, vehicle_type=?, model=?, date_of_registration=?, chassis_no=?, engine_no=?, vehicle_class=?, passenger_capacity=?,
          permit_validity=?, puc_validity=?, insurance_validity=?, fitness_validity=?, road_tax_validity=?, green_tax_validity=?, mv_tax_validity=?,
          driver_id=?, campus_id=?, fuel_type=?, current_odometer=?, is_active=?
        WHERE id=? AND organization_id=?
    ");
    $update->execute([
        $vehicleNumber, $vehicleType, $model, $dateOfRegistration, $chassisNo, $engineNo, $vehicleClass, $passengerCapacity,
        $renewalDates['permit'], $renewalDates['puc'], $renewalDates['insurance'], $renewalDates['fitness'], $renewalDates['road_tax'], $renewalDates['green_tax'], $renewalDates['mv_tax'],
        $driverId, $campusId, $fuelType, $currentOdometer, $isActive, $id, $orgId,
    ]);

    $vehDeptStmt = $pdo->prepare("SELECT department_id FROM vehicles WHERE id = ?");
    $vehDeptStmt->execute([$id]);
    $vehDeptId = $vehDeptStmt->fetch()['department_id'] ?? null;
    if ($vehDeptId) {
        syncVehicleRenewals($pdo, $id, $orgId, $campusId, $vehDeptId, $vehicleNumber, $renewalDates);
    }

    logActivity('वाहन संपादित केले', 'vehicle', $id, $vehicleNumber);
    $_SESSION['flash'] = 'वाहन अद्ययावत केले — नूतनीकरण तारखाही Sync झाल्या.';
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$vehicle = $stmt->fetch();
if (!$vehicle) { die('वाहन सापडले नाही.'); }

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$campuses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

$pageTitle = 'वाहन संपादित करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h($vehicle['vehicle_number']) ?> — संपादित करा</div>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $vehicle['id'] ?>">

    <div style="font-size:12.5px;font-weight:700;color:var(--rail);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">🚐 मूळ माहिती</div>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label-custom">वाहन क्रमांक</label>
        <input type="text" name="vehicle_number" class="form-control-custom" value="<?= h($vehicle['vehicle_number']) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">प्रकार</label>
        <input type="text" name="vehicle_type" class="form-control-custom" value="<?= h($vehicle['vehicle_type']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">मॉडेल</label>
        <input type="text" name="model" class="form-control-custom" value="<?= h($vehicle['model']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">चालक (Driver)</label>
        <select name="driver_id" class="form-select-custom">
          <option value="">-- नेमलेला नाही --</option>
          <?php foreach ($staffPool as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $vehicle['driver_id'] == $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">कॅम्पस</label>
        <select name="campus_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $vehicle['campus_id'] == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">इंधन प्रकार</label>
        <select name="fuel_type" class="form-select-custom">
          <?php foreach (['diesel'=>'डिझेल','petrol'=>'पेट्रोल','cng'=>'सीएनजी','electric'=>'इलेक्ट्रिक','other'=>'इतर'] as $val=>$label): ?>
            <option value="<?= $val ?>" <?= $vehicle['fuel_type'] === $val ? 'selected' : '' ?>><?= $label ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">सध्याचे मीटर रीडिंग (km)</label>
        <input type="number" step="0.1" name="current_odometer" class="form-control-custom" value="<?= $vehicle['current_odometer'] ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">स्थिती</label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $vehicle['is_active'] ? 'selected' : '' ?>>सक्रिय</option>
          <option value="0" <?= !$vehicle['is_active'] ? 'selected' : '' ?>>निष्क्रिय</option>
        </select>
      </div>
    </div>

    <div style="font-size:12.5px;font-weight:700;color:var(--rail);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">📋 RTO / नोंदणी तपशील</div>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label-custom">नोंदणी दिनांक</label>
        <input type="date" name="date_of_registration" class="form-control-custom" value="<?= h($vehicle['date_of_registration']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">चेसिस क्रमांक</label>
        <input type="text" name="chassis_no" class="form-control-custom" value="<?= h($vehicle['chassis_no']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">इंजिन क्रमांक</label>
        <input type="text" name="engine_no" class="form-control-custom" value="<?= h($vehicle['engine_no']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">वाहन वर्ग</label>
        <input type="text" name="vehicle_class" class="form-control-custom" value="<?= h($vehicle['vehicle_class']) ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">प्रवासी क्षमता</label>
        <input type="number" name="passenger_capacity" class="form-control-custom" value="<?= h($vehicle['passenger_capacity']) ?>">
      </div>
    </div>

    <div style="font-size:12.5px;font-weight:700;color:var(--amber);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">🔔 नूतनीकरण तारखा</div>
    <p style="font-size:11.5px;color:var(--text-dim);margin-bottom:14px;">बदलल्यास "नूतनीकरण व अनुपालन" पानावरची नोंदही आपोआप अद्ययावत होईल.</p>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">परमिट Validity</label>
        <input type="date" name="permit_validity" class="form-control-custom" value="<?= h($vehicle['permit_validity']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">PUC Validity</label>
        <input type="date" name="puc_validity" class="form-control-custom" value="<?= h($vehicle['puc_validity']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">विमा Validity</label>
        <input type="date" name="insurance_validity" class="form-control-custom" value="<?= h($vehicle['insurance_validity']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">फिटनेस Validity</label>
        <input type="date" name="fitness_validity" class="form-control-custom" value="<?= h($vehicle['fitness_validity']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">रोड टॅक्स Validity</label>
        <input type="date" name="road_tax_validity" class="form-control-custom" value="<?= h($vehicle['road_tax_validity']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">ग्रीन टॅक्स Validity</label>
        <input type="date" name="green_tax_validity" class="form-control-custom" value="<?= h($vehicle['green_tax_validity']) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">MV टॅक्स Validity</label>
        <input type="date" name="mv_tax_validity" class="form-control-custom" value="<?= h($vehicle['mv_tax_validity']) ?>">
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
      <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">बदल जतन करा</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
