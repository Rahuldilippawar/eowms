<?php
$pageTitle = 'Login/Logout Activity';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();

$filterUserId = (int)($_GET['user_id'] ?? 0);
$filterDate = $_GET['date'] ?? '';

$sql = "SELECT * FROM activity_logs WHERE organization_id = ? AND (action LIKE '%Login%' OR action LIKE '%Logout%')";
$params = [$orgId];
if ($filterUserId) { $sql .= " AND user_id = ?"; $params[] = $filterUserId; }
if ($filterDate) { $sql .= " AND DATE(created_at) = ?"; $params[] = $filterDate; }
$sql .= " ORDER BY created_at DESC LIMIT 300";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (PDOException $e) {
    $logs = [];
}

// आजचा सारांश
try {
    $todayLoginStmt = $pdo->prepare("SELECT COUNT(DISTINCT user_id) c FROM activity_logs WHERE organization_id = ? AND action LIKE '%Login%' AND DATE(created_at) = CURDATE()");
    $todayLoginStmt->execute([$orgId]);
    $todayLoginCount = (int)$todayLoginStmt->fetch()['c'];
} catch (PDOException $e) { $todayLoginCount = 0; }

$usersStmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? ORDER BY name");
$usersStmt->execute([$orgId]);
$allUsers = $usersStmt->fetchAll();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🔐 Login / Logout Activity</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>संपूर्ण Activity Log</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">सगळ्या Users चे Login/Logout — Password आणि Fingerprint/Face ID दोन्ही पद्धतींसह</p>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">आज Login केलेले (वेगळे Users)</div><div class="val" style="color:var(--rail);"><?= $todayLoginCount ?></div></div></div>
</div>

<div class="panel mb-3">
  <form method="GET" style="padding:16px 20px;display:flex;gap:12px;flex-wrap:wrap;align-items:end;">
    <div>
      <label class="form-label-custom">व्यक्ती</label>
      <select name="user_id" class="form-select-custom" style="min-width:200px;">
        <option value="">-- सगळे --</option>
        <?php foreach ($allUsers as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUserId === (int)$u['id'] ? 'selected' : '' ?>><?= h($u['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label-custom">दिनांक</label>
      <input type="date" name="date" class="form-control-custom" value="<?= h($filterDate) ?>">
    </div>
    <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">Filter करा</button>
    <?php if ($filterUserId || $filterDate): ?><a href="login-activity.php" style="font-size:12.5px;color:var(--text-dim);align-self:center;">साफ करा</a><?php endif; ?>
  </form>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>दिनांक/वेळ</th><th>व्यक्ती</th><th>प्रकार</th><th>पद्धत</th><th>तपशील</th></tr></thead>
    <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">काहीही सापडलं नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log):
        $isLogin = stripos($log['action'], 'login') !== false;
        $method = stripos($log['action'], 'fingerprint') !== false || stripos($log['action'], 'face id') !== false ? '👆 Fingerprint/Face ID' : ($isLogin ? '🔑 Password' : '—');
      ?>
        <tr>
          <td class="font-mono" style="font-size:12px;white-space:nowrap;"><?= date('d M Y, h:i A', strtotime($log['created_at'])) ?></td>
          <td style="font-weight:500;"><?= h($log['actor_name'] ?? '—') ?></td>
          <td><span class="status-chip <?= $isLogin ? 'done' : 'pending' ?>"><?= $isLogin ? '➡️ Login' : '⬅️ Logout' ?></span></td>
          <td style="font-size:12.5px;"><?= h($method) ?></td>
          <td style="font-size:12px;color:var(--text-dim);font-family:'JetBrains Mono',monospace;"><?= h($log['details'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
