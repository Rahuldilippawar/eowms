<?php
$pageTitle = 'वसतिगृह CSV आयात';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_hostels', '../');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🏠 वसतिगृह CSV आयात (Bulk)</div>
  <a href="create.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 20px;"><i class="fa-solid fa-plus me-1"></i>Manually एक वसतिगृह जोडा</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">एका फाईलमधून एकाच वेळी अनेक वसतिगृहं (Boys Hostel, Girls Hostel, Block A, इ.) तयार करा.</p>

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
      <form method="POST" action="bulk-import-save.php" enctype="multipart/form-data" style="padding:22px 24px;">
        <label class="form-label-custom">CSV फाईल निवडा</label>
        <div class="upload-box mb-3">
          <input type="file" name="csv_file" accept=".csv,.txt" required style="width:100%;">
        </div>
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
          <i class="fa-solid fa-upload me-1"></i>आयात करा (Import)
        </button>
      </form>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">CSV फॉरमॅट</div></div>
      <div style="padding:18px 20px;">
        <table class="rtable font-mono" style="font-size:11.5px;">
          <thead><tr><th>name</th><th>campus</th><th>capacity</th></tr></thead>
          <tbody>
            <tr><td>Boys Hostel A</td><td>Main Campus</td><td>60</td></tr>
            <tr><td>Girls Hostel B</td><td>Main Campus</td><td>40</td></tr>
          </tbody>
        </table>
        <ul style="font-size:12.5px;color:var(--text-dim);padding-left:18px;margin-top:14px;">
          <li>हेडर रो आवश्यक — <span class="font-mono">name</span> व <span class="font-mono">campus</span> अनिवार्य, <span class="font-mono">capacity</span> ऐच्छिक</li>
          <li><span class="font-mono">campus</span> — तुमच्या संस्थेतील कॅम्पसचं नाव तंतोतंत जुळायला हवं (Campus Management मध्ये आधी तयार असलेलं)</li>
          <li>प्रत्येक वसतिगृह आपोआप "वसतिगृह (HOS)" विभागाखाली तयार होईल</li>
        </ul>
        <a href="bulk-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
