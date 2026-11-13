<?php
// 📋 वाहन कागदपत्र अहवाल — परिवहन विभागाच्या Excel/PDF सारखा: Permit / Insurance / MV Tax / Green Tax / Fitness / PUC (From–To), सह्यांसह
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/vehicle-helpers.php';
$user = currentUser();
$orgId = activeOrgId();
$st = $pdo->prepare("SELECT code FROM departments WHERE id = ? AND organization_id = ?");
$st->execute([$user['department_id'] ?? 0, $orgId]);
if (!canManageVehicles() && ($st->fetchColumn() ?: '') !== 'TRP' && !isManagementOrAbove()) { http_response_code(403); die('हा अहवाल फक्त परिवहन विभाग व व्यवस्थापनासाठी.'); }

$g = $_GET;
$deptF = (int)($g['dept'] ?? 0);
$showCost = !empty($g['cost']);
$inactive = !empty($g['inactive']);
$sig = [];
foreach ([1 => 'Transport Head', 2 => 'Manager General Admin', 3 => 'Hon. Executive President'] as $i => $def) { $sig[$i] = trim(mb_substr((string)($g['s' . $i] ?? $def), 0, 60)); }

$dSt = $pdo->prepare("SELECT DISTINCT d.id, d.name, c.name campus FROM vehicles v JOIN departments d ON d.id = v.department_id LEFT JOIN campuses c ON c.id = d.campus_id WHERE v.organization_id = ? ORDER BY c.name, d.name");
$dSt->execute([$orgId]);
$deptOpts = $dSt->fetchAll();

$sql = "SELECT v.*, c.name campus FROM vehicles v LEFT JOIN campuses c ON c.id = v.campus_id WHERE v.organization_id = ?";
$p = [$orgId];
if (!$inactive) { $sql .= " AND v.is_active = 1"; }
if ($deptF) { $sql .= " AND v.department_id = ?"; $p[] = $deptF; }
$sql .= " ORDER BY v.id";
$st = $pdo->prepare($sql); $st->execute($p);
$vehicles = $st->fetchAll();

$ren = [];
if ($vehicles) {
    $ids = array_column($vehicles, 'id');
    $st = $pdo->prepare("SELECT vehicle_id, renewal_field, issued_date, expiry_date, renewal_cost FROM renewal_items WHERE vehicle_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    foreach ($st as $r) { $ren[(int)$r['vehicle_id']][$r['renewal_field']] = $r; }
}
$DOCS = ['permit' => ['Permit', 'permit_validity'], 'insurance' => ['Insurance', 'insurance_validity'], 'mv_tax' => ['MV Tax', 'mv_tax_validity'],
    'green_tax' => ['Green Tax', 'green_tax_validity'], 'fitness' => ['Fitness Certificate', 'fitness_validity'], 'puc' => ['PUC', 'puc_validity']];
$today = date('Y-m-d'); $soon = date('Y-m-d', strtotime('+30 days'));
$campuses = array_values(array_unique(array_filter(array_column($vehicles, 'campus'))));
$orgName = activeOrgName();
$title = count($campuses) === 1 ? mb_strtoupper($campuses[0]) : $orgName;
$esc = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
$dmy = fn($d) => $d ? date('j/n/Y', strtotime($d)) : '';
$cnt = ['exp' => 0, 'soon' => 0]; $totCost = 0;

// --- दस्तऐवज (Print व PDF साठी एकच HTML)
$fs = 7.4;
$html = '<style>
  body{font-family:freesans,"Noto Sans Devanagari","Times New Roman",serif;color:#000;}
  table.r{width:100%;border-collapse:collapse;font-size:' . $fs . 'pt;}
  table.r td,table.r th{border:0.6pt solid #000;padding:3px 3px;text-align:center;vertical-align:middle;}
  table.r th{font-weight:bold;background:#F2F2F2;}
  .ttl{font-weight:bold;font-size:9pt;text-align:center;} .dt{font-weight:bold;}
  td.vn{text-align:left;font-weight:bold;white-space:nowrap;} td.ty{font-weight:bold;}
  td.mono{font-size:' . ($fs - .6) . 'pt;word-break:break-all;}
  td.exp{background:#FAD4D4;color:#9B1C1C;font-weight:bold;} td.soon{background:#FDECC8;color:#92400E;font-weight:bold;}
  .sig{width:100%;margin-top:42px;font-size:8.5pt;font-weight:bold;} .sig td{text-align:center;width:33%;}
  .lg{font-size:7pt;color:#444;margin-top:6px;}
</style>';
$ncol = 6 + 12 + ($showCost ? 1 : 0);
$html .= '<table class="r" repeat_header="1"><thead>';
$html .= '<tr><td colspan="' . $ncol . '" class="ttl">' . $esc($title) . ' &nbsp; &nbsp; &nbsp; <span class="dt">Date ' . date('d-m-Y') . '</span></td></tr>';
$html .= '<tr><th rowspan="2" style="width:3%">Sr.No</th><th rowspan="2" style="width:8%">Vehical No</th><th rowspan="2" style="width:7%">Type of Vehical</th><th rowspan="2" style="width:4%">Seating Capacity</th><th rowspan="2" style="width:7%">Engine No.</th><th rowspan="2" style="width:8%">Chassis No.</th>';
foreach ($DOCS as [$l]) { $html .= '<th colspan="2">' . $l . '</th>'; }
if ($showCost) { $html .= '<th rowspan="2" style="width:5%">Insurance Cost</th>'; }
$html .= '</tr><tr>' . str_repeat('<th>From</th><th>To</th>', count($DOCS)) . '</tr></thead><tbody>';
$i = 0;
foreach ($vehicles as $v) {
    $i++;
    $html .= '<tr><td>' . $i . '</td><td class="vn">' . $esc($v['vehicle_number']) . '</td><td class="ty">' . $esc($v['vehicle_type']) . '</td><td>' . ($v['passenger_capacity'] ?: '') . '</td>'
        . '<td class="mono">' . $esc($v['engine_no']) . '</td><td class="mono">' . $esc($v['chassis_no']) . '</td>';
    $any = false;
    $life = array_filter(explode(',', (string)($v['lifetime_docs'] ?? '')));
    $hasDoc = (bool)$life;
    foreach ($DOCS as $k => [$l, $col]) { if (($ren[(int)$v['id']][$k] ?? null) || !empty($v[$col])) { $hasDoc = true; } }
    if (!$hasDoc && trim((string)($v['remarks'] ?? '')) !== '') { // कागदपत्र नाहीत — PDF प्रमाणे शेरा पूर्ण रुंदीत
        $html .= '<td colspan="12" style="font-weight:bold;">' . $esc($v['remarks']) . '</td>';
        if ($showCost) { $html .= '<td></td>'; }
        $html .= '</tr>';
        continue;
    }
    foreach ($DOCS as $k => [$l, $col]) {
        if (in_array($k, $life, true)) { $html .= '<td colspan="2" style="font-weight:bold;">one time tax (LTT)</td>'; continue; }
        $r = $ren[(int)$v['id']][$k] ?? null;
        $to = $r['expiry_date'] ?? $v[$col] ?? null; $from = $r['issued_date'] ?? null;
        if ($to) $any = true;
        $cls = $to ? ($to < $today ? 'exp' : ($to <= $soon ? 'soon' : '')) : '';
        if ($cls === 'exp') $cnt['exp']++; elseif ($cls === 'soon') $cnt['soon']++;
        $html .= '<td>' . ($to ? $dmy($from) : 'NA') . '</td><td class="' . $cls . '">' . ($to ? $dmy($to) : 'NA') . '</td>';
    }
    if ($showCost) { $c = (float)($ren[(int)$v['id']]['insurance']['renewal_cost'] ?? 0); $totCost += $c; $html .= '<td>' . ($c ? number_format($c) : '') . '</td>'; }
    $html .= '</tr>';
}
if ($showCost && $vehicles) { $html .= '<tr><td colspan="' . ($ncol - 1) . '" style="text-align:right;font-weight:bold;">Total</td><td style="font-weight:bold;">' . number_format($totCost) . '</td></tr>'; }
if (!$vehicles) { $html .= '<tr><td colspan="' . $ncol . '">वाहन नाही</td></tr>'; }
$html .= '</tbody></table>';
$html .= '<div class="lg">लाल = मुदत संपलेली · पिवळी = 30 दिवसांत संपणार · NA = लागू नाही / नोंद नाही</div>';
$html .= '<table class="sig"><tr><td>' . $esc($sig[1]) . '</td><td>' . $esc($sig[2]) . '</td><td>' . $esc($sig[3]) . '</td></tr></table>';

if (($g['out'] ?? '') === 'pdf') {
    require_once __DIR__ . '/../lib/vendor/autoload.php';
    $tmp = __DIR__ . '/../storage/tmp/mpdf'; if (!is_dir($tmp)) { @mkdir($tmp, 0755, true); }
    $fontDir = (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'];
    $mpdf = new \Mpdf\Mpdf(['mode' => 'utf-8', 'format' => 'A4-L', 'tempDir' => $tmp, 'margin_left' => 7, 'margin_right' => 7, 'margin_top' => 8, 'margin_bottom' => 10,
        'fontDir' => $fontDir, 'fontdata' => ['freesans' => ['R' => 'FreeSans.ttf', 'B' => 'FreeSansBold.ttf', 'useOTL' => 0xFF]], 'default_font' => 'freesans']);
    $mpdf->SetTitle('Vehicle Documents — ' . $title);
    $mpdf->SetFooter('{PAGENO} / {nbpg}');
    $mpdf->WriteHTML($html);
    logActivity('वाहन कागदपत्र अहवाल PDF', 'vehicle', null, count($vehicles) . ' वाहनं');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="vehicle-documents-' . date('Y-m-d') . '.pdf"');
    echo $mpdf->Output('', \Mpdf\Output\Destination::STRING_RETURN);
    exit;
}
$qs = fn($o) => http_build_query(array_filter(array_merge(['dept' => $deptF ?: '', 'cost' => $showCost ? 1 : '', 'inactive' => $inactive ? 1 : '', 's1' => $sig[1], 's2' => $sig[2], 's3' => $sig[3]], $o), fn($v) => $v !== ''));
?><!DOCTYPE html>
<html lang="mr"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>वाहन कागदपत्र अहवाल</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  @page{size:A4 landscape;margin:8mm 7mm 10mm;}
  html{background:#E9E6E2;} body{margin:0;font-family:'Noto Sans Devanagari',sans-serif;}
  .tb{position:sticky;top:0;z-index:5;background:#191C3A;padding:10px 14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;color:#C9CCE0;font-size:12.5px;}
  .tb a,.tb button{font-family:inherit;font-size:13px;border-radius:8px;padding:7px 13px;border:0;cursor:pointer;text-decoration:none;font-weight:600;}
  .tb .bk{color:#fff;background:transparent;border:1px solid #475069;} .tb .pr{background:#D9530F;color:#fff;} .tb .pd{background:#fff;color:#191C3A;}
  .tb select,.tb input[type=text]{font-family:inherit;font-size:12.5px;border-radius:7px;border:1px solid #475069;background:#262B4D;color:#fff;padding:5px 8px;}
  .tb input[type=text]{width:150px;} .tb label{display:inline-flex;gap:5px;align-items:center;}
  .sum{margin-left:auto;} .sum b{color:#fff;} .sum .r{color:#FCA5A5;} .sum .a{color:#FCD34D;}
  .sheet{background:#fff;max-width:1180px;margin:16px auto;padding:18px;box-shadow:0 4px 20px rgba(0,0,0,.12);overflow-x:auto;}
  table.r{min-width:1080px;} table.r thead{display:table-header-group;} table.r tr{page-break-inside:avoid;}
  td.exp,td.soon,table.r th{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
  @media print{ html{background:#fff;} .tb{display:none;} .sheet{box-shadow:none;margin:0;padding:0;max-width:none;overflow:visible;} table.r{min-width:0;} }
</style></head><body>
<form class="tb" method="get">
  <a class="bk" href="index.php">← वाहने</a>
  <select name="dept" onchange="this.form.submit()"><option value="">सर्व विभाग</option><?php foreach ($deptOpts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $deptF === (int)$d['id'] ? 'selected' : '' ?>><?= $esc($d['name'] . ($d['campus'] ? ' — ' . $d['campus'] : '')) ?></option><?php endforeach; ?></select>
  <label><input type="checkbox" name="cost" value="1" <?= $showCost ? 'checked' : '' ?> onchange="this.form.submit()"> विमा खर्च</label>
  <label><input type="checkbox" name="inactive" value="1" <?= $inactive ? 'checked' : '' ?> onchange="this.form.submit()"> निष्क्रिय पण</label>
  <label>सही: <input type="text" name="s1" value="<?= $esc($sig[1]) ?>"><input type="text" name="s2" value="<?= $esc($sig[2]) ?>"><input type="text" name="s3" value="<?= $esc($sig[3]) ?>"></label>
  <button type="submit" class="bk">लागू</button>
  <button type="button" class="pr" onclick="window.print()">🖨️ Print</button>
  <a class="pd" href="docs-register.php?<?= $esc($qs(['out' => 'pdf'])) ?>">⬇ PDF</a>
  <span class="sum"><b><?= count($vehicles) ?></b> वाहने · <span class="r">🔴 <?= $cnt['exp'] ?> मुदत संपलेली</span> · <span class="a">🟡 <?= $cnt['soon'] ?> 30 दिवसांत</span></span>
</form>
<div class="sheet"><?= $html ?></div>
</body></html>
