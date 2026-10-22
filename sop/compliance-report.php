<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if (!hasPermission('manage_sop')) {
    http_response_code(403);
    die('तुम्हाला Compliance अहवाल पाहण्याची परवानगी नाही.');
}

require_once __DIR__ . '/../includes/sop-helpers.php';
$orgId = activeOrgId();

$pageTitle = 'SOP Compliance अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$sopsStmt = $pdo->prepare("SELECT * FROM sops WHERE organization_id = ? AND is_active = 1");
$sopsStmt->execute([$orgId]);
$sops = $sopsStmt->fetchAll();

$rows = [];
foreach ($sops as $sop) {
    $assignedStmt = $pdo->prepare("
        SELECT d.* FROM sop_department_assignments sda JOIN departments d ON d.id = sda.department_id
        WHERE sda.sop_id = ?
    ");
    $assignedStmt->execute([$sop['id']]);
    $depts = $assignedStmt->fetchAll();

    foreach ($depts as $dept) {
        $score = sopGetComplianceScore($pdo, $sop['id'], $dept['id'], 30);
        $rows[] = ['sop' => $sop, 'dept' => $dept, 'score' => $score];
    }
}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-1">📊 SOP Compliance अहवाल</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>SOP व्यवस्थापन</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">गेल्या 30 दिवसांतील सरासरी पालन (Compliance) — प्रत्येक विभाग, प्रत्येक SOP साठी</p>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>SOP</th><th>विभाग</th><th>Compliance %</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही डेटा नाही</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $score = $r['score']; ?>
        <tr>
          <td style="font-weight:500;"><?= h($r['sop']['title']) ?></td>
          <td><?= h($r['dept']['name_local'] ?: $r['dept']['name']) ?></td>
          <td>
            <?php if ($score === null): ?>
              <span style="color:var(--text-dim);font-size:12px;">डेटा नाही</span>
            <?php else: ?>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="background:var(--canvas);border-radius:10px;height:8px;width:100px;overflow:hidden;">
                  <div style="height:100%;width:<?= $score ?>%;background:<?= $score >= 80 ? 'var(--rail)' : ($score >= 50 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
                </div>
                <strong style="font-size:13px;"><?= $score ?>%</strong>
              </div>
            <?php endif; ?>
          </td>
          <td><a href="checklist.php?department_id=<?= $r['dept']['id'] ?>" style="font-size:11.5px;color:var(--rail);">Checklist बघा</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
