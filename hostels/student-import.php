<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();

$orgId = activeOrgId();
$stmt = $pdo->prepare("
    SELECT h.*, c.name AS campus_name
    FROM hostels h LEFT JOIN campuses c ON c.id = h.campus_id
    WHERE h.organization_id = ? AND h.is_active = 1
    ORDER BY h.name
");
$stmt->execute([$orgId]);
$hostels = $stmt->fetchAll();

$pageTitle = 'वसतिगृह विद्यार्थी CSV आयात';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title">वसतिगृह-निहाय विद्यार्थी CSV आयात</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">एका वसतिगृहासाठी एका क्लिकमध्ये संपूर्ण विद्यार्थी यादी अपलोड करा.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['import_errors'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
    <strong>काही ओळी वगळल्या गेल्या:</strong>
    <ul class="mb-0">
      <?php foreach ($_SESSION['import_errors'] as $err): ?><li><?= h($err) ?></li><?php endforeach; ?>
    </ul>
  </div>
  <?php unset($_SESSION['import_errors']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head">
        <div class="title">CSV फाइल अपलोड करा</div>
        <div class="sub">आधी वसतिगृह निवडा, मग फाइल निवडा</div>
      </div>
      <form method="POST" action="student-import-save.php" enctype="multipart/form-data" style="padding:22px 24px;">
        <label class="form-label-custom">वसतिगृह निवडा</label>
        <select name="hostel_id" class="form-select-custom mb-3" required>
          <option value="">-- वसतिगृह निवडा --</option>
          <?php foreach ($hostels as $h): ?>
            <option value="<?= $h['id'] ?>"><?= h($h['name']) ?> <?= $h['campus_name'] ? '(' . h($h['campus_name']) . ')' : '' ?></option>
          <?php endforeach; ?>
        </select>

        <a href="student-template.php" class="btn btn-sm mb-3" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;display:inline-block;"><i class="fa-solid fa-download me-1"></i>Sample CSV Template Download करा</a>

        <label class="form-label-custom">CSV फाइल निवडा</label>
        <div class="upload-box mb-3">
          <input type="file" name="csv_file" accept=".csv,.txt" required style="width:100%;">
        </div>

        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">CSV आयात करा</button>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">CSV फॉरमॅट</div></div>
      <div style="padding:18px 20px;">
        <table class="rtable font-mono" style="font-size:11.5px;">
          <thead><tr><th>name</th><th>school_name</th><th>class_grade</th><th>district</th><th>gender</th><th>admission_date</th><th>date_of_birth</th></tr></thead>
          <tbody>
            <tr><td>सूरज कांबळे</td><td>जि.प. शाळा</td><td>७ वी</td><td>ठाणे</td><td>मुलगा</td><td>2024-06-15</td><td>2012-03-10</td></tr>
            <tr><td>प्रतीक्षा जाधव</td><td>न्यू इंग्लिश स्कूल</td><td>८ वी</td><td>नाशिक</td><td>मुलगी</td><td>2023-06-10</td><td>2011-08-22</td></tr>
          </tbody>
        </table>
        <ul style="font-size:12.5px;color:var(--text-dim);padding-left:18px;margin-top:14px;">
          <li>हेडर रो आवश्यक आहे — <span class="font-mono">name</span> हा column अनिवार्य आहे, बाकी ऐच्छिक</li>
          <li><span class="font-mono">gender</span> मध्ये फक्त "मुलगा" किंवा "मुलगी" वापरा</li>
          <li><span class="font-mono">admission_date</span> व <span class="font-mono">date_of_birth</span> Format: <strong>YYYY-MM-DD</strong> — <span class="font-mono">date_of_birth</span> दिलं तर वाढदिवसाच्या दिवशी Welcome Page वर आपोआप शुभेच्छा-कार्ड दिसेल</li>
          <li>फोटो CSV मधून येत नाही — तो प्रत्येक विद्यार्थ्याच्या Edit मधून वेगळा अपलोड करावा लागतो</li>
          <li>सगळे विद्यार्थी वर निवडलेल्या वसतिगृहात जोडले जातील</li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
