<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_maintenance_settings', '../');
$user = currentUser();

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add_building'])) {
        $name = trim($_POST['building_name'] ?? '');
        $campusId = ($_POST['building_campus_id'] ?? '') !== '' ? (int)$_POST['building_campus_id'] : null;
        if ($name) {
            $pdo->prepare("INSERT INTO buildings (organization_id, campus_id, name, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$orgId, $campusId, $name, $user['id']]);
            logActivity('इमारत जोडली', 'building', $pdo->lastInsertId(), $name);
            $_SESSION['flash'] = "'$name' इमारत जोडली गेली.";
        }
    } elseif (isset($_POST['delete_building_id'])) {
        $bid = (int)$_POST['delete_building_id'];
        $pdo->prepare("DELETE FROM buildings WHERE id = ? AND organization_id = ?")->execute([$bid, $orgId]);
        $_SESSION['flash'] = 'इमारत काढली गेली (त्यातल्या खोल्या राहतील, फक्त इमारत-नाव रिकामं होईल).';
    } elseif (isset($_POST['add_location'])) {
        $name = trim($_POST['name'] ?? '');
        $buildingId = ($_POST['building_id'] ?? '') !== '' ? (int)$_POST['building_id'] : null;
        $floor = (int)($_POST['floor'] ?? 0);
        $locationCode = trim($_POST['location_code'] ?? '');
        if ($name && $locationCode) {
            try {
                $pdo->prepare("INSERT INTO maintenance_locations (organization_id, building_id, floor, name, location_code, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $buildingId, $floor, $name, $locationCode, $user['id']]);
                logActivity('जागा यादीत जोडली', 'maintenance_location', $pdo->lastInsertId(), $name);
                $_SESSION['flash'] = "'$name' जागा यादीत जोडली गेली.";
            } catch (PDOException $e) {
                $_SESSION['form_error'] = "'$locationCode' हा code आधीच वापरला आहे — वेगळा code द्या.";
            }
        }
    } elseif (isset($_POST['toggle_id'])) {
        $tid = (int)$_POST['toggle_id'];
        $pdo->prepare("UPDATE maintenance_locations SET is_active = 1 - is_active WHERE id = ? AND organization_id = ?")->execute([$tid, $orgId]);
        $_SESSION['flash'] = 'स्थिती बदलली गेली.';
    } elseif (isset($_POST['delete_id'])) {
        $did = (int)$_POST['delete_id'];
        $pdo->prepare("DELETE FROM maintenance_locations WHERE id = ? AND organization_id = ?")->execute([$did, $orgId]);
        $_SESSION['flash'] = 'जागा यादीतून कायमची काढली गेली (त्या जागेच्या सगळ्या नोंदीही गेल्या).';
    } elseif (isset($_POST['edit_id'])) {
        $eid = (int)$_POST['edit_id'];
        $name = trim($_POST['edit_name'] ?? '');
        $buildingId = ($_POST['edit_building_id'] ?? '') !== '' ? (int)$_POST['edit_building_id'] : null;
        $floor = (int)($_POST['edit_floor'] ?? 0);
        if ($name) {
            $pdo->prepare("UPDATE maintenance_locations SET name=?, building_id=?, floor=? WHERE id=? AND organization_id=?")
                ->execute([$name, $buildingId, $floor, $eid, $orgId]);
            $_SESSION['flash'] = 'जागा संपादित केली.';
        }
    }
    header('Location: locations.php');
    exit;
}

$pageTitle = 'इमारत व खोली यादी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$campuses = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT b.*, c.name AS campus_name, (SELECT COUNT(*) FROM maintenance_locations WHERE building_id = b.id) AS room_count
    FROM buildings b LEFT JOIN campuses c ON c.id = b.campus_id
    WHERE b.organization_id = ? ORDER BY c.name, b.name
");
$stmt->execute([$orgId]);
$buildings = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT ml.*, b.name AS building_name, (SELECT COUNT(*) FROM maintenance_logs WHERE location_id = ml.id) AS log_count
    FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id
    WHERE ml.organization_id = ? ORDER BY b.name, ml.name
");
$stmt->execute([$orgId]);
$locations = $stmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">🏢 इमारत व खोली यादी</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">देखभाल नोंदीसाठी वापरली जाणारी इमारत + खोली यादी — staff ला Building निवडून मग Room निवडता येईल</div>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>देखभाल नोंदवही कडे परत</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">🏢 नवीन इमारत जोडा</div></div>
      <form method="POST" style="padding:18px 20px;">
        <input type="hidden" name="add_building" value="1">
        <label class="form-label-custom">इमारतीचे नाव</label>
        <input type="text" name="building_name" class="form-control-custom mb-2" placeholder="उदा. मुख्य इमारत, Block A" required>
        <label class="form-label-custom">कॅम्पस</label>
        <select name="building_campus_id" class="form-select-custom mb-2">
          <option value="">-- निवडा --</option>
          <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">इमारत जोडा</button>
      </form>

      <div style="padding:0 20px 18px;">
        <?php foreach ($buildings as $b): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-top:1px solid var(--line);font-size:12.5px;">
            <div><strong><?= h($b['name']) ?></strong> <span style="color:var(--text-dim);"><?= $b['campus_name'] ? '· ' . h($b['campus_name']) : '' ?> · <?= $b['room_count'] ?> खोल्या</span></div>
            <form method="POST" onsubmit="return confirm('ही इमारत काढायची?');">
              <input type="hidden" name="delete_building_id" value="<?= $b['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;cursor:pointer;">Delete</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head"><div class="title">🚪 नवीन खोली/जागा जोडा</div></div>
      <form method="POST" style="padding:18px 20px;">
        <input type="hidden" name="add_location" value="1">
        <div class="row g-2">
          <div class="col-md-5">
            <label class="form-label-custom">खोलीचे नाव</label>
            <input type="text" name="name" class="form-control-custom" placeholder="उदा. Room 101" required>
          </div>
          <div class="col-md-3">
            <label class="form-label-custom">Code (Unique)</label>
            <input type="text" name="location_code" class="form-control-custom" placeholder="R101" required>
          </div>
          <div class="col-md-4">
            <label class="form-label-custom">इमारत</label>
            <select name="building_id" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($buildings as $b): ?><option value="<?= $b['id'] ?>"><?= h($b['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label-custom">मजला (Floor)</label>
            <select name="floor" class="form-select-custom">
              <?php for ($f = 0; $f <= 10; $f++): ?>
                <option value="<?= $f ?>"><?= $f === 0 ? 'तळमजला (Ground)' : $f . ' वा मजला' ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <button type="submit" class="btn mt-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">खोली जोडा</button>
      </form>
    </div>
  </div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>Code</th><th>खोली</th><th>इमारत</th><th>एकूण नोंदी</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($locations)): ?>
        <tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतीही खोली जोडलेली नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($locations as $loc): ?>
        <tr>
          <td class="font-mono"><?= h($loc['location_code']) ?></td>
          <td style="font-weight:500;"><a href="location-history.php?id=<?= $loc['id'] ?>" style="color:var(--rail);text-decoration:none;"><?= h($loc['name']) ?></a></td>
          <td><?= h($loc['building_name'] ?? '—') ?></td>
          <td><?= $loc['log_count'] ?></td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="toggle_id" value="<?= $loc['id'] ?>">
              <button type="submit" class="status-chip <?= $loc['is_active'] ? 'done' : 'overdue' ?>" style="border:none;cursor:pointer;"><?= $loc['is_active'] ? 'सक्रिय' : 'निष्क्रिय' ?></button>
            </form>
          </td>
          <td class="d-flex gap-2">
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#editModal<?= $loc['id'] ?>">Edit</button>
            <form method="POST" onsubmit="return confirm('ही जागा व तिच्या सगळ्या नोंदी कायमच्या काढायच्या?');">
              <input type="hidden" name="delete_id" value="<?= $loc['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;">Delete</button>
            </form>
          </td>
        </tr>

        <div class="modal fade" id="editModal<?= $loc['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST">
                <input type="hidden" name="edit_id" value="<?= $loc['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-bottom:14px;">खोली संपादित करा</div>
                  <label class="form-label-custom">नाव</label>
                  <input type="text" name="edit_name" class="form-control-custom mb-2" value="<?= h($loc['name']) ?>" required>
                  <label class="form-label-custom">इमारत</label>
                  <select name="edit_building_id" class="form-select-custom mb-2">
                    <option value="">-- निवडा --</option>
                    <?php foreach ($buildings as $b): ?>
                      <option value="<?= $b['id'] ?>" <?= $loc['building_id'] == $b['id'] ? 'selected' : '' ?>><?= h($b['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <label class="form-label-custom">मजला (Floor)</label>
                  <select name="edit_floor" class="form-select-custom">
                    <?php for ($f = 0; $f <= 10; $f++): ?>
                      <option value="<?= $f ?>" <?= (int)$loc['floor'] === $f ? 'selected' : '' ?>><?= $f === 0 ? 'तळमजला (Ground)' : $f . ' वा मजला' ?></option>
                    <?php endfor; ?>
                  </select>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;">जतन करा</button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
