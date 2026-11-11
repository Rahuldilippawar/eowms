<?php
/**
 * वाहनाच्या Permit/PUC/Insurance/Fitness/Road Tax/Green Tax/MV Tax या
 * तारखा — "नूतनीकरण व अनुपालन" पानावर आपोआप दाखवण्यासाठी, प्रत्येक
 * तारखेला एक renewal_items नोंद जोडते (आधीच असेल तर Update करते,
 * नसेल तर नवीन तयार करते — त्यामुळे वाहन पुन्हा Edit केलं तरी Duplicate
 * नोंदी तयार होत नाहीत).
 */
function syncVehicleRenewals($pdo, $vehicleId, $orgId, $campusId, $departmentId, $vehicleNumber, $renewalDates) {
    $labels = [
        'permit' => 'परमिट',
        'puc' => 'PUC (प्रदूषण प्रमाणपत्र)',
        'insurance' => 'विमा (Insurance)',
        'fitness' => 'फिटनेस प्रमाणपत्र',
        'road_tax' => 'रोड टॅक्स',
        'green_tax' => 'ग्रीन टॅक्स',
        'mv_tax' => 'MV टॅक्स',
    ];

    foreach ($labels as $field => $label) {
        $expiryDate = $renewalDates[$field] ?? null;

        $existingStmt = $pdo->prepare("SELECT id FROM renewal_items WHERE vehicle_id = ? AND renewal_field = ?");
        $existingStmt->execute([$vehicleId, $field]);
        $existing = $existingStmt->fetch();

        if (!$expiryDate) {
            // तारीख रिकामी ठेवली असेल — आधीची नोंद असेल तर काढून टाका (आता लागू नाही)
            if ($existing) {
                $pdo->prepare("DELETE FROM renewal_items WHERE id = ?")->execute([$existing['id']]);
            }
            continue;
        }

        $itemName = "वाहन {$vehicleNumber} — {$label}";
        if ($existing) {
            $pdo->prepare("UPDATE renewal_items SET expiry_date = ?, item_name = ?, campus_id = ?, department_id = ?, regulatory_body = 'RTO/Transport' WHERE id = ?")
                ->execute([$expiryDate, $itemName, $campusId, $departmentId, $existing['id']]);
        } else {
            $pdo->prepare("
                INSERT INTO renewal_items (organization_id, campus_id, department_id, vehicle_id, renewal_field, item_type, regulatory_body, item_name, expiry_date)
                VALUES (?, ?, ?, ?, ?, 'वाहन नूतनीकरण', 'RTO/Transport', ?, ?)
            ")->execute([$orgId, $campusId, $departmentId, $vehicleId, $field, $itemName, $expiryDate]);
        }
    }
}

/** मानवी-वाचनीय नाव — कोणत्या Field साठी कोणतं Label दाखवायचं */
function vehicleRenewalFieldLabel($field) {
    $labels = [
        'permit' => 'परमिट', 'puc' => 'PUC', 'insurance' => 'विमा', 'fitness' => 'फिटनेस',
        'road_tax' => 'रोड टॅक्स', 'green_tax' => 'ग्रीन टॅक्स', 'mv_tax' => 'MV टॅक्स',
    ];
    return $labels[$field] ?? $field;
}

/**
 * ट्रिप/इंधन नोंद दुरुस्त/डिलीट केल्यावर — वाहनाचं "सध्याचं मीटर" उरलेल्या
 * नोंदींमधल्या सर्वात मोठ्या वाचनावरून पुन्हा मोजतो (नोंदच नसेल तर बदलत नाही).
 */
function vehicleRecomputeOdometer($pdo, $vehicleId, $oldReading = null) {
    $stmt = $pdo->prepare("
        SELECT MAX(r) m FROM (
            SELECT GREATEST(COALESCE(end_odometer, 0), COALESCE(start_odometer, 0)) r FROM vehicle_trips WHERE vehicle_id = ?
            UNION ALL
            SELECT COALESCE(odometer_reading, 0) FROM vehicle_fuel_log WHERE vehicle_id = ?
        ) x
    ");
    $stmt->execute([$vehicleId, $vehicleId]);
    $max = $stmt->fetch()['m'] ?? null;
    $curStmt = $pdo->prepare("SELECT current_odometer FROM vehicles WHERE id = ?");
    $curStmt->execute([$vehicleId]);
    $current = (float)$curStmt->fetchColumn();
    // दुरुस्त/डिलीट झालेली नोंद सध्याच्या मीटरपेक्षा कमी होती — म्हणजे सध्याचं मीटर
    // त्या नोंदीवरून आलेलं नाही (उदा. वाहन-Form मध्ये हाताने टाकलेलं), ते कमी करत नाही
    if ($oldReading !== null && (float)$oldReading < $current) {
        $max = max($current, (float)$max);
    }
    if ($max !== null) {
        $pdo->prepare("UPDATE vehicles SET current_odometer = ? WHERE id = ?")->execute([$max, $vehicleId]);
    }
}

/** ट्रिप/इंधन नोंदीचं मीटर-वाचन (दुरुस्ती आधी लक्षात ठेवण्यासाठी) */
function vehicleEntryReading($pdo, $entryType, $entryId) {
    if ($entryType === 'trip') {
        $stmt = $pdo->prepare("SELECT GREATEST(COALESCE(end_odometer, 0), COALESCE(start_odometer, 0)) FROM vehicle_trips WHERE id = ?");
    } elseif ($entryType === 'fuel') {
        $stmt = $pdo->prepare("SELECT odometer_reading FROM vehicle_fuel_log WHERE id = ?");
    } else {
        return null;
    }
    $stmt->execute([$entryId]);
    $r = $stmt->fetchColumn();
    return $r === false ? null : (float)$r;
}

/** वाहन यादी/नवीन वाहन/Import — Super Admin किंवा manage_vehicles Permission (सगळीकडे हाच नियम) */
function canManageVehicles() {
    return hasRole('super_admin') || hasPermission('manage_vehicles');
}

/**
 * Form मधून आलेले चालक/Campus याच संस्थेचे आहेत का, इंधन-प्रकार व
 * तारखा वैध आहेत का — तपासून, चूक असल्यास मराठी संदेश परत देतो (नाहीतर null).
 */
function vehicleValidateInput($pdo, $orgId, $driverId, $campusId, $fuelType, array $dates) {
    if ($driverId) {
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND organization_id = ?");
        $st->execute([$driverId, $orgId]);
        if (!$st->fetch()) { return 'निवडलेला चालक सापडला नाही.'; }
    }
    if ($campusId) {
        $st = $pdo->prepare("SELECT 1 FROM campuses WHERE id = ? AND organization_id = ?");
        $st->execute([$campusId, $orgId]);
        if (!$st->fetch()) { return 'निवडलेलं College/Campus सापडलं नाही.'; }
    }
    if (!in_array($fuelType, ['petrol', 'diesel', 'cng', 'electric', 'other'], true)) {
        return 'इंधनाचा प्रकार अवैध आहे.';
    }
    foreach ($dates as $d) {
        if ($d !== null && $d !== '' && !DateTime::createFromFormat('!Y-m-d', (string)$d)) { return 'एखादी तारीख चुकीच्या स्वरूपात आहे.'; }
    }
    return null;
}

// ============================================================
// 🧠 Smart वाहन व्यवस्थापन — कागदपत्रं, सर्व्हिस, वापर, खर्च, इंधन-विसंगती
// ============================================================

/** वाहनाची कागदपत्रं — [['col','label','date','days','state' => ok|soon|expired, 'critical' => bool], …] */
function vehicleDocs(array $v, $soonDays = 30) {
    // critical — मुदत संपली तर वाहन रस्त्यावर चालवू नये (कायद्याने आवश्यक)
    $map = [
        'insurance_validity' => ['विमा', true], 'puc_validity' => ['PUC', true], 'fitness_validity' => ['फिटनेस', true],
        'permit_validity' => ['परमिट', true], 'road_tax_validity' => ['रोड टॅक्स', false],
        'green_tax_validity' => ['ग्रीन टॅक्स', false], 'mv_tax_validity' => ['MV टॅक्स', false],
    ];
    $today = strtotime(date('Y-m-d')); $out = [];
    foreach ($map as $col => [$label, $critical]) {
        if (empty($v[$col])) continue;
        $days = (int)floor((strtotime($v[$col]) - $today) / 86400);
        $out[] = ['col' => $col, 'label' => $label, 'date' => $v[$col], 'days' => $days, 'critical' => $critical,
            'state' => $days < 0 ? 'expired' : ($days <= $soonDays ? 'soon' : 'ok')];
    }
    return $out;
}

/** vehicle_maintenance मध्ये next_due_km Column आहे का (Database Update आधीही पान चालावं) */
function vehicleHasDueKm(PDO $pdo) {
    static $has = null;
    if ($has === null) {
        try { $has = (bool)$pdo->query("SHOW COLUMNS FROM vehicle_maintenance LIKE 'next_due_km'")->fetch(); } catch (PDOException $e) { $has = false; }
    }
    return $has;
}

/**
 * पुढची सर्व्हिस — शेवटच्या देखभाल नोंदीवरून (तारीख आणि/किंवा km).
 * [vehicle_id => ['type','next_date','next_km','days','km_left','state' => overdue|soon|ok, 'text']]
 */
function vehicleServiceStatus(PDO $pdo, array $vehicles, $soonDays = 7, $soonKm = 500) {
    $out = [];
    if (!$vehicles) return $out;
    $ids = array_map(fn($v) => (int)$v['id'], $vehicles);
    $odo = array_column($vehicles, 'current_odometer', 'id');
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $kmCol = vehicleHasDueKm($pdo) ? 'm.next_due_km' : 'NULL';
    $st = $pdo->prepare("SELECT m.vehicle_id, m.type, m.maintenance_date, m.next_due_date, $kmCol AS next_due_km FROM vehicle_maintenance m
        WHERE m.vehicle_id IN ($ph) AND m.id = (SELECT m2.id FROM vehicle_maintenance m2 WHERE m2.vehicle_id = m.vehicle_id ORDER BY m2.maintenance_date DESC, m2.id DESC LIMIT 1)");
    $st->execute($ids);
    $today = strtotime(date('Y-m-d'));
    foreach ($st->fetchAll() as $r) {
        $vid = (int)$r['vehicle_id'];
        $days = $r['next_due_date'] ? (int)floor((strtotime($r['next_due_date']) - $today) / 86400) : null;
        $kmLeft = ($r['next_due_km'] !== null && (float)$r['next_due_km'] > 0) ? (float)$r['next_due_km'] - (float)($odo[$vid] ?? 0) : null;
        $state = 'ok';
        if (($days !== null && $days < 0) || ($kmLeft !== null && $kmLeft < 0)) $state = 'overdue';
        elseif (($days !== null && $days <= $soonDays) || ($kmLeft !== null && $kmLeft <= $soonKm)) $state = 'soon';
        $bits = [];
        if ($days !== null) $bits[] = $days < 0 ? abs($days) . ' दिवस उलटले' : ($days === 0 ? 'आज' : $days . ' दिवसांत');
        if ($kmLeft !== null) $bits[] = $kmLeft < 0 ? number_format(abs($kmLeft)) . ' km ओलांडले' : number_format($kmLeft) . ' km बाकी';
        if (!$bits) continue; // पुढची तारीख/km दिलेली नाही
        $out[$vid] = ['type' => $r['type'], 'next_date' => $r['next_due_date'], 'next_km' => $r['next_due_km'], 'days' => $days, 'km_left' => $kmLeft,
            'state' => $state, 'text' => implode(' · ', $bits), 'last_date' => $r['maintenance_date']];
    }
    return $out;
}

/**
 * कालावधीतील वापर व खर्च — [vehicle_id => km, trips, days_used, liters, fuel, maint, maint_n, last_trip, kmpl, cost, cost_km]
 * $from/$to = 'Y-m-d'. last_trip — कालावधीची पर्वा न करता सगळ्यात शेवटची Trip.
 */
function vehicleFleetStats(PDO $pdo, array $ids, $from, $to) {
    $out = [];
    if (!$ids) return $out;
    foreach ($ids as $id) $out[(int)$id] = ['km' => 0.0, 'trips' => 0, 'days_used' => 0, 'liters' => 0.0, 'fuel' => 0.0, 'maint' => 0.0, 'maint_n' => 0, 'last_trip' => null];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $q = function ($sql, $params) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); };
    foreach ($q("SELECT vehicle_id, SUM(distance_km) km, COUNT(*) n, COUNT(DISTINCT trip_date) d FROM vehicle_trips WHERE vehicle_id IN ($ph) AND trip_date BETWEEN ? AND ? GROUP BY vehicle_id", array_merge($ids, [$from, $to])) as $r) {
        $out[(int)$r['vehicle_id']]['km'] = (float)$r['km']; $out[(int)$r['vehicle_id']]['trips'] = (int)$r['n']; $out[(int)$r['vehicle_id']]['days_used'] = (int)$r['d'];
    }
    foreach ($q("SELECT vehicle_id, SUM(liters) l, SUM(COALESCE(cost,0)) c FROM vehicle_fuel_log WHERE vehicle_id IN ($ph) AND fill_date BETWEEN ? AND ? GROUP BY vehicle_id", array_merge($ids, [$from, $to])) as $r) {
        $out[(int)$r['vehicle_id']]['liters'] = (float)$r['l']; $out[(int)$r['vehicle_id']]['fuel'] = (float)$r['c'];
    }
    foreach ($q("SELECT vehicle_id, SUM(COALESCE(cost,0)) c, COUNT(*) n FROM vehicle_maintenance WHERE vehicle_id IN ($ph) AND maintenance_date BETWEEN ? AND ? GROUP BY vehicle_id", array_merge($ids, [$from, $to])) as $r) {
        $out[(int)$r['vehicle_id']]['maint'] = (float)$r['c']; $out[(int)$r['vehicle_id']]['maint_n'] = (int)$r['n'];
    }
    foreach ($q("SELECT vehicle_id, MAX(trip_date) d FROM vehicle_trips WHERE vehicle_id IN ($ph) GROUP BY vehicle_id", $ids) as $r) {
        $out[(int)$r['vehicle_id']]['last_trip'] = $r['d'];
    }
    foreach ($out as &$s) {
        $s['kmpl'] = $s['liters'] > 0 && $s['km'] > 0 ? round($s['km'] / $s['liters'], 1) : null;
        $s['cost'] = $s['fuel'] + $s['maint'];
        $s['cost_km'] = $s['km'] > 0 ? round($s['cost'] / $s['km'], 2) : null;
    }
    unset($s);
    return $out;
}

/**
 * प्रत्येक इंधन-भरणीचं मायलेज (Full-tank पद्धत: मागच्या भरणीपासूनचे km ÷ या वेळचे लिटर)
 * आणि विसंगती — मायलेज अचानक कमी, Meter मागे गेलं, दर जास्त.
 * परत: [fuel_log_id => ['km','kmpl','rate','flags' => [..]]]
 */
function vehicleFuelAnalysis(PDO $pdo, $vehicleId, $limit = 60) {
    $st = $pdo->prepare("SELECT id, fill_date, odometer_reading, liters, cost FROM vehicle_fuel_log WHERE vehicle_id = ? ORDER BY fill_date DESC, id DESC LIMIT " . (int)$limit);
    $st->execute([(int)$vehicleId]);
    $rows = array_reverse($st->fetchAll());
    $out = []; $vals = []; $rates = [];
    $prev = null;
    foreach ($rows as $r) {
        $rate = (float)$r['liters'] > 0 && $r['cost'] !== null && (float)$r['cost'] > 0 ? round((float)$r['cost'] / (float)$r['liters'], 2) : null;
        $e = ['km' => null, 'kmpl' => null, 'rate' => $rate, 'flags' => [], 'date' => $r['fill_date']];
        if ($prev !== null) {
            $km = (float)$r['odometer_reading'] - (float)$prev['odometer_reading'];
            if ($km <= 0) { $e['flags'][] = 'meter'; }
            elseif ((float)$r['liters'] > 0) { $e['km'] = $km; $e['kmpl'] = round($km / (float)$r['liters'], 1); $vals[] = $e['kmpl']; }
        }
        if ($rate !== null) $rates[] = $rate;
        $out[(int)$r['id']] = $e;
        $prev = $r;
    }
    $median = function ($a) { if (!$a) return null; sort($a); $n = count($a); return $n % 2 ? $a[intdiv($n, 2)] : ($a[$n / 2 - 1] + $a[$n / 2]) / 2; };
    $mKmpl = count($vals) >= 3 ? $median($vals) : null;
    $mRate = count($rates) >= 3 ? $median($rates) : null;
    foreach ($out as &$e) {
        if ($mKmpl && $e['kmpl'] !== null && $e['kmpl'] < $mKmpl * 0.7) $e['flags'][] = 'low';
        if ($mRate && $e['rate'] !== null && $e['rate'] > $mRate * 1.15) $e['flags'][] = 'rate';
    }
    unset($e);
    return ['fills' => $out, 'median_kmpl' => $mKmpl, 'median_rate' => $mRate];
}

/** विसंगतीचं मराठी वर्णन */
function vehicleFuelFlagText($flag, $e = [], $median = null) {
    if ($flag === 'meter') return 'Meter मागच्या भरणीपेक्षा कमी/तेवढंच — नोंद तपासा';
    if ($flag === 'low') return 'मायलेज फक्त ' . $e['kmpl'] . ' km/l (नेहमी ~' . round((float)$median, 1) . ') — गळती / चुकीची नोंद?';
    if ($flag === 'rate') return 'दर ₹' . $e['rate'] . '/लि — नेहमीपेक्षा जास्त';
    return $flag;
}
