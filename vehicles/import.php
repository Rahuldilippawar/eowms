<?php
$pageTitle = 'वाहन Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 वाहन Data Import करा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>वाहन यादी</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">एकाच CSV File मधून अनेक वाहनं — त्यांचे संपूर्ण तपशील, नूतनीकरण-तारखांसह — एकाच वेळी जोडता येतील.</p>

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
        <div class="upload-box mb-3"><input type="file" name="csv_file" accept=".csv,.txt" required style="width:100%;"></div>
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
        <ul style="font-size:11px;color:var(--text-dim);padding-left:16px;">
          <li>Columns: <span class="font-mono">department_name, vehicle_number, vehicle_type, model, date_of_registration, chassis_no, engine_no, vehicle_class, passenger_capacity, fuel_type, permit_validity, puc_validity, insurance_validity, fitness_validity, road_tax_validity, green_tax_validity, mv_tax_validity</span></li>
          <li><span class="font-mono">department_name, vehicle_number</span> — अनिवार्य</li>
          <li><span class="font-mono">fuel_type</span> — petrol / diesel / cng / electric / other</li>
          <li>दिनांक Format: <strong>YYYY-MM-DD</strong></li>
          <li>नूतनीकरण-तारखा टाकल्यास — "नूतनीकरण व अनुपालन" Module ला आपोआप Link होतील</li>
          <li>तोच <span class="font-mono">vehicle_number</span> आधीच अस्तित्वात असेल तर ती ओळ वगळली जाईल (Duplicate टाळण्यासाठी)</li>
        </ul>
        <a href="import-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Sample Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
