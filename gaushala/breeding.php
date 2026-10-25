<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_breeding_records', '../');
requireMigratedTable($pdo, 'gaushala_cattle', '../', 'animal_category');
require_once __DIR__ . '/helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_gaushala') || isManagementOrAbove();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_breeding') {
        $cattleId = (int)($_POST['cattle_id'] ?? 0);
        $breedingDate = $_POST['breeding_date'] ?? '';
        $method = in_array($_POST['breeding_method'] ?? '', ['Natural', 'Artificial Insemination'], true) ? $_POST['breeding_method'] : 'Natural';
        $expectedCalving = ($_POST['expected_calving_date'] ?? '') ?: null;
        $remarks = trim($_POST['remarks'] ?? '') ?: null;
        // गाय याच संस्थेची, Active मादी असली पाहिजे
        $cowChk = $pdo->prepare("SELECT COUNT(*) FROM gaushala_cattle WHERE id = ? AND organization_id = ? AND status = 'active' AND gender = 'Female'");
        $cowChk->execute([$cattleId, $orgId]);
        if ($cattleId && $breedingDate && (int)$cowChk->fetchColumn() > 0) {
            $pdo->prepare("INSERT INTO gaushala_breeding_records (cattle_id, breeding_date, breeding_method, expected_calving_date, remarks, recorded_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$cattleId, $breedingDate, $method, $expectedCalving, $remarks, $user['id']]);
            $_SESSION['flash'] = 'प्रजनन-नोंद जोडली गेली.';
        }
    } elseif ($action === 'record_calving') {
        $breedingId = (int)($_POST['breeding_id'] ?? 0);
        $actualCalvingDate = ($_POST['actual_calving_date'] ?? '') ?: date('Y-m-d');
        $status = in_array($_POST['calving_status'] ?? '', ['delivered', 'miscarriage'], true) ? $_POST['calving_status'] : 'delivered';
        $createNewCalf = isset($_POST['create_calf']);

        $breedingStmt = $pdo->prepare("SELECT gb.*, gc.campus_id, gc.breed AS mother_breed, gc.color AS mother_color FROM gaushala_breeding_records gb JOIN gaushala_cattle gc ON gc.id = gb.cattle_id WHERE gb.id = ? AND gc.organization_id = ?");
        $breedingStmt->execute([$breedingId, $orgId]);
        $breeding = $breedingStmt->fetch();

        if (!$breeding) { header('Location: breeding.php'); exit; }
        $newCalfId = null;
        if ($breeding && $createNewCalf && $status === 'delivered') {
            $calfTag = trim($_POST['calf_tag_number'] ?? '') ?: null;
            $calfGender = in_array($_POST['calf_gender'] ?? '', ['Male', 'Female'], true) ? $_POST['calf_gender'] : 'Female';
            try {
                $pdo->prepare("
                    INSERT INTO gaushala_cattle (organization_id, campus_id, tag_number, breed, color, gender, animal_category, date_of_birth, acquisition_type, acquisition_date, mother_cattle_id, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, 'Calf', ?, 'Birth', ?, ?, ?)
                ")->execute([$orgId, $breeding['campus_id'], $calfTag, $breeding['mother_breed'], $breeding['mother_color'], $calfGender, $actualCalvingDate, $actualCalvingDate, $breeding['cattle_id'], $user['id']]);
                $newCalfId = $pdo->lastInsertId();
            } catch (PDOException $e) { /* Tag आधीच वापरलेला असेल तर वगळतो */ }
        }

        $pdo->prepare("UPDATE gaushala_breeding_records SET actual_calving_date = ?, status = ?, calf_cattle_id = ? WHERE id = ? AND cattle_id IN (SELECT id FROM gaushala_cattle WHERE organization_id = ?)")
            ->execute([$actualCalvingDate, $status, $newCalfId, $breedingId, $orgId]);
        $_SESSION['flash'] = $newCalfId ? 'नोंद Update झाली, नवीन वासरू Registry मध्ये जोडलं गेलं.' : 'नोंद Update झाली.';
    }
    header('Location: breeding.php');
    exit;
}

$pageTitle = 'प्रजनन नोंदी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$breedingStmt = $pdo->prepare("
    SELECT gb.*, gc.tag_number, gc.name AS cattle_name, calf.tag_number AS calf_tag, calf.name AS calf_name
    FROM gaushala_breeding_records gb JOIN gaushala_cattle gc ON gc.id = gb.cattle_id
    LEFT JOIN gaushala_cattle calf ON calf.id = gb.calf_cattle_id
    WHERE gc.organization_id = ? ORDER BY FIELD(gb.status, 'pregnant', 'delivered', 'miscarriage'), gb.expected_calving_date
");
$breedingStmt->execute([$orgId]);
$breedingRecords = $breedingStmt->fetchAll();

$femaleCattleStmt = $pdo->prepare("SELECT id, tag_number, name FROM gaushala_cattle WHERE organization_id = ? AND status = 'active' AND gender = 'Female' AND animal_category = 'Cow' ORDER BY (tag_number IS NULL), tag_number, id");
$femaleCattleStmt->execute([$orgId]);
$femaleCattle = $femaleCattleStmt->fetchAll();

$statusLabels = ['pregnant' => '🤰 गाभण', 'delivered' => '✅ वासरू झालं', 'miscarriage' => '⚠️ Miscarriage'];
$statusChips = ['pregnant' => 'pending', 'delivered' => 'done', 'miscarriage' => 'overdue'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🍼 प्रजनन नोंदी</div>
  <div class="d-flex gap-2">
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
    <?php if ($canManage): ?><button type="button" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#addBreedingModal">+ नवीन नोंद</button><?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>गाय</th><th>Breeding दिनांक</th><th>पद्धत</th><th>अपेक्षित वासरू</th><th>स्थिती</th><th>वासरू-Tag</th><?php if ($canManage): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
      <?php if (empty($breedingRecords)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही नोंद नाही</td></tr><?php endif; ?>
      <?php foreach ($breedingRecords as $b): ?>
        <tr>
          <td style="font-weight:600;"><?= h(gsCattleTitle($b['tag_number'], $b['cattle_name'], $b['cattle_id'])) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($b['breeding_date'])) ?></td>
          <td style="font-size:12px;"><?= h($b['breeding_method']) ?></td>
          <td class="font-mono"><?= $b['expected_calving_date'] ? date('d M Y', strtotime($b['expected_calving_date'])) : '—' ?></td>
          <td><span class="status-chip <?= $statusChips[$b['status']] ?>"><?= $statusLabels[$b['status']] ?></span></td>
          <td><?= $b['calf_cattle_id'] ? h(gsCattleTitle($b['calf_tag'], $b['calf_name'], $b['calf_cattle_id'])) : '—' ?></td>
          <?php if ($canManage): ?>
            <td>
              <?php if ($b['status'] === 'pregnant'): ?>
                <button type="button" class="btn btn-sm" style="border:1px solid var(--rail);color:var(--rail);border-radius:6px;font-size:10.5px;padding:4px 10px;" data-bs-toggle="modal" data-bs-target="#calvingModal" onclick="document.getElementById('calvingBreedingId').value='<?= $b['id'] ?>';">Update करा</button>
              <?php endif; ?>
            </td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($canManage): ?>
<div class="modal fade" id="addBreedingModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="add_breeding">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">नवीन प्रजनन-नोंद</div>
        <label class="form-label-custom">गाय</label>
        <select name="cattle_id" class="form-select-custom mb-2" required>
          <option value="">-- निवडा --</option>
          <?php foreach ($femaleCattle as $c): ?><option value="<?= $c['id'] ?>"><?= h(gsCattleTitle($c['tag_number'], $c['name'], $c['id'])) ?></option><?php endforeach; ?>
        </select>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label-custom">Breeding दिनांक</label><input type="date" name="breeding_date" class="form-control-custom" required></div>
          <div class="col-md-6">
            <label class="form-label-custom">पद्धत</label>
            <select name="breeding_method" class="form-select-custom"><option value="Natural">Natural</option><option value="Artificial Insemination">Artificial Insemination</option></select>
          </div>
        </div>
        <label class="form-label-custom">अपेक्षित वासरू-दिनांक (ऐच्छिक)</label>
        <input type="date" name="expected_calving_date" class="form-control-custom mb-2">
        <label class="form-label-custom">टिप्पणी</label>
        <input type="text" name="remarks" class="form-control-custom">
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जोडा</button>
      </div>
    </form>
  </div></div>
</div>

<div class="modal fade" id="calvingModal" tabindex="-1">
  <div class="modal-dialog"><div class="modal-content" style="border-radius:14px;">
    <form method="POST">
      <input type="hidden" name="action" value="record_calving">
      <input type="hidden" name="breeding_id" id="calvingBreedingId">
      <div class="modal-body" style="padding:22px 24px;">
        <div style="font-weight:700;font-size:15px;margin-bottom:14px;">Update करा</div>
        <label class="form-label-custom">काय झालं</label>
        <select name="calving_status" class="form-select-custom mb-2" id="calvingStatusSelect" onchange="document.getElementById('calfFieldsBlock').style.display = this.value === 'delivered' ? 'block' : 'none';">
          <option value="delivered">✅ वासरू झालं</option>
          <option value="miscarriage">⚠️ Miscarriage</option>
        </select>
        <label class="form-label-custom">दिनांक</label>
        <input type="date" name="actual_calving_date" class="form-control-custom mb-2" value="<?= date('Y-m-d') ?>">
        <div id="calfFieldsBlock">
          <label style="font-size:12.5px;display:flex;align-items:center;gap:8px;margin-bottom:8px;"><input type="checkbox" name="create_calf" checked style="width:16px;height:16px;"> वासराला नवीन Registry-Entry म्हणून जोडा</label>
          <div class="row g-2">
            <div class="col-md-6"><label class="form-label-custom">वासराचा Tag <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label><input type="text" name="calf_tag_number" class="form-control-custom"></div>
            <div class="col-md-6">
              <label class="form-label-custom">लिंग</label>
              <select name="calf_gender" class="form-select-custom"><option value="Female">मादी</option><option value="Male">नर</option></select>
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
        <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
