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

/* ======================================================================
 * 📝 जावक पत्र — E-Admin मध्येच पत्र बनवा: letterhead, जा.क्र. (पुढचा क्र. आपोआप), प्रति, विषय, संदर्भ,
 * मजकूर (परिच्छेद / • मुद्दे), सह्या (हव्या तेवढ्या), कार्याध्यक्षांचा शेरा/सही, प्रत माहितीसाठी सादर.
 * जतन करताच तेच पत्र जावक नोंदवहीत नोंदलं जातं (office_letters.doc_json).
 * ====================================================================== */

/** पत्राची सेटिंग्ज (संस्थेनुसार) */
function orLetterSettings(PDO $pdo, $orgId) {
    $g = fn($k, $d = '') => trim((string)orgSetting($pdo, $orgId, $k, '')) !== '' ? (string)orgSetting($pdo, $orgId, $k, '') : $d;
    // Excel नोंदवही आयात केलेल्या संस्थेसाठी — त्यांच्या पत्राचं letterhead व जा.क्र. (सेटिंग्ज बदलल्यावर तेच)
    $sd = [];
    if ($g('office_reg_seed_done') !== '') { require_once __DIR__ . '/office-register-seed.php'; $sd = orSeedData()['letter'] ?? []; }
    $signs = [];
    foreach (array_filter(array_map('trim', explode("\n", $g('office_letter_signs', $sd['signs'] ?? "व्यवस्थापक सामान्य प्रशासन")))) as $ln) { $p = array_map('trim', explode('|', $ln, 2)); $signs[] = count($p) > 1 ? [$p[0], $p[1]] : ['', $p[0]]; }
    return [
        'head' => $g('office_letterhead', $sd['head'] ?? orHead($pdo, $orgId)),
        'prefix' => $g('office_letter_prefix', $sd['prefix'] ?? 'जा.क्र.'),
        'signs' => $signs,
        'remark' => $g('office_letter_remark', 'माननीय कार्याध्यक्ष साहेब यांचा शेरा/सही :'),
        'to' => $g('office_letter_to', ''),
    ];
}

/** "जा.क्र./वि.जं.म.आ.ट्रस्ट/व्या. सा.प्र/ २०२६/२५२" — उपसर्ग / वर्ष / क्रमांक */
function orLetterRef($prefix, $fy, $no) { return rtrim(trim((string)$prefix), '/ ') . '/ ' . substr((string)$fy, 0, 4) . '/' . $no; }

/** विषयावरून शेवटची विनंती-ओळ: "… मंजुरी मिळण्याबाबत." → "तरी, … मंजुरी मिळावी, ही नम्र विनंती." */
function orAutoClosing($subject) {
    $s = preg_replace('/^[\s.।]+|[\s.।]+$/u', '', preg_replace('/\s+/u', ' ', (string)$subject));
    if ($s === '') return 'तरी, उपरोक्त विषयास मंजुरी मिळावी, ही नम्र विनंती.';
    // क्रियापद — आधीचा शब्द स्त्रीलिंगी (मंजुरी, रक्कम, वाढ …) तर "मिळावी", नाहीतर "मिळावे"
    $verbs = ['मिळविण्या' => ['मिळावी', 'मिळावे'], 'मिळण्या' => ['मिळावी', 'मिळावे'], 'मिळणे' => ['मिळावी', 'मिळावे'], 'करण्या' => ['करावी', 'करावे'], 'करणे' => ['करावी', 'करावे'],
        'देण्या' => ['द्यावी', 'द्यावे'], 'देणे' => ['द्यावी', 'द्यावे'], 'होण्या' => ['व्हावी', 'व्हावे'], 'होणे' => ['व्हावी', 'व्हावे'], 'घेण्या' => ['घ्यावी', 'घ्यावे'],
        'आणण्या' => ['आणावी', 'आणावे'], 'आणणे' => ['आणावी', 'आणावे'], 'पाठविण्या' => ['पाठवावी', 'पाठवावे'], 'पाठवणे' => ['पाठवावी', 'पाठवावे']];
    $fem = '/(ी|वाढ|रक्कम|व्यवस्था|सुविधा|रजा|मान्यता|सोय|परवानगी|कारवाई|बस|फी|मदत)$/u';
    if (preg_match('/^(.*?)\s*(' . implode('|', array_keys($verbs)) . ')\s*बाबत$/u', $s, $m) && trim($m[1]) !== '') {
        $pre = trim($m[1]); $last = preg_replace('/^.*\s/u', '', $pre);
        return 'तरी, ' . $pre . ' ' . $verbs[$m[2]][preg_match($fem, $last) ? 0 : 1] . ', ही नम्र विनंती.';
    }
    if (mb_substr($s, -4) === 'बाबत') return 'तरी, ' . trim(mb_substr($s, 0, -4)) . ' बाबत योग्य ती कार्यवाही व्हावी, ही नम्र विनंती.';
    return 'तरी, ' . $s . ' — याबाबत योग्य ती कार्यवाही व्हावी, ही नम्र विनंती.';
}

/** तयार नमुने — [नाव, विषय, मजकूर, शेवट] ({…} = भरायची माहिती) */
function orLetterTemplates() {
    $open = "उपरोक्त विषयास अनुसरून सविनय सादर करण्यात येते की, ";
    return [
        'general' => ['📄 सर्वसाधारण विनंती', '', $open . "\n\n", ''],
        'wage' => ['👷 रोजंदारी मंजुरी', '{नाव} यांच्या माहे {महिना} च्या रोजंदारी मंजुरी मिळण्याबाबत.',
            $open . "आपल्या संकुलामध्ये {नाव} हे {पद} म्हणून रोजंदारी तत्त्वावर कार्यरत आहेत. त्यांनी माहे {महिना} महिन्यात केलेल्या कामाचा तपशील खालीलप्रमाणे असून, त्यानुसार त्यांचे पेमेंट अदा करावयाचे आहे:\n• कामाचा कालावधी: {पासून} ते {पर्यंत}\n• एकूण कार्यदिवस: {दिवस} दिवस\n• रोजंदारी दर: रु. {दर}/- प्रतिदिन\n• एकूण देय रक्कम: रु. {रक्कम}/- (अक्षरी रुपये {अक्षरी} मात्र)", ''],
        'expense' => ['💰 खर्चास मंजुरी', '{कार्यक्रम / काम} साठी झालेल्या खर्चास मंजुरी मिळणेबाबत.',
            $open . "{कार्यक्रम / काम} साठी खालीलप्रमाणे खर्च झालेला आहे:\n• खर्चाचा तपशील: {तपशील}\n• एकूण रक्कम: रु. {रक्कम}/-\nसंबंधित बिले सोबत जोडली आहेत.", ''],
        'purchase' => ['🛒 साहित्य खरेदी मंजुरी', '{विभाग} साठी आवश्यक साहित्य खरेदी करण्यास मंजुरी मिळणेबाबत.',
            $open . "{विभाग} च्या कामासाठी खालील साहित्याची आवश्यकता आहे:\n• {साहित्य} — {नग} नग\n• अंदाजित खर्च: रु. {रक्कम}/-\nकोटेशन सोबत जोडले आहे.", ''],
        'salary' => ['📈 वेतनवाढ', '{नाव} यांच्या वेतनात वाढ करण्याबाबत.',
            $open . "{नाव} हे {पद} म्हणून दिनांक {पासून} पासून कार्यरत असून त्यांचे काम समाधानकारक आहे.\n• सध्याचे वेतन: रु. {सध्याचे}/-\n• प्रस्तावित वेतन: रु. {प्रस्तावित}/-\nत्यांच्या कामाचा आढावा घेऊन वेतनवाढ देण्यात यावी.", ''],
        'leave' => ['🏖️ सुट्टी / गैरहजेरी', '{नाव} यांच्या रजेबाबत.',
            $open . "{नाव} ({पद}) हे दिनांक {पासून} ते {पर्यंत} या कालावधीत {कारण} कारणास्तव रजेवर आहेत / होते.", 'तरी, त्यांची रजा मंजूर करण्यात यावी, ही नम्र विनंती.'],
        'info' => ['📋 माहिती / अहवाल सादर', '{विषय} बाबतची माहिती सादर करणेबाबत.',
            $open . "{विषय} बाबतची माहिती / अहवाल खालीलप्रमाणे सादर करीत आहोत:\n• {मुद्दा 1}\n• {मुद्दा 2}", 'सदर माहिती आपल्या माहितीसाठी सविनय सादर.'],
        'action' => ['⚠️ कारवाई / सूचना', '{नाव} यांच्यावर {कारण} बद्दल कारवाई करणेबाबत.',
            $open . "{नाव} ({पद}) यांनी {तपशील}. यापूर्वीही याबाबत सूचना देण्यात आल्या होत्या.", 'तरी, नियमानुसार योग्य ती कारवाई करण्यात यावी, ही नम्र विनंती.'],
    ];
}

/** मजकूर → HTML: रिकाम्या ओळीने परिच्छेद, "•" / "-" / "*" ने सुरू होणाऱ्या ओळी = मुद्दे, **ठळक** */
function orLetterBodyHtml($body) {
    $esc = fn($t) => preg_replace('/\*\*(.+?)\*\*/u', '<b>$1</b>', htmlspecialchars($t, ENT_QUOTES, 'UTF-8'));
    $out = ''; $list = false;
    foreach (preg_split('/\R/u', trim((string)$body)) as $ln) {
        $t = trim($ln);
        if (preg_match('/^[•\-\*●▪]\s*(.+)$/u', $t, $m)) { if (!$list) { $out .= '<ul>'; $list = true; } $out .= '<li>' . $esc($m[1]) . '</li>'; continue; }
        if ($list) { $out .= '</ul>'; $list = false; }
        if ($t === '') continue;
        $out .= '<p>' . $esc($t) . '</p>';
    }
    if ($list) $out .= '</ul>';
    return $out;
}

/** पत्राचा मजकूर (doc_json) — रिकाम्या जागी सेटिंग्जमधले मूल्य */
function orLetterDoc(array $row = null, array $set = []) {
    $d = $row && !empty($row['doc_json']) ? (json_decode((string)$row['doc_json'], true) ?: []) : [];
    return $d + ['to' => '', 'salutation' => 'महोदय,', 'reference' => '', 'body' => '', 'closing' => '', 'final' => 'आपल्या मंजुरीसाठी सविनय सादर.',
        'signs' => $set['signs'] ?? [], 'remark' => $set['remark'] ?? '', 'copies' => '', 'template' => '', 'digits' => 'mr', 'prefix' => $set['prefix'] ?? 'जा.क्र.'];
}

/** पत्राचा CSS (Print + Preview दोन्हीसाठी) */
function orLetterCss() {
    return '.ltr{font-family:"Noto Sans Devanagari",system-ui,sans-serif;color:#000;background:#fff;font-size:15px;line-height:1.75;}
.ltr .lh{text-align:center;line-height:1.55;} .ltr .lh div{font-size:15px;} .ltr .lh div.l0{font-size:13px;font-weight:700;} .ltr .lh div.l1{font-size:17px;font-weight:600;}
.ltr .rule{border:0;border-top:2px solid #000;margin:6px 0 10px;}
.ltr .rf{display:flex;justify-content:space-between;gap:12px;font-weight:700;font-size:14.5px;flex-wrap:wrap;}
.ltr .to{margin-top:22px;font-weight:700;line-height:2;} .ltr .to div{white-space:pre-wrap;}
.ltr .sb{margin:26px 0 6px;padding-left:12%;font-weight:700;} .ltr .rfn{padding-left:12%;font-size:14px;margin-bottom:6px;}
.ltr .sal{font-weight:700;margin-top:14px;} .ltr .bd p{margin:0 0 6px;text-indent:2.2em;text-align:justify;} .ltr .bd ul{margin:4px 0 8px 2.4em;padding-left:1em;} .ltr .bd li{margin:5px 0;}
.ltr .cl{margin-top:6px;text-align:justify;} .ltr .fin{margin-top:10px;}
.ltr .sg{display:flex;flex-wrap:wrap;justify-content:space-between;gap:24px 12px;margin-top:64px;font-weight:700;} .ltr .sg div{min-width:30%;text-align:center;} .ltr .sg div:first-child{text-align:left;} .ltr .sg div:last-child{text-align:right;} .ltr .sg div:only-child{margin-left:auto;}
.ltr .sg small{display:block;font-weight:600;}
.ltr .rm{margin-top:40px;font-weight:700;min-height:70px;}
.ltr .cp{margin-top:14px;font-size:14px;} .ltr .cp b{display:block;} .ltr .cp ol{margin:2px 0 0 1.2em;padding-left:1em;}';
}

/** पत्र → HTML. $m = ['reg_no', 'fy', 'date', 'subject'] */
function orLetterHtml(array $doc, array $m, $head, $mr = true) {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $N = fn($x) => $mr ? orMr($x) : (string)$x;
    $o = '<div class="ltr"><div class="lh">';
    foreach (array_values(array_filter(array_map('trim', explode("\n", (string)$head)), 'strlen')) as $i => $ln) $o .= '<div class="l' . min($i, 2) . '">' . $h($ln) . '</div>';
    $o .= '</div><hr class="rule">';
    $o .= '<div class="rf"><span>' . $h($N(orLetterRef($doc['prefix'] ?? 'जा.क्र.', $m['fy'], $m['reg_no']))) . '</span><span>दिनांक : ' . $h($N($m['date'] ? date('d/m/Y', strtotime($m['date'])) : '')) . '</span></div>';
    if (trim((string)$doc['to']) !== '') $o .= '<div class="to"><div>प्रति,</div><div>' . $h(trim((string)$doc['to'])) . '</div></div>';
    $o .= '<div class="sb">विषय: ' . $h($m['subject']) . '</div>';
    if (trim((string)$doc['reference']) !== '') $o .= '<div class="rfn"><b>संदर्भ:</b> ' . $h(trim((string)$doc['reference'])) . '</div>';
    if (trim((string)$doc['salutation']) !== '') $o .= '<div class="sal">' . $h($doc['salutation']) . '</div>';
    $o .= '<div class="bd">' . orLetterBodyHtml($doc['body']) . '</div>';
    if (trim((string)$doc['closing']) !== '') $o .= '<div class="cl">' . $h($doc['closing']) . '</div>';
    if (trim((string)$doc['final']) !== '') $o .= '<div class="fin">' . $h($doc['final']) . '</div>';
    $sg = array_values(array_filter((array)$doc['signs'], fn($s) => trim(($s[0] ?? '') . ($s[1] ?? '')) !== ''));
    if ($sg) { $o .= '<div class="sg">'; foreach ($sg as $s) $o .= '<div>' . (trim($s[0] ?? '') !== '' ? '(' . $h(trim($s[0])) . ')<small>' . $h($s[1] ?? '') . '</small>' : $h($s[1] ?? '')) . '</div>'; $o .= '</div>'; }
    if (trim((string)$doc['remark']) !== '') $o .= '<div class="rm">' . $h($doc['remark']) . '</div>';
    $cp = array_values(array_filter(array_map(fn($x) => trim(preg_replace('/^\d+[.)]\s*/u', '', $x)), explode("\n", (string)$doc['copies'])), 'strlen'));
    if ($cp) { $o .= '<div class="cp"><b>प्रत माहितीसाठी सादर :</b><ol>'; foreach ($cp as $c) $o .= '<li>' . $h($c) . '</li>'; $o .= '</ol></div>'; }
    return $o . '</div>';
}
