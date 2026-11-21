<?php
/**
 * 📨 आवक-जावक नोंदवही (कार्यालय) — व्यवस्थापक (सामान्य प्रशासन) यांच्याकडे येणारी (आवक) व जाणारी (जावक) पत्रे.
 * आवक: आवक क्र., दिनांक, प्राप्त पत्राचा जावक क्र., कोणाकडून, विषय, शेरा (सूचना), कामाची स्थिती
 * जावक: जावक क्र., दिनांक, कोणाकडे, विषय, शेरा, कामाची स्थिती
 * कामाच्या स्थितीवरून आपोआप टप्पा (बाकी / चालू / पूर्ण / रद्द), शेऱ्यातून कोणत्या विभागाकडे काम — हे सुद्धा आपोआप.
 * Clerk ला "office_register" परवानगी → नोंद करणे; व्यवस्थापक / अधिकारी → "office_register_view" किंवा Management.
 */
require_once __DIR__ . '/app-settings.php';

function orReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM office_letters LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** नोंद / बदल / आयात — Super Admin किंवा "आवक-जावक नोंदवही" परवानगी */
function orCanEdit() { return hasRole('super_admin') || hasPermission('office_register'); }
/** पाहणे व अहवाल — वरचे + "फक्त पाहणे" परवानगी + Management */
function orCanView() { return orCanEdit() || hasPermission('office_register_view') || isManagementOrAbove(); }

/** आर्थिक वर्ष (एप्रिल–मार्च): 2026-11-20 → "2026-27" */
function orFy($date = null) {
    $t = $date ? strtotime($date) : time();
    $y = (int)date('Y', $t); if ((int)date('n', $t) < 4) $y--;
    return $y . '-' . substr((string)($y + 1), -2);
}
/** "2026-27" → [2026-04-01, 2027-03-31] */
function orFyRange($fy) { $y = (int)substr((string)$fy, 0, 4); return [$y . '-04-01', ($y + 1) . '-03-31']; }

function orMonthNames() { return [1 => 'जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर']; }
function orMonthName($ym) { return orMonthNames()[(int)date('n', strtotime($ym))] ?? ''; }
/** आर्थिक वर्षाचे 12 महिने (एप्रिल → मार्च) — [Y-m-01 …] */
function orFyMonths($fy) { [$a] = orFyRange($fy); $o = []; for ($i = 0; $i < 12; $i++) $o[] = date('Y-m-01', strtotime("$a +$i month")); return $o; }

function orDate($d) { return $d ? date('d.m.Y', strtotime($d)) : ''; }
function orDigitsAscii($s) { return strtr((string)$s, ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9']); }
function orMr($s) { return strtr((string)$s, ['0' => '०', '1' => '१', '2' => '२', '3' => '३', '4' => '४', '5' => '५', '6' => '६', '7' => '७', '8' => '८', '9' => '९']); }

/** "१२५-अ" → "125-अ" (एकसारखा क्रमांक, म्हणजे दुबार नोंद ओळखता येते) */
function orNormNo($no) { return mb_substr(preg_replace('/\s+/u', '', orDigitsAscii(trim((string)$no))), 0, 20); }
/** क्रमांकाचा अंकी भाग — क्रम लावण्यासाठी व पुढचा क्र. काढण्यासाठी */
function orRegNum($no) { return preg_match('/^(\d+)/', orNormNo($no), $m) ? (int)$m[1] : 0; }

function orDirLabel($d, $short = false) { return $d === 'out' ? ($short ? 'जावक' : '📤 जावक') : ($short ? 'आवक' : '📥 आवक'); }

/** टप्पे: key => [लेबल, css] */
function orStatuses() {
    return ['pending' => ['⏳ कार्यवाही बाकी', 'st-p'], 'progress' => ['🔄 कार्यवाही चालू', 'st-g'], 'done' => ['✅ पूर्ण', 'st-d'],
        'info' => ['📄 फक्त माहितीसाठी', 'st-i'], 'cancelled' => ['✕ रद्द', 'st-c']];
}
function orStatusLabel($s) { return orStatuses()[$s][0] ?? $s; }
/** अजून काम बाकी असलेले टप्पे */
function orOpenStatuses() { return ['pending', 'progress']; }

/**
 * "कामाची स्थिती" मधील मजकुरावरून टप्पा — Excel मधल्या 90+ वेगवेगळ्या लिखाणांवरून बनवलेले नियम.
 * उदा. "कार्यवाही झाली आहे." → done · "कार्यवाही चालू आहे." → progress · "प्रलंबित -खरेदीसाठी" → pending · "रद्द झाले." → cancelled
 */
function orClassify($text) {
    $t = trim((string)$text);
    if ($t === '') return 'pending';
    if (preg_match('/रद्द/u', $t)) return 'cancelled';
    if (preg_match('/अपूर्ण|प्रलंबित|निर्णयाधी|अभावी|कमतरता|विलंब|येईल|होतील|बाकी आहे|उपलब्ध नाही/u', $t)) return 'pending';
    if (preg_match('/चालू|सुरु|सुरू|प्रक्रि.?याधीन|प्रक्रीयाधीन|प्रक्रियेत|मागणी केली|नियोजन|पाठवल|पाठविल|दुरुस्तीसाठी|पाहणी|उर्वरित|अंशतः/u', $t)) return 'progress';
    if (preg_match('/पूर्ण|झाल|झाले|जमा|दिले|दिलेल|दिली|नोंद घेतली|करण्यात आल|देण्यात आल|आणण्यात|जोडला|जोडले|बनविण्यात|बसविण्यात|केले आहे|केली आहे|मिळाल/u', $t)) return 'done';
    return 'progress';
}

/** शेऱ्यातून काम कोणत्या विभागाकडे — शब्द → विभाग (Excel मधल्या लिखाणावरून) */
function orDeptKeywords() {
    return [
        'विद्युत विभाग' => 'विद्युत|ईलेक्ट्रीक|इलेक्ट्रिक|इलेक्ट्रीक|electric',
        'वाहन विभाग' => 'वाहन',
        'IT विभाग' => '\bIT\b|आय\.?टी',
        'सिव्हील विभाग' => 'सिव्हील|सिव्हिल|बांधकाम',
        'भोजनालय विभाग' => 'भोजनालय|प्रसादालय|किचन',
        'वसतिगृह विभाग' => 'वसतिगृह|वसतीगृह|हॉस्टेल',
        'स्वच्छता विभाग' => 'स्वच्छता',
        'लेखा विभाग' => 'लेखा|अकाउंट',
        'स्टोअर विभाग' => 'स्टोअर|स्टोर|भांडार',
        'खरेदी विभाग' => 'खरेदी विभाग',
        'कार्यशाळा विभाग' => 'कार्यशाळा|वर्कशॉप',
        'मशीन देखभाल विभाग' => 'मशिन देखभाल|मशीन देखभाल',
        'प्लंबिंग विभाग' => 'प्लंबिंग|प्लम्बिंग',
        'सुरक्षा विभाग' => 'सुरक्षा विभाग|सुरक्षा रक्षक|सिक्युरिटी',
        'गोशाळा विभाग' => 'गोशाळा|गौशाळा',
        'शेती व गार्डन विभाग' => 'शेती|गार्डन|उद्यान',
        'क्रीडा विभाग' => 'क्रीडा',
        'लॉंड्री विभाग' => 'लॉंड्री|लाँड्री|धुलाई',
        'मानव संसाधन विभाग' => 'HR\b|मानव संसाधन|कर्मचारी वैयक्तिक File',
        'दुरुस्ती व देखभाल विभाग' => 'दुरुस्ती व देखभाल',
        'आरोग्य विभाग' => 'दवाखाना|आरोग्य विभाग',
    ];
}
function orDetectDepts($remark) {
    $t = (string)$remark; if (trim($t) === '') return '';
    $hit = [];
    foreach (orDeptKeywords() as $d => $re) if (preg_match('/' . $re . '/iu', $t, $m, PREG_OFFSET_CAPTURE)) $hit[$d] = $m[0][1];
    asort($hit);   // शेऱ्यात आधी आलेला विभाग आधी
    return mb_substr(implode(', ', array_keys($hit)), 0, 255);
}

/** कोणाकडून / कोणाकडे — गट करण्यासाठी एकसारखं नाव */
function orPartyKey($p) { return mb_strtolower(trim(preg_replace('/[\s.]+/u', ' ', str_replace(['साहेब', 'माननीय', 'मा.'], '', (string)$p)))); }

/** या वर्षाचा पुढचा क्रमांक */
function orNextNo(PDO $pdo, $orgId, $dir, $fy) {
    $st = $pdo->prepare("SELECT MAX(reg_num) FROM office_letters WHERE organization_id = ? AND direction = ? AND fy = ?");
    $st->execute([$orgId, $dir, $fy]);
    return (int)$st->fetchColumn() + 1;
}

function orLog(PDO $pdo, $orgId, $id, $event, $note, $uid) {
    try { $pdo->prepare("INSERT INTO office_letter_events (letter_id, organization_id, event, note, user_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())")
        ->execute([$id, $orgId, $event, $note === null ? null : mb_substr((string)$note, 0, 500), $uid]); } catch (PDOException $e) {}
}

/** पत्र किती दिवसांपासून (बाकी/चालू) */
function orAge(array $r) {
    $ref = $r['letter_date'] ?: substr((string)$r['created_at'], 0, 10);
    if (!$ref) return null;
    $end = in_array($r['status'], orOpenStatuses(), true) ? date('Y-m-d') : ($r['done_on'] ?: null);
    if (!$end) return null;
    return max(0, (int)floor((strtotime($end) - strtotime($ref)) / 86400));
}

/** अहवालाचं शीर्षक, नोंदवहीचं नाव, सह्या (संस्थेनुसार बदलता येतात) */
function orHead(PDO $pdo, $orgId, $orgName = '') { $h = (string)orgSetting($pdo, $orgId, 'office_reg_head', ''); return trim($h) !== '' ? $h : (string)$orgName; }
function orTitle(PDO $pdo, $orgId) { $t = trim((string)orgSetting($pdo, $orgId, 'office_reg_title', '')); return $t !== '' ? $t : 'ट्रस्ट व्यवस्थापक'; }
/** [[नाव, पद], …] */
function orSigns(PDO $pdo, $orgId) {
    $raw = (string)orgSetting($pdo, $orgId, 'office_reg_signs', '');
    if (trim($raw) === '') $raw = "|व्यवस्थापक-सामान्य प्रशासन\n|माननीय कार्याध्यक्ष";
    $o = [];
    foreach (array_filter(array_map('trim', explode("\n", $raw))) as $ln) { $p = array_map('trim', explode('|', $ln, 2)); $o[] = [$p[0], $p[1] ?? '']; }
    return $o;
}

/** Excel तारीख (Serial / "०४/०६/२०२६" / "4.6.26") → Y-m-d */
function orParseDate($v) {
    if ($v === '' || $v === null) return null;
    if (is_float($v) || is_int($v)) { $v = (float)$v; return $v > 20000 && $v < 80000 ? gmdate('Y-m-d', (int)round(($v - 25569) * 86400)) : null; }
    $s = orDigitsAscii(trim((string)$v));
    if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
    if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2,4})/', $s, $m)) {
        $y = (int)$m[3]; if ($y < 100) $y += 2000;
        return checkdate((int)$m[2], (int)$m[1], $y) ? sprintf('%04d-%02d-%02d', $y, $m[2], $m[1]) : null;
    }
    return null;
}

/** चुकीचं वर्ष (उदा. 2006 / 2024 — टायपिंग चूक) → नोंदवही महिन्याचं वर्ष. जवळचं (1 वर्षाच्या आतलं) वर्ष तसंच ठेवतो. */
function orFixYear($date, $regMonth) {
    if (!$date || !$regMonth) return $date;
    $y = (int)substr($regMonth, 0, 4); $dy = (int)substr($date, 0, 4);
    if (abs($dy - $y) < 2) return $date;
    $c = $y . substr($date, 4);
    return checkdate((int)substr($c, 5, 2), (int)substr($c, 8, 2), $y) ? $c : $date;
}

/**
 * Excel (महिन्यानुसार "आवक एप्रिल", "जावक एप्रिल" … Sheets) → नोंदींची यादी.
 * शीर्षक ओळ ("अ.क्र.", "आवक क्र." / "जावक क्र.", "दिनांक", "कोणाकडून" …) शोधून रकाने ओळखतो — रकान्यांचा क्रम बदलला तरी चालतो.
 * परत: ['rows' => [...], 'sheets' => [[name, dir, month, n]], 'warn' => [...], 'head' => [...ओळी], 'title' => .., 'signs' => ..]
 */
function orParseWorkbook($path) {
    require_once __DIR__ . '/sheet-reader.php';
    $names = eowmsReadXlsx($path, 'names');
    $mIdx = []; foreach (orMonthNames() as $n => $nm) $mIdx[$nm] = $n;
    $mIdx += ['नोहेंबर' => 11, 'नोव्हेबर' => 11, 'नोंव्हेंबर' => 11, 'सप्टेबर' => 9, 'आगस्ट' => 8, 'जुलइ' => 7, 'फेब्रुवरी' => 2];
    $out = ['rows' => [], 'sheets' => [], 'warn' => [], 'head' => [], 'title' => '', 'signs' => []];
    $clean = fn($x) => trim(preg_replace('/\s+/u', ' ', (string)$x));
    foreach ($names as $si => $sname) {
        $rows = eowmsReadXlsx($path, $si);
        $hdr = null; $col = []; $dir = null; $month = null; $fy = null; $pre = [];
        foreach ($rows as $ri => $r) {
            $first = $clean($r[0] ?? '');
            if ($hdr === null) {
                if (preg_match('/^अ\.?\s*क्र/u', $first)) {
                    $hdr = $ri;
                    foreach ($r as $ci => $h) {
                        $h = $clean($h);
                        if (preg_match('/^आवक\s*क्र/u', $h)) { $col['no'] = $ci; $dir = 'in'; }
                        elseif (preg_match('/^जावक\s*क्र/u', $h)) { $col['no'] = $ci; $dir = $dir ?? 'out'; }
                        elseif (preg_match('/प्राप्त पत्राचा|संदर्भ/u', $h)) $col['ref'] = $ci;
                        elseif (preg_match('/^दिनांक|^तारीख/u', $h)) $col['date'] = $ci;
                        elseif (preg_match('/कोणाकडून|कोणाकडे|पाठविलेले|मिळाले/u', $h)) $col['party'] = $ci;
                        elseif (preg_match('/^विषय/u', $h)) $col['subject'] = $ci;
                        elseif (preg_match('/^शेरा/u', $h)) $col['remark'] = $ci;
                        elseif (preg_match('/स्थिती/u', $h)) $col['status'] = $ci;
                    }
                    continue;
                }
                if ($first !== '') {
                    $pre[] = $first;
                    if (preg_match('/^माहे\s*[-–:]?\s*(.+)$/u', $first, $m)) { $mn = trim($m[1], " -–:"); foreach ($mIdx as $nm => $n) if (mb_strpos($mn, $nm) === 0) { $month = $n; break; } }
                    if (preg_match('/([0-9०-९]{4})\s*[-–]\s*([0-9०-९]{2})/u', $first, $m)) $fy = orDigitsAscii($m[1]) . '-' . orDigitsAscii($m[2]);
                }
                continue;
            }
            // नोंदी
            $no = $clean($r[$col['no'] ?? 1] ?? '');
            if (is_float($r[$col['no'] ?? 1] ?? '')) $no = (string)(int)$r[$col['no'] ?? 1];
            if ($no === '' || !preg_match('/^[0-9०-९]+/u', $no)) {
                // सहीच्या ओळी — "(प्रा. … )" व पद
                foreach ($r as $c) { $c = $clean($c); if ($c !== '' && mb_strlen($c) < 60) $out['signs'][] = $c; }
                continue;
            }
            $out['rows'][] = ['_sheet' => $sname, '_si' => $si, '_ri' => $ri, '_dir' => $dir, '_month' => $month, '_fy' => $fy,
                'reg_no' => orNormNo($no), 'date_raw' => $r[$col['date'] ?? 2] ?? '', 'ref_no' => isset($col['ref']) ? $clean(is_float($r[$col['ref']] ?? '') ? (string)(int)$r[$col['ref']] : ($r[$col['ref']] ?? '')) : '',
                'party' => $clean($r[$col['party'] ?? 3] ?? ''), 'subject' => $clean($r[$col['subject'] ?? 4] ?? ''),
                'remark' => $clean($r[$col['remark'] ?? 5] ?? ''), 'work_status' => $clean($r[$col['status'] ?? 6] ?? '')];
        }
        if ($hdr === null) continue;
        if (!$dir) $dir = mb_strpos($sname, 'जावक') !== false ? 'out' : 'in';
        if (!$out['head'] && count($pre) >= 2) {
            $out['head'] = array_values(array_filter($pre, fn($l) => !preg_match('/^माहे|रजिस्टर|नोंदवही/u', $l)));
            foreach ($pre as $l) if (preg_match('/^(.*?)\s*(आवक|जावक)\s*रजिस्टर/u', $l, $m)) $out['title'] = trim($m[1]);
        }
        if (!$month) foreach ($mIdx as $nm => $n) if (mb_strpos($sname, $nm) !== false) { $month = $n; break; }
        $cnt = 0;
        foreach ($out['rows'] as &$x) {
            if ($x['_si'] !== $si) continue;
            $x['_dir'] = $dir; $cnt++;
            $fyy = $fy ?: null;
            $d = orParseDate($x['date_raw']);
            if (!$fyy) $fyy = $d ? orFy($d) : orFy();
            $y = (int)substr($fyy, 0, 4); $mm = $month ?: ($d ? (int)substr($d, 5, 2) : 4);
            $x['reg_month'] = sprintf('%04d-%02d-01', $mm < 4 ? $y + 1 : $y, $mm);
            $x['fy'] = $fyy;
            $fixed = orFixYear($d, $x['reg_month']);
            if ($d && $fixed !== $d) $out['warn'][] = $sname . ': क्र. ' . $x['reg_no'] . ' — दिनांक ' . orDate($d) . ' → ' . orDate($fixed) . ' (वर्ष दुरुस्त)';
            if (!$d && trim((string)$x['date_raw']) === '') $out['warn'][] = $sname . ': क्र. ' . $x['reg_no'] . ' — दिनांक नाही';
            elseif ($fixed && abs(strtotime($fixed) - strtotime($x['reg_month'])) / 86400 > 120) $out['warn'][] = $sname . ': क्र. ' . $x['reg_no'] . ' — दिनांक ' . orDate($fixed) . ' (नोंदवही महिन्यापासून दूर — तपासा)';
            $x['letter_date'] = $fixed;
        }
        unset($x);
        $out['sheets'][] = [$sname, $dir, $month, $cnt];
    }
    // सह्या: "(प्रा.उल्हास पाटील)" सारखी नावं व पदं
    $nm = []; $pd = [];
    foreach ($out['signs'] as $s) { if (preg_match('/^\((.+)\)$/u', $s, $m)) $nm[trim($m[1])] = 1; elseif (preg_match('/व्यवस्थापक|कार्याध्यक्ष|अध्यक्ष|सचिव|प्राचार्य/u', $s)) $pd[trim($s)] = 1; }
    $nm = array_keys($nm); $pd = array_keys($pd); $sg = [];
    for ($i = 0; $i < max(count($nm), count($pd)); $i++) $sg[] = ($nm[$i] ?? '') . '|' . ($pd[$i] ?? '');
    $out['signs'] = $sg;
    // दुबार क्रमांक (एकाच वर्षात, एकाच प्रकारात): तीच नोंद पुन्हा लिहिली असेल तर वगळतो; वेगळं पत्र असेल तर "-अ" जोडतो (Excel मधलीच पद्धत)
    $seen = []; $keep = [];
    foreach ($out['rows'] as $x) {
        $k = $x['_dir'] . '|' . $x['fy'] . '|' . $x['reg_no'];
        if (isset($seen[$k])) {
            if (orPartyKey($seen[$k]['subject']) === orPartyKey($x['subject'])) { $out['warn'][] = orDirLabel($x['_dir'], true) . ' क्र. ' . $x['reg_no'] . ' — तीच नोंद ' . trim($seen[$k]['_sheet']) . ' व ' . trim($x['_sheet']) . ' मध्ये दोनदा; एकदाच घेतली'; continue; }
            foreach (['अ', 'ब', 'क', 'ड'] as $suf) { $nk = $k . '-' . $suf; if (!isset($seen[$nk])) break; }
            $out['warn'][] = orDirLabel($x['_dir'], true) . ' क्र. ' . $x['reg_no'] . ' दोन वेगळ्या पत्रांना (' . trim($x['_sheet']) . ') — दुसऱ्याला क्र. ' . $x['reg_no'] . '-' . $suf;
            $x['reg_no'] .= '-' . $suf; $k = $nk;
        }
        $seen[$k] = $x; $keep[] = $x;
    }
    $out['rows'] = $keep;
    return $out;
}

/** यादी DB मध्ये — आधीच असलेला क्रमांक (वर्ष + आवक/जावक) वगळतो. परत: [घातल्या, वगळल्या] */
function orImportRows(PDO $pdo, $orgId, array $rows, $uid, $source = 'excel') {
    $ins = $pdo->prepare("INSERT IGNORE INTO office_letters (organization_id, direction, fy, reg_month, reg_no, reg_num, sort_order, letter_date, ref_no, party, subject, remark, assigned_to, work_status, status, done_on, source, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
    $n = 0; $skip = 0; $i = 0;
    $pdo->beginTransaction();
    try {
        foreach ($rows as $x) {
            $i++;
            $st = orClassify($x['work_status'] ?? '');
            $ins->execute([$orgId, $x['_dir'] ?? $x['direction'], $x['fy'], $x['reg_month'], $x['reg_no'], orRegNum($x['reg_no']), $i, $x['letter_date'] ?: null,
                ($x['ref_no'] ?? '') !== '' ? mb_substr($x['ref_no'], 0, 80) : null, ($x['party'] ?? '') !== '' ? mb_substr($x['party'], 0, 255) : null,
                ($x['subject'] ?? '') !== '' ? $x['subject'] : null, ($x['remark'] ?? '') !== '' ? $x['remark'] : null, orDetectDepts($x['remark'] ?? '') ?: null,
                ($x['work_status'] ?? '') !== '' ? $x['work_status'] : null, $st, null, $source, $uid]);
            if ($ins->rowCount()) { $n++; orLog($pdo, $orgId, (int)$pdo->lastInsertId(), 'imported', $source === 'seed' ? 'Excel नोंदवहीतून (सुरुवातीची आयात)' : 'Excel मधून आयात', $uid); } else $skip++;
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return [$n, $skip];
}

/** सुरुवातीची आयात — "ट्रस्ट व्यवस्थापक आवक / जावक रजिस्टर 2026-27" (एप्रिल ते नोव्हेंबर) */
function orSeedImport(PDO $pdo, $orgId, $uid) {
    if (orgSetting($pdo, $orgId, 'office_reg_seed_done', '') !== '') return [0, 0];
    require_once __DIR__ . '/office-register-seed.php';
    $d = orSeedData();
    [$n, $skip] = orImportRows($pdo, $orgId, $d['rows'], $uid, 'seed');
    if (trim((string)orgSetting($pdo, $orgId, 'office_reg_head', '')) === '' && !empty($d['head'])) orgSettingSet($pdo, $orgId, 'office_reg_head', implode("\n", $d['head']));
    if (trim((string)orgSetting($pdo, $orgId, 'office_reg_title', '')) === '' && !empty($d['title'])) orgSettingSet($pdo, $orgId, 'office_reg_title', $d['title']);
    if (trim((string)orgSetting($pdo, $orgId, 'office_reg_signs', '')) === '' && !empty($d['signs'])) orgSettingSet($pdo, $orgId, 'office_reg_signs', implode("\n", $d['signs']));
    orgSettingSet($pdo, $orgId, 'office_reg_seed_done', date('Y-m-d H:i:s'));
    return [$n, $skip];
}
