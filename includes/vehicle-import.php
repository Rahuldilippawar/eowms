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
    $missing = array_values(array_diff(['department_name', 'vehicle_number'], $header));
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
            $it['notes'][] = '"' . $deptName . '" विभाग सापडला नाही — "' . ($dept['name_local'] ?: $dept['name']) . '" मध्ये';
        }
        if (!$dept) { $it['errors'][] = '"' . $deptName . '" हा विभाग सापडला नाही — वरून विभाग निवडा'; }
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
            syncVehicleRenewals($pdo, $vid, $orgId, $dept['campus_id'], $dept['id'], $it['number'], $rd);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$created, $updated, $renewals];
}
