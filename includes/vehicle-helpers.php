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
