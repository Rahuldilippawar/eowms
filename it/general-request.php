<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? 'other';
    $priority = $_POST['priority'] ?? 'normal';
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $location = trim($_POST['location'] ?? '');

    if ($title && $description) {
        $requestCode = 'IT-' . date('ymd') . '-' . strtoupper(substr(uniqid(), -5));
        $pdo->prepare("
            INSERT INTO it_general_requests (organization_id, department_id, request_code, category, priority, title, description, location, requested_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$orgId, $user['department_id'] ?? null, $requestCode, $category, $priority, $title, $description, $location, $user['id']]);
        $newId = $pdo->lastInsertId();

        // IT विभागाच्या HOD ला त्वरित Notification
        $itDeptStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE organization_id = ? AND code = 'IT'");
        $itDeptStmt->execute([$orgId]);
        $itHod = $itDeptStmt->fetch()['hod_id'] ?? null;
        $priorityIcon = $priority === 'urgent' ? '🚨' : ($priority === 'high' ? '⚠️' : '');
        if ($itHod) {
            notifyUser($itHod, $orgId, "$priorityIcon नवीन IT Request — $requestCode", $title, 'it/general-requests-manage.php');
        }

        logActivity('IT Request तयार केली', 'it_general_request', $newId, $title);
        $_SESSION['flash'] = "तुमची तक्रार/विनंती नोंदवली गेली — Request क्रमांक: $requestCode";
        header('Location: my-general-requests.php');
        exit;
    } else {
        $_SESSION['form_error'] = 'कृपया शीर्षक आणि तपशील भरा.';
    }
}

$pageTitle = 'IT/Internet तक्रार नोंदवा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🖥️ IT/Internet तक्रार किंवा विनंती नोंदवा</div>
  <a href="my-general-requests.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-list-check me-1"></i>माझ्या Requests</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">Internet/Network समस्या, Software हवं आहे, नवीन Hardware, Account/Access — कुठलाही विशिष्ट Asset माहीत नसेल तरी इथे नोंदवा.</p>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:700px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">प्रकार</label>
    <select name="category" class="form-select-custom mb-3" required>
      <option value="internet_network">🌐 Internet/Network (WiFi, LAN, Speed)</option>
      <option value="software">💿 Software (Install/Update/Error)</option>
      <option value="new_hardware">🖥️ नवीन Hardware हवं आहे</option>
      <option value="account_access">🔑 Account/Access (Email, Login, Password)</option>
      <option value="printer_scanner">🖨️ Printer/Scanner</option>
      <option value="other">📄 इतर</option>
    </select>

    <label class="form-label-custom">प्राधान्य</label>
    <select name="priority" class="form-select-custom mb-3">
      <option value="low">कमी</option>
      <option value="normal" selected>सर्वसाधारण</option>
      <option value="high">⚠️ जास्त</option>
      <option value="urgent">🚨 तातडीचं</option>
    </select>

    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-3" placeholder="उदा. Lab 2 मध्ये Internet बंद आहे" required>

    <label class="form-label-custom">ठिकाण <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
    <input type="text" name="location" class="form-control-custom mb-3" placeholder="उदा. प्रयोगशाळा 2, दुसरा मजला">

    <label class="form-label-custom">संपूर्ण तपशील</label>
    <textarea name="description" class="form-control-custom mb-3" rows="5" required></textarea>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">तक्रार/विनंती नोंदवा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
