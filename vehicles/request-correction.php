<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$type = $_GET['type'] ?? '';
$entryId = (int)($_GET['entry_id'] ?? 0);
$vehicleId = (int)($_GET['vehicle_id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;

$vehCheck = $pdo->prepare("SELECT driver_id FROM vehicles WHERE id = ? AND organization_id = ?");
$vehCheck->execute([$vehicleId, $orgId]);
$vehRow = $vehCheck->fetch();
$canRequest = hasPermission('manage_vehicles') || $userDeptCode === 'TRP' || ($vehRow && (int)$vehRow['driver_id'] === (int)$user['id']);

if (!$canRequest) {
    http_response_code(403);
    die('तुम्हाला या वाहनाच्या नोंदीसाठी सुधारणा विनंती करण्याची परवानगी नाही.');
}

if (!in_array($type, ['trip', 'fuel', 'maintenance'], true)) { die('चुकीची विनंती.'); }

$tableMap = ['trip' => 'vehicle_trips', 'fuel' => 'vehicle_fuel_log', 'maintenance' => 'vehicle_maintenance'];
$table = $tableMap[$type];

$stmt = $pdo->prepare("SELECT * FROM $table WHERE id = ? AND vehicle_id = ?");
$stmt->execute([$entryId, $vehicleId]);
$entry = $stmt->fetch();
if (!$entry) { die('नोंद सापडली नाही.'); }

$stmt = $pdo->prepare("SELECT * FROM vehicles WHERE id = ? AND organization_id = ?");
$stmt->execute([$vehicleId, $orgId]);
$vehicle = $stmt->fetch();
if (!$vehicle) { die('वाहन सापडले नाही.'); }

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $requestType = $_POST['request_type'] ?? 'edit';
    $reason = trim($_POST['reason'] ?? '');

    if (!$reason) {
        $error = 'कृपया कारण/तपशील लिहा — काय चुकलं आहे आणि काय बरोबर असायला हवं.';
    } else {
        $insert = $pdo->prepare("
            INSERT INTO vehicle_correction_requests (vehicle_id, entry_type, entry_id, request_type, reason, requested_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $insert->execute([$vehicleId, $type, $entryId, $requestType, $reason, $user['id']]);

        // Notify all Super Admins
        $saStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND role = 'super_admin' AND is_active = 1");
        $saStmt->execute([$orgId]);
        foreach ($saStmt->fetchAll(PDO::FETCH_COLUMN) as $saId) {
            notifyUser($saId, $orgId, 'वाहन नोंद सुधारणा विनंती', "{$vehicle['vehicle_number']} — {$user['name']} कडून", 'vehicles/requests.php');
        }
        logActivity('वाहन नोंद सुधारणा विनंती केली', 'vehicle', $vehicleId, "$type #$entryId — $requestType");

        $_SESSION['flash'] = 'तुमची विनंती Super Admin कडे पाठवली गेली.';
        header('Location: dashboard.php?id=' . $vehicleId);
        exit;
    }
}

$typeLabels = ['trip' => 'ट्रिप', 'fuel' => 'इंधन नोंद', 'maintenance' => 'देखभाल नोंद'];

$pageTitle = 'सुधारणा विनंती';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($vehicle['vehicle_number']) ?></div>
<div class="page-title"><?= $typeLabels[$type] ?> — सुधारणा विनंती</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">सध्याची नोंद</div></div>
  <div style="padding:16px 20px;font-size:13px;">
    <?php if ($type === 'trip'): ?>
      <?= date('d M Y', strtotime($entry['trip_date'])) ?> — <?= h($entry['from_location']) ?> → <?= h($entry['to_location']) ?> — <?= $entry['start_odometer'] ?> to <?= $entry['end_odometer'] ?> km (एकूण <?= $entry['distance_km'] ?> km)
    <?php elseif ($type === 'fuel'): ?>
      <?= date('d M Y', strtotime($entry['fill_date'])) ?> — मीटर: <?= $entry['odometer_reading'] ?>, <?= $entry['liters'] ?> लिटर<?= $entry['cost'] ? ', ₹' . $entry['cost'] : '' ?>
    <?php else: ?>
      <?= date('d M Y', strtotime($entry['maintenance_date'])) ?> — <?= h($entry['type']) ?><?= $entry['cost'] ? ', ₹' . $entry['cost'] : '' ?>
    <?php endif; ?>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">विनंतीचा तपशील</div><div class="sub">Super Admin ही entry review करून edit/delete करेल</div></div>
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">काय हवं आहे?</label>
    <select name="request_type" class="form-select-custom mb-3">
      <option value="edit">दुरुस्ती हवी (Edit)</option>
      <option value="delete">ही नोंद पूर्ण काढून टाका (Delete)</option>
    </select>

    <label class="form-label-custom">कारण / तपशील — काय चुकलं आहे, काय बरोबर असायला हवं</label>
    <textarea name="reason" class="form-control-custom" rows="4" required placeholder="उदा. मीटर रीडिंग चुकीचं टाकलं — ५०० ऐवजी ५० किमी असायला हवं होतं"></textarea>

    <div class="d-flex justify-content-end gap-2 mt-3">
      <a href="dashboard.php?id=<?= $vehicleId ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;">रद्द करा</a>
      <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">विनंती पाठवा</button>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
