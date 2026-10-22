<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_campuses', '../');
$user = currentUser();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $city = trim($_POST['city'] ?? '') ?: null;
    $state = trim($_POST['state'] ?? '') ?: null;
    $address = trim($_POST['address'] ?? '') ?: null;
    $collegeType = trim($_POST['college_type'] ?? '') ?: null;
    $principalName = trim($_POST['principal_name'] ?? '') ?: null;
    $affiliatedUniversity = trim($_POST['affiliated_university'] ?? '') ?: null;
    $ugcRecognition = trim($_POST['ugc_recognition'] ?? '') ?: null;
    $naacGrade = trim($_POST['naac_grade'] ?? '') ?: null;
    $naacValidUntil = ($_POST['naac_valid_until'] ?? '') ?: null;
    $ugcValidUntil = ($_POST['ugc_valid_until'] ?? '') ?: null;
    $establishedYear = is_numeric($_POST['established_year'] ?? '') ? (int)$_POST['established_year'] : null;

    if (!$name || !$code) {
        $_SESSION['form_error'] = t('s428');
    } else {
        $letterheadPath = null;
        if (!empty($_FILES['letterhead_logo']['name']) && $_FILES['letterhead_logo']['error'] === UPLOAD_ERR_OK) {
            $allowedExt = ['jpg', 'jpeg', 'png', 'webp'];
            $ext = strtolower(pathinfo($_FILES['letterhead_logo']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, $allowedExt, true)) {
                $logoDir = __DIR__ . '/../assets/uploads/college-letterheads';
                if (!is_dir($logoDir)) { mkdir($logoDir, 0755, true); }
                $safeFilename = 'college-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['letterhead_logo']['tmp_name'], $logoDir . '/' . $safeFilename)) {
                    $letterheadPath = 'assets/uploads/college-letterheads/' . $safeFilename;
                }
            }
        }

        $stmt = $pdo->prepare("
            INSERT INTO campuses (organization_id, name, code, city, state, address, college_type, principal_name, affiliated_university, ugc_recognition, naac_grade, naac_valid_until, ugc_valid_until, established_year, letterhead_logo_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([activeOrgId(), $name, $code, $city, $state, $address, $collegeType, $principalName, $affiliatedUniversity, $ugcRecognition, $naacGrade, $naacValidUntil, $ugcValidUntil, $establishedYear, $letterheadPath]);
        $newCampusId = $pdo->lastInsertId();

        require_once __DIR__ . '/../includes/college-helpers.php';
        syncCollegeRenewals($pdo, $newCampusId, activeOrgId(), $name, $naacValidUntil, $ugcValidUntil);

        $_SESSION['flash'] = t('s512');
        header('Location: index.php');
        exit;
    }
}

$pageTitle = t('s513');
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title">🏫 नवीन Campus/College जोडा</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-md-6">
        <label class="form-label-custom">नाव (College/Campus चं)</label>
        <input type="text" name="name" class="form-control-custom" placeholder="उदा. कला व वाणिज्य महाविद्यालय" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom"><?= h(t('s99')) ?></label>
        <input type="text" name="code" class="form-control-custom" placeholder="उदा. KVM" required>
      </div>
      <div class="col-md-3">
        <label class="form-label-custom">College प्रकार</label>
        <select name="college_type" class="form-select-custom">
          <option value="">-- निवडा (ऐच्छिक) --</option>
          <option value="Trust HQ">🏛️ Trust / मुख्य कार्यालय</option>
          <option value="Primary School">प्राथमिक शाळा</option>
          <option value="Secondary School">माध्यमिक शाळा</option>
          <option value="Junior College">कनिष्ठ महाविद्यालय</option>
          <option value="Arts">कला (Arts)</option>
          <option value="Science">विज्ञान (Science)</option>
          <option value="Commerce">वाणिज्य (Commerce)</option>
          <option value="Engineering">अभियांत्रिकी (Engineering)</option>
          <option value="Pharmacy">औषधनिर्माणशास्त्र (Pharmacy)</option>
          <option value="Education">शिक्षणशास्त्र (Education)</option>
          <option value="Law">विधी (Law)</option>
          <option value="Medical">वैद्यकीय (Medical)</option>
          <option value="Polytechnic">Polytechnic</option>
          <option value="Other">इतर</option>
        </select>
      </div>

      <div class="col-md-4">
        <label class="form-label-custom">प्राचार्यांचे नाव</label>
        <input type="text" name="principal_name" class="form-control-custom" placeholder="डॉ. अमुक तमुक">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">संलग्न विद्यापीठ (Affiliated University)</label>
        <input type="text" name="affiliated_university" class="form-control-custom" placeholder="उदा. सावित्रीबाई फुले पुणे विद्यापीठ">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">स्थापना वर्ष</label>
        <input type="number" name="established_year" class="form-control-custom" placeholder="उदा. 1985" min="1800" max="<?= date('Y') ?>">
      </div>

      <div class="col-md-6">
        <label class="form-label-custom">UGC Recognition</label>
        <input type="text" name="ugc_recognition" class="form-control-custom" placeholder="उदा. 2(f) आणि 12(B)">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">NAAC Grade</label>
        <input type="text" name="naac_grade" class="form-control-custom" placeholder="उदा. A+ (CGPA 3.26)">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">NAAC Re-Accreditation पर्यंत वैध (असल्यास)</label>
        <input type="date" name="naac_valid_until" class="form-control-custom">
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;">टाकल्यास — नूतनीकरण Module ला आपोआप Link होईल, मुदत जवळ आल्यावर इशारा मिळेल</div>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">UGC Recognition पर्यंत वैध (असल्यास)</label>
        <input type="date" name="ugc_valid_until" class="form-control-custom">
      </div>

      <div class="col-12">
        <label class="form-label-custom">College चं Letterhead Logo <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
        <input type="file" name="letterhead_logo" accept="image/png,image/jpeg,image/webp" class="form-control-custom">
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:3px;">या College कडून तयार होणाऱ्या पत्रांच्या Letterhead वर हाच Logo वापरला जाईल</div>
      </div>

      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s46')) ?></label>
        <input type="text" name="city" class="form-control-custom">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h(t('s101')) ?></label>
        <input type="text" name="state" class="form-control-custom" value="Maharashtra">
      </div>
      <div class="col-12">
        <label class="form-label-custom"><?= h(t('s102')) ?></label>
        <input type="text" name="address" class="form-control-custom">
      </div>

      <div class="col-12 d-flex justify-content-end gap-2 mt-2">
        <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><?= h(t('s3')) ?></a>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><?= h(t('s38')) ?></button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
