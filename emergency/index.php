<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

if (!hasPermission('view_emergency_alerts') && !hasRole('super_admin')) {
    http_response_code(403);
    die('तुम्हाला हे पान बघण्याची परवानगी नाही.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resolve') {
    $alertId = (int)($_POST['alert_id'] ?? 0);
    $pdo->prepare("UPDATE emergency_alerts SET status = 'resolved', resolved_by = ?, resolved_at = NOW() WHERE id = ? AND organization_id = ?")
        ->execute([$user['id'], $alertId, $orgId]);
    logActivity('आणीबाणी सूचना Resolve केली', 'emergency_alert', $alertId);
    $_SESSION['flash'] = 'सूचना Resolved म्हणून चिन्हांकित केली.';
    header('Location: index.php');
    exit;
}

$pageTitle = 'आणीबाणी सूचना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$stmt = $pdo->prepare("
    SELECT ea.*, u.name AS triggered_by_name, u.mobile AS triggered_by_mobile, ru.name AS resolved_by_name
    FROM emergency_alerts ea JOIN users u ON u.id = ea.triggered_by LEFT JOIN users ru ON ru.id = ea.resolved_by
    WHERE ea.organization_id = ? ORDER BY ea.status = 'active' DESC, ea.created_at DESC
");
$stmt->execute([$orgId]);
$alerts = $stmt->fetchAll();

$typeLabels = ['fire' => '🔥 आग', 'medical' => '🚑 वैद्यकीय', 'security' => '🛡️ सुरक्षा', 'other' => '🆘 इतर'];
$activeCount = count(array_filter($alerts, fn($a) => $a['status'] === 'active'));
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">🆘 आणीबाणी सूचना</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php if ($activeCount > 0): ?>
  <div class="alert" style="background:var(--rose-soft);color:var(--rose);border-radius:10px;font-size:14px;padding:16px 20px;font-weight:600;margin-bottom:20px;">
    🚨 सध्या <?= $activeCount ?> सक्रिय आणीबाणी सूचना आहे/आहेत!
  </div>
<?php endif; ?>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>प्रकार</th><th>कोणी पाठवली</th><th>स्थान</th><th>तपशील</th><th>दिनांक</th><th>स्थिती</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($alerts)): ?><tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलीही आणीबाणी सूचना नाही</td></tr><?php endif; ?>
      <?php foreach ($alerts as $a): ?>
        <tr style="<?= $a['status'] === 'active' ? 'background:var(--rose-soft);' : '' ?>">
          <td style="font-weight:600;"><?= h($typeLabels[$a['alert_type']] ?? $a['alert_type']) ?></td>
          <td><?= h($a['triggered_by_name']) ?><?php if ($a['triggered_by_mobile']): ?><div style="font-size:10.5px;color:var(--text-dim);"><a href="tel:<?= h($a['triggered_by_mobile']) ?>" style="color:var(--rail);">📞 <?= h($a['triggered_by_mobile']) ?></a></div><?php endif; ?></td>
          <td><?= h($a['location'] ?: '—') ?></td>
          <td style="max-width:220px;"><?= h($a['message'] ?: '—') ?></td>
          <td style="font-size:11.5px;"><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></td>
          <td>
            <?php if ($a['status'] === 'active'): ?>
              <span class="status-chip overdue">🔴 सक्रिय</span>
            <?php else: ?>
              <span class="status-chip done">✅ Resolved</span>
              <div style="font-size:10px;color:var(--text-dim);"><?= h($a['resolved_by_name']) ?> — <?= date('d M, h:i A', strtotime($a['resolved_at'])) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($a['status'] === 'active'): ?>
              <form method="POST"><input type="hidden" name="action" value="resolve"><input type="hidden" name="alert_id" value="<?= $a['id'] ?>">
                <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;font-size:11px;padding:5px 12px;">✓ Resolved करा</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
