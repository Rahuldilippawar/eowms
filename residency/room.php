<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$locationId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
$isMgmt = hasPermission('manage_residency_all_depts');
$isResHod = hasCategory('hod') && $userDeptCode === 'RES';

if (!$isMgmt && $userDeptCode !== 'RES') {
    http_response_code(403);
    die('तुम्हाला ही माहिती पाहण्याची परवानगी नाही.');
}
$canManage = hasRole('super_admin') || $userDeptCode === 'RES' || $isMgmt;
$canVacate = hasRole('super_admin') || $isResHod;

$stmt = $pdo->prepare("SELECT ml.*, b.name AS building_name FROM maintenance_locations ml LEFT JOIN buildings b ON b.id = ml.building_id WHERE ml.id = ? AND ml.organization_id = ?");
$stmt->execute([$locationId, $orgId]);
$room = $stmt->fetch();
if (!$room) { die('खोली सापडली नाही.'); }

// ---------------- POST actions (kept before header.php on purpose) ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'set_residential' && $canManage) {
        // ही जागा निवासी खोली आहे का (Office/Kitchen रहिवासी यादीत दिसू नयेत)
        $isRes = ($_POST['is_residential'] ?? '') === '1' ? 1 : 0;
        if (!$isRes) {
            $occChk = $pdo->prepare("SELECT COUNT(*) FROM residency_occupants WHERE location_id = ? AND status = 'active'");
            $occChk->execute([$locationId]);
            if ((int)$occChk->fetchColumn() > 0) {
                $_SESSION['form_error'] = 'या खोलीत सध्या रहिवासी आहेत — आधी त्यांना Vacate करा, मग "निवासी नाही" करा.';
                header('Location: room.php?id=' . $locationId);
                exit;
            }
        }
        $pdo->prepare("UPDATE maintenance_locations SET is_residential = ? WHERE id = ? AND organization_id = ?")->execute([$isRes, $locationId, $orgId]);
        $_SESSION['flash'] = $isRes ? 'ही जागा निवासी खोली म्हणून चिन्हांकित झाली.' : 'ही जागा आता निवासी यादीत दिसणार नाही.';
    } elseif ($action === 'set_capacity' && $canManage) {
        $capacity = ($_POST['capacity'] ?? '') !== '' ? max(1, (int)$_POST['capacity']) : null;
        $pdo->prepare("UPDATE maintenance_locations SET capacity = ? WHERE id = ? AND organization_id = ?")->execute([$capacity, $locationId, $orgId]);
        $_SESSION['flash'] = 'खोलीची क्षमता जतन झाली.';
    } elseif ($action === 'add_occupant' && $canManage) {
        if (empty($room['is_residential'])) {
            $_SESSION['form_error'] = 'ही जागा निवासी खोली म्हणून चिन्हांकित नाही — आधी "निवासी खोली" करा.';
            header('Location: room.php?id=' . $locationId);
            exit;
        }
        $occupantType = ($_POST['occupant_type'] ?? 'staff') === 'family' ? 'family' : 'staff';
        $relation = trim($_POST['relation'] ?? '') ?: null;
        $age = ($_POST['age'] ?? '') !== '' ? (int)$_POST['age'] : null;
        $movedInDate = ($_POST['moved_in_date'] ?? '') ?: date('Y-m-d');

        // खोलीची क्षमता आधीच भरलेली आहे का ते तपासतो — HOD ने ठरवलेल्या
        // मर्यादेपेक्षा जास्त लोकांना एका Room मध्ये टाकता येऊ नये.
        $capCheckStmt = $pdo->prepare("
            SELECT ml.capacity, (SELECT COUNT(*) FROM residency_occupants WHERE location_id = ml.id AND status = 'active') AS occupied
            FROM maintenance_locations ml WHERE ml.id = ?
        ");
        $capCheckStmt->execute([$locationId]);
        $capInfo = $capCheckStmt->fetch();
        $isFull = $capInfo && $capInfo['capacity'] !== null && $capInfo['occupied'] >= $capInfo['capacity'];

        if ($isFull) {
            $_SESSION['form_error'] = "ही खोली आधीच पूर्ण भरलेली आहे (क्षमता: {$capInfo['capacity']}) — आधी कोणाला तरी Vacate करा, किंवा क्षमता वाढवा.";
            header('Location: room.php?id=' . $locationId);
            exit;
        }

        // निवडलेला कर्मचारी याच संस्थेचा, Active व सध्या कुठेही राहत नसलेला असावा
        $validHousable = function ($uid) use ($pdo, $orgId) {
            $chk = $pdo->prepare("SELECT COUNT(*) FROM users WHERE id = ? AND organization_id = ? AND is_active = 1
                AND id NOT IN (SELECT user_id FROM residency_occupants WHERE status = 'active' AND user_id IS NOT NULL)");
            $chk->execute([$uid, $orgId]);
            return (int)$chk->fetchColumn() > 0;
        };
        $pickedUserId = $occupantType === 'staff' ? (int)($_POST['user_id'] ?? 0)
            : ((($_POST['family_is_employee'] ?? '') === '1') ? (int)($_POST['family_user_id'] ?? 0) : 0);
        if ($pickedUserId && !$validHousable($pickedUserId)) {
            $_SESSION['form_error'] = 'हा कर्मचारी अवैध आहे किंवा आधीच दुसऱ्या खोलीत राहतो.';
            header('Location: room.php?id=' . $locationId);
            exit;
        }

        if ($occupantType === 'staff') {
            $staffUserId = (int)($_POST['user_id'] ?? 0);
            if ($staffUserId) {
                $pdo->prepare("INSERT INTO residency_occupants (organization_id, location_id, occupant_type, user_id, relation, age, moved_in_date, created_by) VALUES (?, ?, 'staff', ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $locationId, $staffUserId, $relation ?: 'स्वतः', $age, $movedInDate, $user['id']]);
                logActivity('रहिवासी नोंद जोडली', 'residency_occupant', $pdo->lastInsertId(), "खोली: {$room['name']}");
                $_SESSION['flash'] = 'रहिवासी नोंदवला गेला.';
            }
        } else {
            // कुटुंबीय सदस्य — तो/ती स्वतः संस्थेचा कर्मचारी असेल तर Dropdown
            // मधून निवडलेला user_id वापरतो (नाव आपोआप त्याच्या Profile वरून
            // येईल), नसेल तर टाईप केलेलं नाव वापरतो.
            $isFamilyEmployee = ($_POST['family_is_employee'] ?? '') === '1';
            $familyUserId = $isFamilyEmployee ? (int)($_POST['family_user_id'] ?? 0) : null;
            $familyName = trim($_POST['family_name'] ?? '');

            if ($isFamilyEmployee && $familyUserId) {
                $pdo->prepare("INSERT INTO residency_occupants (organization_id, location_id, occupant_type, user_id, relation, age, moved_in_date, created_by) VALUES (?, ?, 'family', ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $locationId, $familyUserId, $relation, $age, $movedInDate, $user['id']]);
                logActivity('कुटुंबीय सदस्य (कर्मचारी) नोंदवला', 'residency_occupant', $pdo->lastInsertId(), "खोली: {$room['name']}");
                $_SESSION['flash'] = 'कुटुंबीय सदस्य नोंदवला गेला.';
            } elseif (!$isFamilyEmployee && $familyName) {
                $pdo->prepare("INSERT INTO residency_occupants (organization_id, location_id, occupant_type, name, relation, age, moved_in_date, created_by) VALUES (?, ?, 'family', ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $locationId, $familyName, $relation, $age, $movedInDate, $user['id']]);
                logActivity('कुटुंबीय सदस्य नोंदवला', 'residency_occupant', $pdo->lastInsertId(), "खोली: {$room['name']}");
                $_SESSION['flash'] = 'कुटुंबीय सदस्य नोंदवला गेला.';
            }
        }
    } elseif ($action === 'vacate' && $canVacate) {
        $occupantId = (int)($_POST['occupant_id'] ?? 0);
        $remark = trim($_POST['vacated_remark'] ?? '');
        if ($occupantId && $remark) {
            $pdo->prepare("UPDATE residency_occupants SET status='vacated', vacated_date=CURDATE(), vacated_remark=?, vacated_by=? WHERE id=? AND location_id=?")
                ->execute([$remark, $user['id'], $occupantId, $locationId]);
            logActivity('रहिवासी रिकामी नोंद (Vacate)', 'residency_occupant', $occupantId, $remark);
            $_SESSION['flash'] = 'नोंद रिकामी (vacate) केली गेली — खोली आता रिकामी दिसेल (जर इतर कोणी राहत नसेल तर).';
        } else {
            $_SESSION['form_error'] = 'Vacate करण्यासाठी कारण/टिप्पणी (remark) लिहिणे आवश्यक आहे.';
        }
    } elseif ($action === 'add_item' && $canManage) {
        $itemName = trim($_POST['item_name'] ?? '');
        $ownership = $_POST['ownership'] ?? 'संस्थेचे';
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $remarks = trim($_POST['item_remarks'] ?? '') ?: null;
        if ($itemName) {
            $pdo->prepare("INSERT INTO residency_room_items (organization_id, location_id, item_name, ownership, quantity, remarks, added_by) VALUES (?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $locationId, $itemName, $ownership, $quantity, $remarks, $user['id']]);
            $_SESSION['flash'] = 'साहित्य नोंदवले गेले.';
        }
    } elseif ($action === 'delete_item' && $canManage) {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $pdo->prepare("DELETE FROM residency_room_items WHERE id = ? AND location_id = ?")->execute([$itemId, $locationId]);
        $_SESSION['flash'] = 'साहित्य नोंद काढली गेली.';
    }

    header('Location: room.php?id=' . $locationId);
    exit;
}

// ---------------- GET rendering ----------------
$pageTitle = $room['name'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("
    SELECT ro.*, u.name AS staff_name, u.designation AS staff_designation, vb.name AS vacated_by_name
    FROM residency_occupants ro
    LEFT JOIN users u ON u.id = ro.user_id
    LEFT JOIN users vb ON vb.id = ro.vacated_by
    WHERE ro.location_id = ? ORDER BY ro.status ASC, ro.created_at DESC
");
$stmt->execute([$locationId]);
$occupants = $stmt->fetchAll();
$activeOccupants = array_filter($occupants, fn($o) => $o['status'] === 'active');
$vacatedOccupants = array_filter($occupants, fn($o) => $o['status'] === 'vacated');

$stmt = $pdo->prepare("SELECT ri.*, u.name AS added_by_name FROM residency_room_items ri LEFT JOIN users u ON u.id = ri.added_by WHERE ri.location_id = ? ORDER BY ri.created_at DESC");
$stmt->execute([$locationId]);
$items = $stmt->fetchAll();

// Staff not already living somewhere active, for the "add occupant" dropdown
// संस्थेतला कोणीही Staff — जो सध्या कुठेच (Staff किंवा Family म्हणून)
// Active राहत नाही — "रहिवासी जोडा" च्या Dropdown साठी (Family Member
// Employee असेल तरीही इथूनच निवडता येईल).
$stmt = $pdo->prepare("
    SELECT id, name, designation FROM users
    WHERE organization_id = ? AND is_active = 1
      AND id NOT IN (SELECT user_id FROM residency_occupants WHERE status = 'active' AND user_id IS NOT NULL)
    ORDER BY name
");
$stmt->execute([$orgId]);
$availableStaff = $stmt->fetchAll();

$capacity = $room['capacity'];
$isFullCapacity = $capacity !== null && count($activeOccupants) >= $capacity;
$isVacant = empty($activeOccupants);
?>

<div class="page-eyebrow"><?= h($room['building_name'] ?? '') ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">🏠 <?= h($room['name']) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">
      <?= h($room['location_code']) ?> ·
      <span class="status-chip <?= $isVacant ? 'done' : ($isFullCapacity ? 'overdue' : 'pending') ?>">
        <?= $isVacant ? 'रिकामी (Vacant)' : ($isFullCapacity ? '🔴 पूर्ण भरलेली' : '🟡 अंशतः भरलेली') ?>
      </span>
      <?php if ($capacity !== null): ?><span style="font-weight:600;"> · <?= count($activeOccupants) ?>/<?= $capacity ?> जागा भरलेल्या</span><?php endif; ?>
    </div>
  </div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व खोल्या</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php if ($canManage): ?>
<div class="panel mb-3" style="padding:14px 20px;<?= empty($room['is_residential']) ? 'border:1.5px solid var(--amber);' : '' ?>">
  <form method="POST" class="d-flex align-items-center gap-2 flex-wrap">
    <input type="hidden" name="action" value="set_residential">
    <?php if (!empty($room['is_residential'])): ?>
      <span style="font-size:12.5px;">🏠 ही जागा <b>निवासी खोली</b> आहे (रहिवासी यादी/रिक्त खोल्यांत मोजली जाते).</span>
      <input type="hidden" name="is_residential" value="0">
      <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:6px 14px;">निवासी नाही (Office/Kitchen इ.)</button>
    <?php else: ?>
      <span style="font-size:12.5px;">⚠️ ही जागा <b>निवासी खोली नाही</b> — रहिवासी यादीत दिसत नाही व इथे रहिवासी जोडता येत नाहीत.</span>
      <input type="hidden" name="is_residential" value="1">
      <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 14px;">निवासी खोली म्हणून चिन्हांकित करा</button>
    <?php endif; ?>
  </form>
</div>
<div class="panel mb-3" style="padding:14px 20px;">
  <form method="POST" class="d-flex align-items-center gap-2">
    <input type="hidden" name="action" value="set_capacity">
    <label class="form-label-custom mb-0" style="white-space:nowrap;">🛏️ या खोलीची कमाल क्षमता (किती लोक राहू शकतात)</label>
    <input type="number" name="capacity" class="form-control-custom" style="width:100px;" min="1" value="<?= h($capacity) ?>" placeholder="उदा. 4">
    <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;padding:6px 16px;">जतन करा</button>
  </form>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">👥 सध्याचे रहिवासी (<?= count($activeOccupants) ?>)</div></div>
      <div style="padding:14px 20px;">
        <?php if (empty($activeOccupants)): ?>
          <p style="font-size:13px;color:var(--text-dim);text-align:center;padding:14px;">ही खोली सध्या रिकामी आहे.</p>
        <?php endif; ?>
        <?php foreach ($activeOccupants as $o): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--line);">
            <div>
              <div style="font-weight:600;font-size:13.5px;">
                <?= !empty($o['user_id']) ? h($o['staff_name']) : h($o['name']) ?>
                <span style="font-size:11px;color:var(--text-dim);font-weight:400;">— <?= h($o['relation'] ?? '—') ?><?= $o['age'] ? ', वय ' . $o['age'] : '' ?></span>
              </div>
              <div style="font-size:11px;color:var(--text-dim);">
                <?= $o['occupant_type'] === 'staff' ? h($o['staff_designation'] ?? '') : (!empty($o['user_id']) ? h($o['staff_designation'] ?? '') . ' · कुटुंबीय' : 'कुटुंबीय सदस्य') ?>
                · राहायला आले: <?= $o['moved_in_date'] ? date('d M Y', strtotime($o['moved_in_date'])) : '—' ?>
              </div>
            </div>
            <?php if ($canVacate): ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:11.5px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#vacateModal<?= $o['id'] ?>">Vacate करा</button>
            <?php endif; ?>
          </div>

          <div class="modal fade" id="vacateModal<?= $o['id'] ?>" tabindex="-1">
            <div class="modal-dialog">
              <div class="modal-content" style="border-radius:14px;">
                <form method="POST">
                  <input type="hidden" name="action" value="vacate">
                  <input type="hidden" name="occupant_id" value="<?= $o['id'] ?>">
                  <div class="modal-body" style="padding:22px;">
                    <div style="font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-bottom:10px;">
                      <?= !empty($o['user_id']) ? h($o['staff_name']) : h($o['name']) ?> — Vacate करायचं?
                    </div>
                    <label class="form-label-custom">कारण/टिप्पणी (आवश्यक)</label>
                    <textarea name="vacated_remark" class="form-control-custom" rows="3" required placeholder="उदा. कर्मचारी नोकरी सोडून गेला, बदली झाली, इ."></textarea>
                  </div>
                  <div class="modal-footer" style="border-top:1px solid var(--line);">
                    <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                    <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;">Vacate करा</button>
                  </div>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <form method="POST" style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);" id="addOccupantForm">
            <input type="hidden" name="action" value="add_occupant">
            <div class="d-flex gap-2 mb-2">
              <label style="display:flex;align-items:center;gap:5px;font-size:12.5px;"><input type="radio" name="occupant_type" value="staff" checked onclick="toggleOccupantFields(true)"> कर्मचारी</label>
              <label style="display:flex;align-items:center;gap:5px;font-size:12.5px;"><input type="radio" name="occupant_type" value="family" onclick="toggleOccupantFields(false)"> कुटुंबीय सदस्य</label>
            </div>
            <div id="staffField">
              <label class="form-label-custom">कर्मचारी निवडा</label>
              <select name="user_id" class="form-select-custom mb-2">
                <option value="">-- निवडा --</option>
                <?php foreach ($availableStaff as $s): ?>
                  <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div id="familyField" style="display:none;">
              <label style="display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:8px;">
                <input type="checkbox" id="familyIsEmployeeCheck" name="family_is_employee" value="1" onclick="toggleFamilyEmployeeFields(this.checked)">
                हा/ही व्यक्ती स्वतः संस्थेचा/ची कर्मचारी आहे का?
              </label>
              <div id="familyEmployeeDropdown" style="display:none;">
                <label class="form-label-custom">कर्मचारी निवडा</label>
                <select name="family_user_id" class="form-select-custom mb-2">
                  <option value="">-- निवडा --</option>
                  <?php foreach ($availableStaff as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= h($s['name']) ?> <?= $s['designation'] ? '(' . h($s['designation']) . ')' : '' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div id="familyNameField">
                <label class="form-label-custom">नाव</label>
                <input type="text" name="family_name" class="form-control-custom mb-2">
              </div>
            </div>
            <div class="row g-2">
              <div class="col-md-4"><label class="form-label-custom">Relation</label><input type="text" name="relation" class="form-control-custom" placeholder="स्वतः/पत्नी/मुलगा"></div>
              <div class="col-md-4"><label class="form-label-custom">वय</label><input type="number" name="age" class="form-control-custom"></div>
              <div class="col-md-4"><label class="form-label-custom">राहायला आल्याची तारीख</label><input type="date" name="moved_in_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
            </div>
            <button type="submit" class="btn mt-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">रहिवासी जोडा</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($vacatedOccupants)): ?>
      <div class="panel">
        <div class="panel-head"><div class="title">📜 आधीचे रहिवासी (इतिहास)</div></div>
        <div style="padding:14px 20px;">
          <?php foreach ($vacatedOccupants as $o): ?>
            <div style="padding:8px 0;border-bottom:1px solid var(--line);font-size:12.5px;">
              <strong><?= !empty($o['user_id']) ? h($o['staff_name']) : h($o['name']) ?></strong>
              <span style="color:var(--text-dim);"> — <?= $o['moved_in_date'] ? date('d M Y', strtotime($o['moved_in_date'])) : '?' ?> ते <?= $o['vacated_date'] ? date('d M Y', strtotime($o['vacated_date'])) : '?' ?></span>
              <div style="color:var(--text-dim);font-style:italic;margin-top:2px;">"<?= h($o['vacated_remark']) ?>" — <?= h($o['vacated_by_name'] ?? '') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">🛋️ खोलीतले साहित्य</div></div>
      <div style="padding:14px 20px;">
        <?php if (empty($items)): ?><p style="font-size:12.5px;color:var(--text-dim);">कोणतेही साहित्य नोंदवलेले नाही.</p><?php endif; ?>
        <?php foreach ($items as $it): ?>
          <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--line);">
            <div>
              <div style="font-size:13px;font-weight:500;"><?= h($it['item_name']) ?> <span style="color:var(--text-dim);font-weight:400;">× <?= $it['quantity'] ?></span></div>
              <span class="status-chip <?= $it['ownership'] === 'संस्थेचे' ? 'done' : 'pending' ?>" style="font-size:10.5px;"><?= h($it['ownership']) ?></span>
            </div>
            <?php if ($canManage): ?>
              <form method="POST" onsubmit="return confirm('ही नोंद काढायची?');">
                <input type="hidden" name="action" value="delete_item">
                <input type="hidden" name="item_id" value="<?= $it['id'] ?>">
                <button type="submit" style="border:none;background:none;color:var(--rose);font-size:11.5px;cursor:pointer;">काढा</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <?php if ($canManage): ?>
          <form method="POST" style="margin-top:14px;padding-top:14px;border-top:1px solid var(--line);">
            <input type="hidden" name="action" value="add_item">
            <label class="form-label-custom">साहित्याचे नाव</label>
            <input type="text" name="item_name" class="form-control-custom mb-2" placeholder="उदा. पंखा, कॉट, कपाट" required>
            <div class="row g-2 mb-2">
              <div class="col-6">
                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;"><input type="radio" name="ownership" value="संस्थेचे" checked> संस्थेचे</label>
              </div>
              <div class="col-6">
                <label style="display:flex;align-items:center;gap:6px;font-size:12.5px;"><input type="radio" name="ownership" value="वैयक्तिक"> कर्मचाऱ्याचे वैयक्तिक</label>
              </div>
            </div>
            <label class="form-label-custom">संख्या</label>
            <input type="number" name="quantity" class="form-control-custom mb-2" value="1" min="1">
            <button type="submit" class="btn w-100" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px;">साहित्य जोडा</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function toggleOccupantFields(isStaff) {
  document.getElementById('staffField').style.display = isStaff ? 'block' : 'none';
  document.getElementById('familyField').style.display = isStaff ? 'none' : 'block';
}
function toggleFamilyEmployeeFields(isEmployee) {
  document.getElementById('familyEmployeeDropdown').style.display = isEmployee ? 'block' : 'none';
  document.getElementById('familyNameField').style.display = isEmployee ? 'none' : 'block';
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
