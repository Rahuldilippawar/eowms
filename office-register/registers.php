<?php
// 📚 आवक-जावक नोंदवह्या व access — फक्त Super Admin.
// प्रत्येक विभाग / कॉलेज / शाळा / कार्यालयाची स्वतंत्र नोंदवही, स्वतःचं letterhead, जा.क्र. उपसर्ग, सह्या;
// कोणाला कोणत्या नोंदवहीचा कोणता access: 👁️ फक्त पाहणे · ✍️ पाहणे + नोंद · 🛠️ पाहणे + नोंद + बदल (व्यक्ती, पद किंवा संपूर्ण विभाग)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/office-register.php';
if (!orIsAdmin()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">नोंदवह्या व access फक्त Super Admin ठरवतात.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
if (!orReady($pdo)) { header('Location: index.php'); exit; }
$LV = orLevels();
$KINDS = orRegKinds();

$depts = []; $campuses = []; $roles = [];
try { $st = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local, ''), name) nm, code, campus_id FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY nm"); $st->execute([$orgId]); foreach ($st->fetchAll() as $d) $depts[(int)$d['id']] = $d; } catch (PDOException $e) {}
try { $st = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? ORDER BY name"); $st->execute([$orgId]); $campuses = $st->fetchAll(PDO::FETCH_KEY_PAIR); } catch (PDOException $e) {}
foreach ($pdo->query("SELECT role_key, COALESCE(NULLIF(label_local, ''), label) l, category FROM role_definitions WHERE role_key <> 'super_admin' ORDER BY FIELD(category,'management','hod','staff'), l")->fetchAll() as $r) $roles[$r['role_key']] = $r;
$back = fn($id) => 'registers.php' . ($id ? '?edit=' . (int)$id : '');

// ---------- कृती ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    if ($id && !orRegister($pdo, $orgId, $id)) { header('Location: registers.php'); exit; }
    if ($act === 'save_register') {
        $t = fn($k, $m = 255) => mb_substr(trim(str_replace("\r", '', (string)($_POST[$k] ?? ''))), 0, $m);
        $name = $t('name', 150);
        if ($name === '') { $_SESSION['flash'] = '⚠️ नोंदवहीचं नाव लिहा.'; header('Location: ' . ($id ? $back($id) : 'registers.php?new=1')); exit; }
        $rk = array_values(array_intersect(array_keys($roles), (array)($_POST['role_keys'] ?? [])));
        $d = ['name' => $name, 'kind' => isset($KINDS[$_POST['kind'] ?? '']) ? $_POST['kind'] : 'office',
            'campus_id' => isset($campuses[(int)($_POST['campus_id'] ?? 0)]) ? (int)$_POST['campus_id'] : null, 'department_id' => isset($depts[(int)($_POST['department_id'] ?? 0)]) ? (int)$_POST['department_id'] : null,
            'title' => $t('title', 150) ?: null, 'report_head' => $t('report_head', 1000) ?: null, 'letterhead' => $t('letterhead', 1000) ?: null, 'prefix' => $t('prefix', 150) ?: null,
            'letter_signs' => $t('letter_signs', 1000) ?: null, 'report_signs' => $t('report_signs', 1000) ?: null, 'remark_line' => $t('remark_line', 200) ?: null,
            'role_keys' => $rk ? implode(',', $rk) : null, 'role_level' => isset($LV[$_POST['role_level'] ?? '']) ? $_POST['role_level'] : 'edit',
            'is_active' => !empty($_POST['is_active']) ? 1 : 0, 'sort_order' => (int)($_POST['sort_order'] ?? 0)];
        if ($id) {
            $pdo->prepare("UPDATE office_registers SET " . implode(', ', array_map(fn($k) => "$k = ?", array_keys($d))) . ", updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([...array_values($d), $id, $orgId]);
            logActivity('आवक-जावक नोंदवही बदलली', 'office_register', $id, $name);
        } else {
            $pdo->prepare("INSERT INTO office_registers (organization_id, " . implode(', ', array_keys($d)) . ", created_by, created_at) VALUES (?, " . implode(', ', array_fill(0, count($d), '?')) . ", ?, NOW())")->execute([$orgId, ...array_values($d), $user['id']]);
            $id = (int)$pdo->lastInsertId();
            logActivity('आवक-जावक नोंदवही तयार', 'office_register', $id, $name);
        }
        $_SESSION['flash'] = '✓ "' . $name . '" नोंदवही जतन झाली. आता खाली कोणाला कोणता access ते ठरवा.';
        header('Location: ' . $back($id)); exit;
    }
    if ($act === 'save_access' && $id) {
        $lv = isset($LV[$_POST['level'] ?? '']) ? $_POST['level'] : 'read';
        $uids = array_filter(array_map('intval', (array)($_POST['user_ids'] ?? [$_POST['user_id'] ?? 0])));
        $ok = 0;
        $chk = $pdo->prepare("SELECT id FROM users WHERE id = ? AND organization_id = ?");
        $ins = $pdo->prepare("INSERT INTO office_register_access (register_id, organization_id, user_id, level, granted_by, created_at) VALUES (?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE level = VALUES(level), granted_by = VALUES(granted_by)");
        foreach ($uids as $u) { $chk->execute([$u, $orgId]); if ($chk->fetchColumn()) { $ins->execute([$id, $orgId, $u, $lv, $user['id']]); $ok++; } }
        if ($ok) logActivity('आवक-जावक access दिला', 'office_register', $id, $ok . ' व्यक्ती — ' . strip_tags(orLevelLabel($lv)));
        $_SESSION['flash'] = $ok ? '✓ ' . $ok . ' व्यक्तींना ' . orLevelLabel($lv) . ' access.' : '⚠️ व्यक्ती निवडा.';
        header('Location: ' . $back($id)); exit;
    }
    if ($act === 'save_access_dept' && $id) {
        $lv = isset($LV[$_POST['level'] ?? '']) ? $_POST['level'] : 'read';
        $did = (int)($_POST['dept_id'] ?? 0);
        $n = 0;
        if (isset($depts[$did])) {
            $st = $pdo->prepare("INSERT IGNORE INTO office_register_access (register_id, organization_id, user_id, level, granted_by, created_at) SELECT ?, ?, id, ?, ?, NOW() FROM users WHERE organization_id = ? AND department_id = ? AND is_active = 1 AND role <> 'super_admin'");
            $st->execute([$id, $orgId, $lv, $user['id'], $orgId, $did]); $n = $st->rowCount();
        }
        $_SESSION['flash'] = '✓ ' . ($depts[$did]['nm'] ?? '') . ' विभागातील ' . $n . ' कर्मचाऱ्यांना ' . orLevelLabel($lv) . ' access (आधी access असलेले तसेच).';
        header('Location: ' . $back($id)); exit;
    }
    if ($act === 'revoke_access' && $id) {
        $pdo->prepare("DELETE FROM office_register_access WHERE register_id = ? AND organization_id = ? AND user_id = ?")->execute([$id, $orgId, (int)($_POST['user_id'] ?? 0)]);
        logActivity('आवक-जावक access काढला', 'office_register', $id, 'user ' . (int)($_POST['user_id'] ?? 0));
        $_SESSION['flash'] = '✓ access काढला.';
        header('Location: ' . $back($id)); exit;
    }
}

$regs = orRegisters($pdo, $orgId, true);
$editId = (int)($_GET['edit'] ?? 0);
$E = $editId ? ($regs[$editId] ?? null) : null;
$isNew = !empty($_GET['new']);
$fy = orFy();
// आकडे
$stats = [];
$st = $pdo->prepare("SELECT register_id, SUM(direction = 'in' AND fy = ?) i, SUM(direction = 'out' AND fy = ?) o, SUM(status IN ('pending','progress')) op, COUNT(*) n FROM office_letters WHERE organization_id = ? GROUP BY register_id");
$st->execute([$fy, $fy, $orgId]); foreach ($st->fetchAll() as $r) $stats[(int)$r['register_id']] = $r;
$acc = [];
$st = $pdo->prepare("SELECT a.register_id, a.level, a.user_id, u.name, u.designation, u.role, u.is_active, COALESCE(NULLIF(d.name_local, ''), d.name) dept FROM office_register_access a JOIN users u ON u.id = a.user_id LEFT JOIN departments d ON d.id = u.department_id WHERE a.organization_id = ? ORDER BY FIELD(a.level, 'edit', 'write', 'read'), u.name");
$st->execute([$orgId]); foreach ($st->fetchAll() as $a) $acc[(int)$a['register_id']][] = $a;
$users = [];
if ($E) { $st = $pdo->prepare("SELECT u.id, u.name, u.designation, u.employee_code, COALESCE(NULLIF(d.name_local, ''), d.name) dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.organization_id = ? AND u.is_active = 1 AND u.role <> 'super_admin' ORDER BY u.name"); $st->execute([$orgId]); $users = $st->fetchAll(); }
// पदामुळे access असलेले लोक (माहितीसाठी)
$roleUsers = [];
if ($E && $E['role_keys']) {
    $rk = array_map('trim', explode(',', $E['role_keys']));
    $st = $pdo->prepare("SELECT name, role FROM users WHERE organization_id = ? AND is_active = 1 AND role IN (" . implode(',', array_fill(0, count($rk), '?')) . ") ORDER BY name LIMIT 30");
    $st->execute([$orgId, ...$rk]); $roleUsers = $st->fetchAll();
}
$F = $E ?: ['id' => 0, 'name' => '', 'kind' => 'dept', 'campus_id' => null, 'department_id' => null, 'title' => '', 'report_head' => '', 'letterhead' => '', 'prefix' => '', 'letter_signs' => '', 'report_signs' => '',
    'remark_line' => '', 'role_keys' => '', 'role_level' => 'edit', 'is_active' => 1, 'sort_order' => count($regs)];
$st = $pdo->prepare("SELECT name FROM organizations WHERE id = ?"); $st->execute([$orgId]); $orgName = (string)$st->fetchColumn();

$pageTitle = 'आवक-जावक — नोंदवह्या व access';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .rg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;}
  .rg-card{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:14px;display:flex;flex-direction:column;gap:8px;}
  .rg-card.off{opacity:.6;} .rg-card h5{font-size:15px;font-weight:800;margin:0;} .rg-kind{font-size:11.5px;font-weight:700;color:var(--text-dim);}
  .rg-st{display:flex;gap:6px;flex-wrap:wrap;font-size:11.5px;} .rg-st span{background:var(--canvas);border-radius:7px;padding:2px 8px;} .rg-st b{color:var(--text);}
  .rg-acc{display:flex;gap:4px;flex-wrap:wrap;} .lv{font-size:11px;font-weight:700;border-radius:99px;padding:2px 8px;white-space:nowrap;}
  .lv-read{background:#E0F2FE;color:#075985;} .lv-write{background:#FEF3C7;color:#92400E;} .lv-edit{background:#DCFCE7;color:#166534;} .lv-role{background:#EDE9FE;color:#5B21B6;}
  .rg-box{border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:16px;margin-bottom:14px;}
  .rg-box h6{font-size:13.5px;font-weight:800;margin:0 0 10px;}
  .rg-lv{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;} .rg-lv label{border:2px solid var(--line);border-radius:12px;padding:8px 10px;cursor:pointer;font-size:12.5px;} .rg-lv label b{display:block;font-size:13px;}
  .rg-lv label:has(input:checked){border-color:#1D4ED8;background:color-mix(in srgb,#1D4ED8 7%,var(--surface));} .rg-lv input{display:none;}
  .rg-t{width:100%;border-collapse:collapse;font-size:13px;} .rg-t td,.rg-t th{border-bottom:1px solid var(--line);padding:7px 6px;vertical-align:middle;} .rg-t th{font-size:11.5px;color:var(--text-dim);text-align:left;}
  .rg-roles{display:flex;gap:6px;flex-wrap:wrap;} .rg-roles label{font-size:12px;border:1px solid var(--line);border-radius:99px;padding:3px 10px;cursor:pointer;} .rg-roles label:has(input:checked){background:#5B21B6;border-color:#5B21B6;color:#fff;}
  .rg-hint{font-size:11.5px;color:var(--text-dim);margin-top:3px;}
  @media (max-width:700px){ .rg-lv{grid-template-columns:1fr;} }
</style>

<div class="page-eyebrow"><a href="index.php" style="color:inherit;">📨 आवक-जावक</a></div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">📚 नोंदवह्या व access</div>
  <?php if (!$E && !$isNew): ?><a href="registers.php?new=1" class="btn" style="background:#1D4ED8;color:#fff;border-radius:10px;font-weight:700;">＋ नवीन नोंदवही</a><?php else: ?><a href="registers.php" class="btn" style="border:1px solid var(--line);border-radius:10px;">← सर्व नोंदवह्या</a><?php endif; ?>
</div>

<?php if (!$E && !$isNew): ?>
<div class="rg-box" style="font-size:12.5px;line-height:1.8;">
  <b>Access चे प्रकार:</b> <?php foreach ($LV as $k => [$l, $d]): ?><span class="lv lv-<?= $k ?>"><?= h($l) ?></span> <?= h($d) ?> &nbsp; <?php endforeach; ?><br>
  ज्यांना ज्या नोंदवहीचा access दिला आहे, त्यांना पत्रव्यवहार → आवक-जावक नोंदवहीत फक्त तीच नोंदवही दिसते. Super Admin ला सगळ्या.
</div>
<?php if (!$regs): ?><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">अजून एकही नोंदवही नाही. <a href="registers.php?new=1">＋ पहिली नोंदवही तयार करा</a></div><?php endif; ?>
<div class="rg-grid">
  <?php foreach ($regs as $r): $s = $stats[(int)$r['id']] ?? null; $a = $acc[(int)$r['id']] ?? []; ?>
  <div class="rg-card <?= $r['is_active'] ? '' : 'off' ?>">
    <div class="d-flex justify-content-between gap-2"><div><div class="rg-kind"><?= h($KINDS[$r['kind']] ?? '') ?><?= $r['department_id'] && isset($depts[(int)$r['department_id']]) ? ' · ' . h($depts[(int)$r['department_id']]['nm']) : '' ?><?= $r['campus_id'] && isset($campuses[(int)$r['campus_id']]) ? ' · ' . h($campuses[(int)$r['campus_id']]) : '' ?></div><h5><?= h($r['name']) ?></h5></div><?= $r['is_active'] ? '' : '<span class="lv lv-read">बंद</span>' ?></div>
    <div class="rg-st"><span>आ.व. <?= h($fy) ?>: आवक <b><?= (int)($s['i'] ?? 0) ?></b></span><span>जावक <b><?= (int)($s['o'] ?? 0) ?></b></span><span>उघडी <b style="color:#B45309;"><?= (int)($s['op'] ?? 0) ?></b></span></div>
    <div class="rg-acc">
      <?php foreach (array_slice($a, 0, 6) as $x): ?><span class="lv lv-<?= h($x['level']) ?>" title="<?= h(orLevelLabel($x['level'])) ?>"><?= h(mb_strimwidth($x['name'], 0, 22, '…')) ?></span><?php endforeach; ?>
      <?php if (count($a) > 6): ?><span class="lv lv-read">+<?= count($a) - 6 ?></span><?php endif; ?>
      <?php if ($r['role_keys']): foreach (explode(',', $r['role_keys']) as $rk): ?><span class="lv lv-role">पद: <?= h($roles[trim($rk)]['l'] ?? $rk) ?></span><?php endforeach; endif; ?>
      <?php if (!$a && !$r['role_keys']): ?><span style="font-size:12px;color:#B45309;">⚠️ अजून कोणालाही access नाही</span><?php endif; ?>
    </div>
    <div class="d-flex gap-2 mt-auto"><a href="registers.php?edit=<?= (int)$r['id'] ?>" class="btn btn-sm" style="background:#1D4ED8;color:#fff;border-radius:8px;font-weight:700;">✎ नोंदवही व access</a><a href="index.php?r=<?= (int)$r['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">उघडा</a></div>
  </div>
  <?php endforeach; ?>
</div>

<?php else: ?>
<form method="POST" class="rg-box" data-no-smart-filter id="rgForm">
  <input type="hidden" name="action" value="save_register"><input type="hidden" name="id" value="<?= (int)$F['id'] ?>">
  <h6><?= $E ? '✎ ' . h($E['name']) : '＋ नवीन नोंदवही' ?></h6>
  <div class="row g-2">
    <div class="col-md-3 col-6"><label class="form-label-custom">प्रकार</label><select name="kind" class="form-select-custom"><?php foreach ($KINDS as $k => $l): ?><option value="<?= $k ?>" <?= $F['kind'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 col-6"><label class="form-label-custom">विभाग (असल्यास)</label><select name="department_id" class="form-select-custom" id="rgDept"><option value="">—</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" data-nm="<?= h($d['nm']) ?>" data-code="<?= h($d['code']) ?>" <?= (int)$F['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['nm']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 col-6"><label class="form-label-custom">Campus / कॉलेज / शाळा</label><select name="campus_id" class="form-select-custom"><option value="">—</option><?php foreach ($campuses as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= (int)$F['campus_id'] === (int)$cid ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-3 col-6"><label class="form-label-custom">क्रम</label><input type="number" name="sort_order" class="form-control-custom" value="<?= (int)$F['sort_order'] ?>"></div>
    <div class="col-md-6"><label class="form-label-custom">नोंदवहीचं नाव *</label><input name="name" class="form-control-custom" value="<?= h($F['name']) ?>" maxlength="150" required placeholder="उदा. कार्याध्यक्ष कार्यालय / स्टेट बोर्ड स्कूल / IT विभाग"></div>
    <div class="col-md-6"><label class="form-label-custom">रजिस्टरवरचं नाव ("… आवक रजिस्टर")</label><input name="title" class="form-control-custom" value="<?= h($F['title']) ?>" maxlength="150" placeholder="उदा. ट्रस्ट व्यवस्थापक"></div>
    <div class="col-md-6"><label class="form-label-custom">📝 पत्राचं Letterhead (ओळी)</label><textarea name="letterhead" class="form-control-custom" rows="4" placeholder="!! आत्मा मालिक !!&#10;संस्थेचं नाव&#10;विभाग / कॉलेज&#10;पत्ता"><?= h($F['letterhead']) ?></textarea><div class="rg-hint">रिकामं ठेवलं तर "नोंदवहीचं शीर्षक" किंवा संस्थेचं नाव (<?= h($orgName) ?>)</div></div>
    <div class="col-md-6"><label class="form-label-custom">📄 नोंदवही / अहवालाचं शीर्षक (ओळी)</label><textarea name="report_head" class="form-control-custom" rows="4"><?= h($F['report_head']) ?></textarea></div>
    <div class="col-md-6"><label class="form-label-custom">जा.क्र. उपसर्ग</label><input name="prefix" id="rgPrefix" class="form-control-custom" value="<?= h($F['prefix']) ?>" maxlength="150" placeholder="उदा. जा.क्र./वि.जं.म.आ.ट्रस्ट/IT"><div class="rg-hint">पत्रावर: उपसर्ग / वर्ष / पुढचा जावक क्र. (या नोंदवहीचा स्वतंत्र क्रम)</div></div>
    <div class="col-md-6"><label class="form-label-custom">शेरा / सहीची ओळ (पत्रावर)</label><input name="remark_line" class="form-control-custom" value="<?= h($F['remark_line']) ?>" maxlength="200" placeholder="माननीय कार्याध्यक्ष साहेब यांचा शेरा/सही :"></div>
    <div class="col-md-6"><label class="form-label-custom">पत्रावरच्या सह्या (एका ओळीत एक — "नाव|पद" किंवा फक्त पद)</label><textarea name="letter_signs" class="form-control-custom" rows="3" placeholder="एच.आर. व्यवस्थापक&#10;व्यवस्थापक सामान्य प्रशासन"><?= h($F['letter_signs']) ?></textarea></div>
    <div class="col-md-6"><label class="form-label-custom">नोंदवहीवरच्या सह्या</label><textarea name="report_signs" class="form-control-custom" rows="3" placeholder="प्रा.उल्हास पाटील|व्यवस्थापक-सामान्य प्रशासन"><?= h($F['report_signs']) ?></textarea></div>
    <div class="col-12"><label class="form-label-custom">🎖️ पदानुसार आपोआप access (या पदावरच्या सगळ्यांना)</label>
      <div class="rg-roles"><?php $sel = array_map('trim', explode(',', (string)$F['role_keys'])); foreach ($roles as $rk => $r): ?><label><input type="checkbox" name="role_keys[]" value="<?= h($rk) ?>" <?= in_array($rk, $sel, true) ? 'checked' : '' ?> style="display:none;"><?= h($r['l']) ?></label><?php endforeach; ?></div>
      <div class="d-flex gap-2 align-items-center mt-2" style="font-size:12.5px;">या पदांना: <select name="role_level" class="form-select-custom" style="width:auto;"><?php foreach ($LV as $k => [$l]): ?><option value="<?= $k ?>" <?= $F['role_level'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div></div>
    <div class="col-12"><label style="font-size:13px;"><input type="checkbox" name="is_active" value="1" <?= $F['is_active'] ? 'checked' : '' ?>> चालू (बंद केल्यावर कोणालाच दिसत नाही, नोंदी तशाच राहतात)</label></div>
  </div>
  <button type="submit" class="btn mt-3" style="background:#1D4ED8;color:#fff;border-radius:10px;font-weight:700;">✓ नोंदवही जतन करा</button>
</form>

<?php if ($E): $a = $acc[(int)$E['id']] ?? []; ?>
<div class="rg-box">
  <h6>👥 कोणाला कोणता access — <?= h($E['name']) ?></h6>
  <form method="POST" data-no-smart-filter class="mb-3">
    <input type="hidden" name="action" value="save_access"><input type="hidden" name="id" value="<?= (int)$E['id'] ?>">
    <label class="form-label-custom">व्यक्ती निवडा (एकापेक्षा जास्त — Ctrl / लांब दाबा)</label>
    <input type="search" class="form-control-custom mb-1" id="rgFind" placeholder="नाव, पद, विभाग, कर्मचारी क्र. ने शोधा…">
    <select name="user_ids[]" multiple size="6" class="form-select-custom" id="rgUsers" style="height:auto;"><?php $has = array_column($a, 'level', 'user_id'); foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>" data-s="<?= h(mb_strtolower($u['name'] . ' ' . $u['designation'] . ' ' . $u['dept'] . ' ' . $u['employee_code'])) ?>"><?= h($u['name']) ?><?= $u['designation'] ? ' — ' . h($u['designation']) : '' ?><?= $u['dept'] ? ' (' . h($u['dept']) . ')' : '' ?><?= isset($has[$u['id']]) ? ' ✓ ' . h(strip_tags(orLevelLabel($has[$u['id']]))) : '' ?></option><?php endforeach; ?></select>
    <div class="rg-lv mt-2"><?php foreach ($LV as $k => [$l, $d]): ?><label><input type="radio" name="level" value="<?= $k ?>" <?= $k === 'write' ? 'checked' : '' ?>><b><?= h($l) ?></b><?= h($d) ?></label><?php endforeach; ?></div>
    <button type="submit" class="btn mt-2" style="background:#16A34A;color:#fff;border-radius:10px;font-weight:700;">✓ access द्या</button>
  </form>
  <?php if ($depts): ?>
  <form method="POST" data-no-smart-filter class="d-flex flex-wrap gap-2 align-items-center mb-3" style="font-size:12.5px;">
    <input type="hidden" name="action" value="save_access_dept"><input type="hidden" name="id" value="<?= (int)$E['id'] ?>">
    🏢 संपूर्ण विभागाला: <select name="dept_id" class="form-select-custom" style="width:auto;"><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$E['department_id'] === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['nm']) ?></option><?php endforeach; ?></select>
    <select name="level" class="form-select-custom" style="width:auto;"><?php foreach ($LV as $k => [$l]): ?><option value="<?= $k ?>"><?= h($l) ?></option><?php endforeach; ?></select>
    <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;">विभागातील सगळ्यांना द्या</button>
  </form>
  <?php endif; ?>
  <?php if ($a): ?>
  <table class="rg-t"><thead><tr><th>व्यक्ती</th><th>विभाग</th><th style="width:230px;">access</th><th style="width:60px;"></th></tr></thead><tbody>
  <?php foreach ($a as $x): ?><tr>
    <td><b><?= h($x['name']) ?></b><?= $x['designation'] ? '<div class="rg-hint">' . h($x['designation']) . '</div>' : '' ?><?= $x['is_active'] ? '' : ' <span class="lv lv-read">निष्क्रिय</span>' ?></td><td><?= h($x['dept'] ?: '—') ?></td>
    <td><form method="POST" class="m-0" data-no-smart-filter><input type="hidden" name="action" value="save_access"><input type="hidden" name="id" value="<?= (int)$E['id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$x['user_id'] ?>">
      <select name="level" class="form-select-custom" style="font-size:12.5px;" onchange="this.form.submit()"><?php foreach ($LV as $k => [$l]): ?><option value="<?= $k ?>" <?= $x['level'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></form></td>
    <td><form method="POST" class="m-0" onsubmit="return confirm('<?= h($x['name']) ?> यांचा या नोंदवहीचा access काढायचा?');"><input type="hidden" name="action" value="revoke_access"><input type="hidden" name="id" value="<?= (int)$E['id'] ?>"><input type="hidden" name="user_id" value="<?= (int)$x['user_id'] ?>"><button type="submit" class="btn btn-sm" style="color:#DC2626;border:1px solid var(--line);border-radius:8px;" title="access काढा">✕</button></form></td>
  </tr><?php endforeach; ?>
  </tbody></table>
  <?php else: ?><div class="rg-hint">अजून कोणालाही व्यक्तिशः access दिलेला नाही.</div><?php endif; ?>
  <?php if ($E['role_keys']): ?><div class="mt-3" style="font-size:12.5px;"><span class="lv lv-role">पदामुळे — <?= h(orLevelLabel($E['role_level'])) ?></span> <?= $roleUsers ? h(implode(', ', array_column($roleUsers, 'name'))) : '<span class="rg-hint">या पदावर अजून कोणी नाही</span>' ?></div><?php endif; ?>
</div>
<?php endif; ?>
<script>
(function () {
  var d = document.getElementById('rgDept'), f = document.getElementById('rgForm');
  if (d && f) d.addEventListener('change', function () {
    var o = d.options[d.selectedIndex]; if (!o.value) return;
    if (!f.name.value.trim()) f.name.value = o.dataset.nm;
    if (!f.prefix.value.trim() && o.dataset.code) f.prefix.value = 'जा.क्र./' + o.dataset.code;
    if (f.kind.value === 'office') f.kind.value = 'dept';
  });
  var q = document.getElementById('rgFind'), u = document.getElementById('rgUsers');
  if (q && u) q.addEventListener('input', function () { var t = q.value.trim().toLowerCase(); [].forEach.call(u.options, function (o) { o.hidden = t && o.dataset.s.indexOf(t) < 0; }); });
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
