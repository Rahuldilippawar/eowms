<?php
/**
 * IT विभाग — Helper Functions
 */

/** ही व्यक्ती IT विभागाची (code = 'IT') सदस्य आहे का */
function isItDeptStaff($pdo, $user) {
    $deptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ? AND organization_id = ?");
    $deptCheck->execute([$user['department_id'] ?? 0, activeOrgId()]);
    return ($deptCheck->fetch()['code'] ?? '') === 'IT';
}

/** ही व्यक्ती सक्रिय संस्थेतल्या एखाद्या Lab ची नेमलेली Assistant आहे का */
function isItLabAssistant($pdo, $user) {
    $stmt = $pdo->prepare("SELECT 1 FROM it_labs WHERE organization_id = ? AND assistant_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([activeOrgId(), $user['id'] ?? 0]);
    return (bool)$stmt->fetch();
}

/** IT तक्रारी/विनंत्या व्यवस्थापन — IT विभागाचे सदस्य किंवा manage_it Permission (Sidebar व पान दोन्हीकडे हाच नियम) */
function canManageItRequests($pdo, $user) {
    return hasPermission('manage_it') || isItDeptStaff($pdo, $user);
}

/**
 * $allowLabAssistant = true — Lab Assistant ला (फक्त canManageLabAssets ने
 * ठरवलेल्या स्वतःच्या Lab च्या Assets साठी) पान उघडू देतो; तेव्हा false परत येतं.
 */
function requireItAccess($pdo, $user, $rootPath = '../', $allowLabAssistant = false) {
    $isItStaff = isItDeptStaff($pdo, $user);
    $canManage = $isItStaff || hasPermission('manage_it') || isManagementOrAbove();
    if (!$canManage && $allowLabAssistant && isItLabAssistant($pdo, $user)) {
        return false;
    }
    if (!$canManage) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला IT विभाग पाहण्याची परवानगी नाही. <a href="' . htmlspecialchars($rootPath) . 'index.php">Dashboard var परत जा</a></div>');
    }
    return $canManage;
}

/** दिलेली व्यक्ती या Lab ची नेमलेली Assistant आहे का (किंवा IT/Management/SuperAdmin) */
function canManageLabAssets($pdo, $user, $labId) {
    if (hasPermission('manage_it') || isManagementOrAbove()) { return true; }
    $stmt = $pdo->prepare("SELECT assistant_id FROM it_labs WHERE id = ? AND organization_id = ?");
    $stmt->execute([$labId, activeOrgId()]);
    $lab = $stmt->fetch();
    return $lab && (int)$lab['assistant_id'] === (int)$user['id'];
}

function itGenerateAssetTag($pdo, $orgId, $assetType) {
    $prefixMap = ['computer' => 'PC', 'monitor' => 'MON', 'keyboard' => 'KB', 'mouse' => 'MS', 'cpu' => 'CPU',
                   'ups' => 'UPS', 'smartboard' => 'SB', 'projector' => 'PRJ', 'printer' => 'PRN', 'other' => 'IT'];
    $prefix = $prefixMap[$assetType] ?? 'IT';
    $year = date('y');
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM it_assets WHERE organization_id = ? AND asset_type = ?");
    $stmt->execute([$orgId, $assetType]);
    $seq = (int)$stmt->fetch()['c'] + 1;
    return generateUniqueCode($pdo, 'it_assets', 'asset_tag', '%s-%s-%04d', $seq, $prefix, $year);
}

/** Warranty Expiry ला "नूतनीकरण व अनुपालन" पानाशी जोडतं (वाहन Module सारखंच) */
function syncItAssetWarranty($pdo, $assetId, $orgId, $labId, $assetTag, $assetType, $warrantyExpiry) {
    // याच संस्थेची, याच Asset Tag ची नोंद (Tag अनोखा आहे — "IT Asset <tag> (" या स्वरूपात शोधतो)
    $existingStmt = $pdo->prepare("SELECT id FROM renewal_items WHERE organization_id = ? AND item_type = 'IT Asset Warranty' AND item_name LIKE ?");
    $existingStmt->execute([$orgId, 'IT Asset ' . $assetTag . ' (%']);
    $existing = $existingStmt->fetch();

    // Warranty तारीख नसेल (किंवा Asset काढलं) — नूतनीकरण नोंदही काढतो
    if (!$warrantyExpiry) {
        if ($existing) { $pdo->prepare("DELETE FROM renewal_items WHERE id = ?")->execute([$existing['id']]); }
        return;
    }

    $labStmt = $pdo->prepare("SELECT department_id FROM it_labs WHERE id = ? AND organization_id = ?");
    $labStmt->execute([$labId, $orgId]);
    $deptId = $labStmt->fetch()['department_id'] ?? null;
    if (!$deptId) {
        // Lab ला विभाग नसेल तर संस्थेचा IT विभाग वापरतो
        $itDeptStmt = $pdo->prepare("SELECT id FROM departments WHERE organization_id = ? AND code = 'IT' LIMIT 1");
        $itDeptStmt->execute([$orgId]);
        $deptId = $itDeptStmt->fetch()['id'] ?? null;
    }
    if (!$deptId) { return; }

    $campusStmt = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ?");
    $campusStmt->execute([$deptId]);
    $campusId = $campusStmt->fetch()['campus_id'] ?? null;

    $itemName = "IT Asset {$assetTag} ({$assetType}) — Warranty";
    if ($existing) {
        $pdo->prepare("UPDATE renewal_items SET expiry_date = ?, item_name = ?, campus_id = ?, department_id = ? WHERE id = ?")
            ->execute([$warrantyExpiry, $itemName, $campusId, $deptId, $existing['id']]);
    } else {
        $pdo->prepare("
            INSERT INTO renewal_items (organization_id, campus_id, department_id, item_type, item_name, expiry_date)
            VALUES (?, ?, ?, 'IT Asset Warranty', ?, ?)
        ")->execute([$orgId, $campusId, $deptId, $itemName, $warrantyExpiry]);
    }
}

function itAssetTypeLabel($type) {
    $labels = ['computer' => '💻 Computer', 'monitor' => '🖥️ Monitor', 'keyboard' => '⌨️ Keyboard', 'mouse' => '🖱️ Mouse',
                'cpu' => '🖥️ CPU', 'ups' => '🔋 UPS', 'smartboard' => '📺 Smart Board', 'projector' => '📽️ Projector',
                'printer' => '🖨️ Printer', 'other' => '📦 इतर'];
    return $labels[$type] ?? $type;
}
