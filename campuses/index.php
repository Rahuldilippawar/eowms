<?php
$pageTitle = 'कॅम्पस व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_campuses', '../');

$stmt = $pdo->prepare("
    SELECT c.*, (SELECT COUNT(*) FROM departments d WHERE d.campus_id = c.id) AS dept_count
    FROM campuses c WHERE c.organization_id = ? ORDER BY c.name
");
$stmt->execute([activeOrgId()]);
$campuses = $stmt->fetchAll();
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

<div class="panel mt-3">
  <table class="rtable">
    <thead><tr><th><?= h(t('s15')) ?></th><th><?= h(t('s99')) ?></th><th>प्रकार</th><th><?= h(t('s46')) ?></th><th><?= h(t('s1')) ?></th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($campuses)): ?>
        <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s296')) ?></td></tr>
      <?php endif; ?>
      <?php
        $collegeTypeLabels = ['Trust HQ' => '🏛️ Trust / मुख्य कार्यालय', 'Primary School' => 'प्राथमिक शाळा', 'Secondary School' => 'माध्यमिक शाळा', 'Junior College' => 'कनिष्ठ महाविद्यालय', 'Arts' => 'कला', 'Science' => 'विज्ञान', 'Commerce' => 'वाणिज्य', 'Engineering' => 'अभियांत्रिकी', 'Pharmacy' => 'औषधनिर्माणशास्त्र', 'Education' => 'शिक्षणशास्त्र', 'Law' => 'विधी', 'Medical' => 'वैद्यकीय', 'Polytechnic' => 'Polytechnic', 'Other' => 'इतर'];
      ?>
      <?php foreach ($campuses as $campus): ?>
        <tr>
          <td style="font-weight:500;">
            <?= h($campus['name']) ?>
            <?php if ($campus['principal_name']): ?><div style="font-size:10.5px;color:var(--text-dim);font-weight:400;">प्राचार्य: <?= h($campus['principal_name']) ?></div><?php endif; ?>
            <?php if ($campus['naac_grade']): ?><span style="font-size:10px;background:var(--rail-soft);color:var(--rail);padding:1px 7px;border-radius:6px;font-weight:600;">NAAC <?= h($campus['naac_grade']) ?></span><?php endif; ?>
          </td>
          <td class="font-mono"><?= h($campus['code']) ?></td>
          <td style="font-size:12px;"><?= h($collegeTypeLabels[$campus['college_type']] ?? '—') ?></td>
          <td><?= h($campus['city'] ?: '—') ?></td>
          <td class="font-mono"><?= $campus['dept_count'] ?></td>
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

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
