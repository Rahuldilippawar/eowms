<?php
// वसतिगृह (Hostel) Module ची सामायिक Access-तपासणी — fees/leave/health/
// alumni/room-allocation या पानांवर आधी कुठलीच तपासणी नव्हती, त्यामुळे
// कोणताही Login केलेला कर्मचारी (आणि दुसऱ्या संस्थेचे IDs वापरून) या
// नोंदी बदलू शकत होता.

function requireHostelStaffAccess($pdo, $user) {
    if (hasPermission('manage_hostels') || hasPermission('manage_hostel_students')) {
        return;
    }
    $deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
    $deptCodeCheck->execute([$user['department_id'] ?? 0]);
    if (($deptCodeCheck->fetch()['code'] ?? null) === 'HOS') {
        return;
    }
    http_response_code(403);
    die(t('s462'));
}

// POST मधून आलेला प्रत्येक ID (student/hostel/room/fee/leave/allocation/
// alumni) — सध्याच्या संस्थेच्याच वसतिगृहाचा आहे ना, हे तपासतो.
function requireHostelPostIdsInOrg($pdo, $orgId) {
    $checks = [
        'student_id'    => "SELECT 1 FROM hostel_students hs JOIN hostels h ON h.id = hs.hostel_id WHERE hs.id = ? AND h.organization_id = ?",
        'hostel_id'     => "SELECT 1 FROM hostels WHERE id = ? AND organization_id = ?",
        'room_id'       => "SELECT 1 FROM hostel_rooms r JOIN hostels h ON h.id = r.hostel_id WHERE r.id = ? AND h.organization_id = ?",
        'fee_id'        => "SELECT 1 FROM hostel_fees f JOIN hostel_students hs ON hs.id = f.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE f.id = ? AND h.organization_id = ?",
        'leave_id'      => "SELECT 1 FROM hostel_leave_records l JOIN hostel_students hs ON hs.id = l.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE l.id = ? AND h.organization_id = ?",
        'allocation_id' => "SELECT 1 FROM hostel_room_allocations a JOIN hostel_rooms r ON r.id = a.room_id JOIN hostels h ON h.id = r.hostel_id WHERE a.id = ? AND h.organization_id = ?",
        'alumni_id'     => "SELECT 1 FROM hostel_alumni a JOIN hostel_students hs ON hs.id = a.student_id JOIN hostels h ON h.id = hs.hostel_id WHERE a.id = ? AND h.organization_id = ?",
    ];
    foreach ($checks as $key => $sql) {
        $id = (int)($_POST[$key] ?? 0);
        if (!$id) { continue; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id, $orgId]);
        if (!$stmt->fetch()) {
            http_response_code(403);
            die(t('s462'));
        }
    }
}
