<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
if (!hasPermission('manage_sop')) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला SOP व्यवस्थापन करण्याची परवानगी नाही. <a href="../index.php">Dashboard var परत जा</a></div>');
}

$orgId = activeOrgId();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_sop') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if ($title) {
            $pdo->prepare("INSERT INTO sops (organization_id, title, description, created_by) VALUES (?, ?, ?, ?)")
                ->execute([$orgId, $title, $description, $user['id']]);
            $_SESSION['flash'] = "'$title' SOP तयार झालं — आता त्यात पायऱ्या जोडा.";
            header('Location: edit.php?id=' . $pdo->lastInsertId());
            exit;
        }
    } elseif ($action === 'delete_sop') {
        $pdo->prepare("DELETE FROM sops WHERE id = ? AND organization_id = ?")->execute([(int)$_POST['id'], $orgId]);
        $_SESSION['flash'] = 'SOP काढलं गेलं.';
    } elseif ($action === 'toggle_sop') {
        $pdo->prepare("UPDATE sops SET is_active = 1 - is_active WHERE id = ? AND organization_id = ?")->execute([(int)$_POST['id'], $orgId]);
        $_SESSION['flash'] = 'स्थिती बदलली.';
    }
    header('Location: index.php');
    exit;
}

$pageTitle = 'SOP Compliance Tracking';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/sop-helpers.php';

$sopsStmt = $pdo->prepare("
    SELECT s.*, (SELECT COUNT(*) FROM sop_steps WHERE sop_id = s.id) AS step_count,
      (SELECT COUNT(*) FROM sop_department_assignments WHERE sop_id = s.id) AS dept_count
    FROM sops s WHERE s.organization_id = ? ORDER BY s.created_at DESC
");
$sopsStmt->execute([$orgId]);
$sops = $sopsStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div>
    <div class="page-title mb-1">✅ SOP Compliance Tracking</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">Standard Operating Procedures — विभाग किती % पालन करत आहेत, हे स्वयंचलितपणे Track करा</div>
  </div>
  <a href="compliance-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;"><i class="fa-solid fa-chart-line me-1"></i>Compliance अहवाल</a>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">नवीन SOP तयार करा</div></div>
  <form method="POST" style="padding:20px 24px;">
    <input type="hidden" name="action" value="create_sop">
    <div class="row g-3">
      <div class="col-md-5">
        <label class="form-label-custom">SOP चं नाव</label>
        <input type="text" name="title" class="form-control-custom" placeholder="उदा. वसतिगृह दैनिक तपासणी SOP" required>
      </div>
      <div class="col-md-7">
        <label class="form-label-custom">थोडक्यात वर्णन</label>
        <input type="text" name="description" class="form-control-custom">
      </div>
      <div class="col-12 d-flex justify-content-end">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">तयार करा — मग पायऱ्या जोडा</button>
      </div>
    </div>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>SOP</th><th>पायऱ्या</th><th>जोडलेले विभाग</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($sops)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतंही SOP नाही</td></tr><?php endif; ?>
      <?php foreach ($sops as $s): ?>
        <tr>
          <td style="font-weight:500;"><a href="edit.php?id=<?= $s['id'] ?>" style="color:var(--rail);text-decoration:none;"><?= h($s['title']) ?></a><div style="font-size:11px;color:var(--text-dim);"><?= h($s['description']) ?></div></td>
          <td><?= $s['step_count'] ?></td>
          <td><?= $s['dept_count'] ?></td>
          <td>
            <form method="POST" style="display:inline;">
              <input type="hidden" name="action" value="toggle_sop">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="status-chip <?= $s['is_active'] ? 'done' : 'overdue' ?>" style="border:none;cursor:pointer;"><?= $s['is_active'] ? 'सक्रिय' : 'निष्क्रिय' ?></button>
            </form>
          </td>
          <td class="d-flex gap-2">
            <a href="edit.php?id=<?= $s['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;">संपादित करा</a>
            <form method="POST" onsubmit="return confirm('हे SOP कायमचं काढायचं?');">
              <input type="hidden" name="action" value="delete_sop">
              <input type="hidden" name="id" value="<?= $s['id'] ?>">
              <button type="submit" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;">काढा</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
