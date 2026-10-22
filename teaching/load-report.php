<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$canManage = hasPermission('manage_teaching_academics') || isManagementOrAbove();
if (!$canManage) { http_response_code(403); die('परवानगी नाही.'); }

$pageTitle = 'Teaching Load Report';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// शैक्षणिक वर्ष जूनपासून सुरू — जानेवारी-मे मध्ये मागच्या वर्षाचंच Academic Year
$fyStartYear = (int)date('Y') - ((int)date('n') < 6 ? 1 : 0);
$fy = $fyStartYear . '-' . substr((string)($fyStartYear + 1), 2);

$loadStmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation, c.name AS campus_name, COUNT(tt.id) AS total_periods,
      COUNT(DISTINCT tt.subject_name) AS subject_count, COUNT(DISTINCT tt.class_section) AS class_count
    FROM users u LEFT JOIN teacher_timetable tt ON tt.teacher_id = u.id AND tt.academic_year = ?
    LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.organization_id = ? AND u.teaching_mode = 'Teaching' AND u.is_active = 1
    GROUP BY u.id, u.name, u.designation, c.name ORDER BY total_periods DESC
");
$loadStmt->execute([$fy, $orgId]);
$teacherLoads = $loadStmt->fetchAll();

$avgLoad = count($teacherLoads) > 0 ? round(array_sum(array_column($teacherLoads, 'total_periods')) / count($teacherLoads), 1) : 0;
$maxLoad = !empty($teacherLoads) ? max(array_column($teacherLoads, 'total_periods')) : 1;
?>

<div class="page-eyebrow">Teaching Module</div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">⏱️ Teaching Load Report — FY <?= h($fy) ?></div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>मागे</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">प्रत्येक शिक्षकाला आठवड्याला किती Periods — Workload Balance आहे का ते बघा</p>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">एकूण Teaching Staff</div><div class="val"><?= count($teacherLoads) ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">सरासरी Periods/आठवडा</div><div class="val" style="color:var(--rail);"><?= $avgLoad ?></div></div></div>
</div>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>शिक्षक</th><th>College/School</th><th>विषय</th><th>वर्ग</th><th>Periods/आठवडा</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($teacherLoads)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कुठलाही Teaching Staff नाही</td></tr><?php endif; ?>
      <?php foreach ($teacherLoads as $t):
        $barPct = $maxLoad > 0 ? round(($t['total_periods'] / $maxLoad) * 100) : 0;
        $isOverloaded = $t['total_periods'] > $avgLoad * 1.3 && $avgLoad > 0;
        $isUnderloaded = $t['total_periods'] < $avgLoad * 0.5 && $avgLoad > 0;
      ?>
        <tr>
          <td style="font-weight:600;"><a href="timetable.php?teacher_id=<?= $t['id'] ?>" style="color:inherit;text-decoration:none;"><?= h($t['name']) ?></a></td>
          <td style="font-size:12px;color:var(--text-dim);"><?= h($t['campus_name'] ?: '—') ?></td>
          <td class="font-mono"><?= $t['subject_count'] ?></td>
          <td class="font-mono"><?= $t['class_count'] ?></td>
          <td style="width:180px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="background:var(--canvas);border-radius:8px;height:8px;flex:1;overflow:hidden;">
                <div style="height:100%;width:<?= $barPct ?>%;background:<?= $isOverloaded ? 'var(--rose)' : ($isUnderloaded ? 'var(--amber)' : 'var(--rail)') ?>;"></div>
              </div>
              <span style="font-size:12px;font-weight:600;"><?= $t['total_periods'] ?></span>
            </div>
          </td>
          <td>
            <?php if ($isOverloaded): ?><span class="status-chip overdue" style="font-size:10px;">⚠️ जास्त Load</span>
            <?php elseif ($isUnderloaded): ?><span class="status-chip pending" style="font-size:10px;">कमी Load</span>
            <?php else: ?><span class="status-chip done" style="font-size:10px;">✅ Balanced</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
