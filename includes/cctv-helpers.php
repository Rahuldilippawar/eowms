<?php
/**
 * CCTV विभाग — Helper Functions
 */

function requireCctvAccess($pdo, $user, $rootPath = '../') {
    $deptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
    $deptCheck->execute([$user['department_id'] ?? 0]);
    $isCctvStaff = ($deptCheck->fetch()['code'] ?? '') === 'CCTV';
    $canManage = $isCctvStaff || hasPermission('manage_cctv') || isManagementOrAbove();
    if (!$canManage) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला CCTV विभाग पाहण्याची परवानगी नाही. <a href="' . htmlspecialchars($rootPath) . 'index.php">Dashboard var परत जा</a></div>');
    }
    return $canManage;
}

/** आजच्या (किंवा दिलेल्या) दिवसाची Checkup आधीच झाली आहे का */
function cctvGetTodayCheckup($pdo, $dvrId, $date = null) {
    $date = $date ?? date('Y-m-d');
    $stmt = $pdo->prepare("SELECT * FROM cctv_checkups WHERE dvr_id = ? AND checkup_date = ?");
    $stmt->execute([$dvrId, $date]);
    return $stmt->fetch();
}
