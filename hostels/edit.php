<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_hostels', '../');
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Hostel याच संस्थेचं आहे याची खात्री — नाहीतर दुसऱ्या संस्थेचा Staff-Allocation पुसला जाऊ शकतो
    $ownChk = $pdo->prepare("SELECT id FROM hostels WHERE id = ? AND organization_id = ?");
    $ownChk->execute([$id, $orgId]);
    if (!$ownChk->fetch()) { http_response_code(404); die(t('s430')); }
    $name = trim($_POST['name'] ?? '');
    $campusId = (int)($_POST['campus_id'] ?? 0);
    $capacity = ($_POST['capacity'] ?? '') !== '' ? (int)$_POST['capacity'] : null;
    $isActive = (int)($_POST['is_active'] ?? 1);
    $staffIds = is_array($_POST['staff_ids'] ?? null) ? $_POST['staff_ids'] : [];

    $stmt = $pdo->prepare("UPDATE hostels SET name=?, campus_id=?, capacity=?, is_active=? WHERE id=? AND organization_id=?");
    $stmt->execute([$name, $campusId, $capacity, $isActive, $id, $orgId]);

    // Reset and re-save staff allocation — simplest reliable way to keep it in sync with checkboxes
    $pdo->prepare("DELETE hs FROM hostel_staff hs JOIN hostels h ON h.id = hs.hostel_id WHERE hs.hostel_id = ? AND h.organization_id = ?")->execute([$id, $orgId]);
    if (!empty($staffIds)) {
        $insert = $pdo->prepare("INSERT INTO hostel_staff (hostel_id, user_id) SELECT ?, id FROM users WHERE id = ? AND organization_id = ?");
        foreach ($staffIds as $staffId) {
            $insert->execute([$id, (int)$staffId, $orgId]);
        }
    }

    $_SESSION['flash'] = t('s479');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM hostels WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$hostel = $stmt->fetch();
if (!$hostel) { die(t('s430')); }

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$campuses = $stmt->fetchAll();

// All staff belonging to the "Hostel" department (code=HOS) — candidates for allocation
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation FROM users u
    JOIN departments d ON d.id = u.department_id
    WHERE d.organization_id = ? AND d.code = 'HOS' AND u.is_active = 1
    ORDER BY u.name
");
$stmt->execute([$orgId]);
$hostelStaffPool = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT user_id FROM hostel_staff WHERE hostel_id = ?");
$stmt->execute([$id]);
$allocatedStaffIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = t('s480');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title"><?= h($hostel['name']) ?> <?= h(t('s11')) ?></div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title"><?= h(t('s200')) ?></div></div>
  <form method="POST" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $hostel['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s37')) ?></label>
        <input type="text" name="name" class="form-control-custom" value="<?= h($hostel['name']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s8')) ?></label>
        <select name="campus_id" class="form-select-custom" required>
          <?php foreach ($campuses as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $hostel['campus_id'] == $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s24')) ?></label>
        <input type="number" name="capacity" class="form-control-custom" value="<?= h($hostel['capacity']) ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $hostel['is_active'] ? 'selected' : '' ?>><?= h(t('s4')) ?></option>
          <option value="0" <?= !$hostel['is_active'] ? 'selected' : '' ?>><?= h(t('s5')) ?></option>
        </select>
      </div>

      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s201')) ?></label>
        <?php if (empty($hostelStaffPool)): ?>
          <div style="font-size:12.5px;color:var(--text-dim);"><?= h(t('s202')) ?></div>
        <?php endif; ?>
        <div class="row g-2">
          <?php foreach ($hostelStaffPool as $staff): ?>
            <div class="col-md-4">
              <label style="display:flex;align-items:center;gap:8px;font-size:13px;background:var(--canvas);padding:8px 12px;border-radius:8px;">
                <input type="checkbox" name="staff_ids[]" value="<?= $staff['id'] ?>" <?= in_array($staff['id'], $allocatedStaffIds) ? 'checked' : '' ?>>
                <?= h($staff['name']) ?> <span style="color:var(--text-dim);font-size:11px;">(<?= h($staff['designation']) ?>)</span>
              </label>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
