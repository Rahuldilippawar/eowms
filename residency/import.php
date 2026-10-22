<?php
$pageTitle = 'निवास Data Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_residency_all_depts', '../');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📥 निवासी Data Import करा</div>
  <div class="d-flex gap-2">
    <a href="import-history.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-clock-rotate-left me-1"></i>Import इतिहास</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>रहिवासी विभाग</a>
  </div>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Building, Room, आणि निवासी (Staff/कुटुंबीय) — सगळं एकाच CSV मधून. <strong>आधी काय-काय तयार होणार ते Preview मध्ये बघून, मगच Confirm करा.</strong></p>

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
      <form method="POST" action="import-preview.php" enctype="multipart/form-data" style="padding:22px 24px;">
        <div class="upload-box mb-3"><input type="file" name="csv_file" accept=".csv,.txt" required style="width:100%;"></div>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
          <i class="fa-solid fa-eye me-1"></i>Preview बघा
        </button>
      </form>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">CSV फॉरमॅट</div></div>
      <div style="padding:18px 20px;">
        <ul style="font-size:11.5px;color:var(--text-dim);padding-left:16px;">
          <li>Columns: <span class="font-mono">building_name, room_name, occupant_type, name, staff_email, relation, moved_in_date</span></li>
          <li><span class="font-mono">building_name, room_name, occupant_type, name</span> — अनिवार्य</li>
          <li><span class="font-mono">occupant_type</span> — <strong>staff</strong> किंवा <strong>family</strong></li>
          <li><span class="font-mono">staff_email</span> — फक्त <span class="font-mono">occupant_type=staff</span> असेल आणि तो व्यक्ती आधीच System मध्ये Staff म्हणून नोंदलेला असेल तरच द्या (User Account शी Link होईल)</li>
          <li><span class="font-mono">relation</span> — फक्त <span class="font-mono">occupant_type=family</span> साठी (उदा. पत्नी, मुलगा)</li>
          <li>दिनांक Format: <strong>YYYY-MM-DD</strong></li>
          <li><span class="font-mono">building_name</span>/<span class="font-mono">room_name</span> आधीच अस्तित्वात नसेल — आपोआप तयार होईल</li>
        </ul>
        <a href="import-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Sample Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
