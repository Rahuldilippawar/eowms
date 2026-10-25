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
