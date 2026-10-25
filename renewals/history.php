<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/renewal-helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$id = (int)($_GET['id'] ?? 0);
$item = renewalFetchAccessibleItem($pdo, $id, true);
if (!$item) {
    http_response_code(403);
    die('नोंद सापडली नाही किंवा तुम्हाला ती बघण्याची परवानगी नाही.');
}
$attachmentsByCycle = renewalAttachmentsByCycle($pdo, $id);
$statusInfo = renewalStatusInfo(renewalDaysLeft($item['expiry_date']));

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
    <strong>सध्याची मुदत:</strong> <?= date('d M Y', strtotime($item['expiry_date'])) ?>
      <span class="status-chip <?= $item['status'] === 'inactive' ? '' : $statusInfo['chip'] ?>" style="margin-left:6px;"><?= $item['status'] === 'inactive' ? 'निष्क्रिय' : h($statusInfo['label']) ?></span><br>
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

<div class="panel mt-3">
  <div class="panel-head"><div class="title">📎 प्रमाणपत्रे (Cycle-निहाय)</div><?php if ($item['status'] !== 'inactive'): ?><div class="sub"><a href="edit.php?id=<?= $id ?>" style="color:var(--rail);">नवीन जोडा →</a></div><?php endif; ?></div>
  <div style="padding:14px 20px;font-size:12.5px;">
    <?php if (empty($attachmentsByCycle)): ?><div style="color:var(--text-dim);">अजून कोणतंही प्रमाणपत्र जोडलेलं नाही.</div><?php endif; ?>
    <?php foreach ($attachmentsByCycle as $cycle => $files): ?>
      <div style="font-weight:700;margin:8px 0 4px;">मुदत <?= $cycle ? date('d M Y', strtotime($cycle)) : '—' ?> पर्यंतचं Cycle</div>
      <?php foreach ($files as $a): ?>
        <div style="padding:3px 0;"><a href="attachment.php?id=<?= $a['id'] ?>" style="color:var(--rail);"><i class="fa-solid fa-paperclip me-1"></i><?= h($a['title'] ?: $a['original_filename']) ?></a>
          <span style="color:var(--text-dim);font-size:11px;"> · <?= h($a['uploaded_by_name'] ?? '') ?> · <?= date('d M Y', strtotime($a['uploaded_at'])) ?></span></div>
      <?php endforeach; ?>
    <?php endforeach; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
