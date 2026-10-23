<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_cattle', '../', 'approx_age_months');
require_once __DIR__ . '/helpers.php';
$user = currentUser();
$orgId = activeOrgId();
gsAutoPromoteCalves($pdo, $orgId); // वय पूर्ण झालेली वासरं → गाय/गोऱ्हा
$canManage = hasPermission('manage_gaushala') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_cattle') {
        $tagNumber = trim($_POST['tag_number'] ?? '') ?: null;
        $name = trim($_POST['name'] ?? '') ?: null;
        $breed = trim($_POST['breed'] ?? '') ?: null;
        $gender = in_array($_POST['gender'] ?? '', ['Male', 'Female'], true) ? $_POST['gender'] : 'Female';
        $animalCategory = in_array($_POST['animal_category'] ?? '', ['Cow','Calf','Young Bull','Bull/Ox'], true) ? $_POST['animal_category'] : 'Cow';
        if ($animalCategory === 'Cow') { $gender = 'Female'; }
        elseif ($animalCategory === 'Young Bull' || $animalCategory === 'Bull/Ox') { $gender = 'Male'; }
        $color = trim($_POST['color'] ?? '') ?: null;
        $dob = ($_POST['date_of_birth'] ?? '') ?: null;
        $approxAgeMonths = null;
        if (!$dob) {
            $ageYears = max(0, (int)($_POST['age_years'] ?? 0));
            $ageMonthsIn = max(0, (int)($_POST['age_months'] ?? 0));
            if ($ageYears > 0 || $ageMonthsIn > 0) { $approxAgeMonths = $ageYears * 12 + $ageMonthsIn; }
        }
        $acqType = in_array($_POST['acquisition_type'] ?? '', ['Birth','Donation','Purchase','Other'], true) ? $_POST['acquisition_type'] : 'Other';
        $acqDate = ($_POST['acquisition_date'] ?? '') ?: null;
        $sourceDetails = trim($_POST['source_details'] ?? '') ?: null;
        $campusId = (int)($_POST['campus_id'] ?? 0);
        $caretakerId = ($_POST['caretaker_id'] ?? '') !== '' ? (int)$_POST['caretaker_id'] : null;

        // Photo Upload (ऐच्छिक)
        $photoPath = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../assets/uploads/gaushala/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $safeName = uniqid('cattle_') . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $safeName)) {
                    $photoPath = 'assets/uploads/gaushala/' . $safeName;
                }
            }
        }

        if ($campusId) {
            try {
                $pdo->prepare("
                    INSERT INTO gaushala_cattle (organization_id, campus_id, tag_number, name, breed, gender, animal_category, color, date_of_birth, approx_age_months, acquisition_type, acquisition_date, source_details, caretaker_id, photo_path, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([$orgId, $campusId, $tagNumber, $name, $breed, $gender, $animalCategory, $color, $dob, $approxAgeMonths, $acqType, $acqDate, $sourceDetails, $caretakerId, $photoPath, $user['id']]);
                $_SESSION['flash'] = 'नवीन जनावर जोडलं गेलं.';
            } catch (PDOException $e) {
                $_SESSION['flash'] = (isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062) ? '⚠️ हा Tag/Batch Number आधीच वापरलेला आहे.' : '⚠️ नोंद जतन होऊ शकली नाही.';
            }
        }
    } elseif ($action === 'update_status') {
        $cattleId = (int)($_POST['cattle_id'] ?? 0);
        $status = in_array($_POST['status'] ?? '', ['active','sold','deceased','donated_out'], true) ? $_POST['status'] : 'active';
        $statusDate = ($_POST['status_date'] ?? '') ?: date('Y-m-d');
        $statusRemarks = trim($_POST['status_remarks'] ?? '') ?: null;
        $pdo->prepare("UPDATE gaushala_cattle SET status = ?, status_date = ?, status_remarks = ? WHERE id = ? AND organization_id = ?")
            ->execute([$status, $statusDate, $statusRemarks, $cattleId, $orgId]);
        $_SESSION['flash'] = 'स्थिती Update झाली.';
    } elseif ($action === 'update_identity') {
        $cattleId = (int)($_POST['cattle_id'] ?? 0);
        $newTag = trim($_POST['tag_number'] ?? '') ?: null;
        $newName = trim($_POST['name'] ?? '') ?: null;
        $newCategory = in_array($_POST['animal_category'] ?? '', ['Cow','Calf','Young Bull','Bull/Ox'], true) ? $_POST['animal_category'] : 'Cow';
        $gStmt = $pdo->prepare("SELECT gender FROM gaushala_cattle WHERE id = ? AND organization_id = ?");
        $gStmt->execute([$cattleId, $orgId]);
        $gRow = $gStmt->fetch();

        // Photo Update (ऐच्छिक — नवीन दिली तरच बदलतो)
        $newPhotoPath = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/../assets/uploads/gaushala/';
            if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $safeName = uniqid('cattle_') . '.' . $ext;
                if (move_uploaded_file($_FILES['photo']['tmp_name'], $uploadDir . $safeName)) {
                    $newPhotoPath = 'assets/uploads/gaushala/' . $safeName;
                }
            }
        }

        if ($gRow) {
            if (($newCategory === 'Cow' && $gRow['gender'] === 'Male') || (($newCategory === 'Young Bull' || $newCategory === 'Bull/Ox') && $gRow['gender'] === 'Female')) {
                $_SESSION['flash'] = '⚠️ लिंग आणि प्रकार जुळत नाहीत.';
            } else {
                try {
                    $pdo->prepare("UPDATE gaushala_cattle SET tag_number = ?, name = ?, animal_category = ?, photo_path = COALESCE(?, photo_path) WHERE id = ? AND organization_id = ?")
                        ->execute([$newTag, $newName, $newCategory, $newPhotoPath, $cattleId, $orgId]);
                    $_SESSION['flash'] = 'ओळख Update झाली.';
                } catch (PDOException $e) {
                    $_SESSION['flash'] = '⚠️ हा Tag/Batch Number आधीच वापरलेला आहे.';
                }
            }
        }
    } elseif ($action === 'add_health') {
        $cattleId = (int)($_POST['cattle_id'] ?? 0);
        $recordType = in_array($_POST['record_type'] ?? '', ['Vaccination','Illness','Treatment','Checkup','Deworming'], true) ? $_POST['record_type'] : 'Checkup';
        $description = trim($_POST['description'] ?? '');
        $vetName = trim($_POST['vet_name'] ?? '') ?: null;
        $recordDate = ($_POST['record_date'] ?? '') ?: date('Y-m-d');
        $nextDueDate = ($_POST['next_due_date'] ?? '') ?: null;
        $cost = ($_POST['cost'] ?? '') !== '' ? (float)$_POST['cost'] : null;
        $cattleOrgChk = $pdo->prepare("SELECT COUNT(*) FROM gaushala_cattle WHERE id = ? AND organization_id = ?");
        $cattleOrgChk->execute([$cattleId, $orgId]);
        if ($cattleId && $description && (int)$cattleOrgChk->fetchColumn() > 0) {
            $pdo->prepare("INSERT INTO gaushala_health_records (cattle_id, record_type, description, vet_name, record_date, next_due_date, cost, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$cattleId, $recordType, $description, $vetName, $recordDate, $nextDueDate, $cost, $user['id']]);
            $_SESSION['flash'] = 'आरोग्य-नोंद जोडली गेली.';
        }
    }
    header('Location: cattle.php' . (!empty($_GET['id']) ? '?id=' . (int)$_GET['id'] : ''));
    exit;
}

$pageTitle = 'गाय/जनावर Registry';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$viewCattleId = (int)($_GET['id'] ?? 0);

if ($viewCattleId):
    // एका जनावराची संपूर्ण Detail-View
    $cattleStmt = $pdo->prepare("
        SELECT gc.*, c.name AS campus_name, u.name AS caretaker_name, m.tag_number AS mother_tag, m.name AS mother_name
        FROM gaushala_cattle gc LEFT JOIN campuses c ON c.id = gc.campus_id
        LEFT JOIN users u ON u.id = gc.caretaker_id LEFT JOIN gaushala_cattle m ON m.id = gc.mother_cattle_id
        WHERE gc.id = ? AND gc.organization_id = ?
    ");
    $cattleStmt->execute([$viewCattleId, $orgId]);
    $cattle = $cattleStmt->fetch();
    if (!$cattle) { die('जनावर सापडलं नाही.'); }

    // वय — जन्म-दिनांक असेल तर त्यावरून; नसेल तर नोंदवलेल्या अंदाजे वयावरून (ते आपोआप पुढे सरकतं)
    $ageMonthsD = gsAgeMonths($cattle['date_of_birth'], $cattle['approx_age_months'], $cattle['created_at']);
    $ageDisplay = gsAgeText($ageMonthsD) . ((empty($cattle['date_of_birth']) && $ageMonthsD !== null) ? ' (अंदाजे)' : '');
    $categoryLabels = ['Cow' => '🐄 गाय', 'Calf' => '🐮 वासरू', 'Young Bull' => '🐂 गोऱ्हा', 'Bull/Ox' => '🐂 बैल'];

    $healthStmt = $pdo->prepare("SELECT * FROM gaushala_health_records WHERE cattle_id = ? ORDER BY record_date DESC");
    $healthStmt->execute([$viewCattleId]);
    $healthRecords = $healthStmt->fetchAll();

    $childrenStmt = $pdo->prepare("SELECT id, tag_number, name, animal_category, date_of_birth, approx_age_months, created_at FROM gaushala_cattle WHERE mother_cattle_id = ? AND organization_id = ? ORDER BY id");
    $childrenStmt->execute([$viewCattleId, $orgId]);
    $childrenList = $childrenStmt->fetchAll();

    $statusLabels = ['active' => '🟢 सक्रिय', 'sold' => '💰 विकलं', 'deceased' => '⚰️ मृत्यू', 'donated_out' => '🎁 दान दिलं'];
    $recordTypeLabels = ['Vaccination' => '💉 लसीकरण', 'Illness' => '🤒 आजार', 'Treatment' => '💊 उपचार', 'Checkup' => '🩺 तपासणी', 'Deworming' => '🪱 जंतनाशक'];
    ?>

    <div class="page-eyebrow">गौशाळा</div>
    <div class="d-flex justify-content-between align-items-start mb-1">
      <div class="page-title mb-0">🐮 <?= $cattle['tag_number'] ? h($cattle['tag_number']) : '(Tag नाही)' ?> <?= $cattle['name'] ? '— ' . h($cattle['name']) : '' ?></div>
      <a href="cattle.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
    </div>

    <?php if (!empty($_SESSION['flash'])): ?>
      <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
      <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>

    <div class="row g-3">
      <div class="col-lg-4">
        <?php if (!empty($cattle['photo_path'])): ?>
        <div class="panel mb-3" style="overflow:hidden;">
          <img src="../<?= h($cattle['photo_path']) ?>" style="width:100%;max-height:260px;object-fit:cover;display:block;">
        </div>
        <?php else: ?>
        <div class="panel mb-3" style="padding:40px 20px;text-align:center;color:var(--text-dim);">
          <i class="fa-solid fa-cow" style="font-size:32px;opacity:0.3;"></i>
          <div style="font-size:11.5px;margin-top:8px;">अजून फोटो नाही</div>
        </div>
        <?php endif; ?>
        <div class="panel">
          <table class="rtable" style="font-size:13px;">
            <tbody>
              <tr><td style="color:var(--text-dim);">प्रकार</td><td><?= $categoryLabels[$cattle['animal_category'] ?? 'Cow'] ?? h($cattle['animal_category']) ?></td></tr>
              <tr><td style="color:var(--text-dim);">जात</td><td><?= h($cattle['breed'] ?: '—') ?></td></tr>
              <tr><td style="color:var(--text-dim);">लिंग</td><td><?= $cattle['gender'] === 'Male' ? 'नर' : 'मादी' ?></td></tr>
              <tr><td style="color:var(--text-dim);">वय</td><td style="font-weight:700;color:var(--rail);"><?= h($ageDisplay) ?></td></tr>
              <tr><td style="color:var(--text-dim);">रंग</td><td><?= h($cattle['color'] ?: '—') ?></td></tr>
              <tr><td style="color:var(--text-dim);">जन्म-दिनांक</td><td><?= $cattle['date_of_birth'] ? date('d M Y', strtotime($cattle['date_of_birth'])) : '—' ?></td></tr>
              <tr><td style="color:var(--text-dim);">कसं आलं</td><td><?= h($cattle['acquisition_type']) ?><?= $cattle['source_details'] ? ' — ' . h($cattle['source_details']) : '' ?></td></tr>
              <tr><td style="color:var(--text-dim);">आई</td><td><?= $cattle['mother_cattle_id'] ? '<a href="cattle.php?id=' . (int)$cattle['mother_cattle_id'] . '">' . h(gsCattleTitle($cattle['mother_tag'], $cattle['mother_name'], $cattle['mother_cattle_id'])) . '</a>' : '—' ?></td></tr>
              <tr><td style="color:var(--text-dim);">काळजीवाहक</td><td><?= h($cattle['caretaker_name'] ?: '—') ?></td></tr>
              <tr><td style="color:var(--text-dim);">College/School</td><td><?= h($cattle['campus_name']) ?></td></tr>
              <tr><td style="color:var(--text-dim);">स्थिती</td><td><?= $statusLabels[$cattle['status']] ?></td></tr>
            </tbody>
          </table>
        </div>
        <?php if ($canManage): ?>
        <div class="panel mt-3">
          <div class="panel-head"><div class="title">स्थिती बदला</div></div>
          <form method="POST" style="padding:16px 20px;">
            <input type="hidden" name="action" value="update_status">
            <input type="hidden" name="cattle_id" value="<?= $cattle['id'] ?>">
            <select name="status" class="form-select-custom mb-2">
              <?php foreach ($statusLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $cattle['status'] === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select>
            <input type="date" name="status_date" class="form-control-custom mb-2" value="<?= date('Y-m-d') ?>">
            <input type="text" name="status_remarks" class="form-control-custom mb-2" placeholder="कारण/टिप्पणी">
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:8px;">जतन करा</button>
          </form>
        </div>
        <div class="panel mt-3">
          <div class="panel-head"><div class="title">🏷️ Batch/ओळख बदला</div></div>
          <form method="POST" enctype="multipart/form-data" style="padding:16px 20px;">
            <input type="hidden" name="action" value="update_identity">
            <input type="hidden" name="cattle_id" value="<?= $cattle['id'] ?>">
            <label class="form-label-custom">फोटो <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(बदलायची असेल तरच निवडा)</span></label>
            <input type="file" name="photo" accept="image/*" class="form-control-custom mb-2">
            <label class="form-label-custom">Batch/Tag क्र. <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(रिकामं = बिना Batch)</span></label>
            <input type="text" name="tag_number" class="form-control-custom mb-2" value="<?= h($cattle['tag_number'] ?? '') ?>">
            <label class="form-label-custom">नाव</label>
            <input type="text" name="name" class="form-control-custom mb-2" value="<?= h($cattle['name'] ?? '') ?>">
            <label class="form-label-custom">प्रकार <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(वासरू मोठं झालं की बदला)</span></label>
            <select name="animal_category" class="form-select-custom mb-2">
              <?php foreach ($categoryLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= ($cattle['animal_category'] ?? 'Cow') === $val ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
            </select>
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:8px;">जतन करा</button>
          </form>
        </div>
        <?php endif; ?>
        <?php if (!empty($childrenList)): ?>
        <div class="panel mt-3">
          <div class="panel-head"><div class="title">🍼 हिची वासरं (<?= count($childrenList) ?>)</div></div>
          <?php foreach ($childrenList as $ch): $chAge = gsAgeText(gsAgeMonths($ch['date_of_birth'], $ch['approx_age_months'], $ch['created_at'])); ?>
            <a href="cattle.php?id=<?= $ch['id'] ?>" style="display:flex;justify-content:space-between;padding:10px 20px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;font-size:12.5px;">
              <span><?= h(gsCattleTitle($ch['tag_number'], $ch['name'], $ch['id'])) ?></span>
              <span style="color:var(--text-dim);"><?= h($categoryLabels[$ch['animal_category'] ?? 'Cow'] ?? '') ?> · <?= h($chAge) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-8">
        <div class="panel">
          <div class="panel-head">
            <div class="title">🩺 आरोग्य Timeline</div>
            <?php if ($canManage): ?><button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:6px;font-size:11px;padding:5px 12px;" data-bs-toggle="modal" data-bs-target="#addHealthModal">+ नोंद जोडा</button><?php endif; ?>
          </div>
          <?php if (empty($healthRecords)): ?><p style="font-size:12px;color:var(--text-dim);padding:16px 20px;">अजून कुठलीही आरोग्य-नोंद नाही</p><?php endif; ?>
          <?php foreach ($healthRecords as $h): ?>
            <div style="padding:12px 20px;border-bottom:1px solid var(--line);">
              <div style="display:flex;justify-content:space-between;">
                <span style="font-weight:600;font-size:13px;"><?= $recordTypeLabels[$h['record_type']] ?></span>
                <span style="font-size:11px;color:var(--text-dim);"><?= date('d M Y', strtotime($h['record_date'])) ?></span>
              </div>
              <div style="font-size:12.5px;margin-top:3px;"><?= h($h['description']) ?></div>
              <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;">
                <?= $h['vet_name'] ? '👨‍⚕️ ' . h($h['vet_name']) : '' ?>
                <?= $h['cost'] ? ' · ₹' . number_format($h['cost'], 0) : '' ?>
                <?= $h['next_due_date'] ? ' · पुढची तारीख: ' . date('d M Y', strtotime($h['next_due_date'])) : '' ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php if ($canManage): ?>
    <div class="modal fade" id="addHealthModal" tabindex="-1">
      <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
        <form method="POST">
          <input type="hidden" name="action" value="add_health">
          <input type="hidden" name="cattle_id" value="<?= $cattle['id'] ?>">
          <div class="modal-body" style="padding:22px 24px;">
            <div style="font-weight:700;font-size:15px;margin-bottom:14px;">आरोग्य-नोंद जोडा</div>
            <label class="form-label-custom">प्रकार</label>
            <select name="record_type" class="form-select-custom mb-2">
              <?php foreach ($recordTypeLabels as $val => $lbl): ?><option value="<?= $val ?>"><?= $lbl ?></option><?php endforeach; ?>
            </select>
            <label class="form-label-custom">तपशील</label>
            <textarea name="description" class="form-control-custom mb-2" rows="2" required></textarea>
            <div class="row g-2 mb-2">
              <div class="col-md-6"><label class="form-label-custom">Vet चं नाव</label><input type="text" name="vet_name" class="form-control-custom"></div>
              <div class="col-md-6"><label class="form-label-custom">खर्च (₹)</label><input type="number" step="0.01" name="cost" class="form-control-custom"></div>
            </div>
            <div class="row g-2">
              <div class="col-md-6"><label class="form-label-custom">दिनांक</label><input type="date" name="record_date" class="form-control-custom" value="<?= date('Y-m-d') ?>"></div>
              <div class="col-md-6"><label class="form-label-custom">पुढची तारीख (ऐच्छिक)</label><input type="date" name="next_due_date" class="form-control-custom"></div>
            </div>
          </div>
          <div class="modal-footer" style="border-top:1px solid var(--line);">
            <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
            <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
          </div>
        </form>
      </div></div>
    </div>
    <?php endif; ?>

    <?php require_once __DIR__ . '/../includes/footer.php'; ?>
    <?php exit; ?>
<?php endif; ?>

<?php
// -------------------- Listing View --------------------
$listStmt = $pdo->prepare("
    SELECT gc.*, c.name AS campus_name, u.name AS caretaker_name
    FROM gaushala_cattle gc LEFT JOIN campuses c ON c.id = gc.campus_id LEFT JOIN users u ON u.id = gc.caretaker_id
    WHERE gc.organization_id = ? AND gc.status = 'active' ORDER BY (gc.tag_number IS NULL), gc.tag_number, gc.id
");
$listStmt->execute([$orgId]);
$allActive = $listStmt->fetchAll();

$campusesStmt = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusesStmt->execute([$orgId]);
$campuses = $campusesStmt->fetchAll();

$staffStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$staffStmt->execute([$orgId]);
$allStaff = $staffStmt->fetchAll();

// वर्गवारी: Batch वाली गाय / बिना Batch गाय / वासरं / गोऱ्हे / बैल
$groups = ['tagged' => [], 'untagged' => [], 'calf' => [], 'gorha' => [], 'bull' => []];
foreach ($allActive as $r) {
    $r['age_months'] = gsAgeMonths($r['date_of_birth'], $r['approx_age_months'], $r['created_at']);
    $cat = $r['animal_category'] ?? 'Cow';
    if ($cat === 'Cow') { $groups[gsHasTag($r['tag_number']) ? 'tagged' : 'untagged'][] = $r; }
    elseif ($cat === 'Calf') { $groups['calf'][] = $r; }
    elseif ($cat === 'Young Bull') { $groups['gorha'][] = $r; }
    else { $groups['bull'][] = $r; }
}
$filterDefs = [
    'all'      => ['सगळे', count($allActive), 'var(--ink)'],
    'tagged'   => ['🐄 Batch वाली गाय', count($groups['tagged']), 'var(--rail)'],
    'untagged' => ['🐄 बिना Batch गाय', count($groups['untagged']), 'var(--amber)'],
    'calf'     => ['🐮 वासरं', count($groups['calf']), 'var(--rail)'],
    'gorha'    => ['🐂 गोऱ्हे', count($groups['gorha']), 'var(--amber)'],
    'bull'     => ['🐂 बैल', count($groups['bull']), 'var(--ink)'],
];
$type = $_GET['type'] ?? 'all';
if (!isset($filterDefs[$type])) { $type = 'all'; }
$shown = ($type === 'all') ? array_merge($groups['tagged'], $groups['untagged'], $groups['calf'], $groups['gorha'], $groups['bull']) : $groups[$type];
$listCategoryLabels = gsCategoryLabels();

$breakPanels = [];
if (in_array($type, ['all', 'tagged'], true) && !empty($groups['tagged'])) { $breakPanels[] = ['🐄 Batch वाली गाय — जात व रंगानुसार', gsBreakdown($groups['tagged'])]; }
if (in_array($type, ['all', 'untagged'], true) && !empty($groups['untagged'])) { $breakPanels[] = ['🐄 बिना Batch गाय — जात व रंगानुसार', gsBreakdown($groups['untagged'])]; }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🐮 गाय/जनावर Registry</div>
  <div class="d-flex gap-2">
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
    <a href="register-print.php?type=<?= h($type) ?>" target="_blank" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;border:1px solid var(--rail);border-radius:9px;padding:8px 16px;"><i class="fa-solid fa-print me-1"></i>Register Print करा</a>
    <?php if ($canManage): ?><button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addCattleModal">+ नवीन जनावर</button><?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-2 mb-3">
  <?php foreach ($filterDefs as $key => $def): ?>
    <div class="col-6 col-md-2">
      <a href="?type=<?= $key ?>" style="text-decoration:none;color:inherit;display:block;">
        <div class="kpi-mini" style="<?= $type === $key ? 'border:1.5px solid var(--rail);' : '' ?>"><div class="lbl"><?= h($def[0]) ?></div><div class="val" style="color:<?= $def[2] ?>;"><?= $def[1] ?></div></div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($breakPanels)): ?>
<div class="row g-3 mb-3">
  <?php foreach ($breakPanels as $bp): ?>
    <div class="col-lg-6">
      <div class="panel">
        <div class="panel-head"><div class="title"><?= h($bp[0]) ?></div></div>
        <table class="rtable" style="font-size:12.5px;">
          <thead><tr><th>जात</th><th>रंग</th><th>संख्या</th><th>सरासरी वय</th></tr></thead>
          <tbody>
            <?php foreach ($bp[1] as $b): ?>
              <tr><td><?= h($b['breed']) ?></td><td><?= h($b['color']) ?></td><td class="font-mono" style="font-weight:700;"><?= $b['count'] ?></td><td><?= h(gsAgeText($b['avg_months'])) ?></td></tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th></th><th>Batch/Tag</th><th>नाव</th><th>प्रकार</th><th>जात</th><th>रंग</th><th>लिंग</th><th>वय</th><th>काळजीवाहक</th><th>College/School</th></tr></thead>
    <tbody>
      <?php if (empty($shown)): ?><tr><td colspan="10" style="text-align:center;color:var(--text-dim);padding:24px;">या गटात कुठलंही जनावर नाही</td></tr><?php endif; ?>
      <?php foreach ($shown as $r): ?>
        <tr style="cursor:pointer;" onclick="window.location='cattle.php?id=<?= $r['id'] ?>'">
          <td style="width:42px;">
            <?php if (!empty($r['photo_path'])): ?>
              <img src="../<?= h($r['photo_path']) ?>" style="width:36px;height:36px;border-radius:8px;object-fit:cover;">
            <?php else: ?>
              <div style="width:36px;height:36px;border-radius:8px;background:var(--canvas);display:flex;align-items:center;justify-content:center;color:var(--text-dim);"><i class="fa-solid fa-cow" style="font-size:14px;"></i></div>
            <?php endif; ?>
          </td>
          <td class="font-mono" style="font-weight:600;"><?= gsHasTag($r['tag_number']) ? h($r['tag_number']) : '<span style="color:var(--text-dim);">— (बिना Batch)</span>' ?></td>
          <td><?= h($r['name'] ?: '—') ?></td>
          <td><?= $listCategoryLabels[$r['animal_category'] ?? 'Cow'] ?? h($r['animal_category']) ?></td>
          <td><?= h($r['breed'] ?: '—') ?></td>
          <td><?= h($r['color'] ?: '—') ?></td>
          <td><?= $r['gender'] === 'Male' ? '♂️ नर' : '♀️ मादी' ?></td>
          <td style="font-weight:600;"><?= h(gsAgeText($r['age_months'])) ?><?= (empty($r['date_of_birth']) && $r['age_months'] !== null) ? ' <span style="font-size:10px;color:var(--text-dim);font-weight:400;">(अंदाजे)</span>' : '' ?></td>
          <td><?= h($r['caretaker_name'] ?: '—') ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($r['campus_name']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="addCattleModal" tabindex="-1">
  <div class="modal-dialog modal-lg"><div class="modal-content" style="border-radius:14px;">
    <form method="POST" enctype="multipart/form-data">
      <input type="hidden" name="action" value="add_cattle">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन जनावर जोडा</div>
        <label class="form-label-custom">फोटो (ऐच्छिक)</label>
        <input type="file" name="photo" accept="image/*" class="form-control-custom mb-2">
        <div class="row g-2 mb-2">
          <div class="col-md-3"><label class="form-label-custom">Batch/Tag Number <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label><input type="text" name="tag_number" class="form-control-custom" placeholder="नसेल तर रिकामं ठेवा"></div>
          <div class="col-md-3">
            <label class="form-label-custom">प्रकार</label>
            <select name="animal_category" class="form-select-custom" onchange="gsSyncGender(this.value)">
              <option value="Cow">🐄 गाय</option><option value="Calf">🐮 वासरू</option><option value="Young Bull">🐂 गोऱ्हा</option><option value="Bull/Ox">🐂 बैल</option>
            </select>
          </div>
          <div class="col-md-3"><label class="form-label-custom">नाव (ऐच्छिक)</label><input type="text" name="name" class="form-control-custom"></div>
          <div class="col-md-3"><label class="form-label-custom">जात (Breed)</label><input type="text" name="breed" class="form-control-custom"></div>
        </div>
        <div class="row g-2 mb-2">
          <div class="col-md-4">
            <label class="form-label-custom">लिंग</label>
            <select name="gender" id="gsGenderSelect" class="form-select-custom"><option value="Female">मादी</option><option value="Male">नर</option></select>
          </div>
          <div class="col-md-4"><label class="form-label-custom">रंग</label><input type="text" name="color" class="form-control-custom"></div>
          <div class="col-md-4">
            <label class="form-label-custom">कसं आलं</label>
            <select name="acquisition_type" class="form-select-custom">
              <option value="Birth">जन्म (इथेच झालं)</option><option value="Donation">दान</option><option value="Purchase">खरेदी</option><option value="Other">इतर</option>
            </select>
          </div>
        </div>
        <div class="row g-2 mb-1">
          <div class="col-md-4"><label class="form-label-custom">जन्म-दिनांक <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(माहीत असेल तर)</span></label><input type="date" name="date_of_birth" class="form-control-custom"></div>
          <div class="col-md-4"><label class="form-label-custom">किंवा अंदाजे वय — वर्षे</label><input type="number" min="0" max="40" name="age_years" class="form-control-custom" placeholder="0"></div>
          <div class="col-md-4"><label class="form-label-custom">महिने</label><input type="number" min="0" max="11" name="age_months" class="form-control-custom" placeholder="0"></div>
        </div>
        <div style="font-size:10.5px;color:var(--text-dim);margin-bottom:10px;">💡 जन्म-दिनांक माहीत नसेल (बिना-Batch जनावरांना सहसा नसतो) तर अंदाजे वय भरा — ते महिने जसे जातील तसं आपोआप वाढत जाईल.</div>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">आल्याचा दिनांक</label><input type="date" name="acquisition_date" class="form-control-custom"></div>
          <div class="col-md-6"><label class="form-label-custom">तपशील (कोणाकडून)</label><input type="text" name="source_details" class="form-control-custom"></div>
        </div>
        <div class="row g-2">
          <div class="col-md-6">
            <label class="form-label-custom">College/School</label>
            <select name="campus_id" class="form-select-custom" required>
              <option value="">-- निवडा --</option>
              <?php foreach ($campuses as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6">
            <label class="form-label-custom">काळजीवाहक (ऐच्छिक)</label>
            <select name="caretaker_id" class="form-select-custom">
              <option value="">-- निवडा --</option>
              <?php foreach ($allStaff as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>
<script>
function gsSyncGender(cat) {
  var g = document.getElementById('gsGenderSelect');
  if (!g) { return; }
  if (cat === 'Cow') { g.value = 'Female'; }
  else if (cat === 'Young Bull' || cat === 'Bull/Ox') { g.value = 'Male'; }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
