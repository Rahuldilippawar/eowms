<?php
/**
 * 🚌 वाहन Import — CSV/Excel मधल्या ओळी तपासून (Preview) मग जोडणं/अद्ययावत करणं.
 * तारखा अनेक प्रकारे येतात: 25/11/2022, 24 NOV.2037, 2027-04-02, Excel-तारीख, NA, "one time tax (LTT)".
 * Excel ने dd/mm तारखा mm/dd म्हणून वाचल्या असतील (US पद्धत) तर ते ओळखून दुरुस्त करता येतं.
 */
require_once __DIR__ . '/vehicle-helpers.php';

const VEH_IMPORT_DATE_COLS = ['date_of_registration', 'permit_validity', 'puc_validity', 'insurance_validity', 'fitness_validity', 'road_tax_validity', 'green_tax_validity', 'mv_tax_validity'];
const VEH_IMPORT_RENEWAL_COLS = ['permit' => 'permit_validity', 'puc' => 'puc_validity', 'insurance' => 'insurance_validity', 'fitness' => 'fitness_validity',
    'road_tax' => 'road_tax_validity', 'green_tax' => 'green_tax_validity', 'mv_tax' => 'mv_tax_validity'];

function vehImportKey($number) { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$number)); }

/** वाहन क्रमांक — मोठी अक्षरं, जास्तीच्या जागा काढून */
function vehImportCleanNumber($number) { return strtoupper(trim(preg_replace('/\s+/', ' ', (string)$number))); }

function vehImportFuel($raw) {
    $f = strtolower((string)$raw);
    if (str_contains($f, 'cng')) { return 'cng'; }
    if (str_contains($f, 'diesel') || str_contains($f, 'डिझेल')) { return 'diesel'; }
    if (str_contains($f, 'petrol') || str_contains($f, 'पेट्रोल')) { return 'petrol'; }
    if (str_contains($f, 'electric') || preg_match('/\bev\b/', $f) || str_contains($f, 'इलेक्ट्रिक')) { return 'electric'; }
    return trim($f) === '' ? 'diesel' : 'other';
}

/**
 * एक तारीख समजून घेतो. परत: [Y-m-d|null, सूचना|null, 'ok'|'empty'|'lifetime'|'fixed'|'bad']
 * $swapExcel = Excel-तारखांचा दिवस/महिना अदलाबदल करायचा का
 */
function vehImportDate($raw, $swapExcel) {
    $mk = function ($y, $m, $d, $note = null) {
        $y = (int)$y; $m = (int)$m; $d = (int)$d;
        if ($y < 100) { $y += 2000; }
        if ($m < 1 || $m > 12 || $y < 1950 || $y > 2100 || $d < 1) { return [null, 'तारीख अवैध', 'bad']; }
        $last = (int)date('t', mktime(0, 0, 0, $m, 1, $y));
        if ($d > $last) { return [sprintf('%04d-%02d-%02d', $y, $m, $last), sprintf('%02d/%02d/%04d अशी तारीख नाही — %02d/%02d/%04d धरली', $d, $m, $y, $last, $m, $y), 'fixed']; }
        return [sprintf('%04d-%02d-%02d', $y, $m, $d), $note, $note ? 'fixed' : 'ok'];
    };
    if (is_float($raw) || is_int($raw)) { // Excel ची खरी तारीख (Serial)
        $n = (int)floor($raw);
        if ($n < 10000 || $n > 80000) { return [null, 'तारीख समजली नाही: ' . $raw, 'bad']; }
        $ts = strtotime('1899-12-30 +' . $n . ' days');
        [$y, $m, $d] = [(int)date('Y', $ts), (int)date('n', $ts), (int)date('j', $ts)];
        if ($swapExcel && $d <= 12 && $d !== $m) { return $mk($y, $d, $m, sprintf('Excel ने दिवस/महिना उलट वाचला — %02d/%02d/%04d धरली', $m, $d, $y)); }
        return $mk($y, $m, $d);
    }
    $s = trim((string)$raw);
    $l = mb_strtolower($s);
    if ($s === '' || in_array($l, ['na', 'n/a', 'n.a.', 'n.a', 'nil', '-', '—', 'no', 'none', 'not applicable', 'लागू नाही'], true)) { return [null, null, 'empty']; }
    if (preg_match('/ltt|life\s*time|lifetime|one\s*time|आजीवन/i', $s)) { return [null, 'आजीवन कर (LTT) — नूतनीकरण लागत नाही', 'lifetime']; }
    if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m)) { return $mk($m[1], $m[2], $m[3]); }
    if (preg_match('/^(\d{1,2})\s*[-\/.]\s*(\d{1,2})\s*[-\/.]\s*(\d{2,4})$/', $s, $m)) {
        [$a, $b, $y] = [(int)$m[1], (int)$m[2], $m[3]];
        if ($b > 12 && $a <= 12) { return $mk($y, $a, $b, sprintf('%s — महिना/दिवस उलट होते, दुरुस्त केलं', $s)); }
        return $mk($y, $b, $a); // भारतीय पद्धत: दिवस/महिना/वर्ष
    }
    $months = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];
    if (preg_match('/^(\d{1,2})[\s\-\.\/,]*([a-z]{3})[a-z]*[\s\-\.\/,]*(\d{2,4})$/i', $s, $m) && isset($months[strtolower($m[2])])) { return $mk($m[3], $months[strtolower($m[2])], $m[1]); }
    if (preg_match('/^([a-z]{3})[a-z]*[\s\-\.\/,]*(\d{1,2})[\s\-\.\/,]+(\d{2,4})$/i', $s, $m) && isset($months[strtolower($m[1])])) { return $mk($m[3], $months[strtolower($m[1])], $m[2]); }
    if (preg_match('/^\d{5}(\.\d+)?$/', $s)) { return vehImportDate((float)$s, $swapExcel); } // CSV मध्ये Excel-Serial
    return [null, 'तारीख समजली नाही: "' . $s . '"', 'bad'];
}

/** Excel ने dd/mm तारखा उलट (mm/dd) वाचल्या असण्याची शक्यता — File मधल्या पुराव्यावरून */
function vehImportDetectSwap(array $rows, array $colIndex) {
    $serial = 0; $serialBigDay = 0; $textDayFirst = 0;
    foreach ($rows as $row) {
        foreach (VEH_IMPORT_DATE_COLS as $col) {
            if (!isset($colIndex[$col])) { continue; }
            $v = $row[$colIndex[$col]] ?? '';
            if (is_float($v) || is_int($v)) {
                $serial++;
                if ((int)date('j', strtotime('1899-12-30 +' . (int)$v . ' days')) > 12) { $serialBigDay++; }
            } elseif (preg_match('/^(\d{1,2})\s*[-\/.]/', trim((string)$v), $m) && (int)$m[1] > 12) {
                $textDayFirst++;
            }
        }
    }
    // Excel-तारखा आहेत, त्यातल्या एकाचाही "दिवस" 12 पेक्षा मोठा नाही, आणि हाताने लिहिलेल्या तारखा dd/mm पद्धतीच्या
    return ['suggest' => $serial > 0 && $serialBigDay === 0 && $textDayFirst > 0, 'serial' => $serial, 'textDayFirst' => $textDayFirst];
}

/**
 * ओळी तपासतो. $opts = ['swap' => bool, 'fallback_dept' => id|0, 'update' => bool]
 * परत: ['items' => [...], 'missingCols' => [...], 'headerOk' => bool]
 */
function vehImportAnalyse(PDO $pdo, $orgId, array $rows, array $opts) {
    $header = array_map(fn($h) => strtolower(trim((string)$h)), $rows[0] ?? []);
    $colIndex = array_flip($header);
    // विभागाचा स्तंभ नसला तरी चालेल — Preview मध्ये निवडलेला विभाग (उदा. परिवहन) सगळ्यांना
    $missing = array_values(array_diff(isset($colIndex['department_name']) || !empty($opts['fallback_dept']) || !empty($opts['no_dept_ok']) ? ['vehicle_number'] : ['department_name', 'vehicle_number'], $header));
    if ($missing) { return ['items' => [], 'missingCols' => $missing, 'colIndex' => $colIndex]; }

    $depts = $pdo->prepare("SELECT id, name_local, name, campus_id FROM departments WHERE organization_id = ? AND is_active = 1");
    $depts->execute([$orgId]);
    $deptByName = []; $deptById = [];
    foreach ($depts->fetchAll() as $d) {
        $deptById[$d['id']] = $d;
        foreach ([$d['name_local'], $d['name']] as $n) { if ($n) { $deptByName[mb_strtolower(preg_replace('/\s+/u', ' ', trim($n)))] = $d; } }
    }
    $ex = $pdo->prepare("SELECT * FROM vehicles WHERE organization_id = ?");
    $ex->execute([$orgId]);
    $existing = [];
    foreach ($ex->fetchAll() as $v) { $existing[vehImportKey($v['vehicle_number'])] = $v; }

    $items = []; $seen = [];
    foreach (array_slice($rows, 1) as $i => $row) {
        $get = function ($col) use ($row, $colIndex) { return isset($colIndex[$col]) ? ($row[$colIndex[$col]] ?? '') : ''; };
        $str = fn($col) => trim(preg_replace('/\s+/u', ' ', (string)$get($col)));
        $number = vehImportCleanNumber($get('vehicle_number'));
        if ($number === '' && implode('', array_map('strval', $row)) === '') { continue; }
        $it = ['line' => $i + 2, 'number' => $number, 'key' => vehImportKey($number), 'errors' => [], 'notes' => [], 'dates' => [], 'dateNotes' => []];
        if ($number === '') { $it['errors'][] = 'वाहन क्रमांक रिकामा'; }
        elseif (isset($seen[$it['key']])) { $it['errors'][] = 'हाच वाहन क्रमांक File मध्ये ओळ ' . $seen[$it['key']] . ' वर आधीच आहे'; }
        $seen[$it['key']] = $it['line'];

        $deptName = $str('department_name');
        $dept = $deptByName[mb_strtolower($deptName)] ?? null;
        if (!$dept && !empty($opts['fallback_dept']) && isset($deptById[$opts['fallback_dept']])) {
            $dept = $deptById[$opts['fallback_dept']];
            if ($deptName !== '') { $it['notes'][] = '"' . $deptName . '" विभाग सापडला नाही — "' . ($dept['name_local'] ?: $dept['name']) . '" मध्ये'; }
        }
        if (!$dept) { $it['errors'][] = $deptName !== '' ? '"' . $deptName . '" हा विभाग सापडला नाही — वरून विभाग निवडा' : 'विभाग निवडलेला नाही — वरून विभाग निवडा'; }
        $it['dept'] = $dept;
        $it['deptName'] = $dept ? ($dept['name_local'] ?: $dept['name']) : $deptName;

        $it['type'] = $str('vehicle_type');
        $it['model'] = $str('model');
        $it['chassis'] = strtoupper($str('chassis_no'));
        $it['engine'] = strtoupper($str('engine_no'));
        $it['class'] = $str('vehicle_class');
        $cap = $get('passenger_capacity');
        $it['capacity'] = is_numeric($cap) ? (int)$cap : null;
        $it['fuelRaw'] = $str('fuel_type');
        $it['fuel'] = vehImportFuel($it['fuelRaw']);

        foreach (VEH_IMPORT_DATE_COLS as $col) {
            if (!isset($colIndex[$col])) { $it['dates'][$col] = null; continue; }
            [$d, $note, $kind] = vehImportDate($get($col), !empty($opts['swap']));
            $it['dates'][$col] = $d;
            $it['kinds'][$col] = $kind;
            if ($note) { $it['dateNotes'][$col] = $note; }
        }

        // कागदपत्र कधीपासून (From) व विम्याचा खर्च — नूतनीकरण नोंदीत
        $it['from'] = [];
        foreach (array_keys(VEH_IMPORT_RENEWAL_COLS) as $short) {
            if (!isset($colIndex[$short . '_from'])) continue;
            [$fd] = vehImportDate($get($short . '_from'), !empty($opts['swap']));
            if ($fd) { $it['from'][$short] = $fd; }
        }
        $ic = $get('insurance_cost');
        $it['insCost'] = is_numeric($ic) && (float)$ic > 0 ? (float)$ic : null;
        $rm = $str('remark'); if ($rm !== '') { $it['notes'][] = $rm; }
        $it['remark'] = $rm !== '' ? $rm : $str('remarks');
        $it['lifetime'] = [];
        foreach (VEH_IMPORT_RENEWAL_COLS as $short => $col) { if (($it['kinds'][$col] ?? '') === 'lifetime') { $it['lifetime'][] = $short; } }

        $it['existing'] = $existing[$it['key']] ?? null;
        $it['action'] = $it['errors'] ? 'skip' : ($it['existing'] ? (!empty($opts['update']) ? 'update' : 'skip-existing') : 'create');
        $items[] = $it;
    }
    return ['items' => $items, 'missingCols' => [], 'colIndex' => $colIndex];
}

/** Preview नंतर — प्रत्यक्ष जोडणं/अद्ययावत करणं (एकाच Transaction मध्ये). परत: [created, updated, renewals] */
function vehImportApply(PDO $pdo, $orgId, array $items) {
    $created = 0; $updated = 0; $renewals = 0;
    $pdo->beginTransaction();
    try {
        foreach ($items as $it) {
            if (!in_array($it['action'], ['create', 'update'], true)) { continue; }
            $d = $it['dates'];
            $dept = $it['dept'];
            if ($it['action'] === 'create') {
                $pdo->prepare("INSERT INTO vehicles (organization_id, campus_id, department_id, vehicle_number, vehicle_type, model, date_of_registration, chassis_no, engine_no, vehicle_class, passenger_capacity, fuel_type,
                        permit_validity, puc_validity, insurance_validity, fitness_validity, road_tax_validity, green_tax_validity, mv_tax_validity)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$orgId, $dept['campus_id'], $dept['id'], $it['number'], $it['type'] ?: null, $it['model'] ?: null, $d['date_of_registration'], $it['chassis'] ?: null, $it['engine'] ?: null,
                        $it['class'] ?: null, $it['capacity'], $it['fuel'], $d['permit_validity'], $d['puc_validity'], $d['insurance_validity'], $d['fitness_validity'], $d['road_tax_validity'], $d['green_tax_validity'], $d['mv_tax_validity']]);
                $vid = (int)$pdo->lastInsertId();
                $created++;
                $merged = $d;
            } else {
                $v = $it['existing'];
                $vid = (int)$v['id'];
                // File मध्ये रिकामं/NA असेल तर आधीची माहिती तशीच ठेवतो
                $merged = [];
                foreach (VEH_IMPORT_DATE_COLS as $col) { $merged[$col] = $d[$col] ?: ($v[$col] ?? null); }
                $pdo->prepare("UPDATE vehicles SET department_id = ?, campus_id = ?, vehicle_type = COALESCE(NULLIF(?, ''), vehicle_type), model = COALESCE(NULLIF(?, ''), model),
                        date_of_registration = ?, chassis_no = COALESCE(NULLIF(?, ''), chassis_no), engine_no = COALESCE(NULLIF(?, ''), engine_no), vehicle_class = COALESCE(NULLIF(?, ''), vehicle_class),
                        passenger_capacity = COALESCE(?, passenger_capacity), fuel_type = ?, permit_validity = ?, puc_validity = ?, insurance_validity = ?, fitness_validity = ?, road_tax_validity = ?, green_tax_validity = ?, mv_tax_validity = ?
                        WHERE id = ? AND organization_id = ?")
                    ->execute([$dept['id'], $dept['campus_id'], $it['type'], $it['model'], $merged['date_of_registration'], $it['chassis'], $it['engine'], $it['class'], $it['capacity'],
                        $it['fuelRaw'] !== '' ? $it['fuel'] : $v['fuel_type'], $merged['permit_validity'], $merged['puc_validity'], $merged['insurance_validity'], $merged['fitness_validity'],
                        $merged['road_tax_validity'], $merged['green_tax_validity'], $merged['mv_tax_validity'], $vid, $orgId]);
                $updated++;
            }
            $rd = [];
            foreach (VEH_IMPORT_RENEWAL_COLS as $short => $col) { $rd[$short] = $merged[$col] ?? null; if ($rd[$short]) { $renewals++; } }
            // आजीवन कर (LTT) व शेरा — Update मध्ये File मध्ये शेरा नसेल तर आधीचा तसाच
            vehicleSaveExtra($pdo, $vid, array_merge($it['lifetime'] ?? [], $it['action'] === 'update' ? array_filter(explode(',', (string)($it['existing']['lifetime_docs'] ?? '')), fn($f) => empty($merged[VEH_IMPORT_RENEWAL_COLS[$f] ?? ''])) : []), $it['remark'] ?? '', $it['action'] === 'update');
            syncVehicleRenewals($pdo, $vid, $orgId, $dept['campus_id'], $dept['id'], $it['number'], $rd,
                ['issued' => $it['from'] ?? [], 'cost' => !empty($it['insCost']) ? ['insurance' => $it['insCost']] : []]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$created, $updated, $renewals];
}

/**
 * 📄 परिवहन विभागाचा Report (Excel) जसाच्या तसा — "Sr.No | Vehical No | Type of Vehical | Seating Capacity | Engine No. | Chassis No. |
 * Permit (From/To) | Insurance (From/To) | MV Tax | Green Tax | Fitness Certificate | PUC" — दोन ओळींचं Header.
 * Template च्या स्वरूपात बदलतो (विभाग नाही — तो Preview मध्ये निवडायचा). इतर Sheets मधून "Insurance Cost" पण घेतो.
 * परत: null (हा Report नाही) किंवा ['rows' => [header, ...], 'title' => शीर्षक-ओळ]
 */
function vehImportFromReport(array $rows, array $otherSheets = []) {
    $norm = fn($v) => strtolower(trim(preg_replace('/\s+/', ' ', (string)$v)));
    $hdr = null;
    foreach (array_slice($rows, 0, 15, true) as $i => $r) {
        $t = $norm(implode(' | ', $r));
        if (preg_match('/vehi[c]?a?l(e)? ?no/', $t) && str_contains($t, 'type of vehi')) { $hdr = $i; break; }
    }
    if ($hdr === null) return null;
    $h1 = array_map($norm, $rows[$hdr]);
    $h2 = array_map($norm, $rows[$hdr + 1] ?? []);
    $title = '';
    for ($i = 0; $i < $hdr; $i++) { $t = trim(implode(' ', array_filter(array_map('strval', $rows[$i]), fn($v) => trim($v) !== ''))); if ($t !== '') { $title = $title ?: $t; } }
    $find = function (array $words) use ($h1) { foreach ($h1 as $c => $h) { foreach ($words as $w) { if ($h !== '' && str_contains($h, $w)) return $c; } } return null; };
    $col = ['vehicle_number' => $find(['vehical no', 'vehicle no', 'vehicle number', 'reg']), 'vehicle_type' => $find(['type of vehi']), 'passenger_capacity' => $find(['seating', 'capacity']),
        'engine_no' => $find(['engine']), 'chassis_no' => $find(['chassis']), 'model' => $find(['model', 'make']), 'fuel_type' => $find(['fuel'])];
    // कागदपत्र गट: शीर्षकाखाली From/To असे दोन स्तंभ (To नसेल तर एकच)
    $docs = ['permit' => ['permit'], 'insurance' => ['insurance'], 'mv_tax' => ['mv tax', 'm.v. tax', 'motor vehicle tax'], 'green_tax' => ['green tax'], 'fitness' => ['fitness'], 'puc' => ['puc', 'pollution'], 'road_tax' => ['road tax']];
    $docCol = [];
    foreach ($docs as $k => $w) {
        $c = $find($w); if ($c === null || ($k === 'insurance' && str_contains($h1[$c], 'cost'))) continue;
        $from = $c; $to = null;
        if (str_starts_with($h2[$c] ?? '', 'from') && str_starts_with($h2[$c + 1] ?? '', 'to')) { $to = $c + 1; } elseif (str_starts_with($h2[$c] ?? '', 'to')) { $to = $c; $from = null; } else { $to = $c; $from = null; }
        $docCol[$k] = [$from, $to];
    }
    $costCol = $find(['insurance cost', 'premium']);
    // इतर Sheets मधला विमा खर्च (वाहन क्रमांकानुसार)
    $costs = [];
    foreach ($otherSheets as $sh) {
        foreach (array_slice($sh, 0, 10, true) as $i => $r) {
            $hn = array_map($norm, $r);
            $cc = null; $vc = null;
            foreach ($hn as $c => $h) { if (str_contains($h, 'insurance cost') || $h === 'premium') $cc = $c; if (preg_match('/vehi[c]?a?l(e)? ?no/', $h)) $vc = $c; }
            if ($cc === null || $vc === null) continue;
            foreach (array_slice($sh, $i + 1) as $rr) {
                $k = vehImportKey($rr[$vc] ?? ''); $v = $rr[$cc] ?? '';
                if ($k !== '' && is_numeric($v) && (float)$v > 0) $costs[$k] = (float)$v;
            }
            break;
        }
    }
    $out = [['department_name', 'vehicle_number', 'vehicle_type', 'model', 'passenger_capacity', 'engine_no', 'chassis_no', 'fuel_type',
        'permit_validity', 'insurance_validity', 'mv_tax_validity', 'green_tax_validity', 'fitness_validity', 'puc_validity', 'road_tax_validity',
        'permit_from', 'insurance_from', 'mv_tax_from', 'green_tax_from', 'fitness_from', 'puc_from', 'road_tax_from', 'insurance_cost', 'remark']];
    $g = fn($r, $c) => $c === null ? '' : ($r[$c] ?? '');
    $lastRemark = '';
    foreach (array_slice($rows, $hdr + 2) as $r) {
        $num = trim((string)$g($r, $col['vehicle_number']));
        if ($num === '' || !preg_match('/[A-Za-z]{2}.*\d/', $num)) continue; // सही/तळटीप ओळी
        $cap = $g($r, $col['passenger_capacity']);
        $remark = is_numeric($cap) || trim((string)$cap) === '' ? '' : trim((string)$cap);
        $line = ['', $num, trim((string)$g($r, $col['vehicle_type'])), trim((string)$g($r, $col['model'])), is_numeric($cap) ? $cap : '',
            trim((string)$g($r, $col['engine_no'])), trim((string)$g($r, $col['chassis_no'])), trim((string)$g($r, $col['fuel_type']))];
        $from = [];
        foreach (['permit', 'insurance', 'mv_tax', 'green_tax', 'fitness', 'puc', 'road_tax'] as $k) {
            [$fc, $tc] = $docCol[$k] ?? [null, null];
            $tv = $g($r, $tc); $fv = $g($r, $fc);
            // "To" रिकामं आणि "From" मध्येच मुदत/आजीवन लिहिलेली (उदा. 24 NOV.2037, One time tax) — तीच मुदत
            if (trim((string)$tv) === '' && trim((string)$fv) !== '') { $tv = $fv; $fv = ''; }
            $line[] = $tv; $from[] = $fv;
        }
        // Excel मध्ये एकत्र (Merge) केलेला शेरा — पुढच्या कागदपत्र नसलेल्या वाहनांनाही तोच
        $noDocs = trim(implode('', array_map('strval', array_merge(array_slice($line, 8), $from)))) === '' && !is_numeric($cap);
        if ($remark !== '') { $lastRemark = $remark; } elseif ($noDocs && !empty($lastRemark)) { $remark = $lastRemark; } else { $lastRemark = ''; }
        $key = vehImportKey($num);
        $cost = $costCol !== null && is_numeric($g($r, $costCol)) ? (float)$g($r, $costCol) : ($costs[$key] ?? '');
        $out[] = array_merge($line, $from, [$cost, $remark]);
    }
    return count($out) > 1 ? ['rows' => $out, 'title' => $title] : null;
}
