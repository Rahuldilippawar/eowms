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
      (SELECT COUNT(*) FROM organizations c2 WHERE c2.parent_organization_id = o.id) AS child_org_count
    FROM organizations o LEFT JOIN organizations p ON p.id = o.parent_organization_id
    ORDER BY o.name
")->fetchAll();
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

<div class="panel mt-3">
  <table class="rtable">
    <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s17')) ?></th><th>मुख्य Trust</th><th><?= h(t('s8')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s6')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($orgs)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s345')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($orgs as $org): ?>
        <tr>
          <td style="font-weight:500;"><?= h($org['name']) ?></td>
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
          <td class="font-mono"><?= $org['user_count'] ?></td>
          <td><?php if ($org['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
