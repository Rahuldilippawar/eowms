<?php
$pageTitle = 'सानुकूल फील्ड्स (Custom Fields)';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requireRole(['super_admin'], '../');

$orgId = activeOrgId();
$stmt = $pdo->prepare("SELECT * FROM custom_fields WHERE organization_id = ? ORDER BY sort_order, id");
$stmt->execute([$orgId]);
$fields = $stmt->fetchAll();

// वापर — किती नोंदींमध्ये भरलेलं (field_key वरून), फील्ड तयार झाल्यापासूनच्या नोंदींपैकी किती %
$filled = []; $since = [];
try {
    $st = $pdo->prepare("SELECT v.field_key k, COUNT(DISTINCT v.work_record_id) c FROM custom_field_values v JOIN work_records wr ON wr.id = v.work_record_id
        WHERE wr.organization_id = ? AND v.field_value IS NOT NULL AND v.field_value <> '' GROUP BY v.field_key");
    $st->execute([$orgId]);
    foreach ($st->fetchAll() as $r) { $filled[$r['k']] = (int)$r['c']; }
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM work_records WHERE organization_id = ? AND created_at >= ?");
    foreach ($fields as $f) { $cnt->execute([$orgId, $f['created_at']]); $since[$f['field_key']] = (int)$cnt->fetchColumn(); }
} catch (PDOException $e) {}
require_once __DIR__ . '/../includes/smart-insights.php';
$activeF = array_filter($fields, fn($f) => $f['is_active']);
$lowFill = array_filter($activeF, fn($f) => ($since[$f['field_key']] ?? 0) >= 10 && ($filled[$f['field_key']] ?? 0) * 100 / $since[$f['field_key']] < 30);
$selectNoOpts = array_filter($activeF, fn($f) => $f['field_type'] === 'select' && trim((string)$f['field_options']) === '');
$alerts = [];
foreach ($selectNoOpts as $f) $alerts[] = ['bad', "'{$f['field_label']}' हा Dropdown आहे पण त्यात पर्यायच नाहीत — कर्मचाऱ्यांना काहीच निवडता येत नाही.", 'edit.php?id=' . $f['id']];
foreach ($lowFill as $f) $alerts[] = [$f['is_required'] ? 'warn' : 'info', "'{$f['field_label']}' फक्त " . round(($filled[$f['field_key']] ?? 0) * 100 / $since[$f['field_key']]) . "% नोंदींमध्ये भरलेलं — गरज नसल्यास निष्क्रिय करा.", 'edit.php?id=' . $f['id']];

$typeLabels = ['text' => 'साधा मजकूर', 'textarea' => 'मोठा मजकूर', 'number' => 'संख्या', 'date' => 'दिनांक', 'select' => 'ड्रॉपडाउन', 'checkbox' => 'होय/नाही'];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0"><?= h(t('s412')) ?></div>
  <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;">
    <i class="fa-solid fa-plus me-1"></i>नवीन फील्ड जोडा
  </a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">
  येथे जोडलेले फील्ड्स "दैनंदिन कामाचा अहवाल" entry form च्या शेवटी आपोआप दिसतील — प्रत्येक संस्थेचे नियम वेगळे असू शकतात म्हणून.
</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php
smartTiles([
    ['label' => 'Custom Fields', 'value' => count($fields), 'sub' => count($activeF) . ' सक्रिय · ' . count(array_filter($activeF, fn($f) => $f['is_required'])) . ' आवश्यक'],
    ['label' => 'भरलेल्या नोंदी', 'value' => array_sum($filled), 'sub' => 'सर्व फील्ड मिळून'],
    ['label' => 'कमी वापर', 'value' => count($lowFill), 'sub' => '३०% पेक्षा कमी नोंदींमध्ये', 'tone' => $lowFill ? 'warn' : 'ok'],
]);
smartAlerts($alerts);
?>

<?php if ($activeF): ?>
<div class="panel mb-3">
  <div class="panel-head"><div class="title">👁️ Form मध्ये असं दिसेल</div><div class="sub">दैनंदिन कामाच्या अहवालाच्या शेवटी — फक्त Preview, इथे काहीही जतन होत नाही</div></div>
  <div class="row g-3" style="padding:16px 22px;">
    <?php foreach ($activeF as $f): $opts = array_filter(array_map('trim', preg_split('/[\r\n,]+/', (string)$f['field_options']))); ?>
      <div class="col-md-6">
        <label class="form-label-custom"><?= h($f['field_label']) ?><?= $f['is_required'] ? ' <span style="color:var(--rose);">*</span>' : '' ?></label>
        <?php if ($f['field_type'] === 'textarea'): ?><textarea class="form-control-custom" rows="2" disabled></textarea>
        <?php elseif ($f['field_type'] === 'select'): ?><select class="form-select-custom" disabled><option>-- निवडा --</option><?php foreach ($opts as $o): ?><option><?= h($o) ?></option><?php endforeach; ?></select>
        <?php elseif ($f['field_type'] === 'checkbox'): ?><div><input type="checkbox" disabled> होय</div>
        <?php else: ?><input type="<?= $f['field_type'] === 'number' ? 'number' : ($f['field_type'] === 'date' ? 'date' : 'text') ?>" class="form-control-custom" disabled><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th><?= h(t('s114')) ?></th><th>Label</th><th>Field Key</th><th><?= h(t('s17')) ?></th><th><?= h(t('s115')) ?></th><th>वापर</th><th><?= h(t('s2')) ?></th><th></th></tr></thead>
    <tbody>
      <?php if (empty($fields)): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;"><?= h(t('s413')) ?></td></tr>
      <?php endif; ?>
      <?php foreach ($fields as $f): ?>
        <tr>
          <td class="font-mono"><?= $f['sort_order'] ?></td>
          <td style="font-weight:500;"><?= h($f['field_label']) ?></td>
          <td class="font-mono" style="font-size:12px;color:var(--text-dim);"><?= h($f['field_key']) ?></td>
          <td><?= h($typeLabels[$f['field_type']] ?? $f['field_type']) ?></td>
          <td><?= $f['is_required'] ? 'होय' : 'नाही' ?></td>
          <?php $fc = $filled[$f['field_key']] ?? 0; $sc = $since[$f['field_key']] ?? 0; $fp = $sc ? (int)round($fc * 100 / $sc) : null; ?>
          <td data-sort="<?= $fp ?? -1 ?>" style="font-size:12px;"><span class="font-mono"><?= $fc ?></span><?= $fp !== null ? ' <span style="color:' . ($fp >= 60 ? 'var(--rail)' : ($fp >= 30 ? 'var(--amber)' : 'var(--rose)')) . ';font-weight:600;">(' . $fp . '%)</span>' : '' ?></td>
          <td><?php if ($f['is_active']): ?><span class="status-chip done"><?= h(t('s4')) ?></span><?php else: ?><span class="status-chip overdue"><?= h(t('s5')) ?></span><?php endif; ?></td>
          <td><a href="edit.php?id=<?= $f['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:5px 12px;"><i class="fa-solid fa-pen me-1"></i>Edit</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
