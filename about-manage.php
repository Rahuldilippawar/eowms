<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
requireRole(['super_admin'], '');
$user = currentUser();

$orgId = activeOrgId();

function uploadAboutPhoto($fieldName) {
    if (empty($_FILES[$fieldName]['name']) || $_FILES[$fieldName]['error'] !== UPLOAD_ERR_OK) { return null; }
    $uploadDir = __DIR__ . '/assets/uploads/about/';
    if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
    $ext = strtolower(pathinfo($_FILES[$fieldName]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) { return null; }
    $safeName = uniqid('about_') . '.' . $ext;
    if (move_uploaded_file($_FILES[$fieldName]['tmp_name'], $uploadDir . $safeName)) {
        return 'assets/uploads/about/' . $safeName;
    }
    return null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_section') {
        $id = (int)($_POST['id'] ?? 0);
        $sectionType = $_POST['section_type'] ?? 'guide';
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $photoPath = uploadAboutPhoto('photo');

        if ($id) {
            if ($photoPath) {
                $pdo->prepare("UPDATE about_sections SET title=?, description=?, photo_path=? WHERE id=? AND organization_id=?")
                    ->execute([$title, $description, $photoPath, $id, $orgId]);
            } else {
                $pdo->prepare("UPDATE about_sections SET title=?, description=? WHERE id=? AND organization_id=?")
                    ->execute([$title, $description, $id, $orgId]);
            }
            $_SESSION['flash'] = 'विभाग अद्ययावत केला.';
        } else {
            $pdo->prepare("INSERT INTO about_sections (organization_id, section_type, title, description, photo_path) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $sectionType, $title, $description, $photoPath]);
            $_SESSION['flash'] = 'नवीन विभाग जोडला.';
        }
    } elseif ($action === 'delete_section') {
        $pdo->prepare("DELETE FROM about_sections WHERE id = ? AND organization_id = ?")->execute([(int)$_POST['id'], $orgId]);
        $_SESSION['flash'] = 'विभाग काढला.';
    } elseif ($action === 'save_contributor') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $contribution = trim($_POST['contribution'] ?? '');
        $bio = trim($_POST['bio'] ?? '');
        $photoPath = uploadAboutPhoto('photo');

        if ($id) {
            if ($photoPath) {
                $pdo->prepare("UPDATE about_contributors SET name=?, contribution=?, bio=?, photo_path=? WHERE id=? AND organization_id=?")
                    ->execute([$name, $contribution, $bio, $photoPath, $id, $orgId]);
            } else {
                $pdo->prepare("UPDATE about_contributors SET name=?, contribution=?, bio=? WHERE id=? AND organization_id=?")
                    ->execute([$name, $contribution, $bio, $id, $orgId]);
            }
            $_SESSION['flash'] = 'योगदानकर्ता अद्ययावत केला.';
        } else {
            $pdo->prepare("INSERT INTO about_contributors (organization_id, name, contribution, bio, photo_path) VALUES (?, ?, ?, ?, ?)")
                ->execute([$orgId, $name, $contribution, $bio, $photoPath]);
            $_SESSION['flash'] = 'नवीन योगदानकर्ता जोडला.';
        }
    } elseif ($action === 'delete_contributor') {
        $pdo->prepare("DELETE FROM about_contributors WHERE id = ? AND organization_id = ?")->execute([(int)$_POST['id'], $orgId]);
        $_SESSION['flash'] = 'योगदानकर्ता काढला.';
    }

    header('Location: about-manage.php');
    exit;
}

$pageTitle = 'About Page व्यवस्थापन';
$rootPath = '';
require_once __DIR__ . '/includes/header.php';

$sectionsStmt = $pdo->prepare("SELECT * FROM about_sections WHERE organization_id = ? ORDER BY FIELD(section_type,'god','sadguru','guide'), display_order");
$sectionsStmt->execute([$orgId]);
$sections = $sectionsStmt->fetchAll();

$contribStmt = $pdo->prepare("SELECT * FROM about_contributors WHERE organization_id = ? ORDER BY display_order, name");
$contribStmt->execute([$orgId]);
$contributors = $contribStmt->fetchAll();

$sectionLabels = ['god' => '🕉️ देव', 'sadguru' => '🙏 सद्गुरू आशीर्वाद', 'guide' => '🧭 मार्गदर्शक'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🕉️ About Page व्यवस्थापन</div>
  <a href="about.php" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-eye me-1"></i>About Page बघा</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">देव, सद्गुरू आशीर्वाद, मार्गदर्शक — प्रत्येकाचा फोटो व माहिती इथून जोडा/बदला. खाली प्रकल्पासाठी योगदान देणाऱ्यांची यादी.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन विभाग जोडा (देव / सद्गुरू / मार्गदर्शक)</div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:20px 24px;">
    <input type="hidden" name="action" value="save_section">
    <div class="row g-3">
      <div class="col-md-3">
        <label class="form-label-custom">प्रकार</label>
        <select name="section_type" class="form-select-custom">
          <option value="god">🕉️ देव</option>
          <option value="sadguru">🙏 सद्गुरू आशीर्वाद</option>
          <option value="guide">🧭 मार्गदर्शक</option>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">नाव/शीर्षक</label>
        <input type="text" name="title" class="form-control-custom" placeholder="उदा. श्री जंगली महाराज" required>
      </div>
      <div class="col-md-5">
        <label class="form-label-custom">फोटो</label>
        <input type="file" name="photo" accept="image/*" class="form-control-custom">
      </div>
      <div class="col-12">
        <label class="form-label-custom">वर्णन/आशीर्वाद-वचन</label>
        <textarea name="description" class="form-control-custom" rows="2" placeholder="छोटं वर्णन किंवा आशीर्वादाचे शब्द"></textarea>
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">जोडा</button>
      </div>
    </div>
  </form>
</div>

<div class="panel mb-4">
  <table class="rtable">
    <thead><tr><th>फोटो</th><th>प्रकार</th><th>नाव</th><th>वर्णन</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($sections)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:20px;">अजून काही जोडलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($sections as $s): ?>
        <tr>
          <td><?php if ($s['photo_path']): ?><img src="<?= h($s['photo_path']) ?>" style="width:44px;height:44px;border-radius:8px;object-fit:cover;"><?php else: ?>—<?php endif; ?></td>
          <td><?= h($sectionLabels[$s['section_type']] ?? $s['section_type']) ?></td>
          <td style="font-weight:500;"><?= h($s['title']) ?></td>
          <td style="font-size:12px;color:var(--text-dim);max-width:280px;"><?= h(mb_substr($s['description'] ?? '', 0, 80)) ?></td>
          <td>
            <form method="POST" onsubmit="return confirm('काढायचं?');">
              <input type="hidden" name="action" value="delete_section">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;cursor:pointer;">काढा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">👥 प्रकल्पासाठी योगदान देणारे (Contributors)</div></div>
  <form method="POST" enctype="multipart/form-data" style="padding:20px 24px;">
    <input type="hidden" name="action" value="save_contributor">
    <div class="row g-3">
      <div class="col-md-4">
        <label class="form-label-custom">नाव</label>
        <input type="text" name="name" class="form-control-custom" required>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">योगदान (काय दिलं)</label>
        <input type="text" name="contribution" class="form-control-custom" placeholder="उदा. संकल्पना व गरजा सांगितल्या, Testing केलं">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">फोटो</label>
        <input type="file" name="photo" accept="image/*" class="form-control-custom">
      </div>
      <div class="col-12">
        <label class="form-label-custom">थोडक्यात परिचय (Bio)</label>
        <textarea name="bio" class="form-control-custom" rows="2"></textarea>
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">जोडा</button>
      </div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>फोटो</th><th>नाव</th><th>योगदान</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($contributors)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:20px;">अजून कोणीही जोडलेलं नाही</td></tr><?php endif; ?>
      <?php foreach ($contributors as $c): ?>
        <tr>
          <td><?php if ($c['photo_path']): ?><img src="<?= h($c['photo_path']) ?>" style="width:44px;height:44px;border-radius:50%;object-fit:cover;"><?php else: ?>—<?php endif; ?></td>
          <td style="font-weight:500;"><?= h($c['name']) ?></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($c['contribution']) ?></td>
          <td>
            <form method="POST" onsubmit="return confirm('काढायचं?');">
              <input type="hidden" name="action" value="delete_contributor">
              <input type="hidden" name="id" value="<?= $c['id'] ?>">
              <button type="submit" style="border:none;background:none;color:var(--rose);font-size:12px;cursor:pointer;">काढा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
