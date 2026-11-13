<?php
/**
 * 💰 पगार रचना (Salary Structure) — Basic, HRA, प्रवास भत्ता, वैद्यकीय भत्ता, इतर; PF/ESIC/PT लागू
 * staff_salary मध्ये प्रत्येक बदल "कधीपासून" तारखेसह (इतिहास राहतो); चालू = आजपर्यंतची शेवटची नोंद.
 * users.salary = एकूण (Gross) — जुन्या अहवालांसाठी तसाच भरलेला ठेवतो.
 */

function salaryReady(PDO $pdo) {
    static $ok = null;
    if ($ok === null) { try { $pdo->query("SELECT 1 FROM staff_salary LIMIT 0"); $ok = true; } catch (PDOException $e) { $ok = false; } }
    return $ok;
}

/** घटकांची नावं */
function salaryParts() {
    return ['basic' => 'Basic', 'hra' => 'घरभाडे भत्ता (HRA)', 'ta' => 'प्रवास भत्ता (TA)', 'medical' => 'वैद्यकीय भत्ता', 'other_allow' => 'इतर भत्ता'];
}

/** चालू पगार रचना — [user_id => row]; $userIds = null म्हणजे संस्थेतले सगळे */
function salaryCurrent(PDO $pdo, $orgId, ?array $userIds = null) {
    if (!salaryReady($pdo)) return [];
    $w = ''; $p = [$orgId];
    if ($userIds !== null) {
        $userIds = array_values(array_filter(array_map('intval', $userIds)));
        if (!$userIds) return [];
        $w = ' AND s.user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'; $p = array_merge($p, $userIds);
    }
    // प्रत्येकाची: आजपर्यंत लागू झालेली शेवटची; ती नसेल (सगळ्या भविष्यातल्या) तर सर्वात जवळची
    $st = $pdo->prepare("SELECT s.* FROM staff_salary s WHERE s.organization_id = ?$w ORDER BY s.user_id, (s.effective_from <= CURDATE()) DESC,
        CASE WHEN s.effective_from <= CURDATE() THEN s.effective_from END DESC, s.effective_from ASC, s.id DESC");
    $st->execute($p);
    $out = [];
    foreach ($st as $r) { if (!isset($out[$r['user_id']])) $out[(int)$r['user_id']] = $r; }
    return $out;
}

/** एकूण पगारावरून घटक — ठराविक प्रमाण (Basic 60%, HRA 15%, TA 15%, वैद्यकीय 10%) */
function salarySplit($gross, array $ratio = ['basic' => 60, 'hra' => 15, 'ta' => 15, 'medical' => 10]) {
    $g = (float)$gross; $out = []; $sum = 0;
    foreach ($ratio as $k => $pc) { $out[$k] = round($g * $pc / 100, 2); $sum += $out[$k]; }
    $out['basic'] = round($out['basic'] + ($g - $sum), 2); // पैशांचा फरक Basic मध्ये
    $out['other_allow'] = 0;
    return $out;
}

/**
 * अंदाजे कपात (कर्मचाऱ्याचा हिस्सा) — महाराष्ट्र नियम:
 * PF 12% × Basic (Basic कमाल ₹15,000), ESIC 0.75% × एकूण (एकूण ≤ ₹21,000 असेल तर),
 * व्यवसाय कर: पुरुष ₹7,500 पर्यंत 0, ₹10,000 पर्यंत 175, पुढे 200; स्त्री ₹25,000 पर्यंत 0, पुढे 200 (फेब्रुवारीत 300); दिव्यांग — सूट.
 */
function salaryDeductions(array $s, $gender = null, $month = null) {
    $g = (float)$s['gross']; $basic = (float)$s['basic'];
    $pf = !empty($s['pf_applicable']) ? round(min($basic, 15000) * 0.12) : 0;
    $esic = !empty($s['esic_applicable']) && $g <= 21000 ? (int)ceil($g * 0.0075) : 0;
    $pt = 0;
    if (!empty($s['pt_applicable']) && empty($s['is_handicap'])) {
        $isF = strcasecmp((string)$gender, 'Female') === 0;
        $top = ((int)($month ?? date('n'))) === 2 ? 300 : 200;
        $pt = $isF ? ($g > 25000 ? $top : 0) : ($g > 10000 ? $top : ($g > 7500 ? 175 : 0));
    }
    return ['pf' => $pf, 'esic' => $esic, 'pt' => $pt, 'total' => $pf + $esic + $pt, 'net' => $g - $pf - $esic - $pt];
}

/**
 * पगार रचना जतन — त्याच "कधीपासून" तारखेची नोंद असेल तर बदलतो, नाहीतर नवीन (इतिहास राहतो).
 * $s = gross, basic, hra, ta, medical, other_allow, pf_applicable, esic_applicable, pt_applicable, is_handicap (+ uan_no, esic_no ऐच्छिक)
 * परत: 'new' | 'changed' | 'same'
 */
function salarySave(PDO $pdo, $orgId, $userId, array $s, $effectiveFrom, $source = 'manual', $byUserId = null, $note = null) {
    $num = fn($k) => round((float)($s[$k] ?? 0), 2);
    $row = ['gross' => $num('gross'), 'basic' => $num('basic'), 'hra' => $num('hra'), 'ta' => $num('ta'), 'medical' => $num('medical'), 'other_allow' => $num('other_allow'),
        'pf_applicable' => !empty($s['pf_applicable']) ? 1 : 0, 'esic_applicable' => !empty($s['esic_applicable']) ? 1 : 0,
        'pt_applicable' => !empty($s['pt_applicable']) ? 1 : 0, 'is_handicap' => !empty($s['is_handicap']) ? 1 : 0];
    if ($row['gross'] <= 0) { $row['gross'] = $row['basic'] + $row['hra'] + $row['ta'] + $row['medical'] + $row['other_allow']; }
    $cur = salaryCurrent($pdo, $orgId, [$userId])[(int)$userId] ?? null;
    $status = 'new';
    if ($cur) {
        $same = true;
        foreach ($row as $k => $v) { if ((string)round((float)$cur[$k], 2) !== (string)round((float)$v, 2)) { $same = false; break; } }
        $status = $same ? 'same' : 'changed';
    }
    if ($status !== 'same') {
        $pdo->prepare("INSERT INTO staff_salary (organization_id, user_id, effective_from, gross, basic, hra, ta, medical, other_allow, pf_applicable, esic_applicable, pt_applicable, is_handicap, source, note, created_by, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE gross=VALUES(gross), basic=VALUES(basic), hra=VALUES(hra), ta=VALUES(ta), medical=VALUES(medical), other_allow=VALUES(other_allow),
              pf_applicable=VALUES(pf_applicable), esic_applicable=VALUES(esic_applicable), pt_applicable=VALUES(pt_applicable), is_handicap=VALUES(is_handicap),
              source=VALUES(source), note=VALUES(note), created_by=VALUES(created_by), created_at=NOW()")
            ->execute([$orgId, $userId, $effectiveFrom, $row['gross'], $row['basic'], $row['hra'], $row['ta'], $row['medical'], $row['other_allow'],
                $row['pf_applicable'], $row['esic_applicable'], $row['pt_applicable'], $row['is_handicap'], $source, $note, $byUserId]);
    }
    // कर्मचाऱ्याच्या खात्यातही (जुने अहवाल, Print यादी) — फक्त आज लागू असेल तर पगार
    $set = ['pf_applicable = ?', 'esic_applicable = ?', 'pt_applicable = ?', 'is_handicap = ?', "pf_mode = ?", "esic_mode = ?", "pt_mode = ?"];
    $vals = [$row['pf_applicable'], $row['esic_applicable'], $row['pt_applicable'], $row['is_handicap'],
        $row['pf_applicable'] ? 'YES' : 'NO', $row['esic_applicable'] ? 'YES' : 'NO', $row['pt_applicable'] ? 'YES' : 'NO'];
    // आज लागू असलेली रचना (नंतरच्या तारखेची आधीच असेल तर तीच) — त्याचा एकूण पगार
    $now = salaryCurrent($pdo, $orgId, [$userId])[(int)$userId] ?? null;
    if ($now && $now['effective_from'] <= date('Y-m-d')) { $set[] = 'salary = ?'; $vals[] = $now['gross']; }
    foreach (['uan_no', 'esic_no'] as $k) {
        $v = preg_replace('/\s+/', '', (string)($s[$k] ?? ''));
        if ($v !== '' && $v !== '0') { $set[] = "$k = ?"; $vals[] = mb_substr($v, 0, 50); }
    }
    $vals[] = $userId; $vals[] = $orgId;
    $pdo->prepare("UPDATE users SET " . implode(', ', $set) . " WHERE id = ? AND organization_id = ?")->execute($vals);
    return $status;
}

/**
 * Excel मधून पगार यादी वाचणे — जुन्या Software चा "Employee Salary Detail" (Crystal) Report किंवा साधी Sheet.
 * Header (एक किंवा दोन ओळींत पसरलेलं) शब्दांवरून ओळखतो; विभागाची शीर्षक-ओळ (उदा. "TRANSPORT DEPARTMENT") लक्षात ठेवतो.
 * परत: ['rows' => [[code, name, desig, gender, dept, gross, basic, hra, ta, medical, other_allow, pf, pf_no, esic, esic_no, pt, hc]], 'error' => null]
 */
function salaryParseSheet(array $rows) {
    $norm = fn($v) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$v)));
    $hdrRow = null;
    foreach (array_slice($rows, 0, 30, true) as $i => $r) {
        $t = $norm(implode(' | ', $r));
        if (count(array_filter($r, fn($v) => trim((string)$v) !== '')) < 3) continue; // शीर्षक-ओळ (उदा. "Employee Salary Detail") नव्हे
        if ((str_contains($t, 'salary') || str_contains($t, 'gross') || str_contains($t, 'पगार')) && (str_contains($t, 'basic') || str_contains($t, 'emp'))) { $hdrRow = $i; break; }
    }
    if ($hdrRow === null) return ['rows' => [], 'error' => 'Header ओळ सापडली नाही — "Emp Id", "Actual Salary / Gross", "Basic" असे स्तंभ हवेत.'];
    // Header दोन ओळींत असू शकतं — दोन्ही एकत्र
    $width = max(array_map('count', array_slice($rows, $hdrRow, 2)));
    $hdr = [];
    for ($c = 0; $c < $width; $c++) { $hdr[$c] = $norm(($rows[$hdrRow][$c] ?? '') . ' ' . ($rows[$hdrRow + 1][$c] ?? '')); }
    $find = function (array $words, array $not = []) use ($hdr) {
        foreach ($hdr as $c => $h) {
            if ($h === '') continue;
            foreach ($not as $n) { if (str_contains($h, $n)) continue 2; }
            foreach ($words as $w) { if (preg_match('/(^|[^a-z])' . preg_quote($w, '/') . '/u', $h)) return $c; }
        }
        return null;
    };
    $col = [
        'code' => $find(['emp id', 'emp. id', 'empid', 'emp code', 'employee code', 'emp no', 'कर्मचारी क्रमांक']),
        'name' => $find(['empname', 'emp name', 'employee name', 'name', 'नाव'], ['bank', 'father']),
        'desig' => $find(['desg', 'design', 'पद']),
        'gender' => $find(['gender', 'लिंग']),
        'gross' => $find(['actual salary', 'gross', 'total salary', 'एकूण पगार', 'salary'], ['basic']),
        'basic' => $find(['basic']),
        'hra' => $find(['house rent', 'hra']),
        'ta' => $find(['travelling', 'traveling', 'travel', 'conveyance', 'ta']),
        'medical' => $find(['medical']),
        'other' => $find(['other allow', 'special allow', 'other']),
        'pf_no' => $find(['pf no', 'uan']),
        'esic_no' => $find(['esic no', 'esic number']),
        'pt' => $find(['pt mode', 'pt', 'professional tax']),
        'hc' => $find(['is hc', 'handicap', 'hc']),
    ];
    $col['pf'] = null; $col['esic'] = null;
    foreach ($hdr as $c => $h) {
        if ($h === 'pf' || $h === 'pf mode' || $h === 'pf applicable') $col['pf'] = $c;
        if ($h === 'esic' || $h === 'esic mode' || $h === 'esic applicable') $col['esic'] = $c;
    }
    if ($col['code'] === null || $col['gross'] === null && $col['basic'] === null) return ['rows' => [], 'error' => 'Emp Id किंवा पगाराचा स्तंभ सापडला नाही.'];

    $yes = fn($v) => in_array(strtolower(trim((string)$v)), ['yes', 'y', '1', 'true', 'हो', 'होय'], true);
    $num = fn($v) => is_numeric($v) ? (float)$v : (float)preg_replace('/[^0-9.]/', '', (string)$v);
    $get = fn($r, $k) => $col[$k] === null ? '' : ($r[$col[$k]] ?? '');
    $out = []; $dept = '';
    for ($i = $hdrRow + 2; $i < count($rows); $i++) {
        $r = $rows[$i];
        $filled = array_values(array_filter($r, fn($v) => trim((string)$v) !== ''));
        if (!$filled) continue;
        $code = trim((string)$get($r, 'code'));
        if (is_float($get($r, 'code')) || is_int($get($r, 'code'))) $code = (string)(int)$get($r, 'code');
        // विभागाची शीर्षक-ओळ: एकच मजकूर, अंक नाही
        if (count($filled) === 1 && !is_numeric($filled[0]) && $code === '') { $dept = trim(preg_replace('/\s+/', ' ', (string)$filled[0])); continue; }
        if ($code === '' || !preg_match('/^[A-Za-z0-9\/-]{1,20}$/', $code)) continue;
        $gross = $num($get($r, 'gross')); $basic = $num($get($r, 'basic'));
        if ($gross <= 0 && $basic <= 0) continue;
        $hra = $num($get($r, 'hra')); $ta = $num($get($r, 'ta')); $med = $num($get($r, 'medical')); $oth = $num($get($r, 'other'));
        if ($gross <= 0) $gross = $basic + $hra + $ta + $med + $oth;
        $pfNo = trim((string)$get($r, 'pf_no')); if (is_float($get($r, 'pf_no'))) $pfNo = number_format($get($r, 'pf_no'), 0, '', '');
        $esNo = trim((string)$get($r, 'esic_no')); if (is_float($get($r, 'esic_no'))) $esNo = number_format($get($r, 'esic_no'), 0, '', '');
        $out[] = [
            'code' => $code, 'name' => trim(preg_replace('/\s+/', ' ', (string)$get($r, 'name'))), 'desig' => trim(preg_replace('/\s+/', ' ', (string)$get($r, 'desig'))),
            'gender' => ucfirst(strtolower(trim((string)$get($r, 'gender')))), 'dept' => $dept,
            'gross' => $gross, 'basic' => $basic, 'hra' => $hra, 'ta' => $ta, 'medical' => $med, 'other_allow' => $oth,
            'pf_applicable' => $col['pf'] !== null ? $yes($r[$col['pf']] ?? '') : ($pfNo !== '' && $pfNo !== '0'),
            'uan_no' => $pfNo === '0' ? '' : $pfNo,
            'esic_applicable' => $col['esic'] !== null ? $yes($r[$col['esic']] ?? '') : ($esNo !== '' && $esNo !== '0' && $gross <= 21000),
            'esic_no' => $esNo === '0' ? '' : $esNo,
            'pt_applicable' => $col['pt'] !== null ? $yes($get($r, 'pt')) : true,
            'is_handicap' => $col['hc'] !== null ? $yes($get($r, 'hc')) : false,
        ];
    }
    return ['rows' => $out, 'error' => $out ? null : 'पगाराच्या ओळी सापडल्या नाहीत.'];
}
