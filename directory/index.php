<?php
$pageTitle = 'Trust Directory';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();

$campusStmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
$campusStmt->execute([$orgId]);
$campuses = $campusStmt->fetchAll();

// Trust-स्तरीय विभाग (campus_id NULL, किंवा Trust HQ Campus खालचे) — वेगळे दाखवण्यासाठी
$trustDeptStmt = $pdo->prepare("
    SELECT d.*, (SELECT COUNT(*) FROM users WHERE department_id = d.id AND is_active = 1) AS staff_count
    FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_active = 1 AND (d.campus_id IS NULL OR c.college_type = 'Trust HQ')
    ORDER BY d.name_local
");
$trustDeptStmt->execute([$orgId]);
$trustDepts = $trustDeptStmt->fetchAll();

$collegeTypeLabels = ['Trust HQ' => '🏛️ Trust/मुख्य कार्यालय', 'Primary School' => 'प्राथमिक शाळा', 'Secondary School' => 'माध्यमिक शाळा', 'Junior College' => 'कनिष्ठ महाविद्यालय', 'Arts' => 'कला महाविद्यालय', 'Science' => 'विज्ञान महाविद्यालय', 'Commerce' => 'वाणिज्य महाविद्यालय', 'Engineering' => 'अभियांत्रिकी महाविद्यालय', 'Pharmacy' => 'औषधनिर्माणशास्त्र महाविद्यालय', 'Education' => 'शिक्षणशास्त्र महाविद्यालय', 'Law' => 'विधी महाविद्यालय', 'Medical' => 'वैद्यकीय महाविद्यालय', 'Polytechnic' => 'Polytechnic', 'Other' => ''];
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">📇 Trust Directory</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;">🏛️ <?= h(activeOrgName()) ?> अंतर्गत सर्व School/College → विभाग → कर्मचारी — एकाच झाडासारख्या (Tree) दृश्यात</p>

<div class="panel mb-3">
  <div style="padding:14px 20px;">
    <input type="text" id="dirSearch" class="form-control-custom" placeholder="🔍 नाव किंवा हुद्दा टाइप करा...">
  </div>
</div>

<div id="dirTree">
  <?php if (!empty($trustDepts)): ?>
    <div class="panel mb-3">
      <div class="panel-head" style="cursor:pointer;" onclick="toggleNode('trust-node')">
        <div class="title">🏛️ Trust-स्तरीय विभाग <span style="font-size:11px;color:var(--text-dim);font-weight:400;">(<?= count($trustDepts) ?> विभाग)</span></div>
      </div>
      <div id="trust-node" style="display:none;">
        <?php foreach ($trustDepts as $d): ?>
          <div class="dir-dept" data-dept-id="<?= $d['id'] ?>" style="padding:12px 20px;border-top:1px solid var(--line);cursor:pointer;" onclick="loadStaff(<?= $d['id'] ?>, this)">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-weight:600;font-size:13.5px;"><?= h($d['name_local'] ?: $d['name']) ?></span>
              <span style="font-size:11px;color:var(--text-dim);"><?= $d['staff_count'] ?> कर्मचारी ▾</span>
            </div>
            <div class="dir-staff-list" style="display:none;margin-top:10px;"></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php foreach ($campuses as $c): if ($c['college_type'] === 'Trust HQ') continue;
    $deptStmt = $pdo->prepare("SELECT d.*, (SELECT COUNT(*) FROM users WHERE department_id = d.id AND is_active = 1) AS staff_count FROM departments d WHERE d.campus_id = ? AND d.is_active = 1 ORDER BY d.name_local");
    $deptStmt->execute([$c['id']]);
    $depts = $deptStmt->fetchAll();
    $nodeId = 'campus-' . $c['id'];
  ?>
    <div class="panel mb-3 dir-campus">
      <div class="panel-head" style="cursor:pointer;" onclick="toggleNode('<?= $nodeId ?>')">
        <div class="title">🏫 <?= h($c['name']) ?> <span style="font-size:11px;color:var(--text-dim);font-weight:400;">(<?= h($collegeTypeLabels[$c['college_type']] ?? '') ?> · <?= count($depts) ?> विभाग)</span></div>
      </div>
      <div id="<?= $nodeId ?>" style="display:none;">
        <?php if (empty($depts)): ?>
          <div style="padding:14px 20px;color:var(--text-dim);font-size:12.5px;">या College/School मध्ये अजून कोणताही विभाग नाही</div>
        <?php endif; ?>
        <?php foreach ($depts as $d): ?>
          <div class="dir-dept" data-dept-id="<?= $d['id'] ?>" style="padding:12px 20px;border-top:1px solid var(--line);cursor:pointer;" onclick="loadStaff(<?= $d['id'] ?>, this)">
            <div style="display:flex;justify-content:space-between;align-items:center;">
              <span style="font-weight:600;font-size:13.5px;"><?= h($d['name_local'] ?: $d['name']) ?></span>
              <span style="font-size:11px;color:var(--text-dim);"><?= $d['staff_count'] ?> कर्मचारी ▾</span>
            </div>
            <div class="dir-staff-list" style="display:none;margin-top:10px;"></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div id="dirSearchResults" style="display:none;"></div>

<script>
var rootPath = '../';
function toggleNode(id) {
  var el = document.getElementById(id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}
function loadStaff(deptId, el) {
  var listEl = el.querySelector('.dir-staff-list');
  if (listEl.style.display === 'block') { listEl.style.display = 'none'; return; }
  if (listEl.dataset.loaded === '1') { listEl.style.display = 'block'; return; }
  listEl.innerHTML = '<div style="font-size:12px;color:#999;">लोड होत आहे...</div>';
  listEl.style.display = 'block';
  fetch(rootPath + 'ajax-cascade.php?action=staff_for_department&department_id=' + deptId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var staff = data.staff || [];
      if (staff.length === 0) {
        listEl.innerHTML = '<div style="font-size:12px;color:#999;">या विभागात कोणीही कर्मचारी नाही</div>';
      } else {
        listEl.innerHTML = staff.map(function (s) {
          return '<div style="display:flex;align-items:center;gap:10px;padding:6px 0;font-size:12.5px;">' +
            '<span style="width:26px;height:26px;border-radius:50%;background:var(--rail);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;">' + s.name.substring(0, 2) + '</span>' +
            '<span>' + s.name + (s.designation ? ' <span style="color:#999;">— ' + s.designation + '</span>' : '') + '</span></div>';
        }).join('');
      }
      listEl.dataset.loaded = '1';
    });
}

document.getElementById('dirSearch').addEventListener('input', function () {
  var q = this.value.trim().toLowerCase();
  var tree = document.getElementById('dirTree');
  var resultsBox = document.getElementById('dirSearchResults');
  if (q.length < 2) { tree.style.display = 'block'; resultsBox.style.display = 'none'; return; }
  tree.style.display = 'none';
  resultsBox.style.display = 'block';
  fetch(rootPath + 'search-global.php?q=' + encodeURIComponent(q))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var staffResults = (data.results || []).filter(function (r) { return r.type === 'कर्मचारी'; });
      if (staffResults.length === 0) {
        resultsBox.innerHTML = '<div class="panel" style="padding:24px;text-align:center;color:#999;">काहीही सापडलं नाही</div>';
        return;
      }
      resultsBox.innerHTML = '<div class="panel"><table class="rtable"><tbody>' + staffResults.map(function (r) {
        return '<tr><td>👤 ' + r.title + '</td><td style="color:#999;font-size:12px;">' + r.subtitle + '</td><td><a href="' + rootPath + r.url + '" style="color:var(--rail);font-size:12px;font-weight:600;">बघा →</a></td></tr>';
      }).join('') + '</tbody></table></div>';
    });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
