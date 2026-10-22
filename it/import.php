<?php
$pageTitle = 'Lab/Computer Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/it-helpers.php';
requireItAccess($pdo, $user);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 Lab/Computer Import करा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>IT विभाग</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">एकाच CSV File मधून अनेक Labs आणि त्यांचे Computer/Smart Board/इतर Assets एकाच वेळी जोडता येतील — प्रत्येक Asset साठी वेगळी नोंद टाकावी लागत नाही.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['import_errors'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
    <strong>काही ओळी वगळल्या गेल्या:</strong>
    <ul class="mb-0"><?php foreach ($_SESSION['import_errors'] as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
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
      <div class="panel-head"><div class="title">CSV फाईल अपलोड करा</div></div>
      <form method="POST" action="import-save.php" enctype="multipart/form-data" style="padding:22px 24px;">
        <label class="form-label-custom">CSV फाईल निवडा</label>
        <div class="upload-box mb-3">
          <input type="file" name="csv_file" accept=".csv,.txt" required style="width:100%;">
        </div>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
          <i class="fa-solid fa-upload me-1"></i>Import करा
        </button>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">CSV फॉरमॅट</div></div>
      <div style="padding:18px 20px;">
        <table class="rtable font-mono" style="font-size:9.5px;">
          <thead><tr><th>lab_name</th><th>workstation_no</th><th>asset_type</th><th>serial_no</th></tr></thead>
          <tbody>
            <tr><td>Computer Lab 1</td><td>PC-01</td><td>cpu</td><td>SN001</td></tr>
            <tr><td>Computer Lab 1</td><td>PC-01</td><td>monitor</td><td>SN002</td></tr>
            <tr><td>Computer Lab 1</td><td>PC-01</td><td>keyboard</td><td>SN003</td></tr>
          </tbody>
        </table>
        <ul style="font-size:11.5px;color:var(--text-dim);padding-left:16px;margin-top:12px;">
          <li>Columns: <span class="font-mono">lab_name, lab_location, capacity, workstation_no, asset_type, brand, model, serial_no, purchase_date, purchase_cost, warranty_expiry, os_license, license_expiry</span></li>
          <li><span class="font-mono">lab_name</span> आणि <span class="font-mono">asset_type</span> — अनिवार्य, बाकी सगळे ऐच्छिक</li>
          <li><strong>💡 एका PC चे CPU+Monitor+Keyboard+Mouse एकत्र दाखवायचे असतील</strong> — त्या सगळ्यांना <span class="font-mono">workstation_no</span> मध्ये तोच क्रमांक द्या (उदा. "PC-01") — ते आपोआप एका गटात दिसतील</li>
          <li><strong>एकाच Lab च्या अनेक Assets</strong> — प्रत्येक Asset साठी वेगळी ओळ टाका, पण <span class="font-mono">lab_name</span> तोच ठेवा (आपोआप एकाच Lab खाली एकत्र येतील, Lab दोनदा तयार होणार नाही)</li>
          <li><span class="font-mono">asset_type</span> — computer / monitor / keyboard / mouse / cpu / ups / smartboard / projector / printer / other</li>
          <li>दिनांक Format: <strong>YYYY-MM-DD</strong></li>
          <li>जर <span class="font-mono">lab_name</span> आधीच अस्तित्वात असेल — नवीन Lab न बनवता, त्याच जुन्या Lab खाली नवीन Assets जोडली जातील</li>
        </ul>
        <a href="import-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Sample Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
