<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

// मंजुरी-प्रलंबित Device — Super Admin ने मंजूर केल्यावर तो सध्याच्या संस्थेशी जोडला जातो व Punches स्वीकारले जातात
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['approve', 'reject'], true)) {
    $deviceId = (int)($_POST['device_id'] ?? 0);
    if ($_POST['action'] === 'approve') {
        $pdo->prepare("UPDATE biometric_devices SET is_approved = 1, is_active = 1, organization_id = ? WHERE id = ?")->execute([$orgId, $deviceId]);
        logActivity('Biometric Device मंजूर केलं', 'biometric_device', $deviceId);
        $_SESSION['flash'] = 'Device मंजूर केलं — आता त्याचे Punches स्वीकारले जातील.';
    } else {
        $pdo->prepare("DELETE FROM biometric_devices WHERE id = ? AND is_approved = 0")->execute([$deviceId]);
        $_SESSION['flash'] = 'प्रलंबित Device ची नोंद काढून टाकली.';
    }
    header('Location: devices.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deviceId = (int)($_POST['device_id'] ?? 0);
    $deviceName = trim($_POST['device_name'] ?? '');
    $campusId = ($_POST['campus_id'] ?? '') !== '' ? (int)$_POST['campus_id'] : null;
    $pdo->prepare("UPDATE biometric_devices SET device_name = ?, campus_id = ?, organization_id = ? WHERE id = ?")
        ->execute([$deviceName, $campusId, $orgId, $deviceId]);
    $_SESSION['flash'] = 'Device ची माहिती अद्ययावत केली.';
    header('Location: devices.php');
    exit;
}

$pageTitle = 'Biometric Devices';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$devicesStmt = $pdo->prepare("
    SELECT d.*, c.name AS campus_name FROM biometric_devices d LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_approved = 1 ORDER BY d.last_seen_at DESC
");
$devicesStmt->execute([$orgId]);
$devices = $devicesStmt->fetchAll();

// आपोआप नोंदलेली, अजून मंजूर न झालेली Devices (कुठल्याही संस्थेखाली नोंदलेली असली तरी)
$pendingDevices = $pdo->query("SELECT * FROM biometric_devices WHERE is_approved = 0 ORDER BY last_seen_at DESC")->fetchAll();

$campusStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campusesForDevice = $campusStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">👆 Biometric Devices</div>
  <a href="pin-mapping.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 18px;">🔢 PIN Mapping</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Machine ने एकदा तरी Data पाठवला की — इथे आपोआप नोंदवली जाईल. Machine च्या Settings मध्ये Server URL म्हणून द्या: <code style="background:var(--canvas);padding:2px 8px;border-radius:5px;">http://तुमचं-Domain/iclock/cdata.php</code></p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if (!empty($pendingDevices)): ?>
<div class="panel mb-3" style="border-color:var(--rose);">
  <div class="panel-head"><div class="title">⏳ मंजुरी-प्रलंबित Devices</div><div class="sub">ही Machines आपोआप Connect झाली आहेत — मंजूर करेपर्यंत त्यांचे Punches स्वीकारले जात नाहीत. ओळखीची असेल तरच मंजूर करा (ती सध्याच्या संस्थेशी — <?= h(activeOrgName()) ?> — जोडली जाईल).</div></div>
  <table class="rtable">
    <thead><tr><th>Serial Number</th><th>पहिल्यांदा दिसलं</th><th>शेवटचं Connect</th><th></th></tr></thead>
    <tbody>
      <?php foreach ($pendingDevices as $pd): ?>
        <tr>
          <td class="font-mono" style="font-weight:600;"><?= h($pd['serial_number']) ?></td>
          <td><?= $pd['created_at'] ? date('d M Y, h:i A', strtotime($pd['created_at'])) : '—' ?></td>
          <td><?= $pd['last_seen_at'] ? date('d M Y, h:i A', strtotime($pd['last_seen_at'])) : '—' ?></td>
          <td style="white-space:nowrap;">
            <form method="POST" style="display:inline;">
              <input type="hidden" name="device_id" value="<?= (int)$pd['id'] ?>">
              <button type="submit" name="action" value="approve" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:4px 10px;">मंजूर करा</button>
              <button type="submit" name="action" value="reject" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" onclick="return confirm('ही नोंद काढून टाकायची?');">नाकारा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Serial Number</th><th>नाव</th><th>Campus</th><th>शेवटचं Connect</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($devices)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलंही Device Connect झालेलं नाही — Machine Settings मध्ये वरचा पत्ता टाकून बघा.</td></tr><?php endif; ?>
      <?php foreach ($devices as $d): ?>
        <tr>
          <td class="font-mono" style="font-weight:600;"><?= h($d['serial_number']) ?></td>
          <td><?= h($d['device_name'] ?: '—') ?></td>
          <td><?= h($d['campus_name'] ?: 'Trust-स्तरीय') ?></td>
          <td>
            <?= $d['last_seen_at'] ? date('d M Y, h:i A', strtotime($d['last_seen_at'])) : '—' ?>
            <?php if ($d['last_seen_at'] && strtotime($d['last_seen_at']) > strtotime('-2 minutes')): ?>
              <span class="status-chip done" style="font-size:9.5px;">🟢 Online</span>
            <?php endif; ?>
          </td>
          <td>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#editModal<?= $d['id'] ?>">Edit</button>
          </td>
        </tr>
        <div class="modal fade" id="editModal<?= $d['id'] ?>" tabindex="-1">
          <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
            <form method="POST">
              <input type="hidden" name="device_id" value="<?= $d['id'] ?>">
              <div class="modal-body" style="padding:20px;">
                <label class="form-label-custom">Device चं नाव/ठिकाण</label>
                <input type="text" name="device_name" class="form-control-custom mb-3" value="<?= h($d['device_name']) ?>" placeholder="उदा. मुख्य प्रवेशद्वार">
                <label class="form-label-custom">Campus</label>
                <select name="campus_id" class="form-select-custom">
                  <option value="">-- Trust-स्तरीय --</option>
                  <?php foreach ($campusesForDevice as $c): ?><option value="<?= $c['id'] ?>" <?= (int)$d['campus_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?>
                </select>
              </div>
              <div class="modal-footer" style="border-top:1px solid var(--line);">
                <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
              </div>
            </form>
          </div></div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
