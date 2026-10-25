<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_announcements', '../');
$user = currentUser();
$orgId = activeOrgId();

// ?id= — आधीची सूचना Edit (पुन्हा Notification जात नाही)
$editId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$editing = null;
$editDepts = [];
if ($editId) {
    $eStmt = $pdo->prepare("SELECT * FROM announcements WHERE id = ? AND organization_id = ?");
    $eStmt->execute([$editId, $orgId]);
    $editing = $eStmt->fetch();
    if (!$editing) { header('Location: index.php'); exit; }
    $dStmt = $pdo->prepare("SELECT department_id FROM announcement_departments WHERE announcement_id = ?");
    $dStmt->execute([$editId]);
    $editDepts = array_map('intval', $dStmt->fetchAll(PDO::FETCH_COLUMN));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $priority = $_POST['priority'] ?? 'normal';
    if (!in_array($priority, ['normal', 'important', 'urgent'], true)) { $priority = 'normal'; }
    $targetScope = ($_POST['target_scope'] ?? 'all') === 'specific' ? 'specific' : 'all';
    $isPinned = !empty($_POST['is_pinned']) ? 1 : 0;
    $expiresAt = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expires_at'] ?? '') ? $_POST['expires_at'] : null;

    // निवडलेले विभाग खरंच याच संस्थेचे (आणि सक्रिय) आहेत ना — बाकीचे वगळतो
    $selectedDepts = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['department_ids'] ?? [])))));
    if ($selectedDepts) {
        $ph = implode(',', array_fill(0, count($selectedDepts), '?'));
        $vStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND is_active = 1 AND id IN ($ph)");
        $vStmt->execute(array_merge([$orgId], $selectedDepts));
        $selectedDepts = array_map('intval', $vStmt->fetchAll(PDO::FETCH_COLUMN));
    }

    if (!$title || !$content) {
        $_SESSION['form_error'] = 'कृपया शीर्षक आणि मजकूर टाका.';
    } elseif ($targetScope === 'specific' && !$selectedDepts) {
        $_SESSION['form_error'] = 'कृपया किमान एक विभाग निवडा.';
    } else {
        $hasNewCols = true;
        try { $pdo->query("SELECT is_pinned FROM announcements LIMIT 1"); } catch (PDOException $e) { $hasNewCols = false; }

        if ($editing) {
            if ($hasNewCols) {
                $pdo->prepare("UPDATE announcements SET title = ?, content = ?, priority = ?, target_scope = ?, is_pinned = ?, expires_at = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")
                    ->execute([$title, $content, $priority, $targetScope, $isPinned, $expiresAt, $editId, $orgId]);
            } else {
                $pdo->prepare("UPDATE announcements SET title = ?, content = ?, priority = ?, target_scope = ? WHERE id = ? AND organization_id = ?")
                    ->execute([$title, $content, $priority, $targetScope, $editId, $orgId]);
            }
            $pdo->prepare("DELETE FROM announcement_departments WHERE announcement_id = ?")->execute([$editId]);
            if ($targetScope === 'specific') {
                $deptInsert = $pdo->prepare("INSERT INTO announcement_departments (announcement_id, department_id) VALUES (?, ?)");
                foreach ($selectedDepts as $dId) { $deptInsert->execute([$editId, $dId]); }
            }
            logActivity('परिपत्रक/सूचना बदलली', 'announcement', $editId, $title);
            $_SESSION['flash'] = 'सूचना अद्ययावत केली.';
            header('Location: view.php?id=' . $editId);
            exit;
        }

        if ($hasNewCols) {
            $pdo->prepare("INSERT INTO announcements (organization_id, title, content, priority, target_scope, is_pinned, expires_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $title, $content, $priority, $targetScope, $isPinned, $expiresAt, $user['id']]);
        } else {
            $pdo->prepare("INSERT INTO announcements (organization_id, title, content, priority, target_scope, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$orgId, $title, $content, $priority, $targetScope, $user['id']]);
        }
        $newId = (int)$pdo->lastInsertId();

        if ($targetScope === 'specific') {
            $deptInsert = $pdo->prepare("INSERT INTO announcement_departments (announcement_id, department_id) VALUES (?, ?)");
            foreach ($selectedDepts as $dId) { $deptInsert->execute([$newId, $dId]); }
            $ph = implode(',', array_fill(0, count($selectedDepts), '?'));
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND department_id IN ($ph) AND is_active = 1");
            $uStmt->execute(array_merge([$orgId], $selectedDepts));
        } else {
            $uStmt = $pdo->prepare("SELECT id FROM users WHERE organization_id = ? AND is_active = 1");
            $uStmt->execute([$orgId]);
        }

        // शेकडो जणांना — एकाच Transaction मध्ये; Push नंतर Queue मधून जातात, त्यामुळे पान लगेच परत येतं
        $icon = $priority === 'urgent' ? '🚨' : ($priority === 'important' ? '⚠️' : '📢');
        $count = notifyUsers($uStmt->fetchAll(PDO::FETCH_COLUMN), $orgId, "$icon नवीन सूचना: $title", mb_substr($content, 0, 80), 'announcements/view.php?id=' . $newId, $user['id']);

        logActivity('परिपत्रक/सूचना पाठवली', 'announcement', $newId, $title);
        $_SESSION['flash'] = 'सूचना यशस्वीरित्या पाठवली गेली — ' . $count . ' जणांना कळवलं.';
        header('Location: index.php');
        exit;
    }
    header('Location: create.php' . ($editId ? '?id=' . $editId : ''));
    exit;
}

$pageTitle = $editing ? 'सूचना बदला' : 'नवीन सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$deptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$deptsStmt->execute([$orgId]);
$departments = $deptsStmt->fetchAll();
$val = fn($k, $d = '') => $editing[$k] ?? $d;
$isSpecific = $val('target_scope', 'all') === 'specific';
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= $editing ? '✏️ सूचना बदला' : '📢 नवीन सूचना/परिपत्रक पाठवा' ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>सर्व सूचना</a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="panel" style="max-width:700px;">
  <form method="POST" style="padding:22px 24px;">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
    <label class="form-label-custom">शीर्षक</label>
    <input type="text" name="title" class="form-control-custom mb-3" required maxlength="255" value="<?= h($val('title')) ?>">

    <label class="form-label-custom">मजकूर</label>
    <textarea name="content" class="form-control-custom mb-3" rows="6" required><?= h($val('content')) ?></textarea>

    <div class="row g-2">
      <div class="col-sm-6">
        <label class="form-label-custom">प्राधान्य</label>
        <select name="priority" class="form-select-custom mb-3">
          <?php foreach (['normal' => 'सर्वसाधारण', 'important' => '⚠️ महत्त्वाचं', 'urgent' => '🚨 तातडीचं'] as $k => $lbl): ?>
            <option value="<?= $k ?>" <?= $val('priority', 'normal') === $k ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-6">
        <label class="form-label-custom">मुदत संपण्याची तारीख <span style="font-weight:400;color:var(--text-dim);">(ऐच्छिक)</span></label>
        <input type="date" name="expires_at" class="form-control-custom mb-3" value="<?= h($val('expires_at')) ?>">
      </div>
    </div>
    <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:14px;"><input type="checkbox" name="is_pinned" value="1" <?= $val('is_pinned') ? 'checked' : '' ?> style="width:16px;height:16px;"> 📌 यादीत सर्वात वर ठेवा (Pin)</label>

    <label class="form-label-custom">कोणाला पाठवायचं</label>
    <select name="target_scope" id="targetScope" class="form-select-custom mb-3" onchange="document.getElementById('deptPicker').style.display = this.value === 'specific' ? 'block' : 'none';">
      <option value="all">🏢 संपूर्ण संस्था (सगळे)</option>
      <option value="specific" <?= $isSpecific ? 'selected' : '' ?>>🎯 निवडक विभाग</option>
    </select>

    <div id="deptPicker" style="display:<?= $isSpecific ? 'block' : 'none' ?>;background:var(--canvas);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
      <?php foreach ($departments as $d): ?>
        <label style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:13px;">
          <input type="checkbox" name="department_ids[]" value="<?= $d['id'] ?>" <?= in_array((int)$d['id'], $editDepts, true) ? 'checked' : '' ?> style="width:16px;height:16px;">
          <?= h($d['name_local'] ?: $d['name']) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;"><?= $editing ? '💾 बदल जतन करा' : '📢 सूचना पाठवा' ?></button>
    <?php if ($editing): ?><div style="font-size:11px;color:var(--text-dim);margin-top:6px;text-align:center;">बदल जतन केल्यावर पुन्हा Notification जात नाही.</div><?php endif; ?>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
