<?php
/**
 * 🎓 विद्यार्थी नोंदवही — यादी, जोडणे/बदलणे, Excel/CSV Import (Columns आपोआप ओळखणे)
 * शाळा (इयत्ता/तुकडी) व कॉलेज (शाखा/वर्ष/विभाग) — दोन्ही एकाच Table मध्ये.
 */

/** Migration झालं आहे का */
function studentsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT class_name, division, gender, import_batch FROM students LIMIT 0"); return $ok = true; }
    catch (PDOException $e) { return $ok = false; }
}

/** जोडणे/बदलणे/Import — Super Admin किंवा manage_students परवानगी */
function canManageStudents() {
    return hasRole('super_admin') || hasPermission('manage_students');
}

/**
 * कोणते विद्यार्थी दिसतात: null = सगळे (Super Admin, Management, manage_students);
 * नाहीतर ['campus' => [...], 'dept' => [...]] — HOD ला स्वतःच्या विभागाचे; कोणी नाही तर [] (पान बंद)
 */
function studentVisibleScope(PDO $pdo) {
    $u = currentUser();
    if (!$u) return [];
    if (canManageStudents() || isManagementOrAbove()) return null;
    $dept = [];
    if (hasAnyCategory(['hod']) && !empty($u['department_id'])) $dept[] = (int)$u['department_id'];
    try {
        $st = $pdo->prepare("SELECT id FROM departments WHERE hod_id = ? AND is_active = 1");
        $st->execute([$u['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) $dept[] = (int)$id;
    } catch (PDOException $e) {}
    $dept = array_values(array_unique($dept));
    return $dept ? ['dept' => $dept] : [];
}

/** WHERE भाग (s alias) — दृश्यमर्यादेनुसार */
function studentScopeSql($scope, array &$params) {
    if ($scope === null) return '';
    if (!$scope) return ' AND 1 = 0';
    $params = array_merge($params, $scope['dept']);
    return ' AND s.department_id IN (' . implode(',', array_fill(0, count($scope['dept']), '?')) . ')';
}

/** चालू शैक्षणिक वर्ष — जून पासून (उदा. 2026-27) */
function studentCurrentAcademicYear() {
    $y = (int)date('Y'); if ((int)date('n') < 6) $y--;
    return $y . '-' . substr((string)($y + 1), -2);
}

/** Import साठी Fields — key => [लेबल, Header ओळखण्याचा Regex]. क्रम महत्त्वाचा: आधी जुळणारा जिंकतो */
function studentImportFields() {
    return [
        'admission_date' => ['प्रवेश दिनांक', '/(admission|adm)\.?\s*(date|dt)|date\s*of\s*admission|प्रवेश\s*(दिनांक|तारीख)/iu'],
        'parent_mobile'  => ['पालक मोबाईल', '/(parent|father|mother|guardian|पालक|वडील|आई).*(mob|phone|contact|cell|no\.?$)|पालक\s*मोबा/iu'],
        'father_name'    => ['वडिलांचे नाव', '/father|वडील|वडिलांचे|पित्याचे|पिता/iu'],
        'mother_name'    => ['आईचे नाव', '/mother|आईचे|आई|माता/iu'],
        'date_of_birth'  => ['जन्मतारीख', '/birth|dob|d\.o\.b|जन्म/iu'],
        'admission_no'   => ['प्रवेश / GR क्र.', '/admission|adm\.?\s*no|gr\.?\s*no|g\.r\.|general\s*reg|reg(istration)?\.?\s*no|enrol|prn|student\s*id|प्रवेश\s*क्र|जनरल\s*रजिस्टर|नोंदणी/iu'],
        'roll_no'        => ['Roll No', '/roll|हजेरी\s*क्र|अनुक्रमांक/iu'],
        'first_name'     => ['पहिले नाव', '/^(first|given)\s*name|^प्रथम\s*नाव/iu'],
        'middle_name'    => ['मधले नाव', '/^middle\s*name|^मधले\s*नाव/iu'],
        'last_name'      => ['आडनाव', '/^(last\s*name|surname|family\s*name)|^आडनाव/iu'],
        'name'           => ['विद्यार्थ्याचे नाव', '/name|नाव/iu'],
        'class_name'     => ['इयत्ता / वर्ग', '/^(class|std|standard|grade)|इयत्ता|वर्ग/iu'],
        'division'       => ['तुकडी', '/div|section|तुकडी/iu'],
        'course'         => ['शाखा / अभ्यासक्रम', '/course|stream|branch|program|faculty|शाखा|अभ्यासक्रम/iu'],
        'year_of_study'  => ['वर्ष (1/2/3…)', '/^year(\s*of\s*study)?$|^वर्ष$/iu'],
        'academic_year'  => ['शैक्षणिक वर्ष', '/academic|session|batch|शैक्षणिक/iu'],
        'gender'         => ['लिंग', '/gender|sex|लिंग/iu'],
        'mobile'         => ['मोबाईल', '/mob|phone|contact|cell|मोबा|फोन/iu'],
        'email'          => ['Email', '/mail/iu'],
        'category'       => ['प्रवर्ग', '/categ|caste|प्रवर्ग|जात/iu'],
        'aadhaar'        => ['आधार', '/aadha|adhar|aadhar|uid|आधार/iu'],
        'blood_group'    => ['रक्तगट', '/blood|रक्त/iu'],
        'address'        => ['पत्ता', '/address|पत्ता/iu'],
        'department'     => ['विभाग', '/department|dept|विभाग/iu'],
        'campus'         => ['College / School', '/college|school|campus|institute|महाविद्यालय|शाळा/iu'],
        'remarks'        => ['शेरा', '/remark|note|शेरा/iu'],
    ];
}

/** Header ओळ शोधा (पहिल्या 10 ओळींत ≥ 2 ओळखीचे Columns) व प्रत्येक Column चा अंदाज */
function studentGuessHeader(array $rows) {
    $fields = studentImportFields();
    $best = [0, -1, []];
    foreach (array_slice($rows, 0, 10, true) as $i => $r) {
        $map = []; $used = []; $hits = 0;
        foreach ($r as $c => $cell) {
            $h = trim((string)$cell);
            if ($h === '' || is_float($cell) || preg_match('/^\d+$/', $h)) { $map[$c] = ''; continue; }
            $map[$c] = '';
            foreach ($fields as $k => [, $re]) {
                if (isset($used[$k]) || !preg_match($re, $h)) continue;
                $map[$c] = $k; $used[$k] = true; $hits++; break;
            }
        }
        if ($hits > $best[1]) $best = [$i, $hits, $map];
    }
    return ['row' => $best[0], 'map' => $best[2], 'hits' => max(0, $best[1])];
}

function studentParseDate($v) {
    if ($v === null || $v === '') return null;
    if (is_float($v) || is_int($v) || (is_string($v) && preg_match('/^\d{4,5}(\.\d+)?$/', $v))) {
        $n = (float)$v; if ($n > 20000 && $n < 80000) return gmdate('Y-m-d', (int)round(($n - 25569) * 86400)); // Excel Serial
        return null;
    }
    $s = trim((string)$v);
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $s, $m)) { [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]]; if ($y < 100) $y += $y > 40 ? 1900 : 2000; }
    else { $t = strtotime($s); return $t ? date('Y-m-d', $t) : null; }
    return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
}

function studentNormGender($v) {
    $s = mb_strtolower(trim((string)$v));
    if ($s === '') return null;
    if (preg_match('/^(m|male|boy|पु|पुरुष|मुलगा|मु\.?$)/u', $s) && !preg_match('/^(मुलगी)/u', $s)) return 'M';
    if (preg_match('/^(f|female|girl|स्त्री|मुलगी|म)/u', $s)) return 'F';
    return 'O';
}

function studentNormMobile($v) {
    $d = preg_replace('/\D/', '', is_float($v) ? sprintf('%.0f', $v) : (string)$v);
    if (strlen($d) > 10) $d = substr($d, -10);
    return strlen($d) === 10 ? $d : null;
}

/** एक ओळ → विद्यार्थी Array (मॅपिंगनुसार); त्रुटी असल्यास ['_err' => '…'] */
function studentRowFromSheet(array $r, array $map) {
    $o = [];
    foreach ($map as $c => $k) {
        if ($k === '' || !array_key_exists($c, $r)) continue;
        $v = $r[$c];
        $o[$k] = is_float($v) && !in_array($k, ['date_of_birth', 'admission_date'], true) ? (floor($v) == $v ? sprintf('%.0f', $v) : (string)$v) : $v;
    }
    if (empty($o['name']) && (!empty($o['first_name']) || !empty($o['last_name']))) {
        $o['name'] = trim(implode(' ', array_filter([trim((string)($o['last_name'] ?? '')), trim((string)($o['first_name'] ?? '')), trim((string)($o['middle_name'] ?? ''))])));
    }
    unset($o['first_name'], $o['middle_name'], $o['last_name']);
    foreach ($o as $k => $v) { if (is_string($v)) $o[$k] = trim(preg_replace('/\s+/u', ' ', $v)); }
    $o['date_of_birth'] = studentParseDate($o['date_of_birth'] ?? null);
    $o['admission_date'] = studentParseDate($o['admission_date'] ?? null);
    $o['gender'] = studentNormGender($o['gender'] ?? '');
    $o['mobile'] = isset($o['mobile']) ? studentNormMobile($o['mobile']) : null;
    $o['parent_mobile'] = isset($o['parent_mobile']) ? studentNormMobile($o['parent_mobile']) : null;
    if (isset($o['aadhaar'])) { $a = preg_replace('/\D/', '', (string)$o['aadhaar']); $o['aadhaar'] = strlen($a) === 12 ? $a : null; }
    if (isset($o['year_of_study'])) { $y = (int)preg_replace('/\D/', '', (string)$o['year_of_study']); $o['year_of_study'] = $y >= 1 && $y <= 12 ? $y : null; }
    if (!empty($o['email']) && !filter_var($o['email'], FILTER_VALIDATE_EMAIL)) $o['email'] = null;
    if (empty($o['name']) || mb_strlen($o['name']) < 2) return ['_err' => 'नाव नाही'];
    foreach (['name' => 255, 'admission_no' => 50, 'roll_no' => 30, 'class_name' => 60, 'division' => 20, 'course' => 120, 'category' => 40, 'blood_group' => 6, 'father_name' => 150, 'mother_name' => 150, 'address' => 500, 'remarks' => 255, 'academic_year' => 20] as $k => $len) {
        if (isset($o[$k]) && $o[$k] !== null) $o[$k] = mb_substr((string)$o[$k], 0, $len);
    }
    return $o;
}

/** नाव → ID (campus / department) — अक्षरं व Space दुर्लक्षित करून जुळवतो */
function studentNameKey($s) { return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', (string)$s)); }

/** नवीन प्रवेश क्रमांक (यादीत नसेल तर) — AUTO-<campus>-<क्रम> */
function studentNextAdmissionNo(PDO $pdo, $orgId, $campusId) {
    static $seq = [];
    if (!isset($seq[$campusId])) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM students WHERE organization_id = ? AND admission_no LIKE ?");
        $st->execute([$orgId, 'AUTO-' . (int)$campusId . '-%']);
        $seq[$campusId] = (int)$st->fetchColumn();
    }
    $chk = $pdo->prepare("SELECT 1 FROM students WHERE organization_id = ? AND admission_no = ?");
    do { $no = 'AUTO-' . (int)$campusId . '-' . str_pad((string)(++$seq[$campusId]), 5, '0', STR_PAD_LEFT); $chk->execute([$orgId, $no]); } while ($chk->fetchColumn());
    return $no;
}

/** आधीच असलेला विद्यार्थी शोधा: प्रवेश क्र. → नाहीतर (Campus + वर्ष + वर्ग + तुकडी + Roll) → नाहीतर (Campus + नाव + जन्मतारीख) */
function studentFindExisting(PDO $pdo, $orgId, array $s) {
    if (!empty($s['admission_no'])) {
        $st = $pdo->prepare("SELECT id FROM students WHERE organization_id = ? AND admission_no = ?");
        $st->execute([$orgId, $s['admission_no']]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    if (!empty($s['roll_no'])) {
        $st = $pdo->prepare("SELECT id FROM students WHERE organization_id = ? AND campus_id = ? AND academic_year = ? AND COALESCE(class_name,'') = ? AND COALESCE(division,'') = ? AND roll_no = ? LIMIT 1");
        $st->execute([$orgId, $s['campus_id'], $s['academic_year'], (string)($s['class_name'] ?? ''), (string)($s['division'] ?? ''), $s['roll_no']]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    if (!empty($s['date_of_birth'])) {
        $st = $pdo->prepare("SELECT id FROM students WHERE organization_id = ? AND campus_id = ? AND name = ? AND date_of_birth = ? LIMIT 1");
        $st->execute([$orgId, $s['campus_id'], $s['name'], $s['date_of_birth']]);
        if ($id = $st->fetchColumn()) return (int)$id;
    }
    return 0;
}

const STUDENT_SAVE_COLS = ['campus_id', 'department_id', 'roll_no', 'admission_no', 'name', 'class_name', 'division', 'course', 'year_of_study', 'academic_year', 'gender', 'date_of_birth', 'mobile', 'parent_mobile', 'email', 'category', 'aadhaar', 'blood_group', 'father_name', 'mother_name', 'address', 'admission_date', 'remarks'];

/** जतन — $id = 0 नवीन; बदलताना फक्त दिलेले (रिकामे नसलेले) Fields बदलतो जर $onlyFilled */
function studentSave(PDO $pdo, $orgId, $id, array $s, $userId, $batch = null, $onlyFilled = false) {
    $vals = [];
    foreach (STUDENT_SAVE_COLS as $c) {
        if (!array_key_exists($c, $s)) continue;
        $v = $s[$c] === '' ? null : $s[$c];
        if ($onlyFilled && $v === null) continue;
        $vals[$c] = $v;
    }
    if ($id) {
        if (!$vals) return $id;
        $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($vals)));
        // import_batch फक्त नवीन नोंदीवर — "Import मागे घ्या" फक्त त्या Import ने जोडलेले काढतो, आधीचे नाही
        $pdo->prepare("UPDATE students SET $set, updated_at = NOW() WHERE id = ? AND organization_id = ?")
            ->execute(array_merge(array_values($vals), [$id, $orgId]));
        return $id;
    }
    if (empty($vals['admission_no'])) $vals['admission_no'] = studentNextAdmissionNo($pdo, $orgId, $vals['campus_id']);
    $vals['organization_id'] = $orgId; $vals['created_by'] = $userId; $vals['import_batch'] = $batch; $vals['is_active'] = 1;
    $cols = array_keys($vals);
    $pdo->prepare("INSERT INTO students (`" . implode('`, `', $cols) . "`) VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")")->execute(array_values($vals));
    return (int)$pdo->lastInsertId();
}

function studentGenderLabel($g) { return ['M' => 'मुलगा', 'F' => 'मुलगी', 'O' => 'इतर'][$g] ?? '—'; }
