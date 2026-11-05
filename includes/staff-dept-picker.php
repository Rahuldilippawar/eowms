<?php
/**
 * 🏫 कर्मचारी जोडणं/बदलणं — Campus / College → विभाग निवड (एकच Picker, create व edit दोन्हीकडे)
 *  - आधी Campus/College (प्रकारासह — Engineering, Junior College, Trust HQ…), मग फक्त त्याचेच विभाग
 *  - विभागात शोध, प्रत्येक विभागात सध्या किती कर्मचारी ते दिसतं
 *  - Management पदांसाठी "विभाग नाही" — तेव्हा निवडलेला Campus जतन होतो (form मध्ये name="campus_id")
 * वापर: staffDeptPicker($pdo, $orgId, $selectedCampusId, $selectedDeptId, $colClass)
 */
function staffDeptPicker(PDO $pdo, $orgId, $selCampus = null, $selDept = null, $col = 'col-md-6') {
    $c = $pdo->prepare("SELECT id, name, college_type, city FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
    $c->execute([(int)$orgId]);
    $campuses = $c->fetchAll();
    $d = $pdo->prepare("SELECT d.id, COALESCE(NULLIF(d.name_local, ''), d.name) AS name, d.campus_id,
            (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1) AS staff
        FROM departments d WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY name");
    $d->execute([(int)$orgId]);
    $depts = $d->fetchAll();

    // निवडलेल्या विभागावरून Campus ठरवा (Edit मध्ये)
    if ($selDept) { foreach ($depts as $x) { if ((int)$x['id'] === (int)$selDept && $x['campus_id']) { $selCampus = (int)$x['campus_id']; } } }
    if (!$selCampus && count($campuses) === 1) { $selCampus = (int)$campuses[0]['id']; }
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $data = array_map(fn($x) => ['id' => (int)$x['id'], 'n' => $x['name'], 'c' => $x['campus_id'] === null ? 0 : (int)$x['campus_id'], 's' => (int)$x['staff']], $depts);
    $uid = 'sdp' . substr(md5(uniqid('', true)), 0, 6);
    ob_start(); ?>
<div class="<?= $h($col) ?>">
  <label class="form-label-custom">🏫 Campus / College</label>
  <select name="campus_id" id="<?= $uid ?>C" class="form-select-custom">
    <?php if (count($campuses) !== 1): ?><option value="">— Campus / College निवडा —</option><?php endif; ?>
    <?php foreach ($campuses as $cp): ?><option value="<?= (int)$cp['id'] ?>" <?= (int)$selCampus === (int)$cp['id'] ? 'selected' : '' ?>><?= $h($cp['name'] . ($cp['college_type'] ? ' — ' . $cp['college_type'] : '') . ($cp['city'] ? ' (' . $cp['city'] . ')' : '')) ?></option><?php endforeach; ?>
    <?php if (array_filter($data, fn($x) => $x['c'] === 0)): ?><option value="0" <?= $selCampus === 0 ? 'selected' : '' ?>>— Trust-स्तरीय (Campus नसलेले विभाग) —</option><?php endif; ?>
  </select>
</div>
<div class="<?= $h($col) ?>">
  <label class="form-label-custom">🗂️ विभाग <span id="<?= $uid ?>N" style="font-weight:400;color:var(--text-dim);font-size:11.5px;"></span></label>
  <select name="department_id" id="<?= $uid ?>D" class="form-select-custom"></select>
  <div style="font-size:11px;color:var(--text-dim);margin-top:3px;">विभाग नसेल तर फक्त Management पदांसाठी (अध्यक्ष, GM…) — तेव्हा वरचा Campus जतन होतो.</div>
</div>
<script>
(function () {
  var D = <?= json_encode($data, JSON_UNESCAPED_UNICODE) ?>, sel = <?= (int)$selDept ?>;
  var c = document.getElementById('<?= $uid ?>C'), d = document.getElementById('<?= $uid ?>D'), n = document.getElementById('<?= $uid ?>N');
  var T = function (s) { return window.eowmsT ? eowmsT(s) : s; };
  function fill() {
    var cid = c.value, keep = d.value || String(sel);
    var list = D.filter(function (x) { return cid === '' ? true : String(x.c) === cid; });
    d.innerHTML = '';
    var o0 = document.createElement('option'); o0.value = ''; o0.textContent = T('— विभाग नाही (फक्त Management पदांसाठी) —'); d.appendChild(o0);
    list.forEach(function (x) { var o = document.createElement('option'); o.value = x.id; o.textContent = x.n + (x.s ? '  (' + x.s + ')' : ''); if (String(x.id) === keep) o.selected = true; d.appendChild(o); });
    n.textContent = cid === '' ? '' : '— ' + list.length + ' ' + T('विभाग');
  }
  c.addEventListener('change', function () { sel = 0; d.value = ''; fill(); });
  fill();
})();
</script>
<?php
    return ob_get_clean();
}

/** Form मधून आलेला Campus/विभाग तपासून [campus_id, department_id] — विभाग असेल तर त्याचाच Campus */
function staffDeptResolve(PDO $pdo, $orgId, $postedCampus, $postedDept) {
    $deptId = ($postedDept ?? '') !== '' ? (int)$postedDept : null;
    if ($deptId) {
        $st = $pdo->prepare("SELECT id, campus_id FROM departments WHERE id = ? AND organization_id = ?");
        $st->execute([$deptId, (int)$orgId]);
        $row = $st->fetch();
        if (!$row) { return [null, null, false]; }
        if ($row['campus_id']) { return [(int)$row['campus_id'], $deptId, true]; }
    }
    $campusId = ($postedCampus ?? '') !== '' && (int)$postedCampus > 0 ? (int)$postedCampus : null;
    if ($campusId) {
        $st = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?");
        $st->execute([$campusId, (int)$orgId]);
        if (!$st->fetch()) { $campusId = null; }
    }
    return [$campusId, $deptId, true];
}
