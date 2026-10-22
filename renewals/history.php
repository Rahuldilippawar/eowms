<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT r.*, d.name_local AS dept_name FROM renewal_items r JOIN departments d ON d.id = r.department_id WHERE r.id = ? AND r.organization_id = ?");
$stmt->execute([$id, $orgId]);
$item = $stmt->fetch();
if (!$item) { die('नोंद सापडली नाही.'); }

if (isDeptScoped() && (int)$item['department_id'] !== (int)$user['department_id']) {
    http_response_code(403);
    die('तुम्हाला ही नोंद बघण्याची परवानगी नाही.');
}

$pageTitle = 'नूतनीकरण इतिहास';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$historyItems = [];
try {
    $histStmt = $pdo->prepare("
        SELECT h.*, u.name AS renewed_by_name FROM renewal_history h LEFT JOIN users u ON u.id = h.renewed_by
        WHERE h.renewal_item_id = ? ORDER BY h.renewed_at DESC
    ");
    $histStmt->execute([$id]);
    $historyItems = $histStmt->fetchAll();
} catch (PDOException $e) { /* migration not run yet */ }
?>

<div class="page-eyebrow"><?= h($item['dept_name']) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📜 <?= h($item['item_name']) ?> — नूतनीकरण इतिहास</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>नूतनीकरण यादी</a>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">सध्याची स्थिती</div></div>
  <div style="padding:16px 20px;font-size:13px;line-height:2;">
    <strong>प्रकार:</strong> <?= h($item['item_type']) ?><br>
    <strong>सध्याची मुदत:</strong> <?= date('d M Y', strtotime($item['expiry_date'])) ?><br>
    <strong>सध्याचा खर्च:</strong> <?= $item['renewal_cost'] ? '₹' . number_format($item['renewal_cost'], 0) : '—' ?><br>
    <strong>सध्याचा Vendor:</strong> <?= h($item['vendor_name'] ?: '—') ?>
  </div>
</div>

<div class="panel">
  <div class="panel-head"><div class="title">📜 मागील नूतनीकरणं (<?= count($historyItems) ?>)</div></div>
  <table class="rtable">
    <thead><tr><th>जुनी मुदत</th><th>जुना खर्च</th><th>जुना Vendor</th><th>कोणी Renew केलं</th><th>दिनांक</th></tr></thead>
    <tbody>
      <?php if (empty($historyItems)): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कधीही Renew झालेलं नाही — ही पहिलीच नोंद आहे</td></tr>
      <?php endif; ?>
      <?php foreach ($historyItems as $h): ?>
        <tr>
          <td><?= date('d M Y', strtotime($h['old_expiry_date'])) ?></td>
          <td><?= $h['old_renewal_cost'] ? '₹' . number_format($h['old_renewal_cost'], 0) : '—' ?></td>
          <td><?= h($h['old_vendor_name'] ?: '—') ?></td>
          <td><?= h($h['renewed_by_name'] ?? '—') ?></td>
          <td><?= date('d M Y', strtotime($h['renewed_at'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
