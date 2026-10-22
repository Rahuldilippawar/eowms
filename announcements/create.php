<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_announcements', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    $targetScope = $_POST['target_scope'] ?? 'all';
    $selectedDepts = array_map('intval', $_POST['department_ids'] ?? []);

    if ($title && $content) {
        $pdo->prepare("INSERT INTO announcements (organization_id, title, content, priority, target_scope, created_by) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $title, $content, $priority, $targetScope, $user['id']]);
        $newId = $pdo->lastInsertId();

        $notifyUserIds = [];
        if ($targetScope === 'specific' && !empty($selectedDepts)) {
            $deptInsert = $pdo->prepare("INSERT INTO announcement_departments (announcement_id, department_id) VALUES (?, ?)");
            foreach ($selectedDepts as $dId) { $deptInsert->execute([$newId, $dId]); }
            $ph = implode(',', array_fill(0, count($selectedDepts), '?'));
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND department_id IN ($ph) AND is_active = 1");
            $uStmt->execute(array_merge([$orgId], $selectedDepts));
            $notifyUserIds = $uStmt->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1");
            $uStmt->execute([$orgId]);
            $notifyUserIds = $uStmt->fetchAll(PDO::FETCH_COLUMN);
        }

        $icon = $priority === 'urgent' ? '🚨' : ($priority === 'important' ? '⚠️' : '📢');
        foreach ($notifyUserIds as $uid) {
            if ((int)$uid === (int)$user['id']) continue;
            notifyUser($uid, $orgId, "$icon नवीन सूचना: $title", mb_substr($content, 0, 80), 'announcements/view.php?id=' . $newId);
        }

        logActivity('परिपत्रक/सूचना पाठवली', 'announcement', $newId, $title);
        $_SESSION['flash'] = 'सूचना यशस्वीरित्या पाठवली गेली — ' . count($notifyUserIds) . ' जणांना कळवलं.';
        header('Location: index.php');
        exit;
    } else {
        $_SESSION['form_error'] = 'कृपया शीर्षक आणि मजकूर टाका.';
    }
}

$pageTitle = 'नवीन सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📢 नवीन सूचना/परिपत्रक पाठवा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व सूचना</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:700px;">
  <form method="POST" style="padding:22px 24px;">
    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-3" required>

    <label class="form-label-custom">मजकूर</label>
    <textarea name="content" class="form-control-custom mb-3" rows="6" required></textarea>

    <label class="form-label-custom">प्राधान्य</label>
    <select name="priority" class="form-select-custom mb-3">
      <option value="normal">सर्वसाधारण</option>
      <option value="important">⚠️ महत्त्वाचं</option>
      <option value="urgent">🚨 तातडीचं</option>
    </select>

    <label class="form-label-custom">कोणाला पाठवायचं</label>
    <select name="target_scope" id="targetScope" class="form-select-custom mb-3" onchange="document.getElementById('deptPicker').style.display = this.value === 'specific' ? 'block' : 'none';">
      <option value="all">🏢 संपूर्ण संस्था (सगळे)</option>
      <option value="specific">🎯 निवडक विभाग</option>
    </select>

    <div id="deptPicker" style="display:none;background:var(--canvas);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
      <?php foreach ($departments as $d): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;">
          <input type="checkbox" name="department_ids[]" value="<?= $d['id'] ?>" style="width:16px;height:16px;">
          <?= h($d['name_local'] ?: $d['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">📢 सूचना पाठवा</button>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
