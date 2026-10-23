<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/cctv-helpers.php';
$user = currentUser();
requireCctvAccess($pdo, $user);
$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['update', 'toggle_active'], true)) {
    // DVR बदलणे / बंद-चालू करणे — फक्त याच संस्थेचा DVR
    $dvrId = (int)($_POST['dvr_id'] ?? 0);
    $dvrStmt = $pdo->prepare("SELECT * FROM cctv_dvrs WHERE id = ? AND organization_id = ?");
    $dvrStmt->execute([$dvrId, $orgId]);
    $dvr = $dvrStmt->fetch();
    if (!$dvr) {
        $_SESSION['flash'] = 'DVR सापडला नाही.';
    } elseif ($_POST['action'] === 'toggle_active') {
        $newActive = $dvr['is_active'] ? 0 : 1;
        $pdo->prepare("UPDATE cctv_dvrs SET is_active = ? WHERE id = ? AND organization_id = ?")->execute([$newActive, $dvrId, $orgId]);
        logActivity($newActive ? 'CCTV DVR पुन्हा सुरू केला' : 'CCTV DVR बंद केला', 'cctv_dvr', $dvrId, $dvr['dvr_name']);
        $_SESSION['flash'] = "'{$dvr['dvr_name']}' " . ($newActive ? 'पुन्हा सक्रिय केला.' : 'निष्क्रिय केला.');
    } else {
        $dvrName = trim($_POST['dvr_name'] ?? '');
        $installedDate = ($_POST['installed_date'] ?? '') ?: null;
        if ($dvrName === '' || ($installedDate && !DateTime::createFromFormat('!Y-m-d', $installedDate))) {
            $_SESSION['flash'] = 'DVR चं नाव/तारीख अवैध — बदल झाला नाही.';
        } else {
            $pdo->prepare("UPDATE cctv_dvrs SET dvr_name = ?, location = ?, channel_capacity = ?, storage_capacity_tb = ?, installed_date = ? WHERE id = ? AND organization_id = ?")
                ->execute([$dvrName, trim($_POST['location'] ?? ''),
                    ($_POST['channel_capacity'] ?? '') !== '' ? (int)$_POST['channel_capacity'] : null,
                    ($_POST['storage_capacity_tb'] ?? '') !== '' ? (float)$_POST['storage_capacity_tb'] : null,
                    $installedDate, $dvrId, $orgId]);
            logActivity('CCTV DVR बदलला', 'cctv_dvr', $dvrId, $dvrName);
            $_SESSION['flash'] = "'$dvrName' अद्ययावत झाला.";
        }
    }
    header('Location: dvrs.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dvrName = trim($_POST['dvr_name'] ?? '');
    $location = trim($_POST['location'] ?? '');
    $channelCapacity = ($_POST['channel_capacity'] ?? '') !== '' ? (int)$_POST['channel_capacity'] : null;
    $storageCapacity = ($_POST['storage_capacity_tb'] ?? '') !== '' ? (float)$_POST['storage_capacity_tb'] : null;
    $installedDate = ($_POST['installed_date'] ?? '') ?: null;
    if ($installedDate && !DateTime::createFromFormat('!Y-m-d', $installedDate)) { $installedDate = null; }

    if ($dvrName) {
        $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'CCTV' LIMIT 1");
        $deptStmt->execute([$orgId]);
        $deptId = $deptStmt->fetch()['id'] ?? null;

        $pdo->prepare("INSERT INTO cctv_dvrs (organization_id, department_id, dvr_name, location, channel_capacity, storage_capacity_tb, installed_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$orgId, $deptId, $dvrName, $location, $channelCapacity, $storageCapacity, $installedDate, $user['id']]);
        logActivity('CCTV DVR जोडला', 'cctv_dvr', $pdo->lastInsertId(), $dvrName);
        $_SESSION['flash'] = "'$dvrName' DVR जोडला गेला.";
    }
    header('Location: dvrs.php');
    exit;
}

$pageTitle = 'DVR यादी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$dvrsStmt = $pdo->prepare("
    SELECT d.*, (SELECT COUNT(*) FROM cctv_cameras WHERE dvr_id = d.id AND is_active = 1) AS camera_count
    FROM cctv_dvrs d WHERE d.organization_id = ? ORDER BY d.dvr_name
");
$dvrsStmt->execute([$orgId]);
$dvrs = $dvrsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📀 DVR यादी</div>
  <div class="d-flex gap-2">
    <a href="import.php" style="font-size:12px;color:var(--amber);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-file-import me-1"></i>DVR/Camera Import करा</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>CCTV विभाग</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन DVR जोडा</div></div>
  <form method="POST" style="padding:18px 24px;">
    <div class="row g-3">
      <div class="col-md-3"><label class="form-label-custom">DVR चं नाव</label><input type="text" name="dvr_name" class="form-control-custom" placeholder="उदा. मुख्य इमारत DVR" required></div>
      <div class="col-md-3"><label class="form-label-custom">ठिकाण</label><input type="text" name="location" class="form-control-custom" placeholder="Server Room"></div>
      <div class="col-md-2"><label class="form-label-custom">Channel क्षमता</label><input type="number" name="channel_capacity" class="form-control-custom" placeholder="16"></div>
      <div class="col-md-2"><label class="form-label-custom">Storage (TB)</label><input type="number" step="0.1" name="storage_capacity_tb" class="form-control-custom" placeholder="4"></div>
      <div class="col-md-2"><label class="form-label-custom">बसवल्याचा दिनांक</label><input type="date" name="installed_date" class="form-control-custom"></div>
    </div>
    <button type="submit" class="btn mt-3" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">DVR जोडा</button>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>DVR</th><th>ठिकाण</th><th>Channel क्षमता</th><th>Storage</th><th>Cameras</th><th>बसवल्याचा दिनांक</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($dvrs)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही DVR नाही</td></tr><?php endif; ?>
      <?php foreach ($dvrs as $d): ?>
        <tr style="<?= $d['is_active'] ? '' : 'opacity:.55;' ?>">
          <td style="font-weight:600;"><?= h($d['dvr_name']) ?><?= $d['is_active'] ? '' : ' <span class="status-chip overdue">निष्क्रिय</span>' ?></td>
          <td><?= h($d['location']) ?></td>
          <td><?= $d['channel_capacity'] ?: '—' ?></td>
          <td><?= $d['storage_capacity_tb'] ? $d['storage_capacity_tb'] . ' TB' : '—' ?></td>
          <td><?= $d['camera_count'] ?></td>
          <td><?= $d['installed_date'] ? date('d M Y', strtotime($d['installed_date'])) : '—' ?></td>
          <td style="white-space:nowrap;">
            <button type="button" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 10px;" onclick="document.getElementById('dvrEdit<?= $d['id'] ?>').classList.toggle('d-none')">Edit</button>
            <form method="POST" style="display:inline;" onsubmit="return confirm('<?= $d['is_active'] ? 'हा DVR निष्क्रिय करायचा?' : 'हा DVR पुन्हा सक्रिय करायचा?' ?>')">
              <input type="hidden" name="action" value="toggle_active"><input type="hidden" name="dvr_id" value="<?= $d['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:11px;padding:3px 10px;"><?= $d['is_active'] ? 'निष्क्रिय करा' : 'सक्रिय करा' ?></button>
            </form>
          </td>
        </tr>
        <tr id="dvrEdit<?= $d['id'] ?>" class="d-none">
          <td colspan="7">
            <form method="POST" class="row g-2 align-items-end">
              <input type="hidden" name="action" value="update"><input type="hidden" name="dvr_id" value="<?= $d['id'] ?>">
              <div class="col-md-3"><input type="text" name="dvr_name" class="form-control-custom" value="<?= h($d['dvr_name']) ?>" required></div>
              <div class="col-md-3"><input type="text" name="location" class="form-control-custom" value="<?= h($d['location']) ?>" placeholder="ठिकाण"></div>
              <div class="col-md-1"><input type="number" name="channel_capacity" class="form-control-custom" value="<?= h($d['channel_capacity']) ?>" placeholder="Ch"></div>
              <div class="col-md-1"><input type="number" step="0.1" name="storage_capacity_tb" class="form-control-custom" value="<?= h($d['storage_capacity_tb']) ?>" placeholder="TB"></div>
              <div class="col-md-2"><input type="date" name="installed_date" class="form-control-custom" value="<?= h($d['installed_date']) ?>"></div>
              <div class="col-md-2"><button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:12px;">जतन करा</button></div>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
