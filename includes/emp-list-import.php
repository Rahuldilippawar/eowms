<?php
/**
 * 📋 जुन्या Software ची "Employee List" (Establishment Department — Crystal Report .xls/.xlsx/.csv)
 * विभागनिहाय कर्मचारी यादी वाचणं व E-Admin मध्ये भरणं.
 *
 * File ची रचना: वर संस्थेचं नाव, मग Header ओळ (Sr.No. | Emp Id. | EmpName | Desg1 | Gender |
 * Date Of Birth | Mobile No. | Email Id | Aadhar No | Bank Acc No | Branch Name | Bank Name |
 * Village Address | Current Address), मग विभागाचं नाव एकट्या ओळीत आणि त्याखाली त्या विभागाचे
 * कर्मचारी. प्रत्येक कर्मचाऱ्याखाली एक "पुढची ओळ" — त्यात दुसरा Mobile व बँकेच्या शाखेचं नाव
 * (पहिल्या ओळीत "Branch Name" खाली प्रत्यक्षात IFSC असतो).
 */

/** Header शब्द → आपलं Field (मोठी/लहान अक्षरं, जागा, . दुर्लक्षित) */
function empListHeaderKey($h) {
    $k = strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$h));
    $map = [
        'srno' => 'sr', 'empid' => 'empid', 'employeeid' => 'empid', 'empcode' => 'empid', 'empname' => 'name', 'employeename' => 'name', 'name' => 'name',
        'desg1' => 'desg', 'desg' => 'desg', 'designation' => 'desg', 'gender' => 'gender', 'dateofbirth' => 'dob', 'dob' => 'dob',
        'mobileno' => 'mobile', 'mobile' => 'mobile', 'emailid' => 'email', 'email' => 'email', 'aadharno' => 'aadhar', 'aadhaarno' => 'aadhar', 'aadhar' => 'aadhar',
        'bankaccno' => 'bank_acc', 'bankaccountno' => 'bank_acc', 'branchname' => 'branch', 'ifsc' => 'ifsc', 'ifsccode' => 'ifsc', 'bankname' => 'bank_name',
        'villageaddress' => 'village', 'permanentaddress' => 'village', 'currentaddress' => 'current', 'localaddress' => 'current',
        'department' => 'dept', 'dept' => 'dept', 'joiningdate' => 'doj', 'dateofjoining' => 'doj',
    ];
    return $map[$k] ?? null;
}

/** Excel मधला अंक (Mobile/Aadhar/खाते क्रमांक) — 9.2E+9 / ".0" न होता पूर्ण अंक */
function empListText($v) {
    if (is_float($v) || is_int($v)) { return abs($v - round($v)) < 1e-9 ? sprintf('%.0f', $v) : (string)$v; }
    return trim(preg_replace('/\s+/u', ' ', (string)$v));
}

/** Excel Serial / dd-mm-yyyy / yyyy-mm-dd → Y-m-d (वैध वर्षं नसल्यास null) */
function empListDate($v, $minYear = 1935, $maxYearOffset = 14) {
    $d = null;
    if (is_float($v) || is_int($v) || (is_string($v) && preg_match('/^\d{4,5}(\.0+)?$/', trim($v)))) {
        $n = (float)$v;
        if ($n > 1000) { $d = date('Y-m-d', (int)round(($n - 25569) * 86400)); }
    } elseif (is_string($v) && trim($v) !== '') {
        $s = trim($v);
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{4})$#', $s, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) { $d = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]); }
        elseif (preg_match('#^(\d{4})[/.\-](\d{1,2})[/.\-](\d{1,2})$#', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { $d = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]); }
    }
    if (!$d) return null;
    $y = (int)substr($d, 0, 4);
    return ($y >= $minYear && $y <= (int)date('Y') - $maxYearOffset) ? $d : null;
}

/** "TRANSPORT DEPAERTMENT" → "Transport"; "BHOJNALAY DEPT." → "Bhojnalay" */
function empListDeptName($raw) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    $s = preg_replace('/\s+(DEPA?E?RT?MENT|DEPARTMENT|DEPT\.?|DEPART\.?)$/iu', '', $s);
    $s = trim($s, " .-");
    return $s === '' ? trim((string)$raw) : (preg_match('/\p{Ll}/u', $s) ? $s : mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8'));
}

/** नाव — सगळं CAPITAL असेल तर Title Case ("PAHUNE ACHYUT SADANAND" → "Pahune Achyut Sadanand") */
function empListPersonName($raw) {
    $s = trim(preg_replace('/\s+/u', ' ', (string)$raw));
    if ($s !== '' && !preg_match('/\p{Ll}/u', $s) && preg_match('/[A-Z]/', $s)) { $s = mb_convert_case(mb_strtolower($s), MB_CASE_TITLE, 'UTF-8'); }
    return function_exists('nameCase') ? nameCase($s) : $s;
}

/** विभागाचं मराठी नाव व Code — ओळखीच्या विभागांसाठी (बाकीचे आपोआप) */
function empListDeptHints($name) {
    $k = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
    $hints = [
        'transport' => ['TRP', 'वाहतूक'], 'administrative' => ['ADM', 'प्रशासन'], 'administration' => ['ADM', 'प्रशासन'], 'account' => ['ACC', 'लेखा विभाग'], 'accounts' => ['ACC', 'लेखा विभाग'],
        'housekeeping' => ['HSK', 'हाऊसकीपिंग'], 'store' => ['STR', 'भांडार'], 'security' => ['SEC', 'सुरक्षा'], 'bhojnalay' => ['BHJ', 'भोजनालय'], 'mess' => ['BHJ', 'भोजनालय'],
        'goshala' => ['GSH', 'गोशाळा'], 'gaushala' => ['GSH', 'गोशाळा'], 'reception' => ['RCP', 'स्वागत कक्ष'], 'gardentrust' => ['GRD', 'बाग विभाग (ट्रस्ट)'], 'garden' => ['GRD', 'बाग विभाग'],
        'civil' => ['CVL', 'सिव्हिल'], 'maintenance' => ['MTN', 'देखभाल'], 'napstrust' => ['NAPS', 'NAPS (ट्रस्ट)'], '58plusageemployee' => ['SNR', '५८+ वयाचे कर्मचारी'],
        'electrical' => ['ELE', 'इलेक्ट्रिकल'], 'plumbing' => ['PLM', 'प्लंबिंग'], 'hr' => ['HR', 'मनुष्यबळ'], 'purchase' => ['PUR', 'खरेदी'], 'it' => ['IT', 'आयटी'],
    ];
    return $hints[$k] ?? null;
}

/**
 * File च्या ओळी → ['title' => संस्थेचं नाव, 'employees' => [...], 'depts' => [deptName => count], 'warnings' => [...]]
 * Header सापडला नाही तर RuntimeException.
 */
function empListParse(array $rows) {
    $title = '';
    $hdrRow = null; $col = [];
    foreach ($rows as $i => $r) {
        $keys = [];
        foreach ($r as $c => $v) { if (is_string($v) && ($k = empListHeaderKey($v))) { $keys[$k] = $keys[$k] ?? $c; } }
        if (isset($keys['name']) && (isset($keys['empid']) || isset($keys['desg']))) { $hdrRow = $i; $col = $keys; break; }
        if ($title === '' && is_string($r[0] ?? null) && trim($r[0]) !== '') { $title = trim($r[0]); }
    }
    if ($hdrRow === null) { throw new RuntimeException('या File मध्ये कर्मचारी यादीचा Header (Emp Id / EmpName / Desg …) सापडला नाही.'); }

    $get = fn($r, $k) => isset($col[$k]) ? ($r[$col[$k]] ?? '') : '';
    $emps = []; $warn = []; $dept = null; $last = null; $seen = [];
    for ($i = $hdrRow + 1; $i < count($rows); $i++) {
        $r = $rows[$i];
        $filled = array_filter($r, fn($v) => trim((string)$v) !== '');
        if (!$filled) continue;
        $name = trim((string)$get($r, 'name'));
        $empid = empListText($get($r, 'empid'));
        $first = trim((string)($r[0] ?? ''));
        // विभागाचं शीर्षक — एकटा मजकूर, कर्मचारी नाव/क्रमांक नाही
        if ($name === '' && $empid === '' && count($filled) === 1 && !is_float($r[0] ?? null) && $first !== '' && !preg_match('/^\d+(\.0+)?$/', $first)) {
            $dept = $first; $last = null; continue;
        }
        if ($name === '') {
            // आधीच्या कर्मचाऱ्याची "पुढची ओळ" — शाखेचं नाव, दुसरा Mobile
            if ($last !== null) {
                $br = empListText($get($r, 'branch'));
                if ($br !== '' && $emps[$last]['branch_name'] === null) { $emps[$last]['branch_name'] = mb_substr($br, 0, 150); }
                $m2 = preg_replace('/\D/', '', empListText($get($r, 'mobile')));
                if (strlen($m2) >= 10 && $m2 !== $emps[$last]['mobile']) { $emps[$last]['whatsapp'] = substr($m2, -10); }
            }
            continue;
        }
        $rowDept = trim((string)$get($r, 'dept')) ?: $dept;
        if (!$rowDept) { $warn[] = "ओळ " . ($i + 1) . ": '$name' — विभाग समजला नाही, वगळलं"; continue; }
        $empid = preg_replace('/\.0+$/', '', $empid);
        if ($empid !== '' && isset($seen[$empid])) { $warn[] = "ओळ " . ($i + 1) . ": Emp Id $empid दोनदा आहे — दुसरी नोंद वगळली"; continue; }
        if ($empid !== '') $seen[$empid] = true;
        $desg = trim(preg_replace('/\s+/', ' ', empListText($get($r, 'desg'))));
        if (preg_match('/^\[.*\]$/', $desg)) $desg = ''; // "[Select Designation]"
        $g = strtoupper(trim((string)$get($r, 'gender')));
        $mobile = preg_replace('/\D/', '', empListText($get($r, 'mobile')));
        $email = strtolower(trim((string)$get($r, 'email')));
        $aadhar = preg_replace('/\D/', '', empListText($get($r, 'aadhar')));
        $ifscOrBranch = strtoupper(empListText($get($r, 'branch')));
        $dobRaw = $get($r, 'dob');
        $dob = empListDate($dobRaw);
        $e = [
            'row' => $i + 1, 'dept_raw' => $rowDept, 'dept' => empListDeptName($rowDept),
            'empid' => $empid, 'name' => empListPersonName($name),
            'designation' => $desg !== '' ? mb_convert_case(mb_strtolower($desg), MB_CASE_TITLE, 'UTF-8') : null,
            'gender' => str_starts_with($g, 'F') ? 'Female' : (str_starts_with($g, 'M') ? 'Male' : null),
            'dob' => $dob, 'mobile' => strlen($mobile) >= 10 ? substr($mobile, -10) : null, 'whatsapp' => null,
            'email' => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'aadhar' => strlen($aadhar) === 12 ? $aadhar : null,
            'bank_acc' => ($b = preg_replace('/\s+/', '', empListText($get($r, 'bank_acc')))) !== '' ? mb_substr($b, 0, 50) : null,
            'ifsc' => preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifscOrBranch) ? $ifscOrBranch : (isset($col['ifsc']) && preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $x = strtoupper(empListText($get($r, 'ifsc')))) ? $x : null),
            'branch_name' => !preg_match('/^[A-Z]{4}0[A-Z0-9]{6}$/', $ifscOrBranch) && $ifscOrBranch !== '' ? mb_substr(empListText($get($r, 'branch')), 0, 150) : null,
            'bank_name' => ($bn = empListText($get($r, 'bank_name'))) !== '' ? mb_substr($bn, 0, 150) : null,
            'village' => ($v = empListText($get($r, 'village'))) !== '' ? $v : null,
            'current' => ($v = empListText($get($r, 'current'))) !== '' ? $v : null,
            'doj' => empListDate($get($r, 'doj'), 1950, 0),
            'is_hod' => (bool)preg_match('/head\s+of\s+(the\s+)?department|^hod$/i', $desg),
        ];
        if (!$e['dob'] && trim((string)$dobRaw) !== '') $warn[] = "{$e['name']} ($empid): जन्मतारीख चुकीची ('" . empListText($dobRaw) . "') — रिकामी ठेवली";
        if (!$e['gender'] && $g !== '') $warn[] = "{$e['name']} ($empid): Gender समजलं नाही ('$g')";
        if ($aadhar !== '' && !$e['aadhar']) $warn[] = "{$e['name']} ($empid): Aadhar 12 अंकी नाही — रिकामा ठेवला";
        if ($empid === '') $warn[] = "{$e['name']}: Emp Id नाही — नवीन कर्मचारी म्हणून जोडला जाईल";
        $emps[] = $e; $last = count($emps) - 1;
    }
    $depts = [];
    foreach ($emps as $e) { $depts[$e['dept']] = ($depts[$e['dept']] ?? 0) + 1; }
    return ['title' => $title, 'employees' => $emps, 'depts' => $depts, 'warnings' => $warn];
}

/** या Campus मधला विभाग (नाव / मराठी नाव / Code ने) शोधा — नसेल तर null */
function empListFindDept(array $campusDepts, $name) {
    $norm = fn($s) => strtolower(preg_replace('/[^a-z0-9\x{0900}-\x{097F}]/iu', '', (string)$s));
    $n = $norm($name); $hint = empListDeptHints($name);
    foreach ($campusDepts as $d) {
        if ($norm($d['name']) === $n || ($d['name_local'] && $norm($d['name_local']) === $n) || rtrim($norm($d['name']), 's') === rtrim($n, 's')) return $d;
    }
    if ($hint) { foreach ($campusDepts as $d) { if (strtoupper($d['code']) === $hint[0]) return $d; } }
    return null;
}

/** संस्थेचं Email-Domain (नसल्यास Site चं) — Email नसलेल्या कर्मचाऱ्यांच्या Login साठी */
function empListDomain(PDO $pdo, $orgId) {
    $st = $pdo->prepare("SELECT contact_email FROM organizations WHERE id = ?"); $st->execute([$orgId]);
    $ce = (string)$st->fetchColumn();
    if (strpos($ce, '@') !== false) return strtolower(substr(strrchr($ce, '@'), 1));
    $h = defined('SITE_BASE_URL') ? parse_url(SITE_BASE_URL, PHP_URL_HOST) : ($_SERVER['HTTP_HOST'] ?? 'eadmin.local');
    return preg_replace('/^www\./', '', strtolower((string)$h)) ?: 'eadmin.local';
}

/** सोपा पण सुरक्षित पासवर्ड (O/0, l/1 सारखी गोंधळणारी अक्षरं नाहीत) */
function empListPassword() {
    $a = 'ABCDEFGHJKMNPQRSTUVWXYZ'; $b = 'abcdefghjkmnpqrstuvwxyz'; $n = '23456789';
    return $a[random_int(0, 22)] . $b[random_int(0, 22)] . $b[random_int(0, 22)] . $b[random_int(0, 22)] . '@' . $n[random_int(0, 7)] . $n[random_int(0, 7)] . $n[random_int(0, 7)] . $n[random_int(0, 7)];
}

/** कायमचं हटवणं — staff/delete.php चाच नियम (RESTRICT नोंदी आधी सोडवा, मग users मधून) */
function staffHardDelete(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT * FROM users WHERE id = ?"); $st->execute([(int)$id]); $target = $st->fetch();
    if (!$target || $target['role'] === 'super_admin') return false;
    if (function_exists('recycleCapture')) { recycleCapture($pdo, 'users', (int)$id, null, (int)$target['organization_id']); }
    $own = !$pdo->inTransaction();
    try {
        if ($own) $pdo->beginTransaction();
        $fk = $pdo->query("SELECT k.TABLE_NAME t, k.COLUMN_NAME c, col.IS_NULLABLE n
            FROM information_schema.REFERENTIAL_CONSTRAINTS rc
            JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA AND k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME AND k.TABLE_NAME = rc.TABLE_NAME
            JOIN information_schema.COLUMNS col ON col.TABLE_SCHEMA = k.TABLE_SCHEMA AND col.TABLE_NAME = k.TABLE_NAME AND col.COLUMN_NAME = k.COLUMN_NAME
            WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME = 'users' AND rc.DELETE_RULE IN ('RESTRICT', 'NO ACTION')")->fetchAll();
        foreach ($fk as $r) {
            $t = '`' . str_replace('`', '', $r['t']) . '`'; $c = '`' . str_replace('`', '', $r['c']) . '`';
            $pdo->prepare($r['n'] === 'YES' ? "UPDATE $t SET $c = NULL WHERE $c = ?" : "DELETE FROM $t WHERE $c = ?")->execute([(int)$id]);
        }
        foreach (["DELETE FROM ecampus_updates WHERE user_id = ?", "DELETE FROM campus_heads WHERE user_id = ?"] as $sql) {
            try { $pdo->prepare($sql)->execute([(int)$id]); } catch (PDOException $e) {}
        }
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([(int)$id]);
        if ($own) $pdo->commit();
    } catch (PDOException $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        error_log('staffHardDelete ' . $id . ': ' . $e->getMessage());
        return false;
    }
    if (!empty($target['photo_path']) && str_starts_with((string)$target['photo_path'], 'assets/uploads/')) { $f = __DIR__ . '/../' . $target['photo_path']; if (is_file($f)) @unlink($f); }
    return true;
}
