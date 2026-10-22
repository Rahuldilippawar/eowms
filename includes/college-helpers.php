<?php
/**
 * College (Campus) — Helper Functions
 */

/**
 * NAAC/UGC च्या Valid-Until तारखांना "नूतनीकरण व अनुपालन" पानाशी जोडतं —
 * Vehicle/IT Asset Warranty सारखंच. तारीख बदलली/काढली तर आपोआप अद्ययावत/काढली जाते.
 */
function syncCollegeRenewals($pdo, $campusId, $orgId, $collegeName, $naacValidUntil, $ugcValidUntil) {
    // या College साठी योग्य Department/Campus संदर्भ हवा — renewal_items ला
    // campus_id + department_id आवश्यक असतो, इथे "प्रशासन"/पहिला उपलब्ध विभाग वापरतो.
    $deptStmt = $pdo->prepare("SELECT id FROM departments WHERE campus_id = ? ORDER BY id LIMIT 1");
    $deptStmt->execute([$campusId]);
    $deptId = $deptStmt->fetch()['id'] ?? null;
    if (!$deptId) {
        // या College खाली अजून एकही Department नाही — संस्थेचा कुठलाही विभाग वापरून तात्पुरतं जोडतो
        $anyDeptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? ORDER BY id LIMIT 1");
        $anyDeptStmt->execute([$orgId]);
        $deptId = $anyDeptStmt->fetch()['id'] ?? null;
    }
    if (!$deptId) { return; }

    syncSingleCollegeRenewal($pdo, $orgId, $campusId, $deptId, "NAAC Re-Accreditation — {$collegeName}", 'NAAC Accreditation', $naacValidUntil);
    syncSingleCollegeRenewal($pdo, $orgId, $campusId, $deptId, "UGC Recognition — {$collegeName}", 'UGC Recognition', $ugcValidUntil);
}

function syncSingleCollegeRenewal($pdo, $orgId, $campusId, $deptId, $itemName, $itemType, $expiryDate) {
    $existingStmt = $pdo->prepare("SELECT id FROM renewal_items WHERE item_type = ? AND item_name = ? AND campus_id = ?");
    $existingStmt->execute([$itemType, $itemName, $campusId]);
    $existing = $existingStmt->fetch();

    if (!$expiryDate) {
        if ($existing) { $pdo->prepare("DELETE FROM renewal_items WHERE id = ?")->execute([$existing['id']]); }
        return;
    }

    if ($existing) {
        $pdo->prepare("UPDATE renewal_items SET expiry_date = ? WHERE id = ?")->execute([$expiryDate, $existing['id']]);
    } else {
        $pdo->prepare("
            INSERT INTO renewal_items (organization_id, campus_id, department_id, item_type, item_name, expiry_date)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$orgId, $campusId, $deptId, $itemType, $itemName, $expiryDate]);
    }
}
