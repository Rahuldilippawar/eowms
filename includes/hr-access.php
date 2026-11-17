<?php
/**
 * 👥 HR व्यवस्थापन — Super Admin न बनवता एखाद्या व्यक्तीला HR ची कामं:
 *   manage_hr     — कर्मचारी जोडणं/बदलणं/Import/सक्रिय-निष्क्रिय, हजेरी, रजा (शिल्लक, प्रकार, मंजुरी), सुट्ट्या, Shift
 *   manage_salary — पगार रचना पाहणं/बदलणं, Salary Import व अहवाल
 * पदे व्यवस्थापन → व्यक्तीनुसार Access मधून एका व्यक्तीला देता येतात.
 *
 * 🔒 सुरक्षा: HR (Super Admin नसलेला) Super Admin / Management पदाच्या खात्यांना हात लावू शकत नाही
 * आणि कोणालाही ते पद देऊ शकत नाही — नाहीतर HR स्वतःला किंवा इतरांना वरचे अधिकार देऊ शकेल.
 * कर्मचारी Delete, Extra Access, पदे व अधिकार — फक्त Super Admin.
 */

function canManageHr() {
    return hasRole('super_admin') || hasPermission('manage_hr');
}

function canManageSalary() {
    return hasRole('super_admin') || hasPermission('manage_salary');
}

function requireHrAccess($rootPath = '../') {
    if (!canManageHr()) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">हे पान फक्त Super Admin किंवा HR व्यवस्थापन परवानगी असलेल्यांसाठी आहे. <a href="' . htmlspecialchars($rootPath) . 'index.php">Dashboard</a></div>');
    }
}

/** हे पद HR देऊ शकतो का — Super Admin ला सगळी; HR ला फक्त Staff / HOD श्रेणीची */
function hrCanAssignRole($roleKey) {
    if (hasRole('super_admin')) return true;
    return $roleKey !== 'super_admin' && !in_array(roleCategory($roleKey), ['super_admin', 'management'], true);
}

/** या पदाच्या व्यक्तीचं खातं HR बदलू शकतो का (Super Admin / Management नाही) */
function hrCanEditUserRole($targetRoleKey) {
    return hrCanAssignRole($targetRoleKey);
}

/** HR या व्यक्तीची माहिती (Documents, Emergency Contact, Achievements) बदलू शकतो का — याच संस्थेचा, Staff/HOD श्रेणीचा */
function hrCanManageUserId($targetId) {
    global $pdo;
    if (!canManageHr() || !$targetId) return false;
    if (hasRole('super_admin')) return true;
    $st = $pdo->prepare("SELECT role FROM users WHERE id = ? AND organization_id = ?");
    $st->execute([(int)$targetId, activeOrgId()]);
    $r = $st->fetchColumn();
    return $r !== false && hrCanEditUserRole((string)$r) && (int)$targetId !== (int)(currentUser()['id'] ?? 0);
}
