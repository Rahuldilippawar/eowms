<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if (empty($user['can_edit_daily_work'])) {
    http_response_code(403);
    die('तुम्हाला कामाच्या नोंदी Edit करण्याची परवानगी नाही — Super Admin कडून विनंती करा.');
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM work_records WHERE id = ? AND organization_id = ?");
$stmt->execute([$id, $orgId]);
$record = $stmt->fetch();
if (!$record) { die('नोंद सापडली नाही.'); }

// खरंच या नोंदीत ही व्यक्ती स्वतः सहभागी आहे का ते पडताळतो — HOD ला
// विभागातलं सगळं दिसत असलं तरी, तो/ती फक्त स्वतःच्या नोंदी Edit करू शकतो.
$memberStmt = $pdo->prepare("SELECT 1 FROM work_record_team WHERE work_record_id = ? AND user_id = ?");
$memberStmt->execute([$id, $user['id']]);
if (!$memberStmt->fetch()) {
    http_response_code(403);
    die('ही तुमची स्वतःची नोंद नाही — तुम्ही फक्त स्वतः सहभागी असलेल्या नोंदीच Edit करू शकता.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $workDate = $_POST['work_date'] ?? $record['work_date'];
    $priority = $_POST['priority'] ?? 'normal';
    $actualCost = ($_POST['actual_cost'] ?? '') !== '' ? (float)$_POST['actual_cost'] : null;

    if ($title) {
        $pdo->prepare("UPDATE work_records SET title = ?, description = ?, work_date = ?, priority = ?, actual_cost = ? WHERE id = ? AND organization_id = ?")
            ->execute([$title, $description, $workDate, $priority, $actualCost, $id, $orgId]);
        logActivity('स्वतःची कामाची नोंद Edit केली', 'work_record', $id, $title);
        $_SESSION['flash'] = 'नोंद यशस्वीरित्या दुरुस्त केली गेली.';
        header('Location: my-work.php');
        exit;
    } else {
        $_SESSION['form_error'] = 'कृपया शीर्षक भरा.';
    }
}

$pageTitle = 'नोंद दुरुस्त करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">✏️ माझी नोंद दुरुस्त करा</div>
  <a href="my-work.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>माझी कामे</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;"><?= h($record['work_code']) ?> — फक्त शीर्षक, तपशील, दिनांक, प्राधान्य, खर्च दुरुस्त करता येईल.</p>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:650px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-3" value="<?= h($record['title']) ?>" required>

    <label class="form-label-custom">तपशील</label>
    <textarea name="description" class="form-control-custom mb-3" rows="3"><?= h($record['description']) ?></textarea>

    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label-custom">कामाचा दिनांक</label><input type="date" name="work_date" class="form-control-custom" value="<?= h($record['work_date']) ?>" required></div>
      <div class="col-md-4">
        <label class="form-label-custom">प्राधान्य</label>
        <select name="priority" class="form-select-custom">
          <option value="low" <?= $record['priority'] === 'low' ? 'selected' : '' ?>>कमी</option>
          <option value="normal" <?= $record['priority'] === 'normal' ? 'selected' : '' ?>>सर्वसाधारण</option>
          <option value="high" <?= $record['priority'] === 'high' ? 'selected' : '' ?>>जास्त</option>
          <option value="urgent" <?= $record['priority'] === 'urgent' ? 'selected' : '' ?>>तातडीचं</option>
        </select>
      </div>
      <div class="col-md-4"><label class="form-label-custom">खर्च (₹, असल्यास)</label><input type="number" step="0.01" name="actual_cost" class="form-control-custom" value="<?= h($record['actual_cost']) ?>"></div>
    </div>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">जतन करा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
