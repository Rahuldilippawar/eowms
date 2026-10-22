<?php
/**
 * भांडार Material-Master — Helper Functions
 * (Purchase/Store चं Formal Workflow काढून टाकलं — फक्त Daily-Work च्या
 * "वापरलेलं साहित्य" साठी लागणारी Material-Name यादी इथे ठेवली जाते.)
 */
function requireStoreAccess($pdo, $user, $rootPath = '../') {
    $deptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
    $deptCheck->execute([$user['department_id'] ?? 0]);
    $isStoreStaff = ($deptCheck->fetch()['code'] ?? '') === 'STR';
    $canManage = $isStoreStaff || hasPermission('manage_store') || isManagementOrAbove();
    if (!$canManage) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला ही यादी पाहण्याची परवानगी नाही. <a href="' . htmlspecialchars($rootPath) . 'index.php">Dashboard var परत जा</a></div>');
    }
    return $canManage;
}
