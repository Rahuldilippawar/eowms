<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
if (!canManageVehicles()) { requireRole(['super_admin'], '../'); } // वाहन यादीप्रमाणेच — Super Admin किंवा manage_vehicles
$user = currentUser();

$orgId = activeOrgId();
$error = null;

$renewalFields = ['permit_validity', 'puc_validity', 'insurance_validity', 'fitness_validity', 'road_tax_validity', 'green_tax_validity', 'mv_tax_validity'];

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

    $renewalDates = [];
    foreach ($renewalFields as $f) {
        $shortKey = str_replace('_validity', '', $f);
        $renewalDates[$shortKey] = ($_POST[$f] ?? '') ?: null;
    }
    $inputError = vehicleValidateInput($pdo, $orgId, $driverId, $campusId, $fuelType, array_merge(array_values($renewalDates), [$dateOfRegistration]));

    // Link under the "वाहतूक" (Transport) department if it exists
    $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'TRP' LIMIT 1");
    $deptStmt->execute([$orgId]);
    $transportDept = $deptStmt->fetch();
    $deptId = $transportDept['id'] ?? null;

    if (!$vehicleNumber) {
        $error = 'कृपया वाहन क्रमांक भरा.';
    } elseif ($inputError) {
        $error = $inputError;
    } else {
        $insert = $pdo->prepare("
            INSERT INTO vehicles (organization_id, campus_id, department_id, vehicle_number, vehicle_type, model, date_of_registration, chassis_no, engine_no, vehicle_class, passenger_capacity, permit_validity, puc_validity, insurance_validity, fitness_validity, road_tax_validity, green_tax_validity, mv_tax_validity, driver_id, fuel_type, current_odometer)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([
            $orgId, $campusId, $deptId, $vehicleNumber, $vehicleType, $model, $dateOfRegistration, $chassisNo, $engineNo, $vehicleClass, $passengerCapacity,
            $renewalDates['permit'], $renewalDates['puc'], $renewalDates['insurance'], $renewalDates['fitness'], $renewalDates['road_tax'], $renewalDates['green_tax'], $renewalDates['mv_tax'],
            $driverId, $fuelType, $currentOdometer,
        ]);
        $newVehicleId = $pdo->lastInsertId();

        if ($deptId) {
            syncVehicleRenewals($pdo, $newVehicleId, $orgId, $campusId, $deptId, $vehicleNumber, $renewalDates);
        }

        logActivity('वाहन जोडले', 'vehicle', $newVehicleId, $vehicleNumber);
        $_SESSION['flash'] = "'$vehicleNumber' वाहन जोडले गेले — त्याच्या नूतनीकरण-तारखा 'नूतनीकरण व अनुपालन' पानावर आपोआप दिसतील.";
        header('Location: index.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$campuses = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$staffPool = $stmt->fetchAll();

$pageTitle = 'नवीन वाहन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">नवीन वाहन जोडा</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" style="padding:22px 24px;">

    <div style="font-size:12.5px;font-weight:700;color:var(--rail);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">🚐 मूळ माहिती</div>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label-custom">वाहन क्रमांक</label>
        <input type="text" name="vehicle_number" class="form-control-custom" placeholder="उदा. MH-04-AB-1234" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">प्रकार</label>
        <input type="text" name="vehicle_type" class="form-control-custom" placeholder="उदा. बस, स्कूल व्हॅन, कार">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">मॉडेल</label>
        <input type="text" name="model" class="form-control-custom" placeholder="उदा. Tata Winger">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">चालक (Driver) नेमा</label>
        <select name="driver_id" class="form-select-custom">
          <option value="">-- नंतर नेमा --</option>
          <?php foreach ($staffPool as $s): ?>
            <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">कॅम्पस</label>
        <select name="campus_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?>
            <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">इंधन प्रकार</label>
        <select name="fuel_type" class="form-select-custom">
          <option value="diesel">डिझेल</option>
          <option value="petrol">पेट्रोल</option>
          <option value="cng">सीएनजी</option>
          <option value="electric">इलेक्ट्रिक</option>
          <option value="other">इतर</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">सध्याचे मीटर रीडिंग (km)</label>
        <input type="number" step="0.1" name="current_odometer" class="form-control-custom" value="0">
      </div>
    </div>

    <div style="font-size:12.5px;font-weight:700;color:var(--rail);text-transform:uppercase;letter-spacing:.05em;margin-bottom:12px;">📋 RTO / नोंदणी तपशील</div>
    <div class="row g-3 mb-4">
      <div class="col-md-4">
        <label class="form-label-custom">नोंदणी दिनांक (Date of Registration)</label>
        <input type="date" name="date_of_registration" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">चेसिस क्रमांक (Chassis No.)</label>
        <input type="text" name="chassis_no" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">इंजिन क्रमांक (Engine No.)</label>
        <input type="text" name="engine_no" class="form-control-custom">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">वाहन वर्ग (Class of Vehicle)</label>
        <input type="text" name="vehicle_class" class="form-control-custom" placeholder="उदा. LMV, HTV, Motor Car">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">प्रवासी क्षमता (No. of Passengers)</label>
        <input type="number" name="passenger_capacity" class="form-control-custom">
      </div>
    </div>

    <div style="font-size:12.5px;font-weight:700;color:var(--amber);text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px;">🔔 नूतनीकरण तारखा</div>
    <p style="font-size:11.5px;color:var(--text-dim);margin-bottom:14px;">या तारखा भरल्या की — त्या आपोआप <strong>"नूतनीकरण व अनुपालन"</strong> पानावर दिसतील, तिथे वेगळं टाकायची गरज नाही.</p>
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">परमिट (Permit) Validity</label>
        <input type="date" name="permit_validity" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">PUC Validity</label>
        <input type="date" name="puc_validity" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">विमा (Insurance) Validity</label>
        <input type="date" name="insurance_validity" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">फिटनेस (Fitness) Validity</label>
        <input type="date" name="fitness_validity" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">रोड टॅक्स (Road Tax) Validity</label>
        <input type="date" name="road_tax_validity" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">ग्रीन टॅक्स (Green Tax) Validity</label>
        <input type="date" name="green_tax_validity" class="form-control-custom">
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">MV टॅक्स Validity</label>
        <input type="date" name="mv_tax_validity" class="form-control-custom">
      </div>
    </div>

    <div class="d-flex justify-content-end gap-2 mt-4">
      <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">वाहन जोडा</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
