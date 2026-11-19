<?php
/**
 * 🚌 विद्यार्थी बस पास — आडवं Card (86 × 54 mm), ID Card च्याच Design-सेटिंगमधून (Logo, रंग, Principal सही).
 *  - कुठून → कुठपर्यंत (मोठ्या अक्षरात, बसच्या मार्गासह), कधीपासून → कधीपर्यंत valid
 *  - QR → students/verify-pass.php — Signature जुळलं तरच; पास चालू / संपला / रद्द लगेच दिसतं
 *  - नूतनीकरण = नवीन नोंद, जुना पास इतिहासात राहतो
 */
require_once __DIR__ . '/student-id-card.php';

function busPassReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM student_bus_passes LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

function busStudentsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM bus_students LIMIT 0"); $pdo->query("SELECT bus_student_id FROM student_bus_passes LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** बस पास विभाग पाहणे: बस पास परवानगी किंवा Management */
function canViewBusPass() { return canBusPass() || isManagementOrAbove(); }

/** Import Fields — बससाठीचे (कुठून / कुठपर्यंत / मार्ग) आधी, मग विद्यार्थ्यांचे */
function busStudentImportFields() {
    $f = [
        'balance_fee' => ['₹ बाकी शुल्क', '/balance|बाकी/iu'],
        'paid_fee'    => ['₹ भरलेले शुल्क', '/paid|भरलेले|जमा/iu'],
        'total_fee'   => ['₹ एकूण शुल्क', '/total\s*fee|^fee$|^fees$|एकूण\s*शुल्क|^शुल्क$/iu'],
        'from_place'  => ['🏫 Source (कुठून)', '/^from|^source|boarding|कुठून/iu'],
        'to_place'    => ['🚏 Destination / थांबा', '/^to$|^to\s|destination|drop|stop|village|^गाव|थांबा|कुठपर्यंत|पर्यंत|बस\s*स्थानक/iu'],
        'route_name'  => ['🛣️ मार्ग / बस', '/route|bus\s*no|vehicle|मार्ग|बस\s*क्र/iu'],
    ];
    foreach (studentImportFields() as $k => $v) {
        if (in_array($k, ['admission_date', 'father_name', 'mother_name', 'email', 'category', 'aadhaar', 'department', 'year_of_study'], true)) continue;
        $f[$k] = $v;
    }
    $f['admission_no'] = ['Reg No / Gr.No. / ID', '/regis|reg\.?\s*no|admission|adm\.?\s*no|gr\.?\s*no|g\.r\.|general\s*reg|enrol|prn|student\s*id|प्रवेश\s*क्र|जनरल\s*रजिस्टर|नोंदणी/iu'];
    // "Division" Column मध्ये शाखा (COMP / AIML) असली तरी तुकडी म्हणूनच — पासवर Class · Division
    $f['division'] = ['Division / तुकडी', '/div|section|तुकडी/iu'];
    return $f;
}

/** "कुठपर्यंत" चा अंदाज: College चं गाव → नाहीतर बस यादीत सगळ्यात जास्त वापरलेलं → नाहीतर एकच College असेल तर त्याचं */
function busDefaultTo(PDO $pdo, $orgId, $campusId = 0) {
    static $cache = [];
    $k = (int)$campusId;
    if (isset($cache[$k])) return $cache[$k];
    if ($k) {
        $st = $pdo->prepare("SELECT COALESCE(NULLIF(city,''), name) FROM campuses WHERE id = ? AND organization_id = ?");
        $st->execute([$k, $orgId]);
        if ($v = $st->fetchColumn()) return $cache[$k] = $v;
    }
    try {
        $st = $pdo->prepare("SELECT to_place FROM bus_students WHERE organization_id = ? AND to_place IS NOT NULL AND to_place <> ''" . ($k ? " AND campus_id = " . $k : "") . " GROUP BY to_place ORDER BY COUNT(*) DESC LIMIT 1");
        $st->execute([$orgId]);
        if ($v = $st->fetchColumn()) return $cache[$k] = $v;
    } catch (PDOException $e) {}
    $st = $pdo->prepare("SELECT COALESCE(NULLIF(city,''), name) FROM campuses WHERE organization_id = ? AND is_active = 1");
    $st->execute([$orgId]);
    $all = $st->fetchAll(PDO::FETCH_COLUMN);
    return $cache[$k] = count($all) === 1 ? $all[0] : '';
}

const BUS_STUDENT_COLS = ['campus_id', 'student_id', 'name', 'admission_no', 'roll_no', 'class_name', 'division', 'course', 'academic_year', 'gender', 'date_of_birth', 'mobile', 'parent_mobile', 'address', 'blood_group', 'from_place', 'to_place', 'route_name', 'total_fee', 'paid_fee', 'remarks'];

/** 🏫 Source — बस नेहमी इथून (उदा. AMRIT CAMPUS); संस्थेच्या Setting मधून */
function busSource(PDO $pdo, $orgId) {
    static $c = [];
    if (!isset($c[$orgId])) { $v = function_exists('orgSetting') ? trim((string)orgSetting($pdo, $orgId, 'bus_pass_source', '')) : ''; $c[$orgId] = $v !== '' ? $v : 'AMRIT CAMPUS'; }
    return $c[$orgId];
}

/** ठिकाणाचं नाव — मोठ्या अक्षरात दिलं असेल (ATGAON, AMRIT CAMPUS) तर तसंच; नाहीतर Title Case */
function busPlace($v) {
    $v = trim(preg_replace('/\s+/u', ' ', (string)$v));
    return $v === '' || preg_match('/^[A-Z0-9 .,&()\-\/]+$/', $v) ? $v : nameCase($v);
}

/** ₹ रक्कम — "13,750", "₹ 7000.00" → 13750.0; रिकामं → null */
function busMoney($v) {
    if ($v === null || $v === '') return null;
    $n = preg_replace('/[^0-9.\-]/', '', is_float($v) || is_int($v) ? (string)$v : (string)$v);
    return $n === '' || !is_numeric($n) ? null : round((float)$n, 2);
}

/** बस विद्यार्थी जतन — $id = 0 नवीन; $onlyFilled = Import मधून बदलताना फक्त भरलेले Fields */
function busStudentSave(PDO $pdo, $orgId, $id, array $s, $userId, $batch = null, $onlyFilled = false) {
    $lens = ['name' => 255, 'admission_no' => 50, 'roll_no' => 30, 'class_name' => 60, 'division' => 20, 'course' => 120, 'academic_year' => 20, 'address' => 500, 'blood_group' => 6, 'from_place' => 120, 'to_place' => 120, 'route_name' => 120, 'remarks' => 255];
    $vals = [];
    foreach (BUS_STUDENT_COLS as $c) {
        if (!array_key_exists($c, $s)) continue;
        $v = $s[$c] === '' ? null : $s[$c];
        if ($v !== null && isset($lens[$c])) $v = mb_substr(trim((string)$v), 0, $lens[$c]);
        if (in_array($c, ['total_fee', 'paid_fee'], true)) $v = busMoney($v);
        if ($onlyFilled && $v === null) continue;
        $vals[$c] = $v;
    }
    if ($id) {
        if (!$vals) return (int)$id;
        $pdo->prepare("UPDATE bus_students SET " . implode(', ', array_map(fn($c) => "$c = ?", array_keys($vals))) . ", updated_at = NOW() WHERE id = ? AND organization_id = ?")
            ->execute(array_merge(array_values($vals), [$id, $orgId]));
        return (int)$id;
    }
    $vals += ['organization_id' => $orgId, 'created_by' => $userId, 'import_batch' => $batch];
    $pdo->prepare("INSERT INTO bus_students (" . implode(', ', array_keys($vals)) . ", created_at) VALUES (" . implode(',', array_fill(0, count($vals), '?')) . ", NOW())")
        ->execute(array_values($vals));
    return (int)$pdo->lastInsertId();
}

/** आधीचा बस विद्यार्थी: ID/Gr.No. → (वर्ग + तुकडी + Roll) → (नाव + जन्मतारीख) → (नाव + वर्ग + तुकडी) */
function busStudentFind(PDO $pdo, $orgId, array $s) {
    $q = function ($sql, $p) use ($pdo) { $st = $pdo->prepare($sql . " LIMIT 1"); $st->execute($p); return (int)$st->fetchColumn(); };
    if (!empty($s['student_id']) && ($id = $q("SELECT id FROM bus_students WHERE organization_id = ? AND student_id = ?", [$orgId, $s['student_id']]))) return $id;
    if (!empty($s['admission_no']) && ($id = $q("SELECT id FROM bus_students WHERE organization_id = ? AND admission_no = ?", [$orgId, $s['admission_no']]))) return $id;
    if (!empty($s['roll_no']) && ($s['class_name'] ?? '') !== '' && ($id = $q("SELECT id FROM bus_students WHERE organization_id = ? AND COALESCE(class_name,'') = ? AND COALESCE(division,'') = ? AND roll_no = ? AND COALESCE(academic_year,'') = ?",
        [$orgId, (string)$s['class_name'], (string)($s['division'] ?? ''), $s['roll_no'], (string)($s['academic_year'] ?? '')]))) return $id;
    if (!empty($s['date_of_birth']) && ($id = $q("SELECT id FROM bus_students WHERE organization_id = ? AND name = ? AND date_of_birth = ?", [$orgId, $s['name'], $s['date_of_birth']]))) return $id;
    if (($s['class_name'] ?? '') !== '' && ($id = $q("SELECT id FROM bus_students WHERE organization_id = ? AND name = ? AND COALESCE(class_name,'') = ? AND COALESCE(division,'') = ?", [$orgId, $s['name'], (string)$s['class_name'], (string)($s['division'] ?? '')]))) return $id;
    return 0;
}

function busPassSig($orgId, $id, $validTill) {
    return substr(hash_hmac('sha256', 'buspass|' . (int)$orgId . '|' . (int)$id . '|' . (string)$validTill, appSecret()), 0, 12);
}

/** शैक्षणिक वर्ष "2026-27" → ['2026-07-01', '2027-06-30'] — July ते June (चुकीचं / रिकामं असेल तर चालू वर्ष) */
function busAcademicRange($ay) {
    if (!preg_match('/^(\d{4})\s*[-\/]\s*\d{2,4}$/', trim((string)$ay), $m)) { $ay = studentCurrentAcademicYear(); preg_match('/^(\d{4})/', $ay, $m); }
    $y = (int)$m[1];
    return [$y . '-07-01', ($y + 1) . '-06-30', $y . '-' . substr((string)($y + 1), -2)];
}

/** पास दिलेला नसलेल्या बस विद्यार्थ्याचा QR — शैक्षणिक वर्षासाठी (Signature: विद्यार्थी + वर्ष) */
/** "2026-27" → "JULY - 2026 TO JUNE - 2027" */
function busAyLabel($ay) {
    [$f, $t] = busAcademicRange($ay);
    return 'JULY - ' . substr($f, 0, 4) . ' TO JUNE - ' . substr($t, 0, 4);
}

function busStudentSig($orgId, $bsId, $ay) {
    return substr(hash_hmac('sha256', 'busstu|' . (int)$orgId . '|' . (int)$bsId . '|' . (string)$ay, appSecret()), 0, 12);
}

function busPassVerifyUrl($orgId, $id, $validTill, $bsId = 0, $ay = '') {
    $base = siteBaseUrl();
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    }
    if (!$id && $bsId) return $base . 'students/verify-pass.php?org=' . (int)$orgId . '&bs=' . (int)$bsId . '&ay=' . rawurlencode($ay) . '&s=' . busStudentSig($orgId, $bsId, $ay);
    return $base . 'students/verify-pass.php?org=' . (int)$orgId . '&id=' . (int)$id . '&s=' . busPassSig($orgId, $id, $validTill);
}

/** पासची स्थिती: active / expiring (7 दिवसांत संपतो) / upcoming / expired / cancelled */
function busPassState(array $p, $today = null) {
    $today = $today ?: date('Y-m-d');
    if (($p['status'] ?? 'active') === 'cancelled') return 'cancelled';
    if ($p['valid_till'] < $today) return 'expired';
    if ($p['valid_from'] > $today) return 'upcoming';
    if ($p['valid_till'] <= date('Y-m-d', strtotime($today . ' +7 days'))) return 'expiring';
    return 'active';
}

function busPassStateLabel($state) {
    return ['active' => 'चालू', 'expiring' => 'लवकरच संपतो', 'upcoming' => 'पुढून सुरू', 'expired' => 'संपला', 'cancelled' => 'रद्द'][$state] ?? $state;
}

/** पुढचा पास क्रमांक: BP-2026-00001 */
function busPassNextNo(PDO $pdo, $orgId) {
    $prefix = 'BP-' . date('Y') . '-';
    $st = $pdo->prepare("SELECT pass_no FROM student_bus_passes WHERE organization_id = ? AND pass_no LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$orgId, $prefix . '%']);
    $last = (int)substr((string)$st->fetchColumn(), strlen($prefix));
    return $prefix . str_pad((string)($last + 1), 5, '0', STR_PAD_LEFT);
}

/** शैक्षणिक वर्षाची मूळ वैधता: आज → 30 जून (July पासून नवीन वर्ष) */
function busPassDefaultDates() {
    $y = (int)date('Y'); if ((int)date('n') >= 7) $y++;
    return [date('Y-m-d'), $y . '-06-30'];
}

/** नवीन पास (नूतनीकरणही) — त्या विद्यार्थ्याचे आधीचे चालू पास, तारखा एकमेकांवर येत असतील तर, रद्द */
function busPassIssue(PDO $pdo, $orgId, $busStudentId, array $d, $userId) {
    $pdo->prepare("UPDATE student_bus_passes SET status = 'cancelled', cancelled_at = NOW() WHERE organization_id = ? AND bus_student_id = ? AND status = 'active' AND valid_till >= ? AND valid_from <= ?")
        ->execute([$orgId, $busStudentId, $d['valid_from'], $d['valid_till']]);
    $link = $pdo->prepare("SELECT student_id FROM bus_students WHERE id = ? AND organization_id = ?");
    $link->execute([$busStudentId, $orgId]);
    $no = busPassNextNo($pdo, $orgId);
    $pdo->prepare("INSERT INTO student_bus_passes (organization_id, student_id, bus_student_id, pass_no, from_place, to_place, route_name, valid_from, valid_till, fee, remarks, issued_by, issued_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$orgId, ($link->fetchColumn() ?: null), $busStudentId, $no, $d['from_place'], $d['to_place'], $d['route_name'] ?: null, $d['valid_from'], $d['valid_till'],
            ($d['fee'] ?? '') !== '' ? (float)$d['fee'] : null, $d['remarks'] ?? null, $userId]);
    $passId = (int)$pdo->lastInsertId();
    // पुढच्या वेळेसाठी त्या विद्यार्थ्याचं कुठून / कुठपर्यंत / मार्ग लक्षात ठेवतो
    $pdo->prepare("UPDATE bus_students SET from_place = ?, to_place = ?, route_name = COALESCE(?, route_name), updated_at = NOW() WHERE id = ? AND organization_id = ?")
        ->execute([$d['from_place'], $d['to_place'], $d['route_name'] ?: null, $busStudentId, $orgId]);
    return $passId;
}

/** पास + बस विद्यार्थी (फोटो: बस-नोंदीचा, नसेल तर विद्यार्थी विभागाचा) — यादी, Print, तपासणीसाठी एकच SELECT */
function busPassSelectSql() {
    return "SELECT p.*, s.name, s.class_name, s.division, s.course, s.admission_no, s.roll_no, s.photo_path,
            s.campus_id, s.is_active stu_active, s.parent_mobile, s.mobile, s.academic_year, c.name campus_name
        FROM student_bus_passes p JOIN bus_students s ON s.id = p.bus_student_id LEFT JOIN campuses c ON c.id = s.campus_id";
}

/** 🚌 पिवळी School Bus — बाजूने (स्वतःचं चित्र, Print मध्ये स्पष्ट) */
function busPassBusSvg($cls = '') {
    $win = '';
    for ($i = 0; $i < 5; $i++) $win .= '<rect x="' . (11 + $i * 14) . '" y="13" width="11" height="11" rx="1.6" fill="url(#bpcGl)" stroke="#1F2937" stroke-width="1.1"/>';
    return '<svg class="' . htmlspecialchars($cls, ENT_QUOTES) . '" viewBox="0 0 124 60" aria-hidden="true">'
        . '<defs><linearGradient id="bpcBy" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stop-color="#FFD84D"/><stop offset=".55" stop-color="#FFC107"/><stop offset="1" stop-color="#F0A500"/></linearGradient>'
        . '<linearGradient id="bpcGl" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#E3F4FF"/><stop offset="1" stop-color="#8CC8EE"/></linearGradient></defs>'
        . '<ellipse cx="62" cy="55" rx="56" ry="3.2" fill="rgba(0,0,0,.22)"/>'
        . '<path d="M9 7h83c3 0 5 2 5 5v8h11c3.4 0 6 1.4 7.6 4.2l3.4 6V44c0 1.7-1.3 3-3 3H7c-2.2 0-4-1.8-4-4V13c0-3.3 2.7-6 6-6z" fill="url(#bpcBy)" stroke="#1F2937" stroke-width="1.6" stroke-linejoin="round"/>'
        . '<path d="M7 7.6h85" stroke="#FFE88A" stroke-width="1.4"/>'
        . $win
        . '<rect x="81" y="11.5" width="12.5" height="31" rx="1.4" fill="url(#bpcGl)" stroke="#1F2937" stroke-width="1.1"/><path d="M87.2 11.5v31" stroke="#1F2937" stroke-width="1"/>'
        . '<path d="M97 21h11.2c2.2 0 4 1 5 2.8l2.6 5.2H97z" fill="url(#bpcGl)" stroke="#1F2937" stroke-width="1.1" stroke-linejoin="round"/>'
        . '<rect x="3.6" y="29" width="77" height="2.4" fill="#1F2937"/><rect x="3.6" y="34.4" width="77" height="1.3" fill="#1F2937"/><rect x="93.5" y="33" width="25" height="1.3" fill="#1F2937"/>'
        . '<rect x="1.5" y="42" width="7" height="4" rx="1" fill="#374151"/><rect x="111" y="42" width="10" height="4" rx="1" fill="#374151"/>'
        . '<circle cx="117.4" cy="36.5" r="2" fill="#FFF7AE" stroke="#1F2937" stroke-width=".8"/><rect x="4.2" y="14" width="2.6" height="5" rx=".8" fill="#E53935"/>'
        . '<circle cx="25" cy="46.5" r="7.2" fill="#1F2937"/><circle cx="25" cy="46.5" r="3.2" fill="#D1D5DB"/><circle cx="99" cy="46.5" r="7.2" fill="#1F2937"/><circle cx="99" cy="46.5" r="3.2" fill="#D1D5DB"/>'
        . '</svg>';
}

/** Admin ने Upload केलेला बसचा फोटो (नसेल तर पिवळ्या बसचं चित्र) */
function busPassPhoto(PDO $pdo, $orgId) {
    $v = function_exists('orgSetting') ? trim((string)orgSetting($pdo, $orgId, 'bus_pass_photo', '')) : '';
    return ($v !== '' && eowmsPhotoExistsSafe($v)) ? $v : '';
}

/** एक बस पास (Card) — $p = pass + student fields (name, class_name, division, course, admission_no, photo_path, campus_name, parent_mobile, mobile) */
function busPassCard(array $p, array $set, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $state = busPassState($p);
    $cls = trim(($p['class_name'] ?? '') . (($p['division'] ?? '') !== '' ? ' - ' . $p['division'] : '') . (($p['course'] ?? '') !== '' ? ' · ' . $p['course'] : ''));
    $photo = (!empty($p['photo_path']) && eowmsPhotoExistsSafe($p['photo_path'])) ? $p['photo_path'] : '';
    $initials = mb_strtoupper(mb_substr(trim($p['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($p['name']), $m) ? $m[1] : ''));
    $d = fn($x) => date('d.m.Y', strtotime($x));
    $phone = ($p['parent_mobile'] ?? '') ?: ($p['mobile'] ?? '');
    ob_start(); ?>
<div class="bpc bpc-<?= $h($state) ?>" style="--sc:<?= $h($set['color']) ?>;" data-pid="<?= (int)$p['id'] ?>">
  <?php if ($set['logo']): ?><div class="bpc-wm"><img src="<?= $h($rootPath . $set['logo']) ?>" alt=""></div><?php endif; ?>
  <div class="bpc-head">
    <div class="bpc-logo"><?php if ($set['logo']): ?><img src="<?= $h($rootPath . $set['logo']) ?>" alt=""><?php else: ?><span><?= $h(mb_substr($set['title'], 0, 2)) ?></span><?php endif; ?></div>
    <div class="bpc-org">
      <?php if ($set['top_line'] !== ''): ?><div class="bpc-top"><?= $h($set['top_line']) ?></div><?php endif; ?>
      <div class="bpc-title" data-fit="2"><?= $h($set['title']) ?></div>
      <?php if (($set['subtitle'] ?? '') !== ''): ?><div class="bpc-subt"><?= $h($set['subtitle']) ?></div><?php endif; ?>
    </div>
    <?php $isAy = !empty($p['ay']); ?>
    <div class="bpc-tag"><div class="bpc-bus"><?php if (!empty($set['bus_photo'])): ?><img src="<?= $h($rootPath . $set['bus_photo']) ?>" alt=""><?php else: ?><?= busPassBusSvg() ?><?php endif; ?></div><div class="bpc-tag-t">BUS PASS</div><b><?= $isAy ? 'AY ' . $h($p['ay']) : $h($p['pass_no']) ?></b></div>
  </div>
  <div class="bpc-body">
    <div class="bpc-left">
      <div class="bpc-photo"><?php if ($photo): ?><img src="<?= $h($rootPath . $photo) ?>" alt=""><?php else: ?><span><?= $h($initials) ?></span><?php endif; ?></div>
    </div>
    <div class="bpc-main">
      <div class="bpc-name" data-fit="1"><?= $h($p['name']) ?></div>
      <div class="bpc-cd">
        <div><span>CLASS</span><b data-fit="1"><?= $h(($p['class_name'] ?? '') !== '' ? $p['class_name'] : '—') ?></b></div>
        <div><span>DIVISION</span><b data-fit="1"><?= $h(($p['division'] ?? '') !== '' ? $p['division'] : (($p['course'] ?? '') !== '' ? $p['course'] : '—')) ?></b></div>
        <?php if (($p['admission_no'] ?? '') !== ''): ?><div><span>REG NO</span><b data-fit="1"><?= $h($p['admission_no']) ?></b></div><?php endif; ?>
      </div>
      <div class="bpc-route">
        <div class="bpc-pl"><span><i class="dot"></i>FROM</span><b data-fit="3" data-fit-min="0.48"><?= $h($p['from_place']) ?></b></div>
        <div class="bpc-ar" title="जाणे-येणे"><i class="ln"></i><em class="sw"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h14M14 4l4 4-4 4M20 16H6M10 12l-4 4 4 4"/></svg></em><i class="ln"></i><small>UP · DOWN</small></div>
        <div class="bpc-pl bpc-to"><span>TO<i class="pin"></i></span><b data-fit="3" data-fit-min="0.48"><?= $h($p['to_place']) ?></b></div>
      </div>
      <?php if ($isAy): ?>
      <div class="bpc-valid bpc-valid-ay"><span class="lb">VALID</span><div><em>ACADEMIC YEAR</em><b class="till" data-fit="1"><?= $h(busAyLabel($p['ay'])) ?></b></div></div>
      <?php else: ?>
      <div class="bpc-valid"><span class="lb">VALID</span><div><em>FROM</em><b><?= $h($d($p['valid_from'])) ?></b></div><div><em>TILL</em><b class="till"><?= $h($d($p['valid_till'])) ?></b></div></div>
      <?php endif; ?>
    </div>
    <div class="bpc-side">
      <div class="bpc-qr"><?= idCardQrSvg(busPassVerifyUrl($p['organization_id'], $p['id'], $p['valid_till'], (int)($p['bus_student_id'] ?? 0), (string)($p['ay'] ?? ''))) ?><div>SCAN TO VERIFY</div></div>
      <div class="bpc-sign"><div class="im"><?php if ($set['sign']): ?><img src="<?= $h($rootPath . $set['sign']) ?>" alt=""><?php endif; ?></div><div class="lbl"><?= $h($set['sign_label']) ?></div></div>
    </div>
  </div>
  <div class="bpc-foot">
    <span data-fit="1"><?= busPassBusSvg('bpc-fbus') ?><?= ($p['route_name'] ?? '') !== '' ? $h($p['route_name']) : $h($p['from_place']) . ' ⇄ ' . $h($p['to_place']) ?></span>
    <span class="ph"><?= $phone !== '' ? '☎ PARENT ' . $h($phone) : 'NON-TRANSFERABLE' ?></span>
  </div>
  <?php if (in_array($state, ['expired', 'cancelled'], true)): ?><div class="bpc-stamp"><?= $state === 'expired' ? 'EXPIRED' : 'CANCELLED' ?></div><?php endif; ?>
</div>
<?php return ob_get_clean();
}

/* ================= ₹ बस शुल्क — जमा, पावती ================= */

function busFeeReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM bus_fee_payments LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** पुढची पावती: BF-2026-00001 */
function busFeeNextReceipt(PDO $pdo, $orgId) {
    $prefix = 'BF-' . date('Y') . '-';
    $st = $pdo->prepare("SELECT receipt_no FROM bus_fee_payments WHERE organization_id = ? AND receipt_no LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$orgId, $prefix . '%']);
    return $prefix . str_pad((string)((int)substr((string)$st->fetchColumn(), strlen($prefix)) + 1), 5, '0', STR_PAD_LEFT);
}

/** ₹ जमा: पावती + विद्यार्थ्याचं paid_fee वाढवतो. परत: पावती id */
function busFeeCollect(PDO $pdo, $orgId, $bsId, $amount, $paidOn, $mode, $note, $userId) {
    $no = busFeeNextReceipt($pdo, $orgId);
    $pdo->prepare("INSERT INTO bus_fee_payments (organization_id, bus_student_id, receipt_no, amount, paid_on, mode, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())")
        ->execute([$orgId, $bsId, $no, $amount, $paidOn, $mode ?: null, $note ?: null, $userId]);
    $rid = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE bus_students SET paid_fee = COALESCE(paid_fee, 0) + ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([$amount, $bsId, $orgId]);
    return $rid;
}

function busFeeModes() { return ['cash' => 'रोख', 'upi' => 'UPI', 'bank' => 'Bank / NEFT', 'cheque' => 'Cheque', 'other' => 'इतर']; }

/** ₹ 1,23,456 (भारतीय पद्धत) */
function busInr($n) {
    $n = (float)$n; $neg = $n < 0; $n = abs(round($n));
    $s = (string)$n; $last3 = substr($s, -3); $rest = substr($s, 0, -3);
    if ($rest !== '') $last3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $last3;
    return ($neg ? '-' : '') . '₹' . $last3;
}

/* ================= 🗑️ बस विद्यार्थी हटवणे ================= */

/** हटवण्याचा अधिकार — बस पास व्यवस्थापक, आणि "नोंदी Lock" असेल तर फक्त Super Admin */
function busCanDelete() {
    return canBusPass() && !(function_exists('recordLockActive') && recordLockActive());
}

/**
 * बस विद्यार्थी कायमचे हटवा — त्यांचे पास व ₹ पावत्या सुद्धा. आधी Recycle Bin मध्ये प्रत (Super Admin परत आणू शकतो).
 * परत: ['n' => हटवलेले, 'names' => [...], 'pays' => पावत्या]
 */
function busStudentsDelete(PDO $pdo, $orgId, array $ids) {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    $out = ['n' => 0, 'names' => [], 'pays' => 0];
    if (!$ids) return $out;
    $in = implode(',', $ids);
    $st = $pdo->prepare("SELECT id, name FROM bus_students WHERE organization_id = ? AND id IN ($in)");
    $st->execute([$orgId]);
    $rows = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (!$rows) return $out;
    $ids = array_keys($rows); $in = implode(',', array_map('intval', $ids));
    $fee = busFeeReady($pdo);
    $cap = function_exists('recycleCapture');
    $pdo->beginTransaction();
    try {
        foreach ($ids as $bid) {
            if ($cap) {
                recycleCapture($pdo, 'bus_students', $bid, '🚌 ' . $rows[$bid], $orgId);
                $q = $pdo->prepare("SELECT id, pass_no FROM student_bus_passes WHERE organization_id = ? AND bus_student_id = ?");
                $q->execute([$orgId, $bid]);
                foreach ($q->fetchAll() as $pp) recycleCapture($pdo, 'student_bus_passes', $pp['id'], '🎫 ' . $pp['pass_no'] . ' — ' . $rows[$bid], $orgId);
                if ($fee) {
                    $q = $pdo->prepare("SELECT id, receipt_no FROM bus_fee_payments WHERE organization_id = ? AND bus_student_id = ?");
                    $q->execute([$orgId, $bid]);
                    foreach ($q->fetchAll() as $rr) recycleCapture($pdo, 'bus_fee_payments', $rr['id'], '🧾 ' . $rr['receipt_no'] . ' — ' . $rows[$bid], $orgId);
                }
            }
        }
        if ($fee) {
            $c = $pdo->prepare("DELETE FROM bus_fee_payments WHERE organization_id = ? AND bus_student_id IN ($in)");
            $c->execute([$orgId]); $out['pays'] = $c->rowCount();
        }
        $pdo->prepare("DELETE FROM student_bus_passes WHERE organization_id = ? AND bus_student_id IN ($in)")->execute([$orgId]);
        $pdo->prepare("DELETE FROM bus_students WHERE organization_id = ? AND id IN ($in)")->execute([$orgId]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    $out['n'] = count($ids); $out['names'] = array_values($rows);
    if (function_exists('logActivity')) logActivity('बस विद्यार्थी हटवले', 'bus_student', count($ids) === 1 ? $ids[0] : null, implode(', ', array_slice($out['names'], 0, 20)) . (count($ids) > 20 ? ' …' : ''));
    return $out;
}

