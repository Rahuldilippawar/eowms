<?php
/**
 * Module Registry — संपूर्ण System मधल्या प्रत्येक मोठ्या Module ची
 * नोंद, एका ठिकाणी. Single-Purpose Access Mode (Restricted Roles) —
 * Login-Redirect, Sidebar-Simplification, आणि URL-Enforcement — तिन्ही
 * इथूनच चालतात, त्यामुळे नवीन Module आलं की फक्त इथे एक Entry
 * जोडायची लागते.
 *
 * ⚠️ महत्त्वाचं तत्त्व — इथे फक्त "Narrow, Specialist" Modules असावेत
 * (उदा. IT Labs, CCTV, Guests, Store — ज्यांना एखादी विशिष्ट व्यक्तीच
 * रोज हाताळते). "दैनंदिन काम-नोंदी", "नूतनीकरण", "पत्रव्यवहार", "Task",
 * "कामाची विनंती", "अहवाल" — हे सगळ्या Staff साठी सर्वसामान्य, रोजच्या
 * वापरातले असतात — त्यामुळे ते इथे मुद्दाम **समाविष्ट केलेले नाहीत.**
 * अशा कोणाला Restricted-Mode Role दिला, तरी त्याला नेहमीचंच पूर्ण
 * Command Center दिसेल — फक्त इथल्या Specialist Modules साठीच Single-
 * Purpose View लागू होतो.
 *
 * प्रत्येक Module:
 *   label        — मराठी नाव (Sidebar/Dashboard वर दिसतं)
 *   icon         — FontAwesome Class
 *   dirs         — या Module खालच्या सगळ्या Directories (Access-Check साठी)
 *   dashboard    — Restricted User ला Login नंतर कुठे पाठवायचं
 *   color        — Module चा Signature-रंग (Restricted-Mode Theme साठी)
 */
function getModuleRegistry() {
    return [
        'it' => [
            'label' => 'IT विभाग (Labs/Smart Board)', 'icon' => 'fa-solid fa-server',
            'dirs' => ['it'], 'dashboard' => 'it/module-dashboard.php', 'color' => '#1B8A83',
        ],
        'guests' => [
            'label' => 'पाहुणे नोंदणी', 'icon' => 'fa-solid fa-id-card-clip',
            'dirs' => ['guests'], 'dashboard' => 'guests/index.php', 'color' => '#8A5CD6',
        ],
        // जुना 'checkout' Module आता यातच (checkout/ फक्त Redirect)
        'allocations' => [
            'label' => 'Issue / Return Register (Asset Allocation)', 'icon' => 'fa-solid fa-boxes-packing',
            'dirs' => ['allocations', 'checkout'], 'dashboard' => 'allocations/index.php', 'color' => '#2255AA',
        ],
        'room-booking' => [
            'label' => 'Room/Hall Booking', 'icon' => 'fa-solid fa-door-open',
            'dirs' => ['room-booking'], 'dashboard' => 'room-booking/index.php', 'color' => '#8A5CD6',
        ],
        'messages' => [
            'label' => 'Internal Messaging', 'icon' => 'fa-solid fa-comments',
            'dirs' => ['messages'], 'dashboard' => 'messages/index.php', 'color' => '#2255AA',
        ],
        // जुना 'budget' Module आता यातच (budget/ फक्त Redirect)
        'dept-finance' => [
            'label' => 'विभाग Finance (Budget + Purchase)', 'icon' => 'fa-solid fa-sack-dollar',
            'dirs' => ['dept-finance', 'budget'], 'dashboard' => 'dept-finance/dashboard.php', 'color' => '#2255AA',
        ],
        'grievances' => [
            'label' => 'तक्रार निवारण', 'icon' => 'fa-solid fa-gavel',
            'dirs' => ['grievances'], 'dashboard' => 'grievances/index.php', 'color' => '#C97F1D',
        ],
        'events' => [
            'label' => 'कार्यक्रम व्यवस्थापन', 'icon' => 'fa-solid fa-calendar-days',
            'dirs' => ['events'], 'dashboard' => 'events/index.php', 'color' => '#B23A48',
        ],
        'cctv' => [
            'label' => 'CCTV विभाग', 'icon' => 'fa-solid fa-video',
            'dirs' => ['cctv'], 'dashboard' => 'cctv/index.php', 'color' => '#10162B',
        ],
        'vehicles' => [
            'label' => 'वाहन विभाग', 'icon' => 'fa-solid fa-car',
            'dirs' => ['vehicles'], 'dashboard' => 'vehicles/index.php', 'color' => '#2255AA',
        ],
        'maintenance' => [
            'label' => 'देखभाल विभाग', 'icon' => 'fa-solid fa-screwdriver-wrench',
            'dirs' => ['maintenance'], 'dashboard' => 'maintenance/index.php', 'color' => '#C97F1D',
        ],
        'deadstock' => [
            'label' => 'Dead Stock', 'icon' => 'fa-solid fa-box-archive',
            'dirs' => ['deadstock'], 'dashboard' => 'deadstock/index.php', 'color' => '#666B7D',
        ],
        'hostels' => [
            'label' => 'वसतिगृह विभाग', 'icon' => 'fa-solid fa-house-user',
            'dirs' => ['hostels'], 'dashboard' => 'hostels/index.php', 'color' => '#1B8A83',
        ],
        'gaushala' => [
            'label' => 'गौशाळा', 'icon' => 'fa-solid fa-cow',
            'dirs' => ['gaushala'], 'dashboard' => 'gaushala/index.php', 'color' => '#C97F1D',
        ],
        'residency' => [
            'label' => 'रहिवासी विभाग', 'icon' => 'fa-solid fa-house-chimney-user',
            'dirs' => ['residency'], 'dashboard' => 'residency/index.php', 'color' => '#1B8A83',
        ],
        'music' => [
            'label' => 'संगीत विभाग', 'icon' => 'fa-solid fa-music',
            'dirs' => ['music'], 'dashboard' => 'music/index.php', 'color' => '#8A5CD6',
        ],
        'meditation' => [
            'label' => 'ध्यान व सत्संग', 'icon' => 'fa-solid fa-spa',
            'dirs' => ['meditation'], 'dashboard' => 'meditation/index.php', 'color' => '#1B8A83',
        ],
        'academics' => [
            'label' => 'Virtual Classroom', 'icon' => 'fa-solid fa-chalkboard-user',
            'dirs' => ['academics'], 'dashboard' => 'academics/students.php', 'color' => '#C97F1D',
        ],
        'sop' => [
            'label' => 'SOP', 'icon' => 'fa-solid fa-list-check',
            'dirs' => ['sop'], 'dashboard' => 'sop/checklist.php', 'color' => '#666B7D',
        ],
    ];
}

/** एका Module ची संपूर्ण Info परत देतं — नसेल तर null. */
function getModuleInfo($moduleKey) {
    $registry = getModuleRegistry();
    return $registry[$moduleKey] ?? null;
}

/**
 * सध्याचा Logged-in User "Restricted Mode" मध्ये आहे का — असेल तर
 * त्याचे Allowed Module Keys (Array) परत देतं, नसेल तर null (म्हणजे
 * पूर्ण System दिसेल, जुन्या Behavior प्रमाणेच).
 */
function getRestrictedModules() {
    global $pdo;
    $user = currentUser();
    if (!$user || hasRole('super_admin')) { return null; }

    static $cache = null;
    if ($cache !== null) { return $cache; }

    try {
        $stmt = $pdo->prepare("SELECT restricted_mode, restricted_modules FROM role_definitions WHERE role_key = ?");
        $stmt->execute([$user['role']]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return $cache = null; // Migration अजून चालवलेली नसेल
    }

    if (empty($row) || !$row['restricted_mode'] || empty($row['restricted_modules'])) {
        return $cache = null;
    }

    return $cache = array_filter(array_map('trim', explode(',', $row['restricted_modules'])));
}

/** सध्याचा User Restricted-Mode मध्ये आहे का — साधा Boolean. */
function isRestrictedUser() {
    return getRestrictedModules() !== null;
}
