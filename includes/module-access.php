<?php
/**
 * 🔐 Module Access — "ज्यांना ज्या Module चा Access दिला आहे, फक्त तेच Module व त्याची Link दिसावी"
 *  - Super Admin → सगळं
 *  - बाकी सगळे → फक्त त्या Module ची Permission (पदे व्यवस्थापन → पद → Permissions) असेल,
 *    किंवा ते काम त्यांच्याकडे नेमलेलं असेल (उदा. वाहतूक विभाग, Lab Assistant, वसतिगृह कर्मचारी)
 *  - Management (अध्यक्ष/GM…) ला आता आपोआप सगळे Modules दिसत नाहीत — त्यांच्या पदाला Permission द्यावी लागते
 *  - रोजची मूळ कामं (कामकाज, Tasks, नूतनीकरण, पत्रव्यवहार, विनंत्या, हजेरी/रजा, संदेश, तक्रार) सगळ्यांना नेहमीच
 * Sidebar (includes/sidebar.php) आणि Server (requireLogin → moduleAccessEnforce) दोघेही हेच वापरतात.
 */

/** module key → त्याच्या Permission Keys (यापैकी एक असली तरी पुरे) */
function moduleAccessPermissions() {
    return [
        'meditation'   => ['manage_meditation'],
        'music'        => ['manage_music'],
        'events'       => ['manage_events'],
        'guests'       => ['manage_guests'],
        'room-booking' => ['manage_room_booking'],
        'allocations'  => ['manage_asset_allocations', 'manage_checkout'],
        'gaushala'     => ['manage_gaushala'],
        'vehicles'     => ['manage_vehicles'],
        'store'        => ['manage_store'],
        'cctv'         => ['manage_cctv'],
        'it'           => ['manage_it'],
        'deadstock'    => ['manage_dead_stock_master'],
        'dept-finance' => ['manage_budget', 'manage_dept_finance'],
    ];
}

/** त्या विभागाचे कर्मचारी (Department code) — त्यांचं रोजचं काम, म्हणून Permission नसली तरी Module दिसतो */
function moduleAccessDeptCodes() {
    return ['music' => ['MUS'], 'vehicles' => ['TRP'], 'store' => ['STR'], 'cctv' => ['CCTV'], 'it' => ['IT']];
}

function moduleUserDeptCode() {
    global $pdo;
    static $code = false;
    if ($code !== false) { return $code; }
    $u = currentUser();
    $code = '';
    if ($u && !empty($u['department_id']) && $pdo instanceof PDO) {
        try { $st = $pdo->prepare("SELECT code FROM departments WHERE id = ?"); $st->execute([(int)$u['department_id']]); $code = (string)($st->fetchColumn() ?: ''); } catch (PDOException $e) {}
    }
    return $code;
}

/** हा माणूस हा Module पाहू/वापरू शकतो का? (Permission / विभाग / HOD नियम — नेमणुकीचे खास नियम Sidebar मध्ये) */
function moduleAllowed($key) {
    if (!currentUser()) { return false; }
    if (hasRole('super_admin')) { return true; }
    foreach (moduleAccessPermissions()[$key] ?? [] as $perm) { if (hasPermission($perm)) { return true; } }
    if (in_array(moduleUserDeptCode(), moduleAccessDeptCodes()[$key] ?? [], true)) { return true; }
    if ($key === 'dept-finance' && hasCategory('hod')) { return true; }   // HOD — स्वतःच्या विभागाचं Budget
    if ($key === 'deadstock' && hasCategory('hod')) { return true; }      // HOD — स्वतःच्या विभागाचं Dead Stock
    return !isset(moduleAccessPermissions()[$key]);                       // यादीत नसलेले = मूळ Modules, सगळ्यांना
}

/**
 * थेट URL ने उघडलं तरी — ज्या Modules ना आधी पानावर कोणतीच तपासणी नव्हती (सगळ्यांना उघडे होते) ते इथे अडवतो.
 * (वाहने, IT, CCTV, Store, वसतिगृह इ. ची पानं आधीपासूनच स्वतः तपासतात — त्यांना इथे हात लावत नाही.)
 */
function moduleAccessEnforce($rootPath = '') {
    if (PHP_SAPI === 'cli' || !currentUser() || hasRole('super_admin')) { return; }
    $dir = basename(dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($dir, ['meditation', 'music', 'events', 'guests', 'room-booking', 'allocations', 'gaushala'], true)) { return; }
    if ($dir === 'guests' && basename($_SERVER['SCRIPT_NAME']) === 'pre-register.php') { return; } // सार्वजनिक पूर्व-नोंदणी
    if (moduleAllowed($dir)) { return; }
    http_response_code(403);
    $back = $rootPath . 'index.php';
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Access नाही</title>'
        . '<div style="font-family:system-ui,sans-serif;max-width:520px;margin:60px auto;padding:28px;border:1px solid #EAE5DF;border-radius:16px;text-align:center;background:#fff;">'
        . '<div style="font-size:42px;">🔐</div><h2 style="margin:8px 0 6px;font-size:20px;">या Module चा Access नाही</h2>'
        . '<p style="color:#475069;font-size:14.5px;line-height:1.6;">हा Module वापरण्याची परवानगी तुमच्या पदाला दिलेली नाही. गरज असल्यास Super Admin ला "पदे व्यवस्थापन" मधून Access द्यायला सांगा.</p>'
        . '<a href="' . h($back) . '" style="display:inline-block;margin-top:10px;background:#D9530F;color:#fff;text-decoration:none;padding:9px 20px;border-radius:9px;font-weight:600;">← मुख्य पान</a></div>';
    exit;
}
