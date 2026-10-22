<?php
$pageTitle = 'College तुलना';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
requirePermission('manage_campuses', '../');

$orgId = activeOrgId();

$stmt = $pdo->prepare("
    SELECT c.*,
      (SELECT COUNT(*) FROM departments d WHERE d.campus_id = c.id AND d.is_active = 1) AS dept_count,
      (SELECT COUNT(*) FROM users u JOIN departments d ON d.id = u.department_id WHERE d.campus_id = c.id AND u.is_active = 1) AS staff_count
    FROM campuses c WHERE c.organization_id = ? AND c.is_active = 1 AND (c.college_type IS NULL OR c.college_type != 'Trust HQ') ORDER BY c.name
");
$stmt->execute([$orgId]);
$colleges = $stmt->fetchAll();

$collegeTypeLabels = ['Trust HQ' => '🏛️ Trust / मुख्य कार्यालय', 'Primary School' => 'प्राथमिक शाळा', 'Secondary School' => 'माध्यमिक शाळा', 'Junior College' => 'कनिष्ठ महाविद्यालय', 'Arts' => 'कला', 'Science' => 'विज्ञान', 'Commerce' => 'वाणिज्य', 'Engineering' => 'अभियांत्रिकी', 'Pharmacy' => 'औषधनिर्माणशास्त्र', 'Education' => 'शिक्षणशास्त्र', 'Law' => 'विधी', 'Medical' => 'वैद्यकीय', 'Polytechnic' => 'Polytechnic', 'Other' => 'इतर'];

$totalStaff = array_sum(array_column($colleges, 'staff_count'));
$totalDepts = array_sum(array_column($colleges, 'dept_count'));

// Trust-स्तरीय विभाग — campus_id NULL असलेले, किंवा ज्या Campus ला खुद्द
// "Trust HQ" म्हणून चिन्हांकित केलं आहे त्याखालचे विभाग — दोन्ही Trust-स्तरीयच मानतो.
$trustDeptStmt = $pdo->prepare("
    SELECT
      (SELECT COUNT(*) FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
        WHERE d.organization_id = ? AND d.is_active = 1 AND (d.campus_id IS NULL OR c.college_type = 'Trust HQ')) AS dept_count,
      (SELECT COUNT(*) FROM users u JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = d.campus_id
        WHERE d.organization_id = ? AND u.is_active = 1 AND (d.campus_id IS NULL OR c.college_type = 'Trust HQ')) AS staff_count
");
$trustDeptStmt->execute([$orgId, $orgId]);
$trustLevel = $trustDeptStmt->fetch();
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="alert" style="background:var(--rail-soft);border:1px solid var(--rail);border-radius:10px;font-size:13px;padding:10px 16px;color:var(--ink);margin-bottom:14px;">
  🏛️ <strong>अंतर्गत Trust/Organization:</strong> <?= h(activeOrgName()) ?>
</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">📊 College तुलना (Comparison)</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>कॅम्पस व्यवस्थापन</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">सगळ्या Colleges ची एका दृष्टिक्षेपात तुलना</p>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Colleges</div><div class="val"><?= count($colleges) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">College-स्तरीय Departments</div><div class="val"><?= $totalDepts ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Staff</div><div class="val"><?= $totalStaff ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">🏛️ Trust-स्तरीय Departments</div><div class="val" style="color:var(--rail);"><?= $trustLevel['dept_count'] ?></div></div></div>
</div>
<p style="font-size:11.5px;color:var(--text-dim);margin-bottom:20px;">🏛️ Trust-स्तरीय विभागांमध्ये सध्या <strong><?= $trustLevel['staff_count'] ?> Staff</strong> कार्यरत आहे (उदा. Trust Office, केंद्रीय हिशोब, विधी विभाग, इ. — कुठल्याही विशिष्ट College/School खाली नसलेले).</p>

<div class="panel">
  <table class="rtable">
    <thead>
      <tr>
        <th>College</th><th>प्रकार</th><th>प्राचार्य</th><th>Departments</th><th>Staff</th>
        <th>संलग्न विद्यापीठ</th><th>NAAC</th><th>स्थापना वर्ष</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($colleges)): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणतंही College/Campus जोडलेलं नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($colleges as $c): ?>
        <tr>
          <td style="font-weight:600;"><?= h($c['name']) ?> <span class="font-mono" style="font-size:10px;color:var(--text-dim);">(<?= h($c['code']) ?>)</span></td>
          <td style="font-size:12px;"><?= h($collegeTypeLabels[$c['college_type']] ?? '—') ?></td>
          <td><?= h($c['principal_name'] ?: '—') ?></td>
          <td class="font-mono" style="text-align:center;"><?= $c['dept_count'] ?></td>
          <td class="font-mono" style="text-align:center;font-weight:600;color:var(--rail);"><?= $c['staff_count'] ?></td>
          <td style="font-size:12px;"><?= h($c['affiliated_university'] ?: '—') ?></td>
          <td>
            <?php if ($c['naac_grade']): ?>
              <span class="status-chip done"><?= h($c['naac_grade']) ?></span>
              <?php if ($c['naac_valid_until']): ?><div style="font-size:10px;color:var(--text-dim);margin-top:2px;"><?= date('M Y', strtotime($c['naac_valid_until'])) ?> पर्यंत</div><?php endif; ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="font-mono"><?= h($c['established_year'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($colleges)): ?>
<div class="panel mt-3">
  <div class="panel-head"><div class="title">📈 Staff वितरण — College-निहाय</div></div>
  <div style="padding:16px 20px;">
    <?php $maxStaff = max(array_column($colleges, 'staff_count')) ?: 1; ?>
    <?php foreach ($colleges as $c): $pct = round(($c['staff_count'] / $maxStaff) * 100); ?>
      <div style="margin-bottom:12px;">
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:3px;">
          <span><?= h($c['name']) ?></span><strong><?= $c['staff_count'] ?></strong>
        </div>
        <div style="background:var(--canvas);border-radius:8px;height:10px;overflow:hidden;">
          <div style="height:100%;width:<?= $pct ?>%;background:var(--rail);"></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
