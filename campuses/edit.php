<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_campuses', '../');
$user = currentUser();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $city = trim($_POST['city'] ?? '') ?: null;
    $state = trim($_POST['state'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;
    $isActive = (int)($_POST['is_active'] ?? 1);
    $collegeType = trim($_POST['college_type'] ?? '') ?: null;
    $principalName = trim($_POST['principal_name'] ?? '') ?: null;
    $affiliatedUniversity = trim($_POST['affiliated_university'] ?? '') ?: null;
    $ugcRecognition = trim($_POST['ugc_recognition'] ?? '') ?: null;
    $naacGrade = trim($_POST['naac_grade'] ?? '') ?: null;
    $naacValidUntil = ($_POST['naac_valid_until'] ?? '') ?: null;
    $ugcValidUntil = ($_POST['ugc_valid_until'] ?? '') ?: null;
    $establishedYear = is_numeric($_POST['established_year'] ?? '') ? (int)$_POST['established_year'] : null;

    $letterheadUpdate = '';
    $letterheadParams = [];
    if (!empty($_FILES['letterhead_logo']['name']) && $_FILES['letterhead_logo']['error'] === UPLOAD_ERR_OK) {
        $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['letterhead_logo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, $allowedExt, true)) {
            $logoDir = __DIR__ . '/../assets/uploads/college-letterheads';
            if (!is_dir($logoDir)) { mkdir($logoDir, 0755, true); }
            $safeFilename = 'college-' . uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['letterhead_logo']['tmp_name'], $logoDir . '/' . $safeFilename)) {
                $letterheadUpdate = ', letterhead_logo_path=?';
                $letterheadParams[] = 'assets/uploads/college-letterheads/' . $safeFilename;
            }
        }
    }

    $stmt = $pdo->prepare("
        UPDATE campuses SET name=?, city=?, state=?, address=?, is_active=?, college_type=?, principal_name=?, affiliated_university=?, ugc_recognition=?, naac_grade=?, naac_valid_until=?, ugc_valid_until=?, established_year=?{$letterheadUpdate}
        WHERE id=? AND organization_id=?
    ");
    $stmt->execute(array_merge(
        [$name, $city, $state, $address, $isActive, $collegeType, $principalName, $affiliatedUniversity, $ugcRecognition, $naacGrade, $naacValidUntil, $ugcValidUntil, $establishedYear],
        $letterheadParams,
        [$id, activeOrgId()]
    ));

    require_once __DIR__ . '/../includes/college-helpers.php';
    syncCollegeRenewals($pdo, $id, activeOrgId(), $name, $naacValidUntil, $ugcValidUntil);

    $_SESSION['flash'] = t('s516');
    header('Location: index.php');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, activeOrgId()]);
$campus = $stmt->fetch();
if (!$campus) { die(t('s438')); }

$pageTitle = t('s517');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title"><?= h($campus['name']) ?> <?= h(t('s11')) ?></div>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <input type="hidden" name="id" value="<?= $campus['id'] ?>">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s100')) ?></label>
        <input type="text" name="name" class="form-control-custom" value="<?= h($campus['name']) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s103')) ?></label>
        <input type="text" class="form-control-custom" value="<?= h($campus['code']) ?>" disabled>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">College प्रकार</label>
        <select name="college_type" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach (['Trust HQ' => '🏛️ Trust / मुख्य कार्यालय', 'Primary School' => 'प्राथमिक शाळा', 'Secondary School' => 'माध्यमिक शाळा', 'Junior College' => 'कनिष्ठ महाविद्यालय', 'Arts' => 'कला', 'Science' => 'विज्ञान', 'Commerce' => 'वाणिज्य', 'Engineering' => 'अभियांत्रिकी', 'Pharmacy' => 'औषधनिर्माणशास्त्र', 'Education' => 'शिक्षणशास्त्र', 'Law' => 'विधी', 'Medical' => 'वैद्यकीय', 'Polytechnic' => 'Polytechnic', 'Other' => 'इतर'] as $val => $lbl): ?>
            <option value="<?= $val ?>" <?= $campus['college_type'] === $val ? 'selected' : '' ?>><?= h($lbl) ?> (<?= $val ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label-custom">प्राचार्यांचे नाव</label>
        <input type="text" name="principal_name" class="form-control-custom" value="<?= h($campus['principal_name'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">संलग्न विद्यापीठ</label>
        <input type="text" name="affiliated_university" class="form-control-custom" value="<?= h($campus['affiliated_university'] ?? '') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">स्थापना वर्ष</label>
        <input type="number" name="established_year" class="form-control-custom" value="<?= h($campus['established_year'] ?? '') ?>" min="1800" max="<?= date('Y') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom">UGC Recognition</label>
        <input type="text" name="ugc_recognition" class="form-control-custom" value="<?= h($campus['ugc_recognition'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">NAAC Grade</label>
        <input type="text" name="naac_grade" class="form-control-custom" value="<?= h($campus['naac_grade'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">NAAC Re-Accreditation पर्यंत वैध</label>
        <input type="date" name="naac_valid_until" class="form-control-custom" value="<?= h($campus['naac_valid_until'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">UGC Recognition पर्यंत वैध</label>
        <input type="date" name="ugc_valid_until" class="form-control-custom" value="<?= h($campus['ugc_valid_until'] ?? '') ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">College चं Letterhead Logo</label>
        <?php if (!empty($campus['letterhead_logo_path'])): ?>
          <div style="margin-bottom:6px;"><img src="../<?= h($campus['letterhead_logo_path']) ?>" style="height:40px;"></div>
        <?php endif; ?>
        <input type="file" name="letterhead_logo" accept="image/png,image/jpeg,image/webp" class="form-control-custom">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s46')) ?></label>
        <input type="text" name="city" class="form-control-custom" value="<?= h($campus['city']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s101')) ?></label>
        <input type="text" name="state" class="form-control-custom" value="<?= h($campus['state']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s102')) ?></label>
        <input type="text" name="address" class="form-control-custom" value="<?= h($campus['address']) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s2')) ?></label>
        <select name="is_active" class="form-select-custom">
          <option value="1" <?= $campus['is_active'] ? 'selected' : '' ?>><?= h(t('s4')) ?></option>
          <option value="0" <?= !$campus['is_active'] ? 'selected' : '' ?>><?= h(t('s5')) ?></option>
        </select>
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s12')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
