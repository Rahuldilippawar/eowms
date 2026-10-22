<?php
/**
 * IT विभाग — Helper Functions
 */

function requireItAccess($pdo, $user, $rootPath = '../') {
    $deptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
    $deptCheck->execute([$user['department_id'] ?? 0]);
    $isItStaff = ($deptCheck->fetch()['code'] ?? '') === 'IT';
    $canManage = $isItStaff || hasPermission('manage_it') || isManagementOrAbove();
    if (!$canManage) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला IT विभाग पाहण्याची परवानगी नाही. <a href="' . htmlspecialchars($rootPath) . 'index.php">Dashboard var परत जा</a></div>');
    }
    return $canManage;
}

/** दिलेली व्यक्ती या Lab ची नेमलेली Assistant आहे का (किंवा IT/Management/SuperAdmin) */
function canManageLabAssets($pdo, $user, $labId) {
    if (hasPermission('manage_it') || isManagementOrAbove()) { return true; }
    $stmt = $pdo->prepare("SELECT assistant_id FROM it_labs WHERE id = ?");
    $stmt->execute([$labId]);
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
    return sprintf('%s-%s-%04d', $prefix, $year, $seq);
}

/** Warranty Expiry ला "नूतनीकरण व अनुपालन" पानाशी जोडतं (वाहन Module सारखंच) */
function syncItAssetWarranty($pdo, $assetId, $orgId, $labId, $assetTag, $assetType, $warrantyExpiry) {
    $labStmt = $pdo->prepare("SELECT department_id FROM it_labs WHERE id = ?");
    $labStmt->execute([$labId]);
    $deptId = $labStmt->fetch()['department_id'] ?? null;
    if (!$deptId) { return; }

    $campusStmt = $pdo->prepare("SELECT campus_id FROM departments WHERE id = ?");
    $campusStmt->execute([$deptId]);
    $campusId = $campusStmt->fetch()['campus_id'] ?? null;

    $existingStmt = $pdo->prepare("SELECT id FROM renewal_items WHERE item_type = 'IT Asset Warranty' AND item_name LIKE ?");
    $existingStmt->execute(["%$assetTag%"]);
    $existing = $existingStmt->fetch();

    if (!$warrantyExpiry) {
        if ($existing) { $pdo->prepare("DELETE FROM renewal_items WHERE id = ?")->execute([$existing['id']]); }
        return;
    }

    $itemName = "IT Asset {$assetTag} ({$assetType}) — Warranty";
    if ($existing) {
        $pdo->prepare("UPDATE renewal_items SET expiry_date = ? WHERE id = ?")->execute([$warrantyExpiry, $existing['id']]);
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
