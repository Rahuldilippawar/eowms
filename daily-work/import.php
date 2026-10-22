<?php
$pageTitle = 'जुन्या कामाच्या नोंदी Import';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">📥 जुन्या (Backlog) कामाच्या नोंदी Import करा</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">System सुरू करण्याआधीचा जुना डेटा (मागच्या काही महिन्यांतल्या कामांची नोंद) एका CSV file मधून एकाच वेळी टाकता येईल — जेणेकरून System सुरुवातीपासूनच पूर्ण Historical Record सह दिसेल.</p>

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
        <table class="rtable font-mono" style="font-size:11px;">
          <thead><tr><th>date</th><th>department</th><th>title</th><th>staff_names</th></tr></thead>
          <tbody>
            <tr><td>2026-03-15</td><td>MTN</td><td>पाईप दुरुस्ती</td><td>सुरेश पाटील</td></tr>
            <tr><td>2026-03-16</td><td>HSK</td><td>साफसफाई</td><td>मीरा जाधव, रमेश यादव</td></tr>
          </tbody>
        </table>
        <ul style="font-size:11.5px;color:var(--text-dim);padding-left:16px;margin-top:12px;">
          <li>Columns: <span class="font-mono">date, department, title, description, requested_by, priority, staff_names, estimated_cost, actual_cost</span></li>
          <li><span class="font-mono">date</span>, <span class="font-mono">department</span> (Code, उदा. MTN), <span class="font-mono">title</span> — अनिवार्य, बाकी ऐच्छिक</li>
          <li><span class="font-mono">date</span> Format: <strong>YYYY-MM-DD</strong></li>
          <li><span class="font-mono">staff_names</span> — स्वल्पविरामाने (comma) वेगळी नावं — प्रत्येक नाव System मधल्या कर्मचाऱ्याच्या नावाशी तंतोतंत जुळायला हवं</li>
          <li>सगळ्या Import केलेल्या नोंदी आपोआप <strong>"पूर्ण" (Completed)</strong> स्थितीत साठवल्या जातात — कारण त्या आधीच होऊन गेलेल्या आहेत</li>
          <li>यामुळे जुन्या नोंदींसाठी कोणालाही Notification जाणार नाही</li>
        </ul>
        <a href="import-template.php" class="btn btn-sm mt-2" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">
          <i class="fa-solid fa-download me-1"></i>Sample Template Download करा
        </a>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
