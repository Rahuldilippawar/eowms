<?php
// 🏛️ Campus प्रमुख नेमणूक (केंद्रीय प्रमुख इथे; प्रत्येक Campus चा प्रमुख त्याच्या Edit पानावर) — फक्त Super Admin
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/campus-heads.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($msg = campusHeadsHandlePost($pdo, activeOrgId())) !== null) {
    $_SESSION['flash'] = $msg; header('Location: index.php'); exit;
}
$pageTitle = 'कॅम्पस व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_campuses', '../');

require_once __DIR__ . '/../includes/smart-insights.php';
$stmt = $pdo->prepare("
    SELECT c.*,
      (SELECT COUNT(*) FROM departments d WHERE d.campus_id = c.id) AS dept_count,
      (SELECT COUNT(*) FROM departments d WHERE d.campus_id = c.id AND d.is_active = 1) AS dept_active,
      (SELECT COUNT(*) FROM departments d WHERE d.campus_id = c.id AND d.is_active = 1 AND d.hod_id IS NULL) AS dept_nohod,
      (SELECT COUNT(*) FROM users u WHERE u.campus_id = c.id AND u.is_active = 1) AS staff_active
    FROM campuses c WHERE c.organization_id = ? ORDER BY c.name
");
$stmt->execute([activeOrgId()]);
$campuses = $stmt->fetchAll();
// 🏛️ नेमलेले Campus प्रमुख (Principal / Manager)
$headNames = [];
if (campusHeadsReady($pdo)) {
    $hs = $pdo->prepare("SELECT h.campus_id, u.name, h.title FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.organization_id = ? ORDER BY u.name");
    $hs->execute([activeOrgId()]);
    foreach ($hs->fetchAll() as $hr) { $headNames[$hr['campus_id'] === null ? 'all' : (int)$hr['campus_id']][] = $hr['name'] . ($hr['title'] ? ' (' . $hr['title'] . ')' : ''); }
}

// 🎓 NAAC / UGC मान्यता — मुदत संपली / ९० दिवसांत संपते
$today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+90 days'));
$validity = function ($d) use ($today, $soon) {
    if (!$d) return null;
    if ($d < $today) return ['bad', 'संपली'];
    if ($d <= $soon) return ['warn', (int)((strtotime($d) - strtotime($today)) / 86400) . ' दिवस'];
    return ['ok', ''];
};
$alerts = []; $expiring = 0;
foreach ($campuses as $c) {
    if (!$c['is_active']) {
        if ((int)$c['dept_active']) $alerts[] = ['warn', "'{$c['name']}' निष्क्रिय आहे, पण त्यात {$c['dept_active']} सक्रिय विभाग आहेत.", 'edit.php?id=' . $c['id']];
        continue;
    }
    foreach (['naac_valid_until' => 'NAAC', 'ugc_valid_until' => 'UGC'] as $col => $lbl) {
        $v = $validity($c[$col] ?? null);
        if ($v && $v[0] !== 'ok') {
            $expiring++;
            $alerts[] = [$v[0], "'{$c['name']}' ची {$lbl} मान्यता " . ($v[0] === 'bad' ? 'संपली आहे (' . date('d M Y', strtotime($c[$col])) . ')' : $v[1] . 'ांत संपते (' . date('d M Y', strtotime($c[$col])) . ')') . ' — नूतनीकरण करा.', 'edit.php?id=' . $c['id']];
        }
    }
    if (!(int)$c['dept_count'] && $c['college_type'] !== 'Trust HQ') $alerts[] = ['info', "'{$c['name']}' मध्ये अजून एकही विभाग नाही.", '../departments/create.php'];
    if ((int)$c['dept_nohod']) $alerts[] = ['info', "'{$c['name']}' मधील {$c['dept_nohod']} विभागांना HOD नाहीत.", '../departments/index.php'];
}
$activeCampuses = array_filter($campuses, fn($c) => $c['is_active']);
$noPrincipal = array_filter($activeCampuses, fn($c) => !$c['principal_name'] && !in_array($c['college_type'], ['Trust HQ'], true));
if ($noPrincipal) $alerts[] = ['info', count($noPrincipal) . ' सक्रिय कॅम्पसचे प्राचार्य/प्रमुख नोंदवलेले नाहीत — पत्रांवर व अहवालांवर नाव येत नाही.', '#campusTable'];
usort($alerts, fn($a, $b) => array_search($a[0], ['bad', 'warn', 'info']) <=> array_search($b[0], ['bad', 'warn', 'info']));
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="alert" style="background:var(--rail-soft);border:1px solid var(--rail);border-radius:10px;font-size:13px;padding:10px 16px;color:var(--ink);">
  🏛️ <strong>अंतर्गत Trust/Organization:</strong> <?= h(activeOrgName()) ?> — खालील सर्व School/College याच Trust खाली कार्यरत आहेत
</div>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s295')) ?></div>
  <div class="d-flex gap-2">
    <a href="compare.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;">
      <i class="fa-solid fa-chart-column me-1"></i>College तुलना
    </a>

    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
      <i class="fa-solid fa-plus me-1"></i>नवीन कॅम्पस
    </a>
  </div>
</div>

<?php
smartTiles([
    ['label' => 'कॅम्पस / College', 'value' => count($campuses), 'sub' => count($activeCampuses) . ' सक्रिय'],
    ['label' => 'विभाग', 'value' => array_sum(array_column($activeCampuses, 'dept_active')), 'sub' => array_sum(array_column($activeCampuses, 'dept_nohod')) . ' HOD विना', 'tone' => array_sum(array_column($activeCampuses, 'dept_nohod')) ? 'warn' : 'ok'],
    ['label' => 'सक्रिय कर्मचारी', 'value' => array_sum(array_column($activeCampuses, 'staff_active')), 'sub' => 'सर्व कॅम्पस मिळून'],
    ['label' => 'NAAC / UGC मुदत', 'value' => $expiring, 'sub' => $expiring ? 'संपली / ९० दिवसांत' : 'सर्व वैध', 'tone' => $expiring ? 'bad' : 'ok'],
    ['label' => 'प्रमुख नोंद नाही', 'value' => count($noPrincipal), 'sub' => 'प्राचार्य / प्रमुखाचे नाव', 'tone' => $noPrincipal ? 'warn' : 'ok'],
]);
smartAlerts(array_slice($alerts, 0, 8));
if (hasRole('super_admin')) {
    echo campusHeadsPanel($pdo, activeOrgId(), null, '🌐 सर्व Campus — केंद्रीय प्रमुख (Centralized Head)',
        'यांना Command Center मध्ये सर्व Campus चा Dashboard दिसतो. प्रत्येक College/School चा प्रमुख (Principal / Manager) त्या Campus च्या <b>Edit</b> मधून नेमा.', 'पद (उदा. Director / CEO)');
}
?>

<div class="panel mt-3" id="campusTable">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s99')) ?></th><th>प्रकार</th><th><?= h(t('s46')) ?></th><th><?= h(t('s1')) ?></th><th>कर्मचारी</th><th>NAAC / UGC</th><th><?= h(t('s2')) ?></th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php if (empty($campuses)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s296')) ?></td></tr>
      <?php endif; ?>
      <?php
        $collegeTypeLabels = ['Trust HQ' => '🏛️ Trust / मुख्य कार्यालय', 'Primary School' => 'प्राथमिक शाळा', 'Secondary School' => 'माध्यमिक शाळा', 'Junior College' => 'कनिष्ठ महाविद्यालय', 'Arts' => 'कला', 'Science' => 'विज्ञान', 'Commerce' => 'वाणिज्य', 'Engineering' => 'अभियांत्रिकी', 'Pharmacy' => 'औषधनिर्माणशास्त्र', 'Education' => 'शिक्षणशास्त्र', 'Law' => 'विधी', 'Medical' => 'वैद्यकीय', 'Polytechnic' => 'Polytechnic', 'Other' => 'इतर'];
      ?>
      <?php foreach ($campuses as $campus): ?>
        <tr>
          <td style="font-weight:500;">
            <?= h($campus['name']) ?>
            <?php if ($campus['principal_name']): ?><div style="font-size:10.5px;color:var(--text-dim);font-weight:400;">प्राचार्य: <?= h($campus['principal_name']) ?></div><?php endif; ?>
            <?php if (!empty($headNames[(int)$campus['id']])): ?><div style="font-size:11px;color:var(--rail);font-weight:600;">🏛️ प्रमुख: <?= h(implode(', ', $headNames[(int)$campus['id']])) ?></div><?php endif; ?>
            <?php if ($campus['naac_grade']): ?><span style="font-size:10px;background:var(--rail-soft);color:var(--rail);padding:1px 7px;border-radius:6px;font-weight:600;">NAAC <?= h($campus['naac_grade']) ?></span><?php endif; ?>
          </td>
          <td class="font-mono"><?= h($campus['code']) ?></td>
          <td style="font-size:12px;"><?= h($collegeTypeLabels[$campus['college_type']] ?? '—') ?></td>
          <td><?= h($campus['city'] ?: '—') ?></td>
          <td class="font-mono" data-sort="<?= (int)$campus['dept_active'] ?>"><a href="../departments/index.php" style="color:inherit;"><?= (int)$campus['dept_active'] ?></a><?= $campus['dept_count'] > $campus['dept_active'] ? ' <span style="color:var(--text-dim);font-size:11px;">+' . ($campus['dept_count'] - $campus['dept_active']) . ' निष्क्रिय</span>' : '' ?><?= (int)$campus['dept_nohod'] ? '<div style="font-size:11px;color:var(--amber);">' . (int)$campus['dept_nohod'] . ' HOD विना</div>' : '' ?></td>
          <td class="font-mono"><?= (int)$campus['staff_active'] ?></td>
          <td style="font-size:11.5px;white-space:nowrap;">
            <?php $any = false; foreach (['naac_valid_until' => 'NAAC', 'ugc_valid_until' => 'UGC'] as $col => $lbl): $v = $validity($campus[$col] ?? null); if (!$v) continue; $any = true; ?>
              <div style="color:<?= $v[0] === 'bad' ? 'var(--rose)' : ($v[0] === 'warn' ? 'var(--amber)' : 'var(--rail)') ?>;font-weight:600;"><?= $lbl ?>: <?= date('d M Y', strtotime($campus[$col])) ?><?= $v[1] ? ' · ' . $v[1] : '' ?></div>
            <?php endforeach; if (!$any) echo '<span style="color:var(--text-dim);">—</span>'; ?>
          </td>
          <td><?php if ($campus['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td>
            <a href="edit.php?id=<?= $campus['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <?php if (hasRole('super_admin')): ?>
              <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;"
                data-bs-toggle="modal" data-bs-target="#deleteModal<?= $campus['id'] ?>"><i class="fa-solid fa-trash me-1"></i>Delete</button>
            <?php endif; ?>
          </td>
        </tr>

        <?php if (hasRole('super_admin')): ?>
        <div class="modal fade" id="deleteModal<?= $campus['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="delete.php">
                <input type="hidden" name="id" value="<?= $campus['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;color:var(--rose);margin-bottom:10px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>'<?= h($campus['name']) ?>' कायमचा डिलीट करायचा?
                  </div>
                  <p style="font-size:13px;color:var(--text-dim);">
                    यामुळे या कॅम्पसचे <strong><?= $campus['dept_count'] ?> <?= h(t('s1')) ?></strong> — तसेच त्यांच्याशी संबंधित सर्व कर्मचारी, दैनंदिन काम, नूतनीकरण आणि टास्क नोंदी — कायमच्या डिलीट होतील. ही क्रिया परत करता येणार नाही.
                  </p>
                  <label class="form-label-custom"><?= h(t('s297')) ?> <span class="font-mono" style="color:var(--rose);"><?= h($campus['name']) ?></span></label>
                  <input type="text" name="confirm_name" class="form-control-custom" placeholder="इथे नाव टाइप करा" required>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;"><?= h(t('s3')) ?></button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;"><?= h(t('s298')) ?></button>
                </div>
              </form>
            </div>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
