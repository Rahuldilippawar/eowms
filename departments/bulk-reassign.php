<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requirePermission('manage_departments', '../');
$user = currentUser();
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deptIds = array_map('intval', $_POST['dept_ids'] ?? []);
    $targetCampusId = ($_POST['target_campus_id'] ?? '') !== '' ? (int)$_POST['target_campus_id'] : null;

    if (empty($deptIds)) {
        $_SESSION['form_error'] = 'कृपया किमान एक विभाग निवडा.';
    } else {
        $placeholders = implode(',', array_fill(0, count($deptIds), '?'));
        if ($targetCampusId) {
            $cChk = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
            $cChk->execute([$targetCampusId, $orgId]);
            if (!$cChk->fetch()) {
                $_SESSION['form_error'] = 'निवडलेला कॅम्पस सापडला नाही.';
                header('Location: bulk-reassign.php');
                exit;
            }
        }
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE departments SET campus_id = ? WHERE id IN ($placeholders) AND organization_id = ?");
            $stmt->execute(array_merge([$targetCampusId], $deptIds, [$orgId]));
            // हलवलेल्या विभागांच्या कर्मचाऱ्यांचा कॅम्पसही बदलतो (Trust-स्तरावर हलवल्यास आधीचाच राहतो)
            if ($targetCampusId) {
                $pdo->prepare("UPDATE users SET campus_id = ? WHERE department_id IN ($placeholders) AND organization_id = ?")
                    ->execute(array_merge([$targetCampusId], $deptIds, [$orgId]));
            }
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) { throw $e; }
            $_SESSION['form_error'] = 'निवडलेल्या कॅम्पसमध्ये यांपैकी एखाद्या विभागाचा कोड आधीच वापरात आहे — काहीही बदललं नाही. आधी तो कोड बदला.';
            header('Location: bulk-reassign.php');
            exit;
        }

        $targetLabel = $targetCampusId ? null : 'Trust-स्तरीय';
        if ($targetCampusId) {
            $cStmt = $pdo->prepare("SELECT name FROM campuses WHERE id = ?");
            $cStmt->execute([$targetCampusId]);
            $targetLabel = $cStmt->fetch()['name'] ?? '';
        }
        logActivity('अनेक विभाग हलवले', 'department', null, count($deptIds) . ' विभाग "' . $targetLabel . '" कडे हलवले');
        $_SESSION['flash'] = count($deptIds) . " विभाग यशस्वीरित्या \"$targetLabel\" कडे हलवले गेले.";
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'विभाग — मोठ्या प्रमाणात हलवा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("
    SELECT d.*, c.name AS campus_name
    FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_active = 1
    ORDER BY c.name IS NULL, c.name, d.name_local
");
$stmt->execute([$orgId]);
$departments = $stmt->fetchAll();

$campusStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusStmt->execute([$orgId]);
$campuses = $campusStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔀 विभाग — मोठ्या प्रमाणात हलवा (Bulk Reassign)</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>विभाग व्यवस्थापन</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">तुमचे आधीचे सगळे विभाग एका College खाली असतील — इथून एकाच वेळी अनेक विभाग निवडून, ते "Trust अंतर्गत" किंवा दुसऱ्या College कडे हलवता येतील.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<form method="POST">
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">1️⃣ कोणते विभाग हलवायचे ते निवडा</div></div>
    <div style="padding:14px 20px;">
      <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin-bottom:10px;cursor:pointer;">
        <input type="checkbox" id="selectAll" style="width:16px;height:16px;"> सगळे निवडा/काढा
      </label>
      <div style="max-height:400px;overflow-y:auto;">
        <?php $lastCampus = null; ?>
        <?php foreach ($departments as $d): ?>
          <?php if ($d['campus_name'] !== $lastCampus): $lastCampus = $d['campus_name']; ?>
            <div style="font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.04em;margin:12px 0 4px;"><?= $d['campus_name'] ? h($d['campus_name']) : '🏛️ Trust-स्तरीय (आधीच)' ?></div>
          <?php endif; ?>
          <label style="display:flex;align-items:center;gap:10px;padding:7px 0;font-size:13px;cursor:pointer;border-bottom:1px solid var(--line);">
            <input type="checkbox" name="dept_ids[]" value="<?= $d['id'] ?>" class="dept-checkbox" style="width:16px;height:16px;">
            <span><?= h($d['name_local'] ?: $d['name']) ?></span>
            <span class="font-mono" style="font-size:10px;color:var(--text-dim);">(<?= h($d['code']) ?>)</span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="panel" style="max-width:480px;">
    <div class="panel-head"><div class="title">2️⃣ कुठे हलवायचे</div></div>
    <div style="padding:18px 20px;">
      <select name="target_campus_id" class="form-select-custom mb-3">
        <option value="">🏛️ थेट Trust अंतर्गत (कुठल्याही College/School खाली नाही)</option>
        <?php foreach ($campuses as $c): ?>
          <option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px;font-weight:600;">निवडलेले विभाग हलवा</button>
    </div>
  </div>
</form>

<script>
document.getElementById('selectAll').addEventListener('change', function () {
  document.querySelectorAll('.dept-checkbox').forEach(function (cb) { cb.checked = document.getElementById('selectAll').checked; });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
