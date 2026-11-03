<?php
$pageTitle = 'संस्था व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgs = $pdo->query("
    SELECT o.*, p.name AS parent_name,
      (SELECT COUNT(*) FROM campuses c WHERE c.organization_id = o.id) AS campus_count,
      (SELECT COUNT(*) FROM departments d WHERE d.organization_id = o.id) AS dept_count,
      (SELECT COUNT(*) FROM users u WHERE u.organization_id = o.id) AS user_count,
      (SELECT COUNT(*) FROM organizations c2 WHERE c2.parent_organization_id = o.id) AS child_org_count,
      (SELECT COUNT(*) FROM users u WHERE u.organization_id = o.id AND u.is_active = 1) AS user_active,
      (SELECT COUNT(DISTINCT al.user_id) FROM activity_logs al JOIN users u ON u.id = al.user_id
         WHERE u.organization_id = o.id AND u.is_active = 1 AND al.action LIKE 'Login केलं%' AND al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS login7,
      (SELECT COUNT(*) FROM departments d WHERE d.organization_id = o.id AND d.is_active = 1 AND d.hod_id IS NULL) AS dept_nohod
    FROM organizations o LEFT JOIN organizations p ON p.id = o.parent_organization_id
    ORDER BY o.name
")->fetchAll();
require_once __DIR__ . '/../includes/smart-insights.php';
// शेवटची हालचाल (Activity Log) — संस्था वापरात आहे का
$lastAct = [];
try { foreach ($pdo->query("SELECT organization_id o, MAX(created_at) t FROM activity_logs GROUP BY organization_id")->fetchAll() as $r) { $lastAct[(int)$r['o']] = $r['t']; } } catch (PDOException $e) {}
$curOrg = activeOrgId();
$alerts = [];
foreach ($orgs as $o) {
    if ($o['maintenance_mode']) $alerts[] = ['bad', "'{$o['name']}' — 🛠️ Maintenance Mode चालू आहे; Super Admin सोडून कोणालाही Login करता येत नाही.", '../system-update/maintenance.php'];
    if (!$o['is_active']) { if ((int)$o['user_active']) $alerts[] = ['warn', "'{$o['name']}' निष्क्रिय आहे — तिचे {$o['user_active']} सक्रिय कर्मचारी Login करू शकत नाहीत.", 'edit.php?id=' . $o['id']]; continue; }
    if (!(int)$o['dept_count']) $alerts[] = ['info', "'{$o['name']}' मध्ये अजून विभाग नाहीत — रचना पूर्ण करा.", 'edit.php?id=' . $o['id']];
    elseif ((int)$o['dept_nohod']) $alerts[] = ['info', "'{$o['name']}' — {$o['dept_nohod']} विभागांना HOD नाहीत.", '../departments/index.php'];
    if ((int)$o['user_active'] && empty($lastAct[(int)$o['id']])) $alerts[] = ['info', "'{$o['name']}' मध्ये अजून कोणतीही हालचाल नोंदलेली नाही.", 'edit.php?id=' . $o['id']];
    elseif (!empty($lastAct[(int)$o['id']]) && strtotime($lastAct[(int)$o['id']]) < strtotime('-14 days')) $alerts[] = ['warn', "'{$o['name']}' मध्ये १४ दिवसांपेक्षा जास्त काळ कोणतीही हालचाल नाही.", '../activity-log/index.php'];
}
$activeOrgs = array_filter($orgs, fn($o) => $o['is_active']);
$ago = function ($t) {
    if (!$t) return '—';
    $d = (int)floor((time() - strtotime($t)) / 86400);
    return $d <= 0 ? 'आज' : ($d === 1 ? 'काल' : $d . ' दिवसांपूर्वी');
};
?>

<div class="page-eyebrow">Super Admin</div>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s344')) ?></div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन संस्था
  </a>
</div>

<?php if (!empty($_SESSION['flash'])): ?><div class="alert alert-success mt-2" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div><?php unset($_SESSION['flash']); endif; ?>
<?php
smartTiles([
    ['label' => 'संस्था', 'value' => count($orgs), 'sub' => count($activeOrgs) . ' सक्रिय · ' . count(array_filter($orgs, fn($o) => $o['parent_organization_id'])) . ' उप-शाखा'],
    ['label' => 'कॅम्पस', 'value' => array_sum(array_column($activeOrgs, 'campus_count'))],
    ['label' => 'विभाग', 'value' => array_sum(array_column($activeOrgs, 'dept_count')), 'sub' => array_sum(array_column($activeOrgs, 'dept_nohod')) . ' HOD विना', 'tone' => array_sum(array_column($activeOrgs, 'dept_nohod')) ? 'warn' : ''],
    ['label' => 'सक्रिय कर्मचारी', 'value' => array_sum(array_column($activeOrgs, 'user_active')), 'sub' => array_sum(array_column($activeOrgs, 'login7')) . ' जणांनी ७ दिवसांत Login केलं', 'tone' => 'ok'],
    ['label' => 'Maintenance चालू', 'value' => count(array_filter($orgs, fn($o) => $o['maintenance_mode'])), 'tone' => count(array_filter($orgs, fn($o) => $o['maintenance_mode'])) ? 'bad' : 'ok'],
]);
smartAlerts(array_slice($alerts, 0, 8));
?>

<div class="panel mt-3">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s17')) ?></th><th>मुख्य Trust</th><th><?= h(t('s8')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s6')) ?></th><th title="गेल्या ७ दिवसांत Login केलेले सक्रिय कर्मचारी">वापर (७ दि.)</th><th>शेवटची हालचाल</th><th><?= h(t('s2')) ?></th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php if (empty($orgs)): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s345')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($orgs as $org): ?>
        <tr>
          <td style="font-weight:500;"><?= h($org['name']) ?><?= (int)$org['id'] === (int)$curOrg ? ' <span style="font-size:10px;font-weight:700;color:var(--rail);background:var(--rail-soft);padding:2px 8px;border-radius:10px;">सध्याची</span>' : '' ?><?= $org['maintenance_mode'] ? ' <span style="font-size:10px;font-weight:700;color:var(--rose);">🛠️ Maintenance</span>' : '' ?></td>
          <td><?= h(ucfirst($org['type'])) ?></td>
          <td>
            <?php if ($org['parent_name']): ?>
              <span style="font-size:11.5px;color:var(--rail);">🏢 <?= h($org['parent_name']) ?></span>
            <?php else: ?>
              <span style="font-size:11.5px;color:var(--text-dim);">— स्वतंत्र —</span>
            <?php endif; ?>
          </td>
          <td class="font-mono"><?= $org['campus_count'] ?></td>
          <td class="font-mono"><?= $org['dept_count'] ?></td>
          <td class="font-mono" data-sort="<?= (int)$org['user_active'] ?>"><b><?= (int)$org['user_active'] ?></b><?= $org['user_count'] > $org['user_active'] ? ' <span style="color:var(--text-dim);font-size:11px;">+' . ($org['user_count'] - $org['user_active']) . '</span>' : '' ?></td>
          <?php $lp = (int)$org['user_active'] ? (int)round($org['login7'] * 100 / $org['user_active']) : 0; ?>
          <td data-sort="<?= $lp ?>"><div class="og-bar"><i style="width:<?= $lp ?>%;background:<?= $lp >= 60 ? 'var(--rail)' : ($lp >= 30 ? 'var(--amber)' : 'var(--rose)') ?>;"></i></div> <span class="font-mono" style="font-size:12px;"><?= (int)$org['login7'] ?>/<?= (int)$org['user_active'] ?></span></td>
          <?php $la = $lastAct[(int)$org['id']] ?? null; ?>
          <td data-sort="<?= $la ? strtotime($la) : 0 ?>" style="font-size:12px;<?= $la && strtotime($la) < strtotime('-14 days') ? 'color:var(--amber);font-weight:600;' : '' ?>"><?= $ago($la) ?></td>
          <td><?php if ($org['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td style="white-space:nowrap;text-align:right;">
            <?php if ((int)$org['id'] !== (int)$curOrg && $org['is_active']): ?>
              <form method="POST" action="switch.php" class="d-inline">
                <input type="hidden" name="organization_id" value="<?= (int)$org['id'] ?>"><input type="hidden" name="back" value="index.php">
                <button type="submit" class="btn btn-sm" title="या संस्थेचं काम पाहण्यासाठी तिच्यावर जा" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:12px;padding:5px 10px;"><i class="fa-solid fa-right-left me-1"></i>जा</button>
              </form>
            <?php endif; ?>
            <a href="edit.php?id=<?= $org['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a>
            <button type="button" class="btn btn-sm" style="border:1px solid var(--rose);color:var(--rose);border-radius:7px;font-size:12px;padding:5px 12px;"
              data-bs-toggle="modal" data-bs-target="#deleteModal<?= $org['id'] ?>"><i class="fa-solid fa-trash me-1"></i>Delete</button>
          </td>
        </tr>

        <div class="modal fade" id="deleteModal<?= $org['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="delete.php">
                <input type="hidden" name="id" value="<?= $org['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;color:var(--rose);margin-bottom:10px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>'<?= h($org['name']) ?>' कायमची डिलीट करायची?
                  </div>
                  <p style="font-size:13px;color:var(--text-dim);">
                    यामुळे या संस्थेचे <strong><?= $org['campus_count'] ?> <?= h(t('s346')) ?> <?= $org['dept_count'] ?> <?= h(t('s347')) ?> <?= $org['user_count'] ?> <?= h(t('s6')) ?></strong> — तसेच त्यांच्याशी संबंधित सर्व दैनंदिन काम, नूतनीकरण आणि टास्क नोंदी — कायमच्या डिलीट होतील. ही क्रिया परत करता येणार नाही.
                  </p>
                  <?php if ($org['child_org_count'] > 0): ?>
                    <p style="font-size:12.5px;color:var(--amber);background:var(--amber-soft, #FBEBD6);padding:8px 12px;border-radius:8px;">
                      ⚠️ या संस्थेला <strong><?= $org['child_org_count'] ?> उप-शाखा</strong> जोडलेल्या आहेत — त्या डिलीट होणार नाहीत, पण त्यांचं "मुख्य Trust" आपोआप रिकामं (स्वतंत्र) होईल.
                    </p>
                  <?php endif; ?>
                  <label class="form-label-custom"><?= h(t('s110')) ?> <span class="font-mono" style="color:var(--rose);"><?= h($org['name']) ?></span></label>
                  <input type="text" name="confirm_name" class="form-control-custom" placeholder="इथे नाव टाइप करा" required>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;"><?= h(t('s3')) ?></button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;"><?= h(t('s348')) ?></button>
                </div>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<style>.og-bar{display:inline-block;width:60px;height:6px;border-radius:4px;background:var(--canvas);overflow:hidden;vertical-align:middle;}.og-bar i{display:block;height:100%;border-radius:4px;}</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
