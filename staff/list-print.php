<?php
// 🖨️ कर्मचारी यादी — College/School / विभागनिहाय Print व PDF; कोणती माहिती छापायची ते निवडा
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$orgId = activeOrgId();
$visibleDeptIds = getVisibleDepartmentIdsForStaffTracking();
if ($visibleDeptIds !== null && !$visibleDeptIds) { header('Location: index.php?view=tree'); exit; }
$canSensitive = isManagementOrAbove() || hasRole('super_admin');

// ---- निवडता येणारे स्तंभ: key => [नाव, गट, संवेदनशील?, PDF रुंदी(%)]
$COLS = [
    'photo' => ['फोटो', 'मूलभूत', false, 5],
    'name' => ['नाव', 'मूलभूत', false, 16],
    'employee_code' => ['Emp Id', 'मूलभूत', false, 6],
    'designation' => ['पद', 'मूलभूत', false, 11],
    'dept' => ['विभाग', 'मूलभूत', false, 10],
    'campus' => ['College / School', 'मूलभूत', false, 11],
    'teaching_mode' => ['प्रकार', 'मूलभूत', false, 7],
    'employment_type' => ['नेमणूक प्रकार', 'मूलभूत', false, 8],
    'gender' => ['लिंग', 'वैयक्तिक', false, 5],
    'date_of_birth' => ['जन्मतारीख', 'वैयक्तिक', false, 8],
    'age' => ['वय', 'वैयक्तिक', false, 4],
    'joining_date' => ['रुजू तारीख', 'मूलभूत', false, 8],
    'service' => ['सेवा (वर्षं)', 'मूलभूत', false, 5],
    'father_name' => ['वडिलांचे नाव', 'वैयक्तिक', false, 12],
    'marital_status' => ['वैवाहिक स्थिती', 'वैयक्तिक', false, 7],
    'religion_caste' => ['धर्म / जात', 'वैयक्तिक', false, 8],
    'mobile' => ['Mobile', 'संपर्क', false, 9],
    'whatsapp_number' => ['WhatsApp', 'संपर्क', false, 9],
    'email' => ['Email', 'संपर्क', false, 15],
    'local_address' => ['स्थानिक पत्ता', 'संपर्क', false, 16],
    'permanent_address' => ['कायमचा पत्ता', 'संपर्क', false, 16],
    'emergency' => ['आपत्कालीन संपर्क', 'संपर्क', false, 12],
    'biometric_pin' => ['Biometric PIN', 'कार्यालयीन', false, 6],
    'aadhar_number' => ['आधार', 'कार्यालयीन', true, 10],
    'uan_no' => ['UAN (PF)', 'कार्यालयीन', true, 9],
    'esic_no' => ['ESIC', 'कार्यालयीन', true, 9],
    'bank_name' => ['बँक', 'बँक व पगार', true, 9],
    'bank_account_no' => ['खाते क्रमांक', 'बँक व पगार', true, 11],
    'ifsc_code' => ['IFSC', 'बँक व पगार', true, 8],
    'salary' => ['पगार', 'बँक व पगार', true, 7],
    'status' => ['स्थिती', 'इतर', false, 6],
    'sign' => ['सही', 'इतर', false, 12],
    'remark' => ['शेरा', 'इतर', false, 12],
];
if (!$canSensitive) { $COLS = array_filter($COLS, fn($c) => !$c[2]); }
$PRESETS = [
    'basic' => ['📋 मूलभूत', ['name', 'employee_code', 'designation', 'teaching_mode', 'mobile', 'joining_date']],
    'contact' => ['📞 संपर्क यादी', ['photo', 'name', 'designation', 'mobile', 'whatsapp_number', 'email', 'local_address']],
    'sign' => ['✍️ सही पत्रक', ['name', 'employee_code', 'designation', 'sign']],
    'personal' => ['🪪 वैयक्तिक', ['photo', 'name', 'father_name', 'gender', 'date_of_birth', 'age', 'joining_date', 'service', 'mobile']],
];
if ($canSensitive) { $PRESETS['bank'] = ['🏦 बँक व पगार', ['name', 'employee_code', 'designation', 'bank_name', 'bank_account_no', 'ifsc_code', 'salary']]; }

// ---- Campus व विभाग (पाहण्याच्या अधिकारातले)
$st = $pdo->prepare("SELECT d.id, d.name, d.name_local, d.campus_id, c.name campus, c.college_type FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE d.organization_id = ? AND d.is_active = 1 ORDER BY c.name, d.name");
$st->execute([$orgId]);
$allDepts = array_values(array_filter($st->fetchAll(), fn($d) => $visibleDeptIds === null || in_array((int)$d['id'], $visibleDeptIds, true)));
$isTrustDept = fn($d) => $d['campus_id'] === null || $d['college_type'] === 'Trust HQ';
$campusOpts = [];
foreach ($allDepts as $d) {
    $k = $isTrustDept($d) ? 'trust' : (string)$d['campus_id'];
    $campusOpts[$k] = $k === 'trust' ? '🏛️ ट्रस्ट (संस्था-स्तरीय)' : '🏫 ' . $d['campus'];
}
uksort($campusOpts, fn($a, $b) => ($a === 'trust' ? -1 : ($b === 'trust' ? 1 : strcmp($campusOpts[$a], $campusOpts[$b]))));

// ---- निवड वाचणे
$g = $_GET;
$out = in_array($g['out'] ?? '', ['html', 'pdf'], true) ? $g['out'] : '';
$campus = isset($campusOpts[$g['campus'] ?? '']) ? $g['campus'] : '';
$deptSel = array_values(array_intersect(array_map('intval', (array)($g['dept'] ?? [])), array_map('intval', array_column($allDepts, 'id'))));
$status = in_array($g['status'] ?? '', ['active', 'inactive', 'all'], true) ? $g['status'] : 'active';
$tmode = in_array($g['tmode'] ?? '', ['Teaching', 'Non Teaching'], true) ? $g['tmode'] : '';
$group = in_array($g['group'] ?? '', ['campus', 'dept', 'none'], true) ? $g['group'] : 'campus';
$sort = in_array($g['sort'] ?? '', ['name', 'employee_code', 'designation', 'joining_date'], true) ? $g['sort'] : 'name';
$cols = array_values(array_filter((array)($g['cols'] ?? []), fn($c) => isset($COLS[$c])));
if (!$cols) { $cols = $PRESETS['basic'][1]; }
$orient = in_array($g['orient'] ?? '', ['P', 'L'], true) ? $g['orient'] : (count($cols) > 7 ? 'L' : 'P');
$title = trim(mb_substr((string)($g['title'] ?? ''), 0, 120)) ?: 'कर्मचारी यादी';
$maskAadhar = ($g['mask'] ?? '1') === '1';
$pageBreak = !empty($g['pb']);
$showSummary = ($g['summary'] ?? '1') === '1';
$footerSign = ($g['fsign'] ?? '1') === '1';
$fontSz = in_array($g['fs'] ?? '', ['s', 'm', 'l'], true) ? $g['fs'] : 'm';

if ($out) {
    // ---- कर्मचारी
    $deptIds = $deptSel ?: array_map(fn($d) => (int)$d['id'], array_filter($allDepts, fn($d) => $campus === '' || ($campus === 'trust' ? $isTrustDept($d) : (string)$d['campus_id'] === $campus)));
    $sql = "SELECT u.*, d.name dept_name, d.name_local dept_local, d.campus_id dept_campus, c.name campus_name, c.college_type, uc.name ucampus
        FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = d.campus_id LEFT JOIN campuses uc ON uc.id = u.campus_id
        WHERE u.organization_id = ? AND u.role <> 'super_admin'";
    $p = [$orgId];
    $noDeptOk = !$deptSel && $campus === '' && $visibleDeptIds === null; // सगळी संस्था — विभाग नसलेलेही
    if ($deptIds) { $sql .= " AND (u.department_id IN (" . implode(',', array_fill(0, count($deptIds), '?')) . ")" . ($noDeptOk ? " OR u.department_id IS NULL" : '') . ")"; $p = array_merge($p, $deptIds); }
    elseif (!$noDeptOk) { $sql .= " AND 1 = 0"; }
    if ($status !== 'all') { $sql .= " AND u.is_active = ?"; $p[] = $status === 'active' ? 1 : 0; }
    if ($tmode) { $sql .= " AND u.teaching_mode = ?"; $p[] = $tmode; }
    $sql .= " ORDER BY " . ['name' => 'u.name', 'employee_code' => 'LENGTH(u.employee_code), u.employee_code, u.name', 'designation' => 'u.designation, u.name', 'joining_date' => 'u.joining_date IS NULL, u.joining_date, u.name'][$sort];
    $st = $pdo->prepare($sql); $st->execute($p);
    $people = $st->fetchAll();

    // ---- गट
    $groups = [];
    foreach ($people as $u) {
        $trust = !$u['department_id'] || $u['dept_campus'] === null || $u['college_type'] === 'Trust HQ';
        $cName = $trust ? 'ट्रस्ट (संस्था-स्तरीय)' : $u['campus_name'];
        $dName = $u['dept_name'] ?: '— विभाग नाही —';
        $k1 = $group === 'none' ? '' : ($group === 'campus' ? ($trust ? '0' : '1') . $cName : $dName);
        $k2 = $group === 'campus' ? $dName : '';
        $groups[$k1]['title'] = $group === 'campus' ? $cName : ($group === 'dept' ? $dName . ($cName ? ' — ' . $cName : '') : '');
        $groups[$k1]['subs'][$k2][] = $u;
    }
    ksort($groups, SORT_NATURAL);
    foreach ($groups as &$G) { ksort($G['subs'], SORT_NATURAL); } unset($G);

    $org = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ?"); $org->execute([$orgId]); $org = $org->fetch() ?: ['name' => activeOrgName()];
    $esc = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $dmy = fn($d) => $d && $d !== '0000-00-00' ? date('d/m/Y', strtotime($d)) : '';
    $years = fn($d) => $d && $d !== '0000-00-00' ? (int)(new DateTime($d))->diff(new DateTime('today'))->y : '';
    $photoSrc = function ($path) use ($out) {
        if (!$path) return '';
        $rel = ltrim(preg_replace('#^(\.\./)+#', '', (string)$path), '/');
        $file = __DIR__ . '/../' . $rel;
        if (!is_file($file)) return '';
        return $out === 'pdf' ? $file : '../' . $rel;
    };
    $cell = function ($k, $u) use ($esc, $dmy, $years, $photoSrc, $maskAadhar) {
        switch ($k) {
            case 'photo': $s = $photoSrc($u['photo_path']); return $s ? '<img src="' . $esc($s) . '" class="ph">' : '<span class="ini">' . $esc(mb_strtoupper(mb_substr(trim($u['name']), 0, 1))) . '</span>';
            case 'name': return '<b>' . $esc($u['name']) . '</b>';
            case 'dept': return $esc($u['dept_name']);
            case 'campus': return $esc(!$u['department_id'] || $u['dept_campus'] === null || $u['college_type'] === 'Trust HQ' ? 'ट्रस्ट' : $u['campus_name']);
            case 'date_of_birth': case 'joining_date': return $dmy($u[$k]);
            case 'age': return $years($u['date_of_birth']);
            case 'service': return $years($u['joining_date']);
            case 'emergency': return $esc(trim($u['emergency_contact_name'] . ($u['emergency_contact_relation'] ? ' (' . $u['emergency_contact_relation'] . ')' : '') . ' ' . $u['emergency_contact_mobile']));
            case 'aadhar_number': $a = preg_replace('/\D/', '', (string)$u['aadhar_number']); return $a === '' ? '' : ($maskAadhar ? 'XXXX XXXX ' . substr($a, -4) : trim(chunk_split($a, 4, ' ')));
            case 'salary': return $u['salary'] !== null && $u['salary'] !== '' ? '₹' . number_format((float)$u['salary']) : '';
            case 'status': return (int)$u['is_active'] ? 'सक्रिय' : 'निष्क्रिय';
            case 'sign': case 'remark': return '';
            default: return $esc($u[$k] ?? '');
        }
    };

    // ---- दस्तऐवज (Print व PDF दोन्हीसाठी एकच HTML)
    $fs = ['s' => 8, 'm' => 9, 'l' => 10.5][$fontSz];
    $wsum = array_sum(array_map(fn($c) => $COLS[$c][3], $cols)) + 4;
    $filterTxt = [];
    $filterTxt[] = $deptSel ? count($deptSel) . ' विभाग' : ($campus !== '' ? strip_tags($campusOpts[$campus]) : 'संपूर्ण संस्था');
    if ($tmode) $filterTxt[] = $tmode;
    $filterTxt = array_map(fn($t) => trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $t)), $filterTxt);
    $filterTxt[] = ['active' => 'सक्रिय', 'inactive' => 'निष्क्रिय', 'all' => 'सक्रिय + निष्क्रिय'][$status];
    $html = '<style>
      body{font-family:freesans,"Noto Sans Devanagari","Segoe UI",sans-serif;color:#1A1D29;font-size:' . $fs . 'pt;}
      .hdr{background:#191C3A;color:#fff;padding:12px 16px;border-bottom:3.5px solid #D9530F;}
      .hdr .o{font-size:15pt;font-weight:bold;} .hdr .t{font-size:11pt;color:#FDBA8C;margin-top:2px;font-weight:bold;} .hdr .p{font-size:8.5pt;color:#C9CCE0;margin-top:3px;}
      table.sm{width:100%;border-collapse:collapse;margin:10px 0 4px;} table.sm td{border:1px solid #EAE5DF;background:#F7F5F2;padding:5px 8px;font-size:8.5pt;}
      table.sm b{font-size:12pt;color:#D9530F;}
      h2{font-size:12pt;color:#191C3A;margin:14px 0 4px;padding:5px 8px;background:#F3F1EE;border-left:4px solid #D9530F;} h2 .n{font-size:8.5pt;color:#64708A;font-weight:normal;}
      h3{font-size:10pt;color:#475069;margin:10px 0 3px;} h3 .n{font-size:8pt;color:#8A90AC;font-weight:normal;}
      table.g{width:100%;border-collapse:collapse;table-layout:fixed;}
      table.g th{background:#191C3A;color:#fff;font-weight:bold;text-align:left;padding:4px 5px;font-size:' . ($fs - .5) . 'pt;border:0.5pt solid #191C3A;}
      table.g td{padding:4px 5px;border:0.5pt solid #DDD6CE;vertical-align:middle;}
      table.g tr.z td{background:#FAF8F6;}
      td.sr{text-align:center;color:#64708A;width:4%;}
      td.c-sign,td.c-remark{height:' . (in_array('photo', $cols, true) ? 8 : 7) . 'mm;}
      img.ph{width:9mm;height:10.5mm;border-radius:1mm;}
      .ini{display:inline-block;width:8mm;height:8mm;line-height:8mm;text-align:center;background:#FDE7D9;color:#D9530F;font-weight:bold;border-radius:4mm;}
      .fsig{width:100%;margin-top:22px;} .fsig td{width:33%;text-align:center;padding-top:26px;font-size:9pt;color:#475069;} .fsig span{border-top:0.8pt solid #64708A;padding:3px 18px 0;}
      .pb{page-break-before:always;}
    </style>';
    $html .= '<div class="hdr"><div class="o">' . $esc($org['name']) . '</div><div class="t">' . $esc($title) . '</div><div class="p">' . $esc(implode(' · ', $filterTxt)) . ' · एकूण ' . count($people) . ' कर्मचारी · ' . date('d/m/Y h:i A') . '</div></div>';
    if ($showSummary && $people && $group !== 'none') {
        $tTot = count($people); $tTeach = count(array_filter($people, fn($u) => $u['teaching_mode'] === 'Teaching'));
        $tM = count(array_filter($people, fn($u) => $u['gender'] === 'Male')); $tF = count(array_filter($people, fn($u) => $u['gender'] === 'Female'));
        $html .= '<table class="sm"><tr><td>एकूण<br><b>' . $tTot . '</b></td><td>' . ($group === 'campus' ? 'College / School' : 'विभाग') . '<br><b>' . count($groups) . '</b></td><td>Teaching<br><b>' . $tTeach . '</b></td><td>Non Teaching<br><b>' . ($tTot - $tTeach) . '</b></td>'
            . ($tM + $tF ? '<td>पुरुष / स्त्री<br><b>' . $tM . ' / ' . $tF . '</b></td>' : '') . '</tr></table>';
    }
    $thead = '<thead><tr><th style="width:4%;text-align:center;">क्र.</th>';
    foreach ($cols as $c) { $thead .= '<th style="width:' . round($COLS[$c][3] * 96 / $wsum, 1) . '%">' . $esc($COLS[$c][0]) . '</th>'; }
    $thead .= '</tr></thead>';
    $table = function ($list) use ($thead, $cols, $cell) {
        $t = '<table class="g" repeat_header="1">' . $thead . '<tbody>';
        foreach ($list as $i => $u) {
            $t .= '<tr' . ($i % 2 ? ' class="z"' : '') . '><td class="sr">' . ($i + 1) . '</td>';
            foreach ($cols as $c) { $t .= '<td class="c-' . $c . '">' . $cell($c, $u) . '</td>'; }
            $t .= '</tr>';
        }
        return $t . '</tbody></table>';
    };
    if (!$people) { $html .= '<p style="padding:20px;text-align:center;color:#8A90AC;">निवडलेल्या Filter मध्ये कोणीही कर्मचारी नाही.</p>'; }
    $gi = 0;
    foreach ($groups as $G) {
        $n = array_sum(array_map('count', $G['subs']));
        if ($G['title'] !== '') { $html .= '<h2' . ($pageBreak && $gi ? ' class="pb"' : '') . '>' . $esc($G['title']) . ' <span class="n">(' . $n . ' कर्मचारी' . ($group === 'campus' ? ' · ' . count($G['subs']) . ' विभाग' : '') . ')</span></h2>'; }
        foreach ($G['subs'] as $sk => $list) {
            if ($sk !== '') { $html .= '<h3>' . $esc($sk) . ' <span class="n">(' . count($list) . ')</span></h3>'; }
            $html .= $table($list);
        }
        $gi++;
    }
    if ($footerSign && $people) { $html .= '<table class="fsig"><tr><td><span>तयार करणारा</span></td><td><span>तपासणारा</span></td><td><span>प्रमुख / प्राचार्य</span></td></tr></table>'; }

    $fname = 'karmachari-yadi-' . date('Y-m-d');
    logActivity('कर्मचारी यादी ' . ($out === 'pdf' ? 'PDF' : 'Print'), 'user', null, count($people) . ' कर्मचारी · ' . implode(', ', array_map(fn($c) => $COLS[$c][0], $cols)));
    if ($out === 'pdf') {
        require_once __DIR__ . '/../includes/weekly-report.php';
        $bytes = mgmtReportPdf($html, $title . ' — ' . $org['name'], $org['name'], 'A4' . ($orient === 'L' ? '-L' : ''), ['orgId' => $orgId, 'subtitle' => $title, 'badgeMm' => 14]);
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . (!empty($g['dl']) ? 'attachment' : 'inline') . '; filename="' . $fname . '.pdf"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    $pdfQs = http_build_query(array_merge($g, ['out' => 'pdf', 'dl' => 1]));
    ?><!DOCTYPE html>
<html lang="mr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $esc($title) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @page{size:A4 <?= $orient === 'L' ? 'landscape' : 'portrait' ?>;margin:10mm 9mm 12mm;}
  html{background:#E9E6E2;} body{margin:0;}
  .tb{position:sticky;top:0;z-index:5;background:#191C3A;padding:10px 16px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .tb a,.tb button{font-family:inherit;font-size:13px;border-radius:8px;padding:8px 14px;border:0;cursor:pointer;text-decoration:none;font-weight:600;}
  .tb .bk{color:#fff;background:transparent;border:1px solid #475069;} .tb .pr{background:#D9530F;color:#fff;} .tb .pd{background:#fff;color:#191C3A;}
  .tb .info{color:#C9CCE0;font-size:12px;margin-left:auto;}
  .sheet{background:#fff;max-width:<?= $orient === 'L' ? '1120px' : '800px' ?>;margin:18px auto;padding:22px 24px;box-shadow:0 4px 20px rgba(0,0,0,.12);overflow-x:auto;}
  img.ph{width:34px !important;height:40px !important;object-fit:cover;}
  .ini{width:30px !important;height:30px !important;line-height:30px !important;border-radius:50% !important;}
  table.g thead{display:table-header-group;} table.g tr{page-break-inside:avoid;}
  h2,h3{page-break-after:avoid;}
  .hdr{-webkit-print-color-adjust:exact;print-color-adjust:exact;} table.g th,table.g tr.z td,h2,table.sm td,.ini{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  @media print{ html{background:#fff;} .tb{display:none;} .sheet{box-shadow:none;margin:0;padding:0;max-width:none;overflow:visible;} }
  @media (max-width:700px){ .sheet{margin:8px;padding:12px;} }
</style></head>
<body>
<div class="tb">
  <a class="bk" href="list-print.php?<?= $esc(http_build_query(array_diff_key($g, ['out' => 1]))) ?>">← निवड बदला</a>
  <button class="pr" onclick="window.print()">🖨️ Print</button>
  <a class="pd" href="list-print.php?<?= $esc($pdfQs) ?>">⬇ PDF Download</a>
  <span class="info"><?= count($people) ?> कर्मचारी · <?= count($cols) ?> स्तंभ · A4 <?= $orient === 'L' ? 'आडवे' : 'उभे' ?></span>
</div>
<div class="sheet"><?= $html ?></div>
</body></html>
<?php
    exit;
}

// ================= निवडीचे पान =================
$pageTitle = 'कर्मचारी यादी Print / PDF';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$colGroups = [];
foreach ($COLS as $k => $c) { $colGroups[$c[1]][$k] = $c; }
$deptByCampus = [];
foreach ($allDepts as $d) { $deptByCampus[$isTrustDept($d) ? 'trust' : (string)$d['campus_id']][] = $d; }
?>
<div class="page-eyebrow"><a href="index.php" style="color:inherit;text-decoration:none;">← कर्मचारी</a></div>
<div class="page-title mb-1">🖨️ कर्मचारी यादी — Print / PDF</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">College / School किंवा विभाग निवडा, यादीत कोणती माहिती हवी ते टिक करा आणि Print किंवा PDF काढा. तुमची निवड पुढच्या वेळीसाठी लक्षात ठेवली जाते.</div>

<form method="get" id="lpForm" target="_blank" class="row g-3">
  <div class="col-lg-5">
    <div class="panel lp-box">
      <div class="lp-h">1️⃣ कोणाची यादी</div>
      <label class="lp-l">College / School</label>
      <select name="campus" id="lpCampus" class="form-select-custom">
        <option value="">🏛️ संपूर्ण संस्था (सर्व)</option>
        <?php foreach ($campusOpts as $k => $l): ?><option value="<?= h($k) ?>" <?= $campus === (string)$k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
      </select>
      <label class="lp-l">विभाग <small>(काहीच टिक न केल्यास वरच्या निवडीतले सर्व)</small></label>
      <div class="lp-depts" id="lpDepts">
        <?php foreach ($deptByCampus as $ck => $ds): ?>
          <div class="lp-dg" data-c="<?= h($ck) ?>"><div class="lp-dgt"><?= h($campusOpts[$ck] ?? '') ?> <a href="#" data-all="<?= h($ck) ?>">सर्व</a></div>
            <?php foreach ($ds as $d): ?><label><input type="checkbox" name="dept[]" value="<?= (int)$d['id'] ?>" <?= in_array((int)$d['id'], $deptSel, true) ? 'checked' : '' ?>> <?= h($d['name']) ?></label><?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="row g-2 mt-1">
        <div class="col-6"><label class="lp-l">स्थिती</label><select name="status" class="form-select-custom"><option value="active">सक्रिय</option><option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>निष्क्रिय</option><option value="all" <?= $status === 'all' ? 'selected' : '' ?>>सर्व</option></select></div>
        <div class="col-6"><label class="lp-l">प्रकार</label><select name="tmode" class="form-select-custom"><option value="">सर्व</option><option value="Teaching" <?= $tmode === 'Teaching' ? 'selected' : '' ?>>Teaching</option><option value="Non Teaching" <?= $tmode === 'Non Teaching' ? 'selected' : '' ?>>Non Teaching</option></select></div>
        <div class="col-6"><label class="lp-l">गट करा</label><select name="group" class="form-select-custom"><option value="campus">College / School → विभाग</option><option value="dept" <?= $group === 'dept' ? 'selected' : '' ?>>फक्त विभागनिहाय</option><option value="none" <?= $group === 'none' ? 'selected' : '' ?>>गट नको (एक यादी)</option></select></div>
        <div class="col-6"><label class="lp-l">क्रम</label><select name="sort" class="form-select-custom"><option value="name">नावानुसार</option><option value="employee_code" <?= $sort === 'employee_code' ? 'selected' : '' ?>>Emp Id नुसार</option><option value="designation" <?= $sort === 'designation' ? 'selected' : '' ?>>पदानुसार</option><option value="joining_date" <?= $sort === 'joining_date' ? 'selected' : '' ?>>रुजू तारखेनुसार</option></select></div>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="panel lp-box">
      <div class="lp-h">2️⃣ कोणती माहिती छापायची <span class="lp-cnt" id="lpCnt"></span></div>
      <div class="lp-presets">
        <?php foreach ($PRESETS as $k => [$l, $pc]): ?><button type="button" data-p="<?= h(implode(',', $pc)) ?>"><?= h($l) ?></button><?php endforeach; ?>
        <button type="button" data-p="" class="clr">✖ सर्व काढा</button>
      </div>
      <?php foreach ($colGroups as $gname => $cs): ?>
        <div class="lp-cg"><div class="lp-cgt"><?= h($gname) ?><?= $gname === 'बँक व पगार' || $gname === 'कार्यालयीन' ? ' <small>🔒 गोपनीय</small>' : '' ?></div>
          <?php foreach ($cs as $k => $c): ?><label class="lp-chip"><input type="checkbox" name="cols[]" value="<?= h($k) ?>" <?= in_array($k, $cols, true) ? 'checked' : '' ?>><span><?= h($c[0]) ?></span></label><?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <div class="lp-order" id="lpOrder" title="क्रम बदलण्यासाठी ओढा"></div>
    </div>

    <div class="panel lp-box mt-3">
      <div class="lp-h">3️⃣ दिसणं</div>
      <div class="row g-2">
        <div class="col-md-7"><label class="lp-l">शीर्षक</label><input type="text" name="title" maxlength="120" class="form-control-custom" value="<?= h($title) ?>"></div>
        <div class="col-6 col-md-3"><label class="lp-l">पान</label><select name="orient" class="form-select-custom"><option value="">आपोआप</option><option value="P" <?= ($g['orient'] ?? '') === 'P' ? 'selected' : '' ?>>A4 उभे</option><option value="L" <?= ($g['orient'] ?? '') === 'L' ? 'selected' : '' ?>>A4 आडवे</option></select></div>
        <div class="col-6 col-md-2"><label class="lp-l">अक्षर</label><select name="fs" class="form-select-custom"><option value="s" <?= $fontSz === 's' ? 'selected' : '' ?>>लहान</option><option value="m" <?= $fontSz === 'm' ? 'selected' : '' ?>>मध्यम</option><option value="l" <?= $fontSz === 'l' ? 'selected' : '' ?>>मोठे</option></select></div>
      </div>
      <div class="lp-opts">
        <label><input type="hidden" name="summary" value="0"><input type="checkbox" name="summary" value="1" <?= $showSummary ? 'checked' : '' ?>> वर एकूण सारांश</label>
        <label><input type="checkbox" name="pb" value="1" <?= $pageBreak ? 'checked' : '' ?>> प्रत्येक गट नवीन पानावर</label>
        <label><input type="hidden" name="fsign" value="0"><input type="checkbox" name="fsign" value="1" <?= $footerSign ? 'checked' : '' ?>> खाली सही (तयार करणारा / प्रमुख)</label>
        <?php if ($canSensitive): ?><label><input type="hidden" name="mask" value="0"><input type="checkbox" name="mask" value="1" <?= $maskAadhar ? 'checked' : '' ?>> आधार क्रमांक लपवा (शेवटचे 4)</label><?php endif; ?>
      </div>
    </div>
    <div class="lp-go">
      <button type="submit" name="out" value="html" class="btn lp-btn1">🖨️ Print पहा</button>
      <button type="submit" name="out" value="pdf" class="btn lp-btn2">📄 PDF</button>
    </div>
  </div>
</form>
<style>
  .lp-box{padding:16px 18px;}
  .lp-h{font-weight:700;font-size:14px;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
  .lp-cnt{font-size:11.5px;font-weight:600;color:var(--rail);background:var(--canvas);border-radius:20px;padding:2px 9px;}
  .lp-l{font-size:12px;color:var(--text-dim);font-weight:600;margin:10px 0 4px;display:block;} .lp-l small{font-weight:400;}
  .lp-depts{max-height:260px;overflow:auto;border:1px solid var(--line);border-radius:10px;padding:6px 10px;}
  .lp-dgt{font-size:11.5px;font-weight:700;color:var(--text-dim);margin:6px 0 3px;display:flex;justify-content:space-between;} .lp-dgt a{color:var(--rail);font-weight:600;text-decoration:none;}
  .lp-depts label{display:inline-flex;align-items:center;gap:5px;font-size:12.5px;margin:2px 12px 2px 0;}
  .lp-presets{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:10px;}
  .lp-presets button{border:1px solid var(--line);background:var(--surface,#fff);border-radius:20px;padding:4px 12px;font-size:12px;font-weight:600;color:var(--text);}
  .lp-presets button:hover{border-color:var(--rail);color:var(--rail);} .lp-presets .clr{color:var(--text-dim);}
  .lp-cg{margin-top:8px;} .lp-cgt{font-size:11px;font-weight:700;color:var(--text-dim);text-transform:uppercase;margin-bottom:4px;} .lp-cgt small{text-transform:none;color:#B45309;}
  .lp-chip{display:inline-block;margin:0 5px 6px 0;cursor:pointer;} .lp-chip input{display:none;}
  .lp-chip span{display:inline-block;border:1px solid var(--line);border-radius:8px;padding:4px 10px;font-size:12.5px;background:var(--surface,#fff);user-select:none;}
  .lp-chip input:checked + span{background:var(--rail);border-color:var(--rail);color:#fff;font-weight:600;}
  .lp-order{display:flex;flex-wrap:wrap;gap:5px;margin-top:10px;padding-top:10px;border-top:1px dashed var(--line);min-height:20px;}
  .lp-order:before{content:'क्रम (ओढून बदला):';font-size:11px;color:var(--text-dim);width:100%;}
  .lp-order span{font-size:11.5px;background:var(--canvas);border-radius:6px;padding:3px 8px;cursor:grab;} .lp-order span.drag{opacity:.4;}
  .lp-opts{display:flex;flex-wrap:wrap;gap:6px 18px;margin-top:12px;font-size:12.5px;} .lp-opts label{display:inline-flex;gap:6px;align-items:center;}
  .lp-go{display:flex;gap:10px;margin-top:14px;justify-content:flex-end;}
  .lp-btn1{background:var(--rail);color:#fff;border-radius:10px;padding:10px 22px;font-weight:700;} .lp-btn2{border:1.5px solid var(--rail);color:var(--rail);border-radius:10px;padding:10px 22px;font-weight:700;}
  @media (max-width:600px){ .lp-go{position:sticky;bottom:70px;background:var(--canvas);padding:8px 0;} .lp-go .btn{flex:1;} }
</style>
<script>
(function () {
  var f = document.getElementById('lpForm'), camp = document.getElementById('lpCampus'), KEY = 'eowms_listprint';
  var boxes = function () { return Array.prototype.slice.call(f.querySelectorAll('input[name="cols[]"]')); };
  var order = <?= json_encode(array_values($cols)) ?>; // निवडलेले स्तंभ — क्रमाने
  function syncOrder() {
    var on = boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
    order = order.filter(function (k) { return on.indexOf(k) !== -1; });
    on.forEach(function (k) { if (order.indexOf(k) === -1) order.push(k); });
    var o = document.getElementById('lpOrder'); o.innerHTML = '';
    order.forEach(function (k) {
      var lbl = f.querySelector('input[name="cols[]"][value="' + k + '"]').nextElementSibling.textContent;
      var s = document.createElement('span'); s.textContent = lbl; s.draggable = true; s.dataset.k = k; o.appendChild(s);
    });
    document.getElementById('lpCnt').textContent = order.length + ' स्तंभ';
  }
  var dragK = null;
  document.getElementById('lpOrder').addEventListener('dragstart', function (e) { dragK = e.target.dataset.k; e.target.classList.add('drag'); });
  document.getElementById('lpOrder').addEventListener('dragover', function (e) { e.preventDefault(); });
  document.getElementById('lpOrder').addEventListener('drop', function (e) {
    e.preventDefault(); var t = e.target.dataset && e.target.dataset.k; if (!dragK || !t || t === dragK) return;
    order.splice(order.indexOf(dragK), 1); order.splice(order.indexOf(t), 0, dragK); dragK = null; syncOrder();
  });
  f.addEventListener('change', function (e) { if (e.target.name === 'cols[]') syncOrder(); });
  f.querySelectorAll('.lp-presets button').forEach(function (b) {
    b.addEventListener('click', function () {
      var want = b.dataset.p ? b.dataset.p.split(',') : [];
      boxes().forEach(function (x) { x.checked = want.indexOf(x.value) !== -1; });
      order = want.slice(); syncOrder();
    });
  });
  function filterDepts() {
    var c = camp.value;
    f.querySelectorAll('.lp-dg').forEach(function (g) {
      var show = !c || g.dataset.c === c; g.style.display = show ? '' : 'none';
      if (!show) g.querySelectorAll('input').forEach(function (i) { i.checked = false; });
    });
  }
  camp.addEventListener('change', filterDepts);
  f.querySelectorAll('[data-all]').forEach(function (a) {
    a.addEventListener('click', function (e) { e.preventDefault(); var ins = a.closest('.lp-dg').querySelectorAll('input'); var all = Array.prototype.every.call(ins, function (i) { return i.checked; }); ins.forEach(function (i) { i.checked = !all; }); });
  });
  // पाठवण्याआधी स्तंभ क्रमाने (hidden inputs) + निवड लक्षात ठेवणे
  f.addEventListener('submit', function () {
    f.querySelectorAll('input[data-ord]').forEach(function (x) { x.remove(); });
    boxes().forEach(function (b) { b.disabled = true; });
    order.forEach(function (k) { var i = document.createElement('input'); i.type = 'hidden'; i.name = 'cols[]'; i.value = k; i.dataset.ord = 1; f.appendChild(i); });
    setTimeout(function () { boxes().forEach(function (b) { b.disabled = false; }); }, 50);
    try { localStorage.setItem(KEY, JSON.stringify({ cols: order, q: new URLSearchParams(new FormData(f)).toString() })); } catch (x) {}
  });
  // मागची निवड (URL मध्ये काही नसेल तर)
  if (location.search.indexOf('cols') === -1) {
    try {
      var sv = JSON.parse(localStorage.getItem(KEY) || 'null');
      if (sv && sv.cols && sv.cols.length) { boxes().forEach(function (x) { x.checked = sv.cols.indexOf(x.value) !== -1; }); order = sv.cols.filter(function (k) { return f.querySelector('input[name="cols[]"][value="' + k + '"]'); }); }
      if (sv && sv.q) {
        var q = new URLSearchParams(sv.q);
        ['campus', 'status', 'tmode', 'group', 'sort', 'orient', 'fs', 'title'].forEach(function (n) { var el = f.elements[n]; if (el && q.get(n) !== null) el.value = q.get(n); });
        var ds = q.getAll('dept[]'); f.querySelectorAll('input[name="dept[]"]').forEach(function (i) { i.checked = ds.indexOf(i.value) !== -1; });
        ['summary', 'fsign', 'mask', 'pb'].forEach(function (n) { var c = f.querySelector('input[type=checkbox][name="' + n + '"]'); if (c) c.checked = q.getAll(n).indexOf('1') !== -1; });
      }
    } catch (x) {}
  }
  filterDepts(); syncOrder();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
