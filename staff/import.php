<?php
$pageTitle = 'कर्मचारी CSV आयात';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');
?>

<div class="page-eyebrow">Super Admin</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s258')) ?></div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
    <i class="fa-solid fa-user-plus me-1"></i>Manually एक कर्मचारी जोडा
  </a>
</div>

<?php if (!empty($_SESSION['import_credentials'])): ?>
  <div class="panel mb-3" style="border:1.5px solid var(--rail);">
    <div class="panel-head" style="background:var(--rail-soft);">
      <div class="title" style="color:var(--rail);"><i class="fa-solid fa-key me-1"></i><?= h(t('s259')) ?></div>
      <div class="sub"><?= h(t('s260')) ?></div>
    </div>
    <table class="rtable font-mono" style="font-size:12px;">
      <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s1')) ?></th><th>Email (Username)</th><th>Password</th></tr></thead>
      <tbody>
        <?php foreach ($_SESSION['import_credentials'] as $cred): ?>
          <tr><td><?= h($cred['name']) ?></td><td><?= h($cred['department']) ?></td><td><?= h($cred['email']) ?></td><td style="font-weight:700;color:var(--rail);"><?= h($cred['password']) ?></td></tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php unset($_SESSION['import_credentials']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['import_skipped'])): ?>
  <div class="alert alert-warning" style="border-radius:10px;font-size:13px;">
    <strong><?= h(t('s261')) ?></strong>
    <ul class="mb-0"><?php foreach ($_SESSION['import_skipped'] as $s): ?><li><?= h($s) ?></li><?php endforeach; ?></ul>
  </div>
  <?php unset($_SESSION['import_skipped']); ?>
<?php endif; ?>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <div class="panel">
      <div class="panel-head">
        <div class="title"><?= h(t('s262')) ?></div>
        <div class="sub"><?= h(t('s263')) ?></div>
      </div>
      <form method="POST" action="import-save.php" enctype="multipart/form-data" style="padding:22px 24px;">
        <label class="form-label-custom"><?= h(t('s264')) ?></label>
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
      <div class="panel-head"><div class="title"><?= h(t('s265')) ?></div><div class="sub"><?= h(t('s266')) ?></div></div>
      <div style="padding:18px 20px;">
        <table class="rtable font-mono" style="font-size:11.5px;">
          <thead><tr><th>name</th><th>department</th><th>designation</th><th>username</th><th>password</th><th>mobile</th><th>salary</th><th>joining_date</th><th>date_of_birth</th></tr></thead>
          <tbody>
            <tr><td><?= h(t('s267')) ?></td><td>Maintenance</td><td>Technician</td><td>suresh.patil</td><td><?= h(t('s96')) ?></td><td>9876543210</td><td>15000</td><td>2024-06-01</td><td>1990-05-14</td></tr>
            <tr><td><?= h(t('s268')) ?></td><td>Housekeeping</td><td>Supervisor</td><td>meera.jadhav</td><td><?= h(t('s96')) ?></td><td>9123456780</td><td>18000</td><td>2023-01-15</td><td>1988-11-02</td></tr>
          </tbody>
        </table>
        <ul style="font-size:12.5px;color:var(--text-dim);padding-left:18px;margin-top:14px;">
          <li><?= h(t('s269')) ?></li>
          <li><?= h(t('s270')) ?></li>
          <li><strong>salary</strong>, <strong>joining_date</strong>, <strong>mobile</strong>, <strong>date_of_birth</strong> — सगळे ऐच्छिक आहेत, रिकामे ठेवता येतात. <strong>date_of_birth</strong> दिलं तर वाढदिवसाच्या दिवशी Welcome Page वर आपोआप शुभेच्छा-कार्ड दिसेल. <strong>salary फक्त Management ला दिसतो.</strong></li>
          <li>तारखांचा Format: <strong>YYYY-MM-DD</strong> (उदा. 2024-06-01)</li>
          <li>फोटो CSV मधून येत नाही — तो प्रत्येक कर्मचाऱ्याच्या Profile → Edit मधून वेगळा अपलोड करावा लागतो.</li>
          <li><strong><?= h(t('s271')) ?></strong> <?= h(t('s272')) ?></li>
          <li><?= h(t('s273')) ?></li>
        </ul>
        <a href="template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
