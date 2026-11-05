<?php
/**
 * 🏛️ Campus प्रमुख (Principal / Manager) आणि सर्व Campus चे केंद्रीय प्रमुख
 *  - Super Admin "कॅम्पस व्यवस्थापन → Campus प्रमुख" मधून नेमतो (table campus_heads; campus_id NULL = सर्व Campus)
 *  - Campus प्रमुख → त्याच्या Campus चा Dashboard, आणि त्या Campus चे सगळे विभाग (Tasks/नूतनीकरण/विनंत्या…)
 *  - केंद्रीय प्रमुख → संपूर्ण संस्था (सर्व Campus)
 */

function campusHeadsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { $pdo->query("SELECT 1 FROM campus_heads LIMIT 1"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** हा माणूस कोणत्या Campus चा प्रमुख: ['central' => bool, 'campuses' => [id => ['name'=>…, 'title'=>…]]] */
function campusHeadInfo(PDO $pdo, $userId, $orgId = null) {
    static $cache = [];
    $orgId = $orgId ?? activeOrgId();
    $key = (int)$userId . '|' . (int)$orgId;
    if (isset($cache[$key])) { return $cache[$key]; }
    $out = ['central' => false, 'central_title' => null, 'campuses' => []];
    if (!$userId || !campusHeadsReady($pdo)) { return $cache[$key] = $out; }
    $st = $pdo->prepare("SELECT h.campus_id, h.title, c.name, c.college_type FROM campus_heads h LEFT JOIN campuses c ON c.id = h.campus_id
        WHERE h.user_id = ? AND h.organization_id = ? AND (h.campus_id IS NULL OR c.is_active = 1) ORDER BY c.name");
    $st->execute([(int)$userId, (int)$orgId]);
    foreach ($st->fetchAll() as $r) {
        if ($r['campus_id'] === null) { $out['central'] = true; $out['central_title'] = $r['title']; }
        else { $out['campuses'][(int)$r['campus_id']] = ['name' => $r['name'], 'title' => $r['title'], 'type' => $r['college_type']]; }
    }
    return $cache[$key] = $out;
}

/** Campus प्रमुख असेल तर त्या Campus(es) चे सगळे सक्रिय विभाग; नसेल तर [] */
function campusHeadDepartmentIds(PDO $pdo, $userId) {
    $info = campusHeadInfo($pdo, $userId);
    if (!$info['campuses']) { return []; }
    $ids = array_keys($info['campuses']);
    $st = $pdo->prepare("SELECT id FROM departments WHERE is_active = 1 AND campus_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * मुख्य Dashboard चा व्याप: null = संपूर्ण संस्था; नाहीतर दिसणाऱ्या विभागांची यादी.
 * Super Admin / केंद्रीय प्रमुख / Management → संस्था; Campus प्रमुख → त्याचा Campus (+ स्वतःचा विभाग); बाकी → स्वतःचा विभाग
 */
function dashboardScopeDeptIds(PDO $pdo) {
    $u = currentUser();
    if (!$u) { return [0]; }
    if (($u['role'] ?? '') === 'super_admin') { return null; }
    $info = campusHeadInfo($pdo, $u['id']);
    if ($info['central']) { return null; }
    if ($info['campuses']) {
        $ids = campusHeadDepartmentIds($pdo, $u['id']);
        if (!empty($u['department_id'])) { $ids[] = (int)$u['department_id']; }
        return array_values(array_unique($ids)) ?: [0];
    }
    if (isManagementOrAbove()) { return null; }
    return [(int)($u['department_id'] ?? 0)];
}

/** SQL तुकडा: " AND <col> IN (?,?)" + params ($ids null = काहीच Filter नाही) */
function scopeInSql($col, $ids) {
    if ($ids === null) { return ['', []]; }
    $ids = $ids ?: [0];
    return [' AND ' . $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)];
}

/* ---------- नेमणूक — कॅम्पस व्यवस्थापन (index.php: केंद्रीय प्रमुख, edit.php: त्या Campus चा प्रमुख) — फक्त Super Admin ---------- */

/** POST action = head_assign / head_remove हाताळतो (Super Admin नसेल तर काहीच करत नाही). परत: flash संदेश किंवा null */
function campusHeadsHandlePost(PDO $pdo, $orgId) {
    $a = $_POST['action'] ?? '';
    if (!in_array($a, ['head_assign', 'head_remove'], true) || !hasRole('super_admin') || !campusHeadsReady($pdo)) { return null; }
    $me = currentUser();
    if ($a === 'head_remove') {
        $r = $pdo->prepare("SELECT h.*, u.name FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.id = ? AND h.organization_id = ?");
        $r->execute([(int)($_POST['head_id'] ?? 0), (int)$orgId]);
        if (!($row = $r->fetch())) { return 'नोंद सापडली नाही.'; }
        $pdo->prepare("DELETE FROM campus_heads WHERE id = ?")->execute([$row['id']]);
        logActivity('Campus प्रमुख पदावरून काढले', 'campus', $row['campus_id'], $row['name']);
        return $row['name'] . ' यांचा Campus प्रमुख Access काढला.';
    }
    $uid = (int)($_POST['user_id'] ?? 0);
    $cid = ($_POST['campus_id'] ?? '') === 'all' ? null : (int)($_POST['campus_id'] ?? 0);
    $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 100)) ?: null;
    $u = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
    $u->execute([$uid, (int)$orgId]);
    $target = $u->fetch();
    $okCampus = $cid === null;
    if (!$okCampus) { $c = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?"); $c->execute([$cid, (int)$orgId]); $okCampus = (bool)$c->fetch(); }
    if (!$target || !$okCampus) { return 'कर्मचारी किंवा Campus सापडला नाही.'; }
    $ex = $pdo->prepare("SELECT id FROM campus_heads WHERE organization_id = ? AND user_id = ? AND " . ($cid === null ? 'campus_id IS NULL' : 'campus_id = ?'));
    $ex->execute($cid === null ? [(int)$orgId, $uid] : [(int)$orgId, $uid, $cid]);
    if ($row = $ex->fetch()) { $pdo->prepare("UPDATE campus_heads SET title = ? WHERE id = ?")->execute([$title, $row['id']]); }
    else { $pdo->prepare("INSERT INTO campus_heads (organization_id, campus_id, user_id, title, assigned_by) VALUES (?, ?, ?, ?, ?)")->execute([(int)$orgId, $cid, $uid, $title, $me['id']]); }
    logActivity($cid === null ? 'केंद्रीय Campus प्रमुख नेमला' : 'Campus प्रमुख नेमला', 'campus', $cid, $target['name'] . ($title ? " ($title)" : ''));
    return '✅ ' . $target['name'] . ' — ' . ($cid === null ? 'सर्व Campus चे केंद्रीय प्रमुख' : 'Campus प्रमुख') . ' म्हणून नेमले. Login केल्यावर त्यांना Command Center मध्ये त्यांचा Campus Dashboard दिसेल.';
}

/** नेमलेले प्रमुख (chips) + नवीन नेमण्याचा Form. $campusId null = केंद्रीय प्रमुख */
function campusHeadsPanel(PDO $pdo, $orgId, $campusId, $heading, $hint, $placeholder) {
    if (!campusHeadsReady($pdo)) { return '<div class="panel mt-3" style="padding:14px 18px;font-size:13px;">🛠️ Campus प्रमुख नेमण्यासाठी आधी <b>System Update → Database Update</b> चालवा.</div>'; }
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $st = $pdo->prepare("SELECT h.id, h.title, u.name FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.organization_id = ? AND " . ($campusId === null ? 'h.campus_id IS NULL' : 'h.campus_id = ?') . " ORDER BY u.name");
    $st->execute($campusId === null ? [(int)$orgId] : [(int)$orgId, (int)$campusId]);
    $heads = $st->fetchAll();
    $staff = $pdo->prepare("SELECT u.id, u.name, u.designation, c.name AS campus_name FROM users u LEFT JOIN campuses c ON c.id = u.campus_id WHERE u.organization_id = ? AND u.is_active = 1 AND u.role <> 'super_admin' ORDER BY u.name");
    $staff->execute([(int)$orgId]);
    $o = '<div class="panel mt-3" style="padding:16px 20px;border-left:4px solid var(--rail);"><div style="font-weight:800;font-size:15px;">' . $heading . '</div><div style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">' . $hint . '</div><div class="d-flex flex-wrap gap-2">';
    if (!$heads) { $o .= '<span style="font-size:12.5px;color:var(--amber);font-weight:600;">⚠️ अजून कोणी नेमलेलं नाही</span>'; }
    foreach ($heads as $hd) {
        $o .= '<span style="display:inline-flex;gap:8px;align-items:center;background:var(--canvas);border:1px solid var(--line);border-radius:99px;padding:5px 6px 5px 12px;font-size:12.5px;"><b>' . $h($hd['name']) . '</b>' . ($hd['title'] ? '<span style="color:var(--rail);font-weight:600;">' . $h($hd['title']) . '</span>' : '')
            . '<form method="POST" style="display:inline;margin:0;" onsubmit="return window.confirm(\'' . $h($hd['name']) . ' यांचा Campus प्रमुख Access काढायचा?\');"><input type="hidden" name="action" value="head_remove"><input type="hidden" name="head_id" value="' . (int)$hd['id'] . '">'
            . '<button type="submit" title="काढा" style="border:none;background:var(--rose-soft,#FDECEC);color:var(--rose);border-radius:99px;width:22px;height:22px;font-size:12px;line-height:1;cursor:pointer;">×</button></form></span>';
    }
    $o .= '</div><form method="POST" class="d-flex gap-2 flex-wrap align-items-center mt-2"><input type="hidden" name="action" value="head_assign"><input type="hidden" name="campus_id" value="' . ($campusId === null ? 'all' : (int)$campusId) . '">'
        . '<select name="user_id" class="form-select-custom" style="max-width:280px;" required><option value="">— कर्मचारी निवडा —</option>';
    foreach ($staff->fetchAll() as $s) { $o .= '<option value="' . (int)$s['id'] . '">' . $h($s['name'] . ($s['designation'] ? ' — ' . $s['designation'] : '') . ($s['campus_name'] ? ' · ' . $s['campus_name'] : '')) . '</option>'; }
    $o .= '</select><input type="text" name="title" class="form-control-custom" style="max-width:200px;" maxlength="100" placeholder="' . $h($placeholder) . '">'
        . '<button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:7px 14px;font-weight:600;"><i class="fa-solid fa-user-plus me-1"></i>नेमा</button></form></div>';
    return $o;
}
